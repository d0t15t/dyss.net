<?php

declare(strict_types=1);

namespace Drupal\receipt_vision_verify\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Admin dashboard showing receipt verification stats.
 */
class VerificationDashboard extends ControllerBase {

  public function __construct(
    protected Connection $database,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('database'));
  }

  public function build(): array {
    $build = [];

    // Section 1: state breakdown.
    $build['states'] = $this->buildStateBreakdown();

    // Section 2: confidence histogram.
    $build['confidence'] = $this->buildConfidenceHistogram();

    // Section 3: per-field discrepancy frequency.
    $build['fields'] = $this->buildFieldFrequency();

    return $build;
  }

  /**
   * How many receipts are in each workflow state?
   */
  protected function buildStateBreakdown(): array {
    $query = $this->database->select('node__field_receipt_status', 's')
      ->fields('s', ['field_receipt_status_value'])
      ->condition('s.bundle', 'receipt');
    $query->addExpression('COUNT(*)', 'cnt');
    $query->groupBy('s.field_receipt_status_value');

    $rows = [];
    foreach ($query->execute() as $row) {
      $rows[] = [
        ['data' => $row->field_receipt_status_value],
        ['data' => $row->cnt],
      ];
    }

    return [
      '#type' => 'details',
      '#title' => $this->t('Receipts by workflow state'),
      '#open' => TRUE,
      'table' => [
        '#type' => 'table',
        '#header' => [$this->t('State'), $this->t('Count')],
        '#rows' => $rows,
        '#empty' => $this->t('No receipts yet.'),
      ],
    ];
  }

  /**
   * Confidence score distribution (buckets of 10).
   */
  protected function buildConfidenceHistogram(): array {
    $query = $this->database->select('node__field_extraction_confidence', 'c');
    $query->addExpression('FLOOR(c.field_extraction_confidence_value / 10) * 10', 'bucket');
    $query->addExpression('COUNT(*)', 'cnt');
    $query->condition('c.bundle', 'receipt');
    $query->groupBy('bucket');
    $query->orderBy('bucket', 'ASC');

    $rows = [];
    foreach ($query->execute() as $row) {
      $rows[] = [
        ['data' => $row->bucket . '–' . ($row->bucket + 9) . '%'],
        ['data' => $row->cnt],
      ];
    }

    return [
      '#type' => 'details',
      '#title' => $this->t('Confidence score distribution'),
      '#open' => TRUE,
      'table' => [
        '#type' => 'table',
        '#header' => [$this->t('Bucket'), $this->t('Count')],
        '#rows' => $rows,
        '#empty' => $this->t('No verified receipts yet.'),
      ],
    ];
  }

  /**
   * How often does each field disagree? Parse all discrepancy JSONs and count.
   */
  protected function buildFieldFrequency(): array {
    $query = $this->database->select('node__field_extraction_discrepancies', 'd')
      ->fields('d', ['field_extraction_discrepancies_value'])
      ->condition('d.bundle', 'receipt');

    $counts = [];
    foreach ($query->execute() as $row) {
      $decoded = json_decode((string) $row->field_extraction_discrepancies_value, TRUE);
      if (!is_array($decoded)) {
        continue;
      }
      foreach (array_keys($decoded) as $field) {
        $counts[$field] = ($counts[$field] ?? 0) + 1;
      }
    }

    arsort($counts);
    $rows = [];
    foreach ($counts as $field => $count) {
      $rows[] = [['data' => $field], ['data' => $count]];
    }

    return [
      '#type' => 'details',
      '#title' => $this->t('Most-disagreed fields'),
      '#open' => TRUE,
      'table' => [
        '#type' => 'table',
        '#header' => [$this->t('Field'), $this->t('Discrepancy count')],
        '#rows' => $rows,
        '#empty' => $this->t('No discrepancies recorded yet.'),
      ],
    ];
  }

}
