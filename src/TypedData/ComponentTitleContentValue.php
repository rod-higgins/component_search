<?php

namespace Drupal\component_search\TypedData;

use Drupal\Core\TypedData\TypedData;

/**
 * Computed property for title content.
 */
class ComponentTitleContentValue extends TypedData {

  /**
   * Cached processed value.
   */
  protected $processed = NULL;

  /**
   * {@inheritdoc}
   */
  public function getValue() {
    if ($this->processed !== NULL) {
      return $this->processed;
    }

    $entity = $this->getParent()->getEntity();
    
    if (!$entity) {
      return $this->processed = '';
    }

    try {
      $extractor = \Drupal::service('component_search.content_extractor');
      $data = $extractor->extractSearchApiContent($entity);
      $titles = $data['component_titles'] ?? [];
      return $this->processed = implode(' ', $titles);
    } catch (\Exception $e) {
      \Drupal::logger('component_search')->error('Error computing title content: @error', [
        '@error' => $e->getMessage(),
      ]);
      return $this->processed = '';
    }
  }

  /**
   * {@inheritdoc}
   */
  public function setValue($value, $notify = TRUE) {
    $this->processed = NULL;
    parent::setValue($value, $notify);
  }
}