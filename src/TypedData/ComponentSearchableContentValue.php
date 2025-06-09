<?php

namespace Drupal\component_search\TypedData;

use Drupal\Core\TypedData\TypedData;

/**
 * Computed property for searchable content value.
 */
class ComponentSearchableContentValue extends TypedData {

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
      $content = $extractor->extractSearchableContent($entity);
      return $this->processed = $content;
    } catch (\Exception $e) {
      \Drupal::logger('component_search')->error('Error computing searchable content: @error', [
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