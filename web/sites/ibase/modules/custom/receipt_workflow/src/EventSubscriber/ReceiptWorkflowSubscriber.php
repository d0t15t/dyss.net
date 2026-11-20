<?php

declare(strict_types=1);

namespace Drupal\receipt_workflow\EventSubscriber;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\workflow\Entity\WorkflowTransition;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Drives automatic workflow state transitions for receipt nodes.
 *
 * Workflow: receipt (wid)
 *
 * Automatic transitions (all on node.receipt):
 *   receipt_new → receipt_file_attached     when field_receipt has a file
 *   receipt_file_attached → receipt_text_extracted  when field_receipt_text is populated (OCR done)
 *   receipt_text_extracted → receipt_needs_review   when AI extraction populates minimum fields
 */
class ReceiptWorkflowSubscriber implements EventSubscriberInterface {

  /**
   * Guard against re-entrant saves triggered by transition::execute().
   *
   * @var array<int, true>
   */
  private static array $processing = [];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [];
  }

  /**
   * React to any entity insert.
   */
  public function onEntityInsert(object $entity): void {
    if ($entity->getEntityTypeId() === 'node' && $entity->bundle() === 'receipt') {
      $this->handleReceiptNode($entity, TRUE);
    }
  }

  /**
   * React to any entity update.
   */
  public function onEntityUpdate(object $entity, ?object $original = NULL): void {
    if ($entity->getEntityTypeId() === 'node' && $entity->bundle() === 'receipt') {
      $this->handleReceiptNode($entity, FALSE, $original);
    }
  }

  // ── Core logic ─────────────────────────────────────────────────────────────

  /**
   * Handle saves of node.receipt entities.
   *
   * The entire lifecycle is tracked on a single node:
   *   1. file attached (field_receipt)           → receipt_file_attached
   *   2. OCR text populated (field_receipt_text)  → receipt_text_extracted
   *   3. minimum fields populated                 → receipt_needs_review
   */
  private function handleReceiptNode(object $node, bool $isInsert, ?object $original = NULL): void {
    $nid = $node->id();
    if (isset(self::$processing[$nid])) {
      return;
    }

    // Cascade through all eligible transitions in one save cycle.
    // Each iteration checks the current state and advances it if possible,
    // then loops again to see whether the new state allows further advancement.
    // Safety cap prevents infinite loops if logic is ever misconfigured.
    for ($i = 0; $i < 10; $i++) {
      $currentState = $this->getCurrentState($node);
      $nextState = $this->computeNextState($node, $currentState);

      if ($nextState === NULL) {
        break;
      }

      $this->executeTransition($node, $nextState);
    }
  }

  /**
   * Returns the next state the node should advance to, or NULL if none.
   */
  private function computeNextState(object $node, string $currentState): ?string {
    // receipt_new → receipt_file_attached when file is set.
    if ($currentState === 'receipt_new' && !$node->get('field_receipt')->isEmpty()) {
      return 'receipt_file_attached';
    }

    // receipt_file_attached → receipt_text_extracted when OCR text appears.
    if ($currentState === 'receipt_file_attached') {
      $text = $node->get('field_receipt_text')->value ?? '';
      if (trim($text) !== '') {
        return 'receipt_text_extracted';
      }
    }

    // receipt_text_extracted → receipt_needs_review when minimum fields set.
    if ($currentState === 'receipt_text_extracted' && $this->hasMinimumFields($node)) {
      return 'receipt_needs_review';
    }

    return NULL;
  }

  // ── Helpers ────────────────────────────────────────────────────────────────

  /**
   * Returns the current workflow state ID of a receipt node.
   */
  private function getCurrentState(object $node): string {
    if ($node->hasField('field_receipt_status') && !$node->get('field_receipt_status')->isEmpty()) {
      return (string) $node->get('field_receipt_status')->value;
    }
    return 'receipt_new';
  }

  /**
   * Returns TRUE when the minimum required fields for review are populated.
   */
  private function hasMinimumFields(object $node): bool {
    $required = ['field_total_amount', 'field_receipt_date'];
    foreach ($required as $field) {
      if ($node->hasField($field) && $node->get($field)->isEmpty()) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Executes a workflow state transition on a node, forced (no permission check).
   *
   * Uses the workflow module's transition API so that transition history is
   * recorded correctly with from/to state IDs.
   */
  private function executeTransition(object $node, string $toSid): void {
    $fromSid = $this->getCurrentState($node);

    if ($fromSid === $toSid) {
      return;
    }

    $nid = $node->id();
    self::$processing[$nid] = TRUE;
    try {
      /** @var \Drupal\workflow\Entity\WorkflowTransition $transition */
      $transition = \Drupal\workflow\Entity\WorkflowTransition::create([
        $fromSid,
        'entity' => $node,
        'field_name' => 'field_receipt_status',
      ]);
      $transition->setValues($toSid, 1, \Drupal::time()->getRequestTime(), '', TRUE);
      $transition->force(TRUE);
      // executeAndUpdateEntity() both records transition history AND updates
      // the entity's workflow field. Plain execute() only records history,
      // leaving the entity field unchanged after the outer save has completed.
      $transition->executeAndUpdateEntity(TRUE);

      $this->loggerFactory->get('receipt_workflow')->info(
        'Receipt node @nid transitioned from @from to @to.',
        ['@nid' => $node->id(), '@from' => $fromSid, '@to' => $toSid],
      );
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('receipt_workflow')->error(
        'Failed to transition receipt node @nid from @from to @to: @msg',
        ['@nid' => $node->id(), '@from' => $fromSid, '@to' => $toSid, '@msg' => $e->getMessage()],
      );
    }
    finally {
      unset(self::$processing[$nid]);
    }
  }

}
