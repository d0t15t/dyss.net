<?php

declare(strict_types=1);

namespace Drupal\receipt_vision_verify\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for the Receipt Vision Verify module.
 */
class ReceiptVisionVerifyCommands extends DrushCommands {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct();
  }

  /**
   * Re-run vision extraction on receipt nodes.
   *
   * Clears field_vision_extraction, field_extraction_confidence, and
   * field_extraction_discrepancies on each matching node and saves it. The
   * AI automator re-fires on save and re-populates these fields with the
   * current vision prompt configuration.
   */
  #[CLI\Command(name: 'receipt-vision-verify:rerun-all', aliases: ['rvv:rerun'])]
  #[CLI\Option(name: 'status', description: 'Only re-run nodes in this workflow state (e.g. receipt_needs_review).')]
  #[CLI\Option(name: 'limit', description: 'Maximum number of nodes to re-run (0 = no limit).')]
  #[CLI\Option(name: 'dry-run', description: 'Print what would be re-run without saving.')]
  #[CLI\Usage(name: 'drush rvv:rerun', description: 'Re-run vision on all receipt nodes.')]
  #[CLI\Usage(name: 'drush rvv:rerun --status=receipt_needs_review', description: 'Re-run only on receipts currently flagged for review.')]
  #[CLI\Usage(name: 'drush rvv:rerun --limit=10 --dry-run', description: 'Show the first 10 nodes that would be re-run.')]
  public function rerunAll(array $options = ['status' => '', 'limit' => 0, 'dry-run' => FALSE]): void {
    $nodeStorage = $this->entityTypeManager->getStorage('node');

    $query = $nodeStorage->getQuery()
      ->condition('type', 'receipt')
      ->accessCheck(FALSE);

    if (!empty($options['status'])) {
      $query->condition('field_receipt_status', $options['status']);
    }
    if ((int) $options['limit'] > 0) {
      $query->range(0, (int) $options['limit']);
    }

    $nids = $query->execute();
    $count = count($nids);

    if ($count === 0) {
      $this->logger()->notice('No matching receipt nodes found.');
      return;
    }

    $this->logger()->notice(dt('Found @count receipt node(s).', ['@count' => $count]));

    if ($options['dry-run']) {
      foreach ($nodeStorage->loadMultiple($nids) as $node) {
        $this->output()->writeln(sprintf(
          '  [dry-run] node %d — "%s" (%s)',
          $node->id(),
          $node->label(),
          (string) ($node->get('field_receipt_status')->value ?? ''),
        ));
      }
      return;
    }

    $processed = 0;
    $failed = 0;
    foreach ($nodeStorage->loadMultiple($nids) as $node) {
      try {
        $node->set('field_vision_extraction', NULL);
        $node->set('field_extraction_confidence', NULL);
        $node->set('field_extraction_discrepancies', NULL);
        $node->save();
        $processed++;
        $this->output()->writeln(sprintf('  ✓ node %d re-saved', $node->id()));
      }
      catch (\Exception $e) {
        $failed++;
        $this->logger()->error(dt('node @nid failed: @msg', [
          '@nid' => $node->id(),
          '@msg' => $e->getMessage(),
        ]));
      }
    }

    $this->logger()->success(dt('Re-ran @done node(s); @fail failed.', [
      '@done' => $processed,
      '@fail' => $failed,
    ]));
  }

}
