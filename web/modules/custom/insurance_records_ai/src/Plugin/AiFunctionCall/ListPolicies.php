<?php

namespace Drupal\insurance_records_ai\Plugin\AiFunctionCall;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\ai\Service\FunctionCalling\FunctionCallInterface;
use Drupal\ai_agents\PluginInterfaces\AiAgentContextInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lists all insurance policies with their key facts.
 */
#[FunctionCall(
  id: 'insurance_records:list_policies',
  function_name: 'insurance_list_policies',
  name: 'List insurance policies',
  description: 'Lists every insurance policy with insurer, number, category, status, policyholder, insured persons, who pays, current premium, key dates and open action items. Use it for overview questions (totals, what is active, who is covered).',
  group: 'information_tools',
)]
class ListPolicies extends FunctionCallBase implements ExecutableFunctionCallInterface, AiAgentContextInterface {

  protected EntityTypeManagerInterface $entityTypeManager;

  protected AccountProxyInterface $currentUser;

  protected string $output = '';

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): FunctionCallInterface|static {
    $instance = new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('ai.context_definition_normalizer'),
    );
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->currentUser = $container->get('current_user');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function execute(?object $object = NULL) {
    if (!$this->currentUser->hasPermission('view insurance records')) {
      throw new \Exception('You do not have permission to list insurance records.');
    }
    $storage = $this->entityTypeManager->getStorage('node');
    $ids = $storage->getQuery()->accessCheck(TRUE)->condition('type', 'insurance_policy')->sort('title')->execute();
    $label = fn($field) => implode(', ', array_map(fn($t) => $t->label(), $field->referencedEntities()));
    $list = fn($field) => $field->isEmpty() ? '' : ($field->getFieldDefinition()->getSetting('allowed_values')[$field->value] ?? $field->value);
    $lines = [];
    foreach ($storage->loadMultiple($ids) as $p) {
      $premium = $p->get('field_premium_amount')->isEmpty()
        ? 'unknown/none'
        : $p->get('field_premium_amount')->value . ' EUR ' . $list($p->get('field_premium_interval'));
      $lines[] = implode("\n", array_filter([
        sprintf('- %s (link: %s)', $p->label(), $p->toUrl()->toString()),
        sprintf('  Insurer: %s; number: %s; category: %s; status: %s', $label($p->get('field_insurer')), $p->get('field_policy_number')->value, $label($p->get('field_insurance_category')), $list($p->get('field_policy_status'))),
        sprintf('  Policyholder: %s; insured: %s; paid by: %s; premium: %s', $label($p->get('field_policyholder')), $label($p->get('field_insured_persons')), $label($p->get('field_premium_payer')), $premium),
        sprintf('  Start: %s; end: %s; benefit/pension start: %s', $p->get('field_policy_start')->value ?: '-', $p->get('field_policy_end')->value ?: '-', $p->get('field_benefit_start')->value ?: '-'),
        $p->get('field_open_points')->value ? '  Action items: ' . trim(preg_replace('/\s+/', ' ', strip_tags(str_replace('</li>', '; ', $p->get('field_open_points')->value)))) : '',
      ]));
    }
    $this->output = count($lines) . " policies:\n" . implode("\n", $lines);
  }

  /**
   * {@inheritdoc}
   */
  public function getReadableOutput(): string {
    return $this->output;
  }

}
