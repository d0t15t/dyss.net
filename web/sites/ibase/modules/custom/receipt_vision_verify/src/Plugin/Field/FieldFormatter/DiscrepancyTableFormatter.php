<?php

declare(strict_types=1);

namespace Drupal\receipt_vision_verify\Plugin\Field\FieldFormatter;

use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Field\Attribute\FieldFormatter;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders field_extraction_discrepancies as a table with accept-vision actions.
 */
#[FieldFormatter(
  id: 'receipt_discrepancy_table',
  label: new TranslatableMarkup('Discrepancy table'),
  field_types: ['string_long'],
)]
class DiscrepancyTableFormatter extends FormatterBase {

  protected AccountInterface $currentUser;
  protected CsrfTokenGenerator $csrfToken;

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = new static(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['label'],
      $configuration['view_mode'],
      $configuration['third_party_settings'],
    );
    $instance->currentUser = $container->get('current_user');
    $instance->csrfToken = $container->get('csrf_token');
    return $instance;
  }

  public function viewElements(FieldItemListInterface $items, $langcode): array {
    $elements = [];
    $entity = $items->getEntity();
    $canAccept = $this->currentUser->hasPermission('rerun receipt verification');

    foreach ($items as $delta => $item) {
      $json = (string) $item->value;
      $decoded = json_decode($json, TRUE);

      if (!is_array($decoded) || empty($decoded)) {
        $elements[$delta] = [
          '#markup' => '<em>' . $this->t('No discrepancies detected — OCR and vision agreed on all fields.') . '</em>',
        ];
        continue;
      }

      $rows = [];
      foreach ($decoded as $fieldName => $disc) {
        $severity = $disc['severity'] ?? 'medium';
        $actionCell = ['data' => ''];

        if ($canAccept) {
          $acceptUrl = $this->buildAcceptUrl('receipt_vision_verify.accept_field', [
            'node' => $entity->id(),
            'field_name' => $fieldName,
          ]);
          $actionCell = [
            'data' => [
              '#type' => 'link',
              '#title' => $this->t('Use vision'),
              '#url' => $acceptUrl,
              '#attributes' => ['class' => ['button', 'button--small']],
            ],
          ];
        }

        $rows[] = [
          ['data' => $fieldName],
          ['data' => (string) ($disc['ocr'] ?? '—')],
          ['data' => (string) ($disc['vision'] ?? '—')],
          [
            'data' => $severity,
            'class' => ['receipt-severity', 'receipt-severity--' . $severity],
          ],
          $actionCell,
        ];
      }

      $table = [
        '#type' => 'table',
        '#header' => [
          $this->t('Field'),
          $this->t('OCR value'),
          $this->t('Vision value'),
          $this->t('Severity'),
          $this->t('Action'),
        ],
        '#rows' => $rows,
        '#attributes' => ['class' => ['receipt-discrepancy-table']],
      ];

      $wrapper = [
        '#type' => 'container',
        'table' => $table,
      ];

      if ($canAccept && count($decoded) > 1) {
        $acceptAllUrl = $this->buildAcceptUrl('receipt_vision_verify.accept_all', [
          'node' => $entity->id(),
        ]);
        $wrapper['actions'] = [
          '#type' => 'container',
          '#attributes' => ['class' => ['receipt-discrepancy-actions']],
          'accept_all' => [
            '#type' => 'link',
            '#title' => $this->t('Accept all vision values'),
            '#url' => $acceptAllUrl,
            '#attributes' => ['class' => ['button', 'button--primary']],
          ],
        ];
      }

      $elements[$delta] = $wrapper;
    }

    return $elements;
  }

  /**
   * Build a URL with CSRF token attached as query param.
   */
  protected function buildAcceptUrl(string $routeName, array $parameters): Url {
    $url = Url::fromRoute($routeName, $parameters);
    $token = $this->csrfToken->get($url->getInternalPath());
    $url->setOption('query', ['token' => $token]);
    return $url;
  }

}
