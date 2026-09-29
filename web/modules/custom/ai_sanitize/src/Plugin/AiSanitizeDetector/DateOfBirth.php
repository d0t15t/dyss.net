<?php

namespace Drupal\ai_sanitize\Plugin\AiSanitizeDetector;

use Drupal\ai_sanitize\Attribute\AiSanitizeDetector;
use Drupal\ai_sanitize\Detector\DetectorBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Detects dates of birth: dates that follow a birth-date label.
 *
 * Only dates near a label ("Geburtsdatum", "geb.", "date of birth" …) are
 * replaced; all other dates stay, since they carry the meaning of documents.
 * OCR'd forms often put the label and the value a few lines apart, so up to
 * three short lines without digits may sit in between.
 */
#[AiSanitizeDetector(
  id: 'date_of_birth',
  label: new TranslatableMarkup('Date of birth'),
  placeholder: 'BIRTHDATE',
  description: new TranslatableMarkup('Dates that follow a birth-date label (Geburtsdatum, geb., geboren am, date of birth, DOB). Other dates are kept.'),
  weight: 60,
)]
class DateOfBirth extends DetectorBase {

  /**
   * {@inheritdoc}
   */
  public function detect(string $text): array {
    $label = '(?:\bgeb\.|\bgeboren(?:\s+am)?\b|\bGeburtsdatum\b|\bGeb\.-Datum\b|\bdate\s+of\s+birth\b|\bbirth\s*date\b|\bDOB\b)';
    $months = '(?:Jan|Feb|M[aä]r|Apr|Mai|May|Jun|Jul|Aug|Sep|Okt|Oct|Nov|De[cz])[a-zä]*\.?';
    $date = '(\d{1,2}\.\s?(?:\d{1,2}\.|' . $months . ')\s?(?:\d{4}|\d{2})\b|\d{4}-\d{2}-\d{2}\b|\d{1,2}\/\d{1,2}\/\d{2,4}\b)';
    $gap = '[\s:.,\-]*(?:[^\n\d]{0,40}\n){0,3}[^\n\d]{0,40}?';
    return $this->matchAll('/' . $label . $gap . $date . '/iu', $text, NULL, 1);
  }

}
