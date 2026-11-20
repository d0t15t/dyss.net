<?php

declare(strict_types=1);

namespace Drupal\receipt_vision_verify\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\node\NodeInterface;
use Drupal\receipt_vision_verify\Service\VisionComparator;
use Drupal\workflow\Entity\WorkflowTransition;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Runs comparison + routes receipt_verified transitions after vision extraction.
 *
 * Must run BEFORE receipt_workflow's subscriber so we intercept the
 * text_extracted → needs_review transition and route through verified instead.
 * Ordering is enforced via hook_module_implements_alter() in .module.
 */
class VisionVerifySubscriber implements EventSubscriberInterface {

  /**
   * Guard against re-entrant saves.
   */
  private static array $processing = [];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly VisionComparator $comparator,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  public static function getSubscribedEvents(): array {
    return [];
  }

  public function onEntityInsert(object $entity): void {
    if ($entity->getEntityTypeId() === 'node' && $entity->bundle() === 'receipt') {
      $this->handleReceiptNode($entity);
    }
  }

  public function onEntityUpdate(object $entity, ?object $original = NULL): void {
    if ($entity->getEntityTypeId() === 'node' && $entity->bundle() === 'receipt') {
      $this->handleReceiptNode($entity);
    }
  }

  /**
   * Core logic: run comparison if vision data is populated but comparator
   * hasn't run yet, then route workflow state based on confidence.
   */
  private function handleReceiptNode(NodeInterface $node): void {
    $nid = $node->id();
    if (isset(self::$processing[$nid])) {
      return;
    }

    $settings = $this->configFactory->get('receipt_vision_verify.settings');
    if (!$settings->get('enabled')) {
      return;
    }

    // Need vision JSON to compare against.
    if (!$node->hasField('field_vision_extraction') || $node->get('field_vision_extraction')->isEmpty()) {
      return;
    }

    // Skip if the comparator has already run (confidence populated).
    if ($node->hasField('field_extraction_confidence') && !$node->get('field_extraction_confidence')->isEmpty()) {
      return;
    }

    // Only interfere in the AI-pipeline states. Don't disturb manual states
    // like approved or archived.
    $currentState = $this->getCurrentState($node);
    $autoStates = [
      'receipt_new',
      'receipt_file_attached',
      'receipt_text_extracted',
      'receipt_verified',
      'receipt_needs_review',
    ];
    if (!in_array($currentState, $autoStates, TRUE)) {
      return;
    }

    self::$processing[$nid] = TRUE;
    try {
      $ran = $this->comparator->compare($node);
      if (!$ran) {
        return;
      }

      $confidence = (int) ($node->get('field_extraction_confidence')->value ?? 0);
      $threshold = (int) $settings->get('auto_approve_threshold');

      // Persist the comparator's writes (confidence + discrepancies).
      $node->save();

      // Route to verified, then to approved or needs_review.
      // Skip transition if we're already past verified (e.g. needs_review
      // reached first — we still want to re-route based on new confidence).
      $target = $confidence >= $threshold ? 'receipt_approved' : 'receipt_needs_review';

      // Walk through the verified state so the history reflects the pass.
      if ($currentState !== 'receipt_verified' && $currentState !== $target) {
        $this->executeTransition($node, 'receipt_verified');
      }
      if ($this->getCurrentState($node) !== $target) {
        $this->executeTransition($node, $target);
      }
    }
    finally {
      unset(self::$processing[$nid]);
    }
  }

  private function getCurrentState(NodeInterface $node): string {
    if ($node->hasField('field_receipt_status') && !$node->get('field_receipt_status')->isEmpty()) {
      return (string) $node->get('field_receipt_status')->value;
    }
    return 'receipt_new';
  }

  private function executeTransition(NodeInterface $node, string $toSid): void {
    $fromSid = $this->getCurrentState($node);
    if ($fromSid === $toSid) {
      return;
    }

    try {
      /** @var \Drupal\workflow\Entity\WorkflowTransition $transition */
      $transition = WorkflowTransition::create([
        $fromSid,
        'entity' => $node,
        'field_name' => 'field_receipt_status',
      ]);
      $transition->setValues($toSid, 1, \Drupal::time()->getRequestTime(), '', TRUE);
      $transition->force(TRUE);
      // executeAndUpdateEntity() records history AND updates the node's
      // field_receipt_status value. Plain execute() only records history.
      $transition->executeAndUpdateEntity(TRUE);

      $this->loggerFactory->get('receipt_vision_verify')->info(
        'Receipt node @nid: @from → @to (confidence: @c).',
        [
          '@nid' => $node->id(),
          '@from' => $fromSid,
          '@to' => $toSid,
          '@c' => $node->get('field_extraction_confidence')->value ?? 'n/a',
        ],
      );
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('receipt_vision_verify')->error(
        'Transition @from → @to failed for node @nid: @msg',
        ['@from' => $fromSid, '@to' => $toSid, '@nid' => $node->id(), '@msg' => $e->getMessage()],
      );
    }
  }

}
