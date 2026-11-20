<?php

declare(strict_types=1);

namespace Drupal\receipt_vision_verify\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\node\NodeInterface;
use Drupal\receipt_vision_verify\Service\VisionComparator;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Accepts vision-extracted values into receipt node fields.
 *
 * Works in tandem with VisionComparator — the admin sees discrepancies, picks
 * the vision version (usually more accurate), and this controller writes it
 * to the actual node field.
 */
class AcceptVisionController extends ControllerBase {

  public function __construct(
    protected VisionComparator $comparator,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('receipt_vision_verify.comparator'));
  }

  /**
   * Accept the vision value for a single field on a receipt node.
   */
  public function acceptField(NodeInterface $node, string $field_name): RedirectResponse {
    if ($node->bundle() !== 'receipt') {
      $this->messenger()->addError($this->t('Not a receipt node.'));
      return $this->redirectToEdit($node);
    }

    $discrepancies = $this->loadDiscrepancies($node);
    if (!isset($discrepancies[$field_name])) {
      $this->messenger()->addWarning($this->t('No discrepancy recorded for @field.', ['@field' => $field_name]));
      return $this->redirectToEdit($node);
    }

    $visionValue = $discrepancies[$field_name]['vision'] ?? NULL;
    if ($this->applyVisionValue($node, $field_name, $visionValue)) {
      unset($discrepancies[$field_name]);
      $node->set('field_extraction_discrepancies', json_encode($discrepancies, JSON_UNESCAPED_UNICODE));
      $this->recomputeConfidence($node);
      $node->save();

      $this->messenger()->addStatus($this->t(
        'Vision value applied to @field: "@value"',
        ['@field' => $field_name, '@value' => (string) $visionValue],
      ));
    }
    else {
      $this->messenger()->addError($this->t(
        'Could not apply vision value to @field.',
        ['@field' => $field_name],
      ));
    }

    return $this->redirectToEdit($node);
  }

  /**
   * Accept all vision values at once.
   */
  public function acceptAll(NodeInterface $node): RedirectResponse {
    if ($node->bundle() !== 'receipt') {
      $this->messenger()->addError($this->t('Not a receipt node.'));
      return $this->redirectToEdit($node);
    }

    $discrepancies = $this->loadDiscrepancies($node);
    if (empty($discrepancies)) {
      $this->messenger()->addStatus($this->t('No discrepancies to accept.'));
      return $this->redirectToEdit($node);
    }

    $applied = 0;
    $failed = [];
    foreach ($discrepancies as $fieldName => $disc) {
      $visionValue = $disc['vision'] ?? NULL;
      if ($this->applyVisionValue($node, $fieldName, $visionValue)) {
        $applied++;
      }
      else {
        $failed[] = $fieldName;
      }
    }

    // Clear all discrepancies and set confidence to 100.
    $node->set('field_extraction_discrepancies', json_encode([], JSON_UNESCAPED_UNICODE));
    $node->set('field_extraction_confidence', 100);
    $node->save();

    if ($applied > 0) {
      $this->messenger()->addStatus($this->t(
        'Applied vision values to @count field(s).',
        ['@count' => $applied],
      ));
    }
    if (!empty($failed)) {
      $this->messenger()->addWarning($this->t(
        'Could not apply: @fields',
        ['@fields' => implode(', ', $failed)],
      ));
    }

    return $this->redirectToEdit($node);
  }

  // ── Helpers ────────────────────────────────────────────────────────────────

  /**
   * Apply a vision value to a node field, handling type conversion.
   *
   * @return bool
   *   TRUE on success, FALSE if the value could not be applied.
   */
  protected function applyVisionValue(NodeInterface $node, string $fieldName, mixed $visionValue): bool {
    if ($visionValue === NULL || $visionValue === '' || !$node->hasField($fieldName)) {
      return FALSE;
    }

    $def = $node->getFieldDefinition($fieldName);
    $type = $def->getType();

    try {
      switch ($type) {
        case 'decimal':
        case 'float':
        case 'integer':
          $numeric = is_numeric($visionValue) ? $visionValue : (float) preg_replace('/[^0-9.\-]/', '', (string) $visionValue);
          $node->set($fieldName, $numeric);
          return TRUE;

        case 'datetime':
          $ts = strtotime((string) $visionValue);
          if ($ts === FALSE) {
            return FALSE;
          }
          // Determine if field stores date-only or datetime.
          $datetimeType = $def->getSetting('datetime_type') ?? 'date';
          $format = $datetimeType === 'date' ? 'Y-m-d' : 'Y-m-d\TH:i:s';
          $node->set($fieldName, date($format, $ts));
          return TRUE;

        case 'entity_reference':
          return $this->applyEntityReference($node, $def, $fieldName, (string) $visionValue);

        case 'string':
        case 'string_long':
        case 'text':
        case 'text_long':
          $node->set($fieldName, (string) $visionValue);
          return TRUE;

        default:
          $node->set($fieldName, $visionValue);
          return TRUE;
      }
    }
    catch (\Exception $e) {
      \Drupal::logger('receipt_vision_verify')->error(
        'Failed to apply vision value to @field: @msg',
        ['@field' => $fieldName, '@msg' => $e->getMessage()],
      );
      return FALSE;
    }
  }

  /**
   * Resolve a vision string value to a taxonomy term (find-or-create).
   */
  protected function applyEntityReference(NodeInterface $node, $def, string $fieldName, string $visionValue): bool {
    $targetType = $def->getSetting('target_type');
    if ($targetType !== 'taxonomy_term') {
      // Only handle taxonomy terms for now.
      return FALSE;
    }

    $handlerSettings = $def->getSetting('handler_settings') ?? [];
    $targetBundles = array_values($handlerSettings['target_bundles'] ?? []);
    if (empty($targetBundles)) {
      return FALSE;
    }
    $vid = $targetBundles[0];

    $storage = $this->entityTypeManager()->getStorage('taxonomy_term');

    // Case-insensitive find.
    $existing = $storage->loadByProperties(['vid' => $vid, 'name' => $visionValue]);
    if (empty($existing)) {
      foreach ($storage->loadByProperties(['vid' => $vid]) as $term) {
        if (strcasecmp($term->getName(), $visionValue) === 0) {
          $existing = [$term];
          break;
        }
      }
    }

    if ($existing) {
      $tid = (int) reset($existing)->id();
    }
    else {
      $new = $storage->create(['vid' => $vid, 'name' => $visionValue]);
      $new->save();
      $tid = (int) $new->id();
    }

    $node->set($fieldName, ['target_id' => $tid]);
    return TRUE;
  }

  /**
   * Recompute confidence after a single-field accept.
   *
   * We clear the current confidence/discrepancies and let the comparator re-run
   * against the remaining vision JSON to produce a fresh score.
   */
  protected function recomputeConfidence(NodeInterface $node): void {
    // The comparator writes both confidence and discrepancies from scratch.
    // But we want to preserve our already-modified discrepancies list (the one
    // we just removed an entry from). Save the node once, then reload via the
    // comparator logic applied only to remaining fields.
    // Simpler: recompute directly using the comparator.
    $this->comparator->compare($node);
  }

  protected function loadDiscrepancies(NodeInterface $node): array {
    if (!$node->hasField('field_extraction_discrepancies') || $node->get('field_extraction_discrepancies')->isEmpty()) {
      return [];
    }
    $decoded = json_decode((string) $node->get('field_extraction_discrepancies')->value, TRUE);
    return is_array($decoded) ? $decoded : [];
  }

  protected function redirectToEdit(NodeInterface $node): RedirectResponse {
    return new RedirectResponse($node->toUrl('edit-form')->toString());
  }

}
