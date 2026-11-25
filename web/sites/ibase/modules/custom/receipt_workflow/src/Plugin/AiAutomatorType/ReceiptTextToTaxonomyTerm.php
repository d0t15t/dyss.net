<?php

declare(strict_types=1);

namespace Drupal\receipt_workflow\Plugin\AiAutomatorType;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\Service\AiProviderFormHelper;
use Drupal\ai\Service\PromptJsonDecoder\PromptJsonDecoderInterface;
use Drupal\ai_automators\Attribute\AiAutomatorType;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Extracts a taxonomy term reference from receipt OCR text.
 *
 * Use on entity_reference fields that point to taxonomy terms:
 * field_vendor, field_currency, field_payment_method, field_expense_category.
 *
 * The plugin fetches all terms from the target vocabulary, includes them in the
 * prompt so the LLM can pick the best match, and stores the matched term ID.
 *
 * Example prompt for vendor:
 *   "From the receipt text below, identify the vendor/merchant name.
 *    Return ONLY the exact name as it appears in the provided list, or if the
 *    vendor is not in the list return the raw name from the receipt.
 *
 *    Available vendors:\n[available_terms]\n\n[receipt_text]"
 *
 * Use [available_terms] for the list of existing terms and [receipt_text] for
 * the OCR content.
 */
#[AiAutomatorType(
  id: 'receipt:text_to_taxonomy_term',
  label: new TranslatableMarkup('Receipt: Extract Taxonomy Term'),
  field_rule: 'entity_reference',
  target: 'taxonomy_term',
)]
final class ReceiptTextToTaxonomyTerm extends ReceiptExtractorBase {

  public function __construct(
    AiProviderPluginManager $pluginManager,
    AiProviderFormHelper $formHelper,
    PromptJsonDecoderInterface $promptJsonDecoder,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($pluginManager, $formHelper, $promptJsonDecoder);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $container->get('ai.provider'),
      $container->get('ai.form_helper'),
      $container->get('ai.prompt_json_decode'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function helpText(): string {
    return 'Extracts a taxonomy term (e.g., vendor, currency, payment method, expense category) from OCR text of the linked receipt media. Use [receipt_text] for the OCR content and [available_terms] for the list of existing terms.';
  }

  /**
   * {@inheritdoc}
   */
  public function placeholderText(): string {
    return "From the receipt below, identify the vendor/merchant name.\nReturn ONLY the name — pick from the list if it matches, otherwise return the raw name from the receipt.\n\nAvailable:\n[available_terms]\n\n[receipt_text]";
  }

  /**
   * {@inheritdoc}
   */
  public function generate(ContentEntityInterface $entity, FieldDefinitionInterface $fieldDefinition, array $automatorConfig): array {
    $receiptText = $this->getReceiptText($entity);
    if ($receiptText === NULL) {
      return [];
    }

    $vocabulary = $this->getTargetVocabulary($fieldDefinition);
    $terms = $this->loadTerms($vocabulary);
    $termNames = array_values($terms); // name → tid map inverted
    $termsByName = $terms;             // name → tid

    $availableList = implode("\n", array_keys($termsByName));

    $prompt = $automatorConfig['prompt'] ?? '';
    $prompt = str_replace('[receipt_text]', $receiptText, $prompt);
    $prompt = str_replace('[available_terms]', $availableList, $prompt);

    if (empty(trim($prompt))) {
      return [];
    }

    $instance = $this->prepareLlmInstance('chat', $automatorConfig);
    $raw = trim($this->runRawChatMessage($prompt, $automatorConfig, $instance, $entity)->getText());

    if ($raw === '' || strtolower($raw) === 'null' || strtolower($raw) === 'n/a') {
      return [];
    }

    // Try exact match first.
    if (isset($termsByName[$raw])) {
      return [['target_id' => $termsByName[$raw]]];
    }

    // Case-insensitive fallback.
    $rawLower = strtolower($raw);
    foreach ($termsByName as $name => $tid) {
      if (strtolower($name) === $rawLower) {
        return [['target_id' => $tid]];
      }
    }

    // No match — optionally create a new term if the vocabulary allows.
    $tid = $this->createTermIfAllowed($raw, $vocabulary);
    if ($tid) {
      return [['target_id' => $tid]];
    }

    return [];
  }

  /**
   * {@inheritdoc}
   */
  protected function parseValue(string $raw, ContentEntityInterface $entity, FieldDefinitionInterface $fieldDefinition, array $automatorConfig): mixed {
    // Handled entirely in generate().
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function verifyValue(ContentEntityInterface $entity, $value, FieldDefinitionInterface $fieldDefinition, array $automatorConfig): bool {
    return is_array($value) && !empty($value['target_id']);
  }

  /**
   * {@inheritdoc}
   */
  public function storeValues(ContentEntityInterface $entity, array $values, FieldDefinitionInterface $fieldDefinition, array $automatorConfig): bool {
    $entity->set($fieldDefinition->getName(), $values);
    return TRUE;
  }

  // ── Helpers ────────────────────────────────────────────────────────────────

  /**
   * Returns all terms for a vocabulary as [ term_name => term_id ].
   */
  private function loadTerms(string $vocabulary): array {
    if (empty($vocabulary)) {
      return [];
    }
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $tids = $storage->getQuery()
      ->condition('vid', $vocabulary)
      ->accessCheck(FALSE)
      ->execute();

    $map = [];
    foreach ($storage->loadMultiple($tids) as $term) {
      $map[$term->getName()] = (int) $term->id();
    }
    return $map;
  }

  /**
   * Returns the target vocabulary ID from the field definition.
   */
  private function getTargetVocabulary(FieldDefinitionInterface $fieldDefinition): string {
    $settings = $fieldDefinition->getSetting('handler_settings') ?? [];
    $bundles = $settings['target_bundles'] ?? [];
    return (string) (array_key_first($bundles) ?? '');
  }

  /**
   * Creates a new taxonomy term if the vocabulary machine name ends in a
   * "soft" list (vendor) — returns the new term ID, or NULL otherwise.
   */
  private function createTermIfAllowed(string $name, string $vocabulary): ?int {
    // Only auto-create terms for open-ended vocabularies.
    $openVocabs = ['vendor'];
    if (!in_array($vocabulary, $openVocabs, TRUE)) {
      return NULL;
    }

    $term = $this->entityTypeManager->getStorage('taxonomy_term')->create([
      'name' => $name,
      'vid'  => $vocabulary,
    ]);
    $term->save();
    return (int) $term->id();
  }

}
