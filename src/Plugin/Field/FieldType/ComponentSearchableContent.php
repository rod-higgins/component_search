<?php

namespace Drupal\component_search\Plugin\Field\FieldType;

use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\TypedData\DataDefinition;

/**
 * Computed field for aggregated component search content.
 *
 * @FieldType(
 *   id = "component_searchable_content",
 *   label = @Translation("Component Searchable Content"),
 *   description = @Translation("Aggregated searchable content from all component fields"),
 *   default_widget = "string_textfield",
 *   default_formatter = "string",
 *   no_ui = TRUE,
 *   computed = TRUE,
 * )
 */
class ComponentSearchableContent extends FieldItemBase {

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition) {
    $properties['value'] = DataDefinition::create('string')
      ->setLabel(t('Searchable Content'))
      ->setDescription(t('Aggregated searchable text from all component fields'))
      ->setComputed(TRUE)
      ->setClass('\Drupal\component_search\TypedData\ComponentSearchableContentValue');

    $properties['component_types'] = DataDefinition::create('string')
      ->setLabel(t('Component Types'))
      ->setDescription(t('Comma-separated list of component types used'))
      ->setComputed(TRUE)
      ->setClass('\Drupal\component_search\TypedData\ComponentTypesValue');

    $properties['title_content'] = DataDefinition::create('string')
      ->setLabel(t('Title Content'))
      ->setDescription(t('Extracted title and heading content'))
      ->setComputed(TRUE)
      ->setClass('\Drupal\component_search\TypedData\ComponentTitleContentValue');

    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public static function schema(FieldStorageDefinitionInterface $field_definition) {
    return [
      'columns' => [
        'value' => [
          'type' => 'text',
          'size' => 'big',
          'not null' => FALSE,
        ],
        'component_types' => [
          'type' => 'varchar',
          'length' => 1024,
          'not null' => FALSE,
        ],
        'title_content' => [
          'type' => 'text',
          'size' => 'medium',
          'not null' => FALSE,
        ],
      ],
      'indexes' => [
        'component_types' => ['component_types'],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function isEmpty() {
    return empty($this->get('value')->getValue());
  }

  /**
   * Get the searchable content.
   */
  public function getSearchableContent(): string {
    return $this->get('value')->getValue() ?: '';
  }

  /**
   * Get the component types used.
   */
  public function getComponentTypes(): array {
    $types = $this->get('component_types')->getValue();
    return $types ? explode(',', $types) : [];
  }

  /**
   * Get the title content.
   */
  public function getTitleContent(): string {
    return $this->get('title_content')->getValue() ?: '';
  }
}

/**
 * Computed property for searchable content value.
 */
class ComponentSearchableContentValue extends \Drupal\Core\TypedData\TypedData {

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

/**
 * Computed property for component types.
 */
class ComponentTypesValue extends \Drupal\Core\TypedData\TypedData {

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

/**
 * Computed property for title content.
 */
class ComponentTitleContentValue extends \Drupal\Core\TypedData\TypedData {

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