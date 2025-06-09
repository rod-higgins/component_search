<?php

namespace Drupal\component_search\ParamConverter;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\ParamConverter\ParamConverterInterface;
use Symfony\Component\Routing\Route;

/**
 * Parameter converter for component search entities.
 */
class ComponentSearchEntityConverter implements ParamConverterInterface {

  /**
   * Entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Constructor.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public function convert($value, $definition, $name, array $defaults) {
    if (empty($value)) {
      return NULL;
    }

    $entity_type_id = $definition['type'] ?? 'node';
    
    try {
      $storage = $this->entityTypeManager->getStorage($entity_type_id);
      $entity = $storage->load($value);
      
      if (!$entity) {
        return NULL;
      }

      // Check if entity has component fields
      $has_component_fields = FALSE;
      foreach ($entity->getFieldDefinitions() as $field_definition) {
        if ($field_definition->getType() === 'component_field') {
          $field_values = $entity->get($field_definition->getName());
          if (!$field_values->isEmpty()) {
            $has_component_fields = TRUE;
            break;
          }
        }
      }

      return $has_component_fields ? $entity : NULL;
      
    } catch (\Exception $e) {
      \Drupal::logger('component_search')->warning('Error in parameter converter: @error', [
        '@error' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function applies($definition, $name, Route $route) {
    return !empty($definition['type']) && 
           isset($definition['component_search']) && 
           $definition['component_search'] === TRUE;
  }
}