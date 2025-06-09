<?php

namespace Drupal\component_search\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityInterface;

/**
 * Helper service for component search indexing operations.
 */
class ComponentIndexingHelper {

  protected ComponentContentExtractor $contentExtractor;
  protected EntityTypeManagerInterface $entityTypeManager;
  protected ConfigFactoryInterface $configFactory;

  public function __construct(
    ComponentContentExtractor $content_extractor,
    EntityTypeManagerInterface $entity_type_manager,
    ConfigFactoryInterface $config_factory
  ) {
    $this->contentExtractor = $content_extractor;
    $this->entityTypeManager = $entity_type_manager;
    $this->configFactory = $config_factory;
  }

  /**
   * Get all entities that have component fields.
   */
  public function getEntitiesWithComponentFields(array $entity_types = ['node'], int $limit = 0): array {
    $entities = [];
    
    foreach ($entity_types as $entity_type_id) {
      try {
        $storage = $this->entityTypeManager->getStorage($entity_type_id);
        $entity_type = $this->entityTypeManager->getDefinition($entity_type_id);
        
        // Check if this entity type can have fields
        if (!$entity_type->entityClassImplements('\Drupal\Core\Entity\FieldableEntityInterface')) {
          continue;
        }

        $query = $storage->getQuery()
          ->accessCheck(FALSE);
          
        if ($limit > 0) {
          $query->range(0, $limit);
        }
        
        $entity_ids = $query->execute();
        
        foreach ($entity_ids as $entity_id) {
          $entity = $storage->load($entity_id);
          if ($entity && $this->entityHasComponentFields($entity)) {
            $entities[] = $entity;
          }
        }
      } catch (\Exception $e) {
        \Drupal::logger('component_search')->warning('Error loading entities of type @type: @error', [
          '@type' => $entity_type_id,
          '@error' => $e->getMessage(),
        ]);
      }
    }
    
    return $entities;
  }

  /**
   * Check if an entity has component fields.
   */
  public function entityHasComponentFields(EntityInterface $entity): bool {
    foreach ($entity->getFieldDefinitions() as $field_definition) {
      if ($field_definition->getType() === 'component_field') {
        $field_name = $field_definition->getName();
        $field_values = $entity->get($field_name);
        
        if (!$field_values->isEmpty()) {
          return TRUE;
        }
      }
    }
    return FALSE;
  }

