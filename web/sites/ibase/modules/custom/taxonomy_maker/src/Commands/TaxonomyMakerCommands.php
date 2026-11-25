<?php

namespace Drupal\taxonomy_maker\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for the Taxonomy Maker module.
 *
 * List file format:
 *   - Lines starting with # are comments and are ignored.
 *   - A line like [vocabulary_machine_name] starts a new vocabulary section.
 *   - All non-empty, non-comment lines after a section header are term names.
 *   - Multiple vocabulary sections are supported in a single file.
 *   - Hierarchy is not supported; all terms are created at the root level.
 */
class TaxonomyMakerCommands extends DrushCommands {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct();
  }

  /**
   * Import taxonomy terms from a list file.
   *
   * The list file may contain multiple vocabulary sections. Each section
   * starts with [vocabulary_machine_name] on its own line, followed by one
   * term name per line. Lines beginning with # are treated as comments.
   * Terms that already exist in the given vocabulary are skipped.
   */
  #[CLI\Command(name: 'taxonomy-maker:import', aliases: ['txm-import'])]
  #[CLI\Argument(name: 'file', description: 'Path to the list file (absolute, or relative to the Drupal root).')]
  #[CLI\Usage(name: 'drush taxonomy-maker:import modules/taxonomy_maker/data/example.txt', description: 'Import the bundled example file (path relative to Drupal root).')]
  #[CLI\Usage(name: 'drush taxonomy-maker:import /var/www/html/web/modules/taxonomy_maker/data/example.txt', description: 'Import terms using an absolute path.')]
  public function import(string $file): void {
    // Resolve relative paths against the current working directory.
    if (!str_starts_with($file, '/')) {
      $file = getcwd() . '/' . ltrim($file, '/');
    }

    if (!is_file($file) || !is_readable($file)) {
      throw new \InvalidArgumentException("File not found or not readable: $file");
    }

    $parsed = $this->parseFile($file);

    if (empty($parsed)) {
      $this->logger()->warning('No vocabulary sections or terms found in the file.');
      return;
    }

    $termStorage = $this->entityTypeManager->getStorage('taxonomy_term');
    $vocabStorage = $this->entityTypeManager->getStorage('taxonomy_vocabulary');

    $totalCreated = 0;
    $totalSkipped = 0;

    foreach ($parsed as $vocabId => $termNames) {
      if (!$vocabStorage->load($vocabId)) {
        $this->logger()->error(dt("Vocabulary '!vid' does not exist — skipping its !count term(s).", [
          '!vid' => $vocabId,
          '!count' => count($termNames),
        ]));
        continue;
      }

      $created = 0;
      $skipped = 0;

      foreach ($termNames as $name) {
        $existing = $termStorage->loadByProperties([
          'vid' => $vocabId,
          'name' => $name,
        ]);

        if (!empty($existing)) {
          $skipped++;
          continue;
        }

        $term = $termStorage->create([
          'vid' => $vocabId,
          'name' => $name,
        ]);
        $term->save();
        $created++;
      }

      $this->logger()->success(dt("'!vid': !created created, !skipped skipped.", [
        '!vid' => $vocabId,
        '!created' => $created,
        '!skipped' => $skipped,
      ]));

      $totalCreated += $created;
      $totalSkipped += $skipped;
    }

    $this->logger()->success(dt('Done. Total: !created created, !skipped skipped.', [
      '!created' => $totalCreated,
      '!skipped' => $totalSkipped,
    ]));
  }

  /**
   * Parse a list file into a keyed array of [vocab_id => [term, term, ...]].
   *
   * @param string $file
   *   Absolute path to the list file.
   *
   * @return array<string, list<string>>
   *   Associative array keyed by vocabulary machine name, values are arrays
   *   of term name strings.
   */
  protected function parseFile(string $file): array {
    $lines = file($file, FILE_IGNORE_NEW_LINES);
    $result = [];
    $currentVocab = NULL;

    foreach ($lines as $raw) {
      $line = trim($raw);

      // Skip blank lines and comments.
      if ($line === '' || str_starts_with($line, '#')) {
        continue;
      }

      // Vocabulary section header: [machine_name].
      if (preg_match('/^\[([a-z0-9_]+)\]$/', $line, $matches)) {
        $currentVocab = $matches[1];
        if (!array_key_exists($currentVocab, $result)) {
          $result[$currentVocab] = [];
        }
        continue;
      }

      // Term name — only accepted when inside a vocabulary section.
      if ($currentVocab !== NULL) {
        $result[$currentVocab][] = $line;
      }
      else {
        $this->logger()->warning(dt("Ignoring line outside of a vocabulary section: '!line'", ['!line' => $line]));
      }
    }

    return $result;
  }

}
