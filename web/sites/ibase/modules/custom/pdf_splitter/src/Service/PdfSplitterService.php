<?php

namespace Drupal\pdf_splitter\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Utility\Token;
use Drupal\file\Entity\File;
use Drupal\node\NodeInterface;
use setasign\Fpdi\Fpdi;

/**
 * Splits a multi-page PDF into per-page file entities.
 */
class PdfSplitterService {
  use StringTranslationTrait;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected FileSystemInterface $fileSystem,
    protected LoggerChannelFactoryInterface $loggerFactory,
    protected MessengerInterface $messenger,
    protected Token $token,
  ) {}

  /**
   * Splits the PDF from field_receipts_multi_file into per-page files on field_receipts.
   *
   * @param \Drupal\node\NodeInterface $node
   *   A receipts_set node with a PDF on field_receipts_multi_file.
   */
  public function splitPdfToFiles(NodeInterface $node): void {
    if ($node->get('field_receipts_multi_file')->isEmpty()) {
      $this->messenger->addError($this->t('No file is attached to the multi-file field.'));
      return;
    }

    /** @var \Drupal\file\FileInterface $file */
    $file = $node->get('field_receipts_multi_file')->entity;
    if (!$file) {
      $this->messenger->addError($this->t('Could not load the attached file.'));
      return;
    }

    $realPath = $this->fileSystem->realpath($file->getFileUri());
    if (!$realPath || !file_exists($realPath)) {
      $this->messenger->addError($this->t('Could not locate the source file on disk.'));
      return;
    }

    if (strtolower(pathinfo($realPath, PATHINFO_EXTENSION)) !== 'pdf') {
      $this->messenger->addError($this->t('The attached file is not a PDF.'));
      return;
    }

    // Determine destination directory from the receipt node's field_receipt settings.
    $receiptFieldDefs = $this->entityTypeManager->getStorage('field_config')
      ->load('node.receipt.field_receipt');
    $fileDirectory = $receiptFieldDefs ? ($receiptFieldDefs->getSetting('file_directory') ?? '') : '';
    $fileDirectory = $this->token->replace($fileDirectory, [], ['clear' => TRUE]);
    $fileDirectory = trim($fileDirectory, '/');
    $destDir = 'public://' . ($fileDirectory ? $fileDirectory . '/' : '');
    $this->fileSystem->prepareDirectory(
      $destDir,
      FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS,
    );

    // Count pages.
    $counter = new Fpdi();
    try {
      $pageCount = $counter->setSourceFile($realPath);
    }
    catch (\Exception $e) {
      $this->messenger->addError($this->t(
        'Could not read the PDF (@msg). If the PDF uses cross-reference streams, try linearising it first with Ghostscript.',
        ['@msg' => $e->getMessage()],
      ));
      $this->loggerFactory->get('pdf_splitter')->error('FPDI error: @msg', ['@msg' => $e->getMessage()]);
      return;
    }

    // Extract pages. Disable AI automators during node creation — we'll
    // re-trigger them in a progress-showing batch once all nodes exist.
    $originalName = pathinfo($file->getFilename(), PATHINFO_FILENAME);
    $createdNodeIds = [];

    ai_automators_entity_can_save_toggle(FALSE);
    for ($pageNum = 1; $pageNum <= $pageCount; $pageNum++) {
      $pagePdf = new Fpdi();
      $pagePdf->setSourceFile($realPath);
      $templateId = $pagePdf->importPage($pageNum);
      $size = $pagePdf->getTemplateSize($templateId);

      $orientation = $size['orientation'] ?? (($size['width'] > $size['height']) ? 'L' : 'P');
      $pagePdf->AddPage($orientation, [$size['width'], $size['height']]);
      $pagePdf->useTemplate($templateId);

      $tempPath = sys_get_temp_dir() . '/pdf_split_' . $node->id() . '_p' . $pageNum . '_' . uniqid() . '.pdf';
      $pagePdf->Output($tempPath, 'F');

      $newFilename = $originalName . '_page_' . $pageNum . '.pdf';
      $destination = $destDir . $newFilename;

      try {
        $newUri = $this->fileSystem->copy($tempPath, $destination, FileExists::Rename);
      }
      catch (\Exception $e) {
        $this->loggerFactory->get('pdf_splitter')->error(
          'Failed to copy page @page: @msg',
          ['@page' => $pageNum, '@msg' => $e->getMessage()],
        );
        @unlink($tempPath);
        continue;
      }
      @unlink($tempPath);

      $newFile = File::create([
        'uri'      => $newUri,
        'filename' => $newFilename,
        'status'   => 1,
      ]);
      $newFile->save();

      // Create a receipt node with the split page file.
      $nodeStorage = $this->entityTypeManager->getStorage('node');
      $receiptNode = $nodeStorage->create([
        'type'                 => 'receipt',
        'title'                => $node->getTitle() . ' - Page ' . $pageNum,
        'field_receipt'        => ['target_id' => $newFile->id()],
        'field_receipt_status' => 'receipt_new',
        'uid'                  => $node->getOwnerId(),
      ]);
      $receiptNode->save();

      $createdNodeIds[] = $receiptNode->id();
    }
    ai_automators_entity_can_save_toggle(TRUE);

    if (empty($createdNodeIds)) {
      $this->messenger->addError($this->t('No pages were successfully extracted from the PDF.'));
      return;
    }

    // Reference the receipt nodes in order on field_receipts.
    foreach ($createdNodeIds as $nid) {
      $node->get('field_receipts')->appendItem(['target_id' => $nid]);
    }
    $node->save();

    $this->messenger->addStatus($this->t(
      'PDF split into @count page(s); now running AI extraction…',
      ['@count' => count($createdNodeIds)],
    ));

    // Batch-process each receipt node so automators run with a progress bar
    // and don't timeout on large PDFs.
    $operations = [];
    foreach ($createdNodeIds as $nid) {
      $operations[] = [[self::class, 'batchProcessReceipt'], [$nid]];
    }

    batch_set([
      'title' => $this->t('Running AI extraction on @count receipt(s)', ['@count' => count($createdNodeIds)]),
      'operations' => $operations,
      'finished' => [self::class, 'batchFinished'],
      'init_message' => $this->t('Preparing AI extraction…'),
      'progress_message' => $this->t('Processing receipt @current of @total…'),
      'error_message' => $this->t('An error occurred during AI extraction.'),
    ]);
  }

  /**
   * Batch callback — process AI automators for a single receipt node.
   */
  public static function batchProcessReceipt(int $nid, array &$context): void {
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    /** @var \Drupal\node\NodeInterface|null $node */
    $node = $storage->load($nid);
    if (!$node) {
      return;
    }

    try {
      // Saving triggers direct-mode AI automators (OCR + extraction rules)
      // and cascades workflow transitions through the subscriber.
      $node->save();
      $context['results'][] = $nid;
      $context['message'] = (string) t('Processed receipt @title', [
        '@title' => $node->label(),
      ]);
    }
    catch (\Exception $e) {
      \Drupal::logger('pdf_splitter')->error(
        'AI extraction failed for receipt node @nid: @msg',
        ['@nid' => $nid, '@msg' => $e->getMessage()],
      );
      $context['message'] = (string) t('Failed on receipt #@nid: @msg', [
        '@nid' => $nid,
        '@msg' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Batch finished callback.
   */
  public static function batchFinished(bool $success, array $results, array $operations): void {
    if ($success) {
      \Drupal::messenger()->addStatus(t('AI extraction completed for @count receipt(s).', [
        '@count' => count($results),
      ]));
    }
    else {
      \Drupal::messenger()->addError(t('AI extraction finished with errors — check the log.'));
    }
  }

}