  /**
   * Get component statistics for an entity.
   */
  public function getEntityComponentStats(EntityInterface $entity): array {
    $stats = [
      'total_components' => 0,
      'component_types' => [],
      'has_content' => FALSE,
      'content_length' => 0,
    ];

    try {
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
            $stats['total_components']++;
            
            if (!in_array($component_type, $stats['component_types'])) {
              $stats['component_types'][] = $component_type;
            }
          }
        }
      }

      // Get extracted content to measure length
      $content = $this->contentExtractor->extractSearchableContent($entity);
      if (!empty($content)) {
        $stats['has_content'] = TRUE;
        $stats['content_length'] = strlen($content);
      }

    } catch (\Exception $e) {
      \Drupal::logger('component_search')->warning('Error getting component stats for entity @id: @error', [
        '@id' => $entity->id(),
        '@error' => $e->getMessage(),
      ]);
    }

    return $stats;
  }

  /**
   * Calculate optimal batch size based on content complexity.
   */
  public function calculateOptimalBatchSize(EntityInterface $entity): int {
    $component_count = $this->getEntityComponentCount($entity);
    $base_size = $this->configFactory->get('component_search.settings')
      ->get('performance_settings.batch_size') ?? 50;
    
    // Adjust batch size based on component complexity
    if ($component_count > 10) {
      return max(5, intval($base_size / 4));
    }
    if ($component_count > 5) {
      return max(10, intval($base_size / 2));
    }
    
    return $base_size;
  }

  /**
   * Get count of components in an entity.
   */
  protected function getEntityComponentCount(EntityInterface $entity): int {
    $count = 0;
    
    foreach ($entity->getFieldDefinitions() as $field_definition) {
      if ($field_definition->getType() === 'component_field') {
        $field_values = $entity->get($field_definition->getName());
        $count += $field_values->count();
      }
    }
    
    return $count;
  }

  /**
   * Batch process entities for indexing with dynamic batch sizing.
   */
  public function batchProcessEntities(array $entities, callable $callback, int $batch_size = null): array {
    $results = [
      'processed' => 0,
      'skipped' => 0,
      'errors' => 0,
      'total' => count($entities),
    ];

    // Group entities by complexity for optimal batch sizing
    $entity_groups = $this->groupEntitiesByComplexity($entities);
    
    foreach ($entity_groups as $complexity => $group_entities) {
      $optimal_batch_size = $batch_size ?? $this->getOptimalBatchSizeForComplexity($complexity);
      $chunks = array_chunk($group_entities, $optimal_batch_size);
      
      foreach ($chunks as $chunk) {
        foreach ($chunk as $entity) {
          try {
            if ($this->entityHasComponentFields($entity)) {
              $callback($entity);
              $results['processed']++;
            } else {
              $results['skipped']++;
            }
          } catch (\Exception $e) {
            $results['errors']++;
            \Drupal::logger('component_search')->error('Error processing entity @id: @error', [
              '@id' => $entity->id(),
              '@error' => $e->getMessage(),
            ]);
          }
        }
      }
    }

    return $results;
  }

  /**
   * Group entities by complexity for batch processing.
   */
  protected function groupEntitiesByComplexity(array $entities): array {
    $groups = ['low' => [], 'medium' => [], 'high' => []];
    
    foreach ($entities as $entity) {
      $component_count = $this->getEntityComponentCount($entity);
      
      if ($component_count <= 3) {
        $groups['low'][] = $entity;
      } elseif ($component_count <= 8) {
        $groups['medium'][] = $entity;
      } else {
        $groups['high'][] = $entity;
      }
    }
    
    return array_filter($groups);
  }

  /**
   * Get optimal batch size for complexity level.
   */
  protected function getOptimalBatchSizeForComplexity(string $complexity): int {
    $base_size = $this->configFactory->get('component_search.settings')
      ->get('performance_settings.batch_size') ?? 50;
      
    switch ($complexity) {
      case 'high':
        return max(5, intval($base_size / 4));
      case 'medium':
        return max(15, intval($base_size / 2));
      case 'low':
      default:
        return $base_size;
    }
  }

  /**
   * Get component field usage statistics across the site.
   */
  public function getGlobalComponentStats(): array {
    $stats = [
      'total_entities' => 0,
      'entities_with_components' => 0,
      'total_components' => 0,
      'component_types' => [],
      'entity_types' => [],
    ];

    $entity_types = ['node', 'taxonomy_term', 'media', 'user', 'paragraph'];
    
    foreach ($entity_types as $entity_type_id) {
      try {
        $entities = $this->getEntitiesWithComponentFields([$entity_type_id]);
        
        $entity_count = 0;
        $component_count = 0;
        $types_found = [];
        
        foreach ($entities as $entity) {
          $entity_stats = $this->getEntityComponentStats($entity);
          
          if ($entity_stats['total_components'] > 0) {
            $entity_count++;
            $component_count += $entity_stats['total_components'];
            $types_found = array_merge($types_found, $entity_stats['component_types']);
          }
        }
        
        if ($entity_count > 0) {
          $stats['entity_types'][$entity_type_id] = [
            'entities' => $entity_count,
            'components' => $component_count,
            'types' => array_unique($types_found),
          ];
          
          $stats['entities_with_components'] += $entity_count;
          $stats['total_components'] += $component_count;
          $stats['component_types'] = array_unique(array_merge($stats['component_types'], $types_found));
        }
        
      } catch (\Exception $e) {
        \Drupal::logger('component_search')->warning('Error getting stats for entity type @type: @error', [
          '@type' => $entity_type_id,
          '@error' => $e->getMessage(),
        ]);
      }
    }

    return $stats;
  }

  /**
   * Validate entity for component search indexing.
   */
  public function validateEntityForIndexing(EntityInterface $entity): array {
    $validation = [
      'valid' => TRUE,
      'issues' => [],
      'warnings' => [],
    ];

    try {
      // Check if entity is published (for nodes)
      if ($entity->getEntityTypeId() === 'node' && method_exists($entity, 'isPublished')) {
        if (!$entity->isPublished()) {
          $validation['warnings'][] = 'Entity is not published';
        }
      }

      // Check for component fields
      if (!$this->entityHasComponentFields($entity)) {
        $validation['issues'][] = 'Entity has no component fields with content';
        $validation['valid'] = FALSE;
      }

      // Check content extraction
      $content = $this->contentExtractor->extractSearchableContent($entity);
      if (empty($content)) {
        $validation['warnings'][] = 'No searchable content extracted from components';
      }

      // Check for very large content
      if (strlen($content) > 100000) {
        $validation['warnings'][] = 'Extracted content is very large (' . strlen($content) . ' characters)';
      }

    } catch (\Exception $e) {
      $validation['valid'] = FALSE;
      $validation['issues'][] = 'Error validating entity: ' . $e->getMessage();
    }

    return $validation;
  }

  /**
   * Get recommended indexing settings for the current site.
   */
  public function getRecommendedSettings(): array {
    $stats = $this->getGlobalComponentStats();
    $recommendations = [];

    // Batch size recommendation
    if ($stats['entities_with_components'] > 1000) {
      $recommendations['batch_size'] = 25; // Smaller batches for large sites
    } elseif ($stats['entities_with_components'] > 100) {
      $recommendations['batch_size'] = 50; // Medium batches
    } else {
      $recommendations['batch_size'] = 100; // Larger batches for small sites
    }

    // Caching recommendation
    $recommendations['enable_caching'] = $stats['entities_with_components'] > 50;

    // Reference extraction recommendation
    $recommendations['extract_references'] = $stats['total_components'] < 500; // Disable for very large sites

    // Content boost recommendation
    if (count($stats['component_types']) > 10) {
      $recommendations['content_boost'] = 1.2; // Lower boost for sites with many component types
    } else {
      $recommendations['content_boost'] = 1.5; // Higher boost for focused sites
    }

    return $recommendations;
  }

  /**
   * Clean up orphaned search index entries.
   */
  public function cleanupOrphanedEntries(): int {
    $cleaned = 0;
    
    try {
      // This would need to be implemented based on specific search backend
      // For now, we'll just log that cleanup was requested
      \Drupal::logger('component_search')->info('Search index cleanup requested');
      
      // TODO: Implement actual cleanup logic for different search backends
      
    } catch (\Exception $e) {
      \Drupal::logger('component_search')->error('Error during search index cleanup: @error', [
        '@error' => $e->getMessage(),
      ]);
    }
    
    return $cleaned;
  }
}