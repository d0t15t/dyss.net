<?php

namespace Drupal\insurance_records_ai\Plugin\AiFunctionCall;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\ai\Service\FunctionCalling\FunctionCallInterface;
use Drupal\ai_agents\PluginInterfaces\AiAgentContextInterface;
use Drupal\media\MediaInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Searches the insurance documents in the Solr index.
 */
#[FunctionCall(
  id: 'insurance_records:search_documents',
  function_name: 'insurance_search_documents',
  name: 'Search insurance documents',
  description: 'Full-text search (Solr) over the scanned insurance documents: titles, OCR text (mostly German) and the linked policy (title, number, insurer, insured persons). Optional filters narrow by tax year, insured person, document type or insurer. Returns matching documents with id, date, type, policy, stated values, links and a highlighted text excerpt. Use insurance_read_document with an id to read a full document.',
  group: 'information_tools',
  context_definitions: [
    'query' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Search terms'),
      description: new TranslatableMarkup('Keywords to search for. German terms work best for document content (e.g. "Rückkaufswert", "Beitragsrechnung", "Steuerbescheinigung"); policy numbers and insurer names also work. Leave empty to list documents by the filters only.'),
      required: FALSE,
    ),
    'tax_year' => new ContextDefinition(
      data_type: 'integer',
      label: new TranslatableMarkup('Tax year'),
      description: new TranslatableMarkup('Only documents needed for this tax year (e.g. 2025).'),
      required: FALSE,
    ),
    'insured_person' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Insured person'),
      description: new TranslatableMarkup('Exact name of the insured person: "Isaac Trogdon", "Isabella Scott" or "Rüdiger Trogdon".'),
      required: FALSE,
    ),
    'document_type' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Document type'),
      description: new TranslatableMarkup('Only documents of this type.'),
      required: FALSE,
      constraints: [
        'AllowedValues' => [
          'annual_statement',
          'pension_information',
          'invoice',
          'policy_document',
          'cancellation',
          'tax_certificate',
          'payment_notice',
          'sustainability',
          'correspondence',
        ],
      ],
    ),
    'insurer' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Insurer'),
      description: new TranslatableMarkup('Exact insurer name as stored, e.g. "Gothaer", "AXA", "Allianz", "ÖRAG", "Münchener Verein", "HUK-COBURG", "Feuersozietät", "Feuersozietät / Bayern-Versicherung", "Deutsche Rentenversicherung Bund".'),
      required: FALSE,
    ),
    'limit' => new ContextDefinition(
      data_type: 'integer',
      label: new TranslatableMarkup('Maximum results'),
      description: new TranslatableMarkup('How many documents to return (1-20, default 8).'),
      required: FALSE,
    ),
  ],
)]
class SearchDocuments extends FunctionCallBase implements ExecutableFunctionCallInterface, AiAgentContextInterface {

  protected EntityTypeManagerInterface $entityTypeManager;

  protected AccountProxyInterface $currentUser;

  protected FileUrlGeneratorInterface $fileUrlGenerator;

  /**
   * The readable output for the agent.
   */
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
    $instance->fileUrlGenerator = $container->get('file_url_generator');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function execute(?object $object = NULL) {
    if (!$this->currentUser->hasPermission('view insurance records')) {
      throw new \Exception('You do not have permission to search insurance records.');
    }
    $index = $this->entityTypeManager->getStorage('search_api_index')->load('insurance_documents');
    if (!$index) {
      throw new \Exception('The insurance_documents search index does not exist.');
    }

    $keys = trim((string) $this->getContextValue('query'));
    $limit = max(1, min(20, (int) ($this->getContextValue('limit') ?: 8)));
    $query = $index->query()->range(0, $limit);
    if ($keys !== '') {
      $query->keys($keys);
      $query->sort('search_api_relevance', 'DESC');
    }
    $query->sort('field_document_date', 'DESC');
    $filters = [
      'field_tax_years' => $this->getContextValue('tax_year'),
      'insured' => $this->getContextValue('insured_person'),
      'field_document_type' => $this->getContextValue('document_type'),
      'insurer' => $this->getContextValue('insurer'),
    ];
    foreach (array_filter($filters, fn($v) => $v !== NULL && $v !== '') as $field => $value) {
      $query->addCondition($field, $value);
    }
    $results = $query->execute();

    $lines = [sprintf('%d document(s) found, showing %d.', $results->getResultCount(), count($results->getResultItems()))];
    foreach ($results->getResultItems() as $item) {
      $media = $item->getOriginalObject()?->getValue();
      if (!$media instanceof MediaInterface || !$media->access('view', $this->currentUser)) {
        continue;
      }
      $lines[] = '';
      $lines[] = self::describe($media, $this->fileUrlGenerator);
      if ($excerpt = $item->getExcerpt()) {
        $lines[] = 'Excerpt: ' . trim(strip_tags(str_replace(['<strong>', '</strong>'], ['**', '**'], $excerpt)));
      }
    }
    $this->output = implode("\n", $lines);
  }

  /**
   * One-block description of an insurance document for the agent.
   */
  public static function describe(MediaInterface $media, FileUrlGeneratorInterface $file_url_generator): string {
    $policy = $media->get('field_policy')->entity;
    $type = $media->get('field_document_type');
    $type_label = $type->isEmpty() ? '' : ($type->getFieldDefinition()->getSetting('allowed_values')[$type->value] ?? $type->value);
    $parts = [
      sprintf('[Document %d] %s (%s, %s)', $media->id(), $media->label(), $media->get('field_document_date')->value, $type_label),
    ];
    if ($policy) {
      $parts[] = sprintf('Policy: %s, no. %s, insurer %s; link: %s',
        $policy->label(),
        $policy->get('field_policy_number')->value,
        $policy->get('field_insurer')->entity?->label(),
        $policy->toUrl()->toString());
    }
    if (!$media->get('field_reported_value')->isEmpty()) {
      $parts[] = sprintf('Stated value: %s EUR as of %s', $media->get('field_reported_value')->value, $media->get('field_value_date')->value);
    }
    if (!$media->get('field_reported_premium')->isEmpty()) {
      $parts[] = sprintf('Stated premium: %s EUR', $media->get('field_reported_premium')->value);
    }
    if (!$media->get('field_tax_years')->isEmpty()) {
      $parts[] = 'Needed for tax years: ' . implode(', ', array_column($media->get('field_tax_years')->getValue(), 'value'));
    }
    if ($note = $media->get('field_scan_note')->value) {
      $parts[] = 'Scan note: ' . $note;
    }
    if ($file = $media->get('field_insurance_file')->entity) {
      $parts[] = 'PDF: ' . $file_url_generator->generateString($file->getFileUri());
    }
    return implode("\n", $parts);
  }

  /**
   * {@inheritdoc}
   */
  public function getReadableOutput(): string {
    return $this->output;
  }

}
