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
 * Reads the OCR text of one insurance document.
 */
#[FunctionCall(
  id: 'insurance_records:read_document',
  function_name: 'insurance_read_document',
  name: 'Read insurance document',
  description: 'Returns the details and full OCR text (mostly German, may contain OCR errors) of one insurance document, by the document id from insurance_search_documents.',
  group: 'information_tools',
  context_definitions: [
    'document_id' => new ContextDefinition(
      data_type: 'integer',
      label: new TranslatableMarkup('Document id'),
      description: new TranslatableMarkup('The number shown as [Document N] in the search results.'),
      required: TRUE,
    ),
  ],
)]
class ReadDocument extends FunctionCallBase implements ExecutableFunctionCallInterface, AiAgentContextInterface {

  /**
   * OCR text beyond this length is cut off to keep the context small.
   */
  const MAX_CHARS = 12000;

  protected EntityTypeManagerInterface $entityTypeManager;

  protected AccountProxyInterface $currentUser;

  protected FileUrlGeneratorInterface $fileUrlGenerator;

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
      throw new \Exception('You do not have permission to read insurance records.');
    }
    $media = $this->entityTypeManager->getStorage('media')->load((int) $this->getContextValue('document_id'));
    if (!$media instanceof MediaInterface || $media->bundle() !== 'insurance_document' || !$media->access('view', $this->currentUser)) {
      $this->output = 'No insurance document with that id.';
      return;
    }
    $text = (string) $media->get('field_ocr_text')->value;
    if (mb_strlen($text) > self::MAX_CHARS) {
      $text = mb_substr($text, 0, self::MAX_CHARS) . "\n[… text cut off]";
    }
    $this->output = SearchDocuments::describe($media, $this->fileUrlGenerator) . "\n\nOCR text:\n" . $text;
  }

  /**
   * {@inheritdoc}
   */
  public function getReadableOutput(): string {
    return $this->output;
  }

}
