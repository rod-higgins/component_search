<?php

namespace Drupal\component_search\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\search\SearchIndexInterface;

/**
 * Service to manage component search operations.
 */
class ComponentSearchManager {

  protected ComponentContentExtractor $contentExtractor;
  protected ?SearchIndexInterface $searchIndex;
  protected ConfigFactoryInterface $configFactory;
  protected ModuleHandlerInterface $moduleHandler;

  public function __construct(
    ComponentContentExtractor $content_extractor,
    ?SearchIndexInterface $search_index,
    ConfigFactoryInterface $config_factory,
    ModuleHandlerInterface $module_handler
  ) {
    $this->contentExtractor = $content_extractor;
    $this->searchIndex = $search_index;
    $this->configFactory = $config_factory;
    $this->moduleHandler = $module_handler;
  }

  /**
   * Handle entity changes for search integration.
   */
  public function handleEntityChange(EntityInterface $entity, string $operation): void {
    $config = $this->configFactory->get('component_search.settings');

    // Handle Drupal core search - only if search module is enabled and service available
    if ($config->get('enable_core_search') && 
        $this->searchIndex && 
        $this->moduleHandler->moduleExists('search')) {
      $this->updateCoreSearchIndex($entity, $operation);
    }

    // Handle Search API
    if ($config->get('enable_search_api') && $this->moduleHandler->moduleExists('search_api')) {
      $this->updateSearchApiIndexes($entity, $operation);
    }
  }

  /**
   * Update Drupal core search index.
   */
  protected function updateCoreSearchIndex(EntityInterface $entity, string $operation): void {
    // Additional null check for safety
    if (!$this->searchIndex) {
      return;
    }

    try {
      switch ($operation) {
        case 'insert':
        case 'update':
          // Mark entity for reindexing
          if (method_exists($this->searchIndex, 'markForReindex')) {
            $this->searchIndex->markForReindex($entity->getEntityTypeId(), $entity->id());
          }
          break;

        case 'delete':
          // Remove from index
          if (method_exists($this->searchIndex, 'clear')) {
            $this->searchIndex->clear($entity->getEntityTypeId(), $entity->id());
          }
          break;
      }
    } catch (\Exception $e) {
      \Drupal::logger('component_search')->warning('Error updating core search index: @error', [
        '@error' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Update Search API indexes.
   */
  protected function updateSearchApiIndexes(EntityInterface $entity, string $operation): void {
    try {
      $index_storage = \Drupal::entityTypeManager()->getStorage('search_api_index');
      $indexes = $index_storage->loadMultiple();

      foreach ($indexes as $index) {
        if (!$index->status()) {
          continue;
        }

        $datasource_id = 'entity:' . $entity->getEntityTypeId();
        if (!$index->isValidDatasource($datasource_id)) {
          continue;
        }

        switch ($operation) {
          case 'insert':
          case 'update':
            $index->trackItemsUpdated($datasource_id, [$entity->id()]);
            break;

          case 'delete':
            $index->trackItemsDeleted($datasource_id, [$entity->id()]);
            break;
        }
      }
    } catch (\Exception $e) {
      \Drupal::logger('component_search')->warning('Error updating Search API indexes: @error', [
        '@error' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Bulk reindex entities with component fields.
   */
  public function bulkReindex(array $entity_types = ['node']): array {
    $results = ['processed' => 0, 'errors' => 0];
    $config = $this->configFactory->get('component_search.settings');
    $batch_size = $config->get('performance_settings.batch_size') ?? 50;

    foreach ($entity_types as $entity_type) {
      try {
        $storage = \Drupal::entityTypeManager()->getStorage($entity_type);
        
        // Find entities with component fields
        $query = $storage->getQuery()
          ->accessCheck(FALSE)
          ->range(0, $batch_size);

        $entity_ids = $query->execute();

        foreach ($entity_ids as $entity_id) {
          try {
            $entity = $storage->load($entity_id);
            if ($entity && $this->hasComponentFields($entity)) {
              $this->handleEntityChange($entity, 'update');
              $results['processed']++;
            }
          } catch (\Exception $e) {
            $results['errors']++;
            \Drupal::logger('component_search')->warning('Error reindexing entity @id: @error', [
              '@id' => $entity_id,
              '@error' => $e->getMessage(),
            ]);
          }
        }
      } catch (\Exception $e) {
        \Drupal::logger('component_search')->error('Error in bulk reindex for @type: @error', [
          '@type' => $entity_type,
          '@error' => $e->getMessage(),
        ]);
      }
    }

    return $results;
  }

  /**
   * Check if entity has component fields.
   */
  protected function hasComponentFields(EntityInterface $entity): bool {
    foreach ($entity->getFieldDefinitions() as $field_definition) {
      if ($field_definition->getType() === 'component_field') {
        $field_values = $entity->get($field_definition->getName());
        if (!$field_values->isEmpty()) {
          return TRUE;
        }
      }
    }
    return FALSE;
  }

  /**
   * Get search statistics.
   */
  public function getSearchStatistics(): array {
    $stats = [
      'total_entities_with_components' => 0,
      'total_components_indexed' => 0,
      'component_types' => [],
    ];

    try {
      // Count entities with component fields
      $entity_types = ['node', 'taxonomy_term', 'media'];
      
      foreach ($entity_types as $entity_type) {
        $storage = \Drupal::entityTypeManager()->getStorage($entity_type);
        $query = $storage->getQuery()->accessCheck(FALSE);
        
        // This is a simplified count - in practice you'd need to query for specific field names
        $count = $query->count()->execute();
        $stats['total_entities_with_components'] += $count;
      }

      // Get component type usage statistics
      if (\Drupal::hasService('component_field.discovery')) {
        $discovery = \Drupal::service('component_field.discovery');
        $components = $discovery->discoverComponents();
        
        foreach ($components as $component_type => $component_info) {
          $stats['component_types'][$component_type] = [
            'label' => $component_info['label'] ?? $component_type,
            'usage_count' => 0, // Would need to be calculated from actual data
          ];
        }
      }

    } catch (\Exception $e) {
      \Drupal::logger('component_search')->warning('Error getting search statistics: @error', [
        '@error' => $e->getMessage(),
      ]);
    }

    return $stats;
  }
}