<?php

namespace Drupal\component_search\TypedData;

use Drupal\Core\TypedData\TypedData;

/**
 * Computed property for component types.
 */
class ComponentTypesValue extends TypedData {

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

    $component_types = [];

    try {
      // Find all component fields and extract types
      foreach ($entity->getFieldDefinitions() as $field_name => $field_definition) {
        if ($field_definition->getType() !== 'component_field') {
          continue;
        }

        $field_values = $entity->get($field_name);
        if ($field_values->isEmpty()) {
          continue;
        }

        foreach ($field_values as $field_item) {
          $component_type = $field_item->get('component_type')->getValue();
          if (!empty($component_type)) {
            $component_types[] = $component_type;
          }
        }
      }

      return $this->processed = implode(',', array_unique($component_types));
    } catch (\Exception $e) {
      \Drupal::logger('component_search')->error('Error computing component types: @error', [
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