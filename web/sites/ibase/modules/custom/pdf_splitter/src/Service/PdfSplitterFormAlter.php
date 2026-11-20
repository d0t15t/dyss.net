<?php

namespace Drupal\pdf_splitter\Service;

use Drupal\Core\Form\FormStateInterface;

/**
 * Adds "Split PDF into page files" controls to the receipts_set node form.
 *
 * On submit, splits field_receipts_multi_file (single PDF) into per-page file
 * entities on field_receipts (unlimited file field).
 */
class PdfSplitterFormAlter {

  public function __construct(
    protected PdfSplitterService $splitter,
  ) {}

  /**
   * Attaches PDF splitting form elements to the receipts_set node form.
   */
  public function alter(array &$form, FormStateInterface $form_state): void {
    if (!class_exists('\setasign\Fpdi\Fpdi')) {
      \Drupal::messenger()->addWarning(\t(
        'PDF splitting requires the setasign/fpdi library. Run: <code>ddev composer require setasign/fpdi setasign/fpdf</code>'
      ));
      return;
    }

    $form['pdf_splitter'] = [
      '#type'   => 'details',
      '#title'  => \t('PDF Splitting'),
      '#open'   => FALSE,
      '#weight' => 50,
      '#tree'   => TRUE,
    ];

    $form['pdf_splitter']['split_pdf'] = [
      '#type'          => 'checkbox',
      '#title'         => \t('Split PDF into page files'),
      '#default_value' => FALSE,
      '#description'   => \t('After saving, each page of the uploaded PDF (from "Multi file") will be saved as a separate file and added to the "Receipts" file field.'),
    ];

    $form['#validate'][] = [static::class, 'validateHandler'];

    // Append split logic to run after EntityForm::save().
    foreach (array_keys($form['actions']) as $action) {
      if (!empty($form['actions'][$action]['#type']) && $form['actions'][$action]['#type'] === 'submit') {
        $form['actions'][$action]['#submit'][] = [static::class, 'submitHandler'];
      }
    }
  }

  /**
   * Validates that a source file is attached when splitting is enabled.
   */
  public static function validateHandler(array &$form, FormStateInterface $form_state): void {
    $values = $form_state->getValue('pdf_splitter');
    if (empty($values['split_pdf'])) {
      return;
    }

    $fileField = $form_state->getValue('field_receipts_multi_file');
    $hasFid = FALSE;
    if (is_array($fileField)) {
      foreach ($fileField as $item) {
        if (!empty($item['fids'])) {
          $hasFid = TRUE;
          break;
        }
      }
    }

    if (!$hasFid) {
      $form_state->setErrorByName(
        'field_receipts_multi_file',
        \t('Upload a PDF to the "Multi file" field before splitting.')
      );
    }
  }

  /**
   * Performs the split after the node has been saved.
   */
  public static function submitHandler(array &$form, FormStateInterface $form_state): void {
    $values = $form_state->getValue('pdf_splitter');
    if (empty($values['split_pdf'])) {
      return;
    }

    $node = $form_state->getFormObject()->getEntity();
    $nodeId = $node->id();
    if (!$nodeId) {
      return;
    }

    // Reload to get the fully-saved state.
    /** @var \Drupal\node\NodeInterface $node */
    $node = \Drupal::entityTypeManager()->getStorage('node')->load($nodeId);
    if (!$node) {
      return;
    }

    \Drupal::service('pdf_splitter.splitter')->splitPdfToFiles($node);
  }

}
