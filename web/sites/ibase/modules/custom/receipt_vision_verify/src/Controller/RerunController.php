<?php

declare(strict_types=1);

namespace Drupal\receipt_vision_verify\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Manual re-run of vision verification on a single receipt node.
 */
class RerunController extends ControllerBase {

  /**
   * Clears the vision-extraction fields and re-saves the node, which re-triggers
   * the AI automators (they only run when the target field is empty).
   */
  public function rerun(NodeInterface $node): RedirectResponse {
    if ($node->bundle() !== 'receipt') {
      $this->messenger()->addError($this->t('Not a receipt node.'));
      return new RedirectResponse(Url::fromRoute('<front>')->toString());
    }

    $node->set('field_vision_extraction', NULL);
    $node->set('field_extraction_confidence', NULL);
    $node->set('field_extraction_discrepancies', NULL);
    $node->save();

    $this->messenger()->addStatus($this->t(
      'Vision verification queued for re-run on "@title".',
      ['@title' => $node->label()],
    ));

    return new RedirectResponse($node->toUrl()->toString());
  }

}
