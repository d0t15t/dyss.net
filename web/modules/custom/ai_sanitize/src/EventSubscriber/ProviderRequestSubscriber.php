<?php

namespace Drupal\ai_sanitize\EventSubscriber;

use Drupal\ai\Event\PreGenerateResponseEvent;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai_sanitize\Event\TextSanitizedEvent;
use Drupal\ai_sanitize\Sanitizer;
use Drupal\Core\Config\ConfigFactoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Sanitizes every request the AI module sends to a provider.
 *
 * Runs on PreGenerateResponseEvent, which the AI module dispatches before any
 * provider call. For chat that covers the system prompt, every message
 * (including tool results, which is where document content enters agent
 * loops) and the arguments of tool calls; for other operation types the text
 * or prompt. Messages are changed in place, so conversation history and
 * loggers that read the same objects later (e.g. ai_chatlog) keep the
 * sanitized version too.
 */
class ProviderRequestSubscriber implements EventSubscriberInterface {

  public function __construct(
    protected readonly Sanitizer $sanitizer,
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly EventDispatcherInterface $eventDispatcher,
    protected readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Before the AI module's guardrails (priority 0), so guardrails,
    // loggers and the provider all see the sanitized request.
    return [PreGenerateResponseEvent::EVENT_NAME => ['onPreGenerate', 100]];
  }

  /**
   * Sanitizes the request input.
   */
  public function onPreGenerate(PreGenerateResponseEvent $event): void {
    $config = $this->configFactory->get('ai_sanitize.settings');
    $operation_types = $config->get('operation_types') ?? [];
    if ($operation_types && !in_array($event->getOperationType(), $operation_types, TRUE)) {
      return;
    }
    $tags = $event->getTags();
    if (array_intersect($config->get('bypass_tags') ?? [], array_merge(array_keys($tags), array_filter($tags, 'is_string')))) {
      return;
    }

    $input = $event->getInput();
    if (!is_object($input)) {
      return;
    }
    $counts = [];
    $sanitize = function (string $text) use (&$counts): string {
      $result = $this->sanitizer->sanitize($text);
      foreach ($result->counts as $type => $n) {
        $counts[$type] = ($counts[$type] ?? 0) + $n;
      }
      return $result->text;
    };

    $this->reserveExistingPlaceholders($input);

    if ($input instanceof ChatInput) {
      $input->setSystemPrompt($sanitize($input->getSystemPrompt()));
      $messages = $input->getMessages();
      foreach ($messages as $message) {
        if ($message instanceof ChatMessage) {
          $this->sanitizeMessage($message, $sanitize);
        }
      }
      $input->setMessages($messages);
    }
    elseif (method_exists($input, 'getText') && method_exists($input, 'setText')) {
      $input->setText($sanitize((string) $input->getText()));
    }
    elseif (method_exists($input, 'getPrompt') && method_exists($input, 'setPrompt')) {
      $input->setPrompt($sanitize((string) $input->getPrompt()));
    }

    if (!$counts) {
      return;
    }
    ksort($counts);
    $event->setInput($input);
    $event->setMetadata('ai_sanitize', $counts);
    $this->eventDispatcher->dispatch(new TextSanitizedEvent($counts, $event->getOperationType(), $event->getProviderId(), $event->getRequestThreadId(), $tags), TextSanitizedEvent::EVENT_NAME);
    if ($config->get('log_counts')) {
      $this->logger->notice('Replaced @summary in a @operation request to @provider.', [
        '@summary' => implode(', ', array_map(fn($type, $n) => "$n × $type", array_keys($counts), $counts)),
        '@operation' => $event->getOperationType(),
        '@provider' => $event->getProviderId(),
      ]);
    }
  }

  /**
   * Sanitizes a chat message's text and the string arguments of its tool calls.
   */
  protected function sanitizeMessage(ChatMessage $message, callable $sanitize): void {
    $message->setText($sanitize($message->getText()));
    foreach ($message->getTools() ?? [] as $tool) {
      if (!method_exists($tool, 'getArguments')) {
        continue;
      }
      foreach ($tool->getArguments() as $argument) {
        if (method_exists($argument, 'getValue')) {
          $argument->setValue($this->sanitizeValue($argument->getValue(), $sanitize));
        }
      }
    }
  }

  /**
   * Sanitizes a tool argument value: strings, and strings nested in arrays.
   */
  protected function sanitizeValue(mixed $value, callable $sanitize): mixed {
    if (is_string($value)) {
      return $sanitize($value);
    }
    if (is_array($value)) {
      return array_map(fn($item) => $this->sanitizeValue($item, $sanitize), $value);
    }
    return $value;
  }

  /**
   * Continues placeholder numbering after placeholders already in the input.
   */
  protected function reserveExistingPlaceholders(object $input): void {
    $map = $this->sanitizer->map();
    if ($input instanceof ChatInput) {
      $map->reserveExisting($input->getSystemPrompt());
      foreach ($input->getMessages() as $message) {
        if ($message instanceof ChatMessage) {
          $map->reserveExisting($message->getText());
        }
      }
    }
    elseif (method_exists($input, 'getText')) {
      $map->reserveExisting((string) $input->getText());
    }
    elseif (method_exists($input, 'getPrompt')) {
      $map->reserveExisting((string) $input->getPrompt());
    }
  }

}
