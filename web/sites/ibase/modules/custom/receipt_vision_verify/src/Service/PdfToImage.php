<?php

declare(strict_types=1);

namespace Drupal\receipt_vision_verify\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\file\Entity\File;
use Drupal\file\FileInterface;

/**
 * Renders a PDF page as a PNG image for vision AI consumption.
 *
 * Uses Ghostscript (`gs`) — must be installed at the OS level.
 * Images are cached as managed File entities under private://receipt_vision_cache/
 * and keyed by source file URI + mtime + DPI so the same input produces a
 * single cached render. Private, as the renders show the receipts' contents.
 */
class PdfToImage {

  protected const CACHE_DIR = 'private://receipt_vision_cache/';

  public function __construct(
    protected FileSystemInterface $fileSystem,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ConfigFactoryInterface $configFactory,
    protected LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Render page 1 of a PDF as a PNG.
   *
   * @return \Drupal\file\FileInterface|null
   *   The rendered managed File entity, or NULL on failure.
   */
  public function renderPdf(FileInterface $pdfFile, ?int $dpi = NULL): ?FileInterface {
    $dpi ??= (int) $this->configFactory->get('receipt_vision_verify.settings')->get('image_dpi') ?: 200;

    $sourceUri = $pdfFile->getFileUri();
    $sourcePath = $this->fileSystem->realpath($sourceUri);
    if (!$sourcePath || !file_exists($sourcePath)) {
      $this->loggerFactory->get('receipt_vision_verify')->error(
        'PDF file not on disk: @uri', ['@uri' => $sourceUri]
      );
      return NULL;
    }

    // Cache key based on source file ID + mtime + dpi.
    $cacheKey = $pdfFile->id() . '_' . filemtime($sourcePath) . '_' . $dpi;
    $destFilename = 'receipt_' . $cacheKey . '.png';
    $destUri = self::CACHE_DIR . $destFilename;

    // Return cached file if it exists.
    $existing = $this->entityTypeManager->getStorage('file')->loadByProperties(['uri' => $destUri]);
    if ($existing) {
      return reset($existing);
    }

    // Prepare cache directory.
    $destDir = self::CACHE_DIR;
    $this->fileSystem->prepareDirectory(
      $destDir,
      FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS,
    );

    $destPath = $this->fileSystem->realpath($destUri) ?: $this->fileSystem->realpath($destDir) . '/' . $destFilename;

    // Render first page with Ghostscript.
    $cmd = sprintf(
      'gs -dBATCH -dNOPAUSE -dSAFER -sDEVICE=png16m -r%d -dFirstPage=1 -dLastPage=1 -sOutputFile=%s %s 2>&1',
      $dpi,
      escapeshellarg($destPath),
      escapeshellarg($sourcePath),
    );

    $output = [];
    $exitCode = 0;
    exec($cmd, $output, $exitCode);

    if ($exitCode !== 0 || !file_exists($destPath)) {
      $this->loggerFactory->get('receipt_vision_verify')->error(
        'Ghostscript failed (exit @code): @out',
        ['@code' => $exitCode, '@out' => implode("\n", $output)],
      );
      return NULL;
    }

    // Create managed file.
    $file = File::create([
      'uri' => $destUri,
      'filename' => $destFilename,
      'filemime' => 'image/png',
      'status' => 1,
    ]);
    $file->save();

    return $file;
  }

  /**
   * Delete cache files associated with a receipt node.
   *
   * Called from hook_entity_delete. We don't have a direct cache→node mapping,
   * but the cache key embeds the PDF file ID, so we clean up by iterating
   * files in the cache directory and removing ones that reference files no
   * longer owned by any node.
   */
  public function deleteCacheForNode(int $nodeId): void {
    // Soft cleanup: load the node's file reference if still available.
    $node = $this->entityTypeManager->getStorage('node')->load($nodeId);
    if (!$node || !$node->hasField('field_receipt') || $node->get('field_receipt')->isEmpty()) {
      return;
    }
    $pdfFid = $node->get('field_receipt')->target_id;
    if (!$pdfFid) {
      return;
    }

    // Find any cached PNGs whose filename starts with receipt_{fid}_.
    $storage = $this->entityTypeManager->getStorage('file');
    $fids = $storage->getQuery()
      ->condition('filename', 'receipt_' . $pdfFid . '_%', 'LIKE')
      ->accessCheck(FALSE)
      ->execute();

    foreach ($storage->loadMultiple($fids) as $file) {
      $file->delete();
    }
  }

}
