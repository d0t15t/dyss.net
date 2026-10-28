<?php

namespace Drupal\dj_mixes\Plugin\Field\FieldFormatter;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Field\Attribute\FieldFormatter;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\Plugin\Field\FieldFormatter\EntityReferenceFormatterBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\dj_mixes\DjMixPager;
use Drupal\taxonomy\TermInterface;

/**
 * Links terms to the DJ mixes listing, filtered by that term.
 *
 * E.g. a genre links to /mixes?genre[73]=73 - the query the listing's
 * checkbox filters use, so the term's box shows ticked - instead of to the
 * term's own page. Unpublished (disabled) terms are left out.
 */
#[FieldFormatter(
  id: 'dj_mixes_listing_filter_link',
  label: new TranslatableMarkup('Link to the DJ mixes listing'),
  description: new TranslatableMarkup('The term name, linked to the DJ mixes listing filtered by it.'),
  field_types: ['entity_reference'],
)]
class ListingFilterLinkFormatter extends EntityReferenceFormatterBase {

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return ['parameter' => 'genre'] + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public static function isApplicable(FieldDefinitionInterface $field_definition) {
    return $field_definition->getFieldStorageDefinition()->getSetting('target_type') === 'taxonomy_term';
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $element['parameter'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Filter parameter'),
      '#description' => $this->t('The URL parameter of the listing filter for this field, e.g. <code>genre</code> or <code>tags</code>.'),
      '#default_value' => $this->getSetting('parameter'),
      '#required' => TRUE,
    ];
    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    return [$this->t('Links to the DJ mixes listing, filter: @parameter', ['@parameter' => $this->getSetting('parameter')])];
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = [];
    $parameter = $this->getSetting('parameter');
    // Left-out terms still vary the output, e.g. once re-enabled.
    $skipped = new CacheableMetadata();
    foreach ($this->getEntitiesToView($items, $langcode) as $delta => $term) {
      $cacheability = CacheableMetadata::createFromObject($term);
      if ($term instanceof TermInterface && !$term->isPublished()) {
        $skipped->addCacheableDependency($term);
        continue;
      }
      $elements[$delta] = [
        '#type' => 'link',
        '#title' => $term->label(),
        '#url' => Url::fromRoute('view.' . DjMixPager::VIEW . '.' . DjMixPager::DISPLAY, [], [
          'query' => [$parameter => [$term->id() => $term->id()]],
        ]),
      ];
      $cacheability->applyTo($elements[$delta]);
    }
    $skipped->applyTo($elements);
    return $elements;
  }

}
