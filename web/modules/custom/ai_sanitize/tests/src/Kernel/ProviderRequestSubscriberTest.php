<?php

namespace Drupal\Tests\ai_sanitize\Kernel;

use Drupal\ai\Event\PreGenerateResponseEvent;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\Tools\ToolsFunctionOutput;
use Drupal\ai\OperationType\Embeddings\EmbeddingsInput;
use Drupal\ai_sanitize\Event\TextSanitizedEvent;
use Drupal\ai_sanitize\EventSubscriber\ProviderRequestSubscriber;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests that outgoing AI requests are sanitized at the provider boundary.
 */
#[Group('ai_sanitize')]
#[CoversClass(ProviderRequestSubscriber::class)]
class ProviderRequestSubscriberTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'file', 'key', 'ai', 'ai_sanitize'];

  /**
   * Synthetic sensitive values.
   */
  const IBAN = 'DE89 3704 0044 0532 0130 00';
  const OTHER_IBAN = 'GB82 WEST 1234 5698 7654 32';
  const EMAIL = 'max.muster@example.org';

  /**
   * TextSanitizedEvents dispatched during the test.
   *
   * @var \Drupal\ai_sanitize\Event\TextSanitizedEvent[]
   */
  protected array $sanitizedEvents = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['ai_sanitize']);
    $this->container->get('event_dispatcher')->addListener(TextSanitizedEvent::EVENT_NAME, function (TextSanitizedEvent $event) {
      $this->sanitizedEvents[] = $event;
    });
  }

  /**
   * Dispatches a pre-generate event like the AI module's provider proxy does.
   */
  protected function dispatch(mixed $input, string $operation = 'chat', array $tags = []): PreGenerateResponseEvent {
    $event = new PreGenerateResponseEvent('thread-1', 'anthropic', $operation, [], $input, 'claude-sonnet-5', $tags);
    $this->container->get('event_dispatcher')->dispatch($event, PreGenerateResponseEvent::EVENT_NAME);
    return $event;
  }

  /**
   * An agent-loop request: every part of it is sanitized.
   */
  public function testAgentLoopRequestIsSanitized(): void {
    // Arguments as the AI module decodes them from the tool call's JSON.
    $tool_call = new ToolsFunctionOutput(NULL, 'call_1', [
      'query' => 'Überweisung von ' . self::IBAN,
      'filters' => ['contact' => self::EMAIL, 'limit' => 5],
    ]);
    $tool_call->setName('search_documents');
    $assistant = new ChatMessage('assistant', '');
    $assistant->setTools([$tool_call]);
    $tool_result = new ChatMessage('tool', "Kontoauszug\nIBAN " . self::IBAN . "\nIhre PIN 4711-9823\nGeburtsdatum: 01.02.1980\nVertrag 02-542922-60 vom 31.12.2025");

    $input = new ChatInput([
      new ChatMessage('user', 'Wofür ist ' . self::IBAN . '? Antwort an ' . self::EMAIL),
      $assistant,
      $tool_result,
    ]);
    $input->setSystemPrompt('You help ' . self::EMAIL . '.');

    $event = $this->dispatch($input);
    $sent = $event->getInput();
    [$user, $assistant, $tool_result] = $sent->getMessages();

    $this->assertSame('You help [EMAIL_1].', $sent->getSystemPrompt());
    $this->assertSame('Wofür ist [IBAN_1]? Antwort an [EMAIL_1]', $user->getText());
    $arguments = $assistant->getTools()[0]->getArguments();
    $this->assertSame('Überweisung von [IBAN_1]', $arguments[0]->getValue());
    $this->assertSame(['contact' => '[EMAIL_1]', 'limit' => 5], $arguments[1]->getValue(), 'Nested argument values are sanitized; non-strings are kept.');
    $this->assertSame("Kontoauszug\nIBAN [IBAN_1]\nIhre PIN [SECRET_1]\nGeburtsdatum: [BIRTHDATE_1]\nVertrag 02-542922-60 vom 31.12.2025", $tool_result->getText(), 'Policy numbers and ordinary dates are kept.');

    $all = $sent->getSystemPrompt() . serialize(array_map(fn($m) => $m->getText(), $sent->getMessages()));
    foreach ([self::IBAN, self::EMAIL, '4711-9823', '01.02.1980'] as $value) {
      $this->assertStringNotContainsString($value, $all);
    }

    $this->assertSame(['BIRTHDATE' => 1, 'EMAIL' => 3, 'IBAN' => 3, 'SECRET' => 1], $event->getMetadata('ai_sanitize'));
    $this->assertCount(1, $this->sanitizedEvents);
    $this->assertSame(8, $this->sanitizedEvents[0]->total());
  }

  /**
   * The next loop iteration keeps numbering consistent with the history.
   */
  public function testFollowUpRequestContinuesNumbering(): void {
    $this->dispatch(new ChatInput([new ChatMessage('user', 'Konto ' . self::IBAN)]));
    // A new PHP process starts with a fresh map.
    $this->container->get('ai_sanitize.sanitizer')->resetMap();

    $input = new ChatInput([
      new ChatMessage('user', 'Konto [IBAN_1]'),
      new ChatMessage('tool', 'Anderes Konto ' . self::OTHER_IBAN),
    ]);
    $messages = $this->dispatch($input)->getInput()->getMessages();
    $this->assertSame('Anderes Konto [IBAN_2]', $messages[1]->getText(), 'A different value never reuses [IBAN_1].');
  }

  /**
   * Non-chat inputs (here: embeddings) are sanitized too.
   */
  public function testEmbeddingsInput(): void {
    $input = new EmbeddingsInput('Kunde ' . self::EMAIL);
    $this->assertSame('Kunde [EMAIL_1]', $this->dispatch($input, 'embeddings')->getInput()->getPrompt());
  }

  /**
   * Allowlisted values, bypass tags and operation-type scoping are honoured.
   */
  public function testConfiguration(): void {
    $this->config('ai_sanitize.settings')
      ->set('allowlist', ['service@example.org'])
      ->set('bypass_tags', ['trusted_local_model'])
      ->set('always_redact', ['Kundennummer K-4711'])
      ->save();

    $input = new ChatInput([new ChatMessage('user', 'service@example.org und ' . self::EMAIL . ', Kundennummer K-4711')]);
    $this->assertSame('service@example.org und [EMAIL_1], [REDACTED_1]', $this->dispatch($input)->getInput()->getMessages()[0]->getText());

    $input = new ChatInput([new ChatMessage('user', self::EMAIL)]);
    $this->assertSame(self::EMAIL, $this->dispatch($input, 'chat', ['trusted_local_model' => TRUE])->getInput()->getMessages()[0]->getText());

    $this->config('ai_sanitize.settings')->set('operation_types', ['embeddings'])->set('bypass_tags', [])->save();
    $input = new ChatInput([new ChatMessage('user', self::EMAIL)]);
    $this->assertSame(self::EMAIL, $this->dispatch($input)->getInput()->getMessages()[0]->getText());
  }

}
