<?php

namespace Drupal\component_search\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\search\SearchIndexInterface;

/**
 * Service to manage component search operations.
 */
class ComponentSearchManager {

  protected ComponentContentExtractor $contentExtractor;
  protected ?SearchIndexInterface $searchIndex;
  protected ConfigFactoryInterface $configFactory;
  protected ModuleHandlerInterface $moduleHandler;
  protected LoggerChannelFactoryInterface $loggerFactory;

  public function __construct(
    ComponentContentExtractor $content_extractor,
    ConfigFactoryInterface $config_factory,
    ModuleHandlerInterface $module_handler,
    LoggerChannelFactoryInterface $logger_factory,
    SearchIndexInterface $search_index = NULL
  ) {
    $this->contentExtractor = $content_extractor;
    $this->configFactory = $config_factory;
    $this->moduleHandler = $module_handler;
    $this->loggerFactory = $logger_factory;
    $this->searchIndex = $search_index;
  }

  /**
   * Handle entity changes for search integration with performance optimizations.
   */
  public function handleEntityChange(EntityInterface $entity, string $operation): void {
    if (!$this->hasComponentFields($entity)) {
      return;
    }

    // For updates, check if component content actually changed
    if ($operation === 'update' && !$this->hasComponentContentChanged($entity)) {
      return; // Skip processing if no component changes
    }

    $config = $this->configFactory->get('component_search.settings');
    
    // Always queue for background processing to avoid blocking
    if ($config->get('performance_settings.enable_background_indexing') ?? true) {
      $this->queueEntityForIndexing($entity, $operation);
      return;
    }
    
    // Fallback to immediate processing only if explicitly configured
    $this->processEntityImmediately($entity, $operation);
  }

  /**
   * Process entity immediately (synchronous processing).
   */
  protected function processEntityImmediately(EntityInterface $entity, string $operation): void {
    $config = $this->configFactory->get('component_search.settings');

    // Handle Drupal core search
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
   * Check if component field content has actually changed.
   */
  protected function hasComponentContentChanged(EntityInterface $entity): bool {
    if ($entity->isNew()) {
      return true;
    }
    
    $original = $entity->original ?? null;
    if (!$original) {
      return true;
    }
    
    return $this->compareComponentFields($entity, $original);
  }

  /**
   * Compare component fields between current and original entity.
   */
  protected function compareComponentFields(EntityInterface $entity, EntityInterface $original): bool {
    foreach ($entity->getFieldDefinitions() as $field_name => $field_definition) {
      if ($field_definition->getType() !== 'component_field') {
        continue;
      }
      
      $current_values = $entity->get($field_name);
      $original_values = $original->get($field_name);
      
      // Compare field values
      if ($current_values->count() !== $original_values->count()) {
        return true;
      }
      
      for ($i = 0; $i < $current_values->count(); $i++) {
        $current_config = $current_values[$i]->getConfiguration();
        $original_config = $original_values[$i]->getConfiguration();
        
        if (serialize($current_config) !== serialize($original_config)) {
          return true;
        }
      }
    }
    
    return false;
  }

  /**
   * Update Drupal core search index.
   */
  protected function updateCoreSearchIndex(EntityInterface $entity, string $operation): void {
    if (!$this->searchIndex) {
      return;
    }

    try {
      switch ($operation) {
        case 'insert':
        case 'update':
          // Mark entity for reindexing
          $this->searchIndex->markForReindex($entity->getEntityTypeId(), $entity->id());
          break;

        case 'delete':
          // Remove from index
          $this->searchIndex->clear($entity->getEntityTypeId(), $entity->id());
          break;
      }
    } catch (\Exception $e) {
      $this->loggerFactory->get('component_search')->warning('Error updating core search index: @error', [
        '@error' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Update Search API indexes.
   */
  protected function updateSearchApiIndexes(EntityInterface $entity, string $operation): void {
    try {
      if (!\Drupal::hasService('entity_type.manager')) {
        return;
      }

      $entity_type_manager = \Drupal::entityTypeManager();
      if (!$entity_type_manager->hasDefinition('search_api_index')) {
        return;
      }

      $index_storage = $entity_type_manager->getStorage('search_api_index');
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
      $this->loggerFactory->get('component_search')->warning('Error updating Search API indexes: @error', [
        '@error' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Bulk reindex entities with component fields using dynamic batch sizing.
   */
  public function bulkReindex(array $entity_types = ['node']): array {
    $results = ['processed' => 0, 'errors' => 0];
    
    foreach ($entity_types as $entity_type) {
      try {
        if (!\Drupal::hasService('entity_type.manager')) {
          continue;
        }

        $entity_type_manager = \Drupal::entityTypeManager();
        if (!$entity_type_manager->hasDefinition($entity_type)) {
          continue;
        }

        $storage = $entity_type_manager->getStorage($entity_type);
        
        // Process in smaller initial batches to determine complexity
        $initial_batch_size = 10;
        $query = $storage->getQuery()
          ->accessCheck(FALSE)
          ->range(0, $initial_batch_size);

        $entity_ids = $query->execute();
        $entities = $storage->loadMultiple($entity_ids);
        
        // Use dynamic batch sizing based on actual entities
        if (\Drupal::hasService('component_search.indexing_helper')) {
          $helper = \Drupal::service('component_search.indexing_helper');
          $batch_results = $helper->batchProcessEntities(
            array_values($entities), 
            function($entity) {
              $this->handleEntityChange($entity, 'update');
            }
          );
          
          $results['processed'] += $batch_results['processed'];
          $results['errors'] += $batch_results['errors'];
        }
        
      } catch (\Exception $e) {
        $this->loggerFactory->get('component_search')->error('Error in bulk reindex for @type: @error', [
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
    try {
      foreach ($entity->getFieldDefinitions() as $field_definition) {
        if ($field_definition->getType() === 'component_field') {
          $field_values = $entity->get($field_definition->getName());
          if (!$field_values->isEmpty()) {
            return TRUE;
          }
        }
      }
    } catch (\Exception $e) {
      $this->loggerFactory->get('component_search')->warning('Error checking component fields for entity @id: @error', [
        '@id' => $entity->id(),
        '@error' => $e->getMessage(),
      ]);
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
        if (!\Drupal::hasService('entity_type.manager')) {
          continue;
        }

        $entity_type_manager = \Drupal::entityTypeManager();
        if (!$entity_type_manager->hasDefinition($entity_type)) {
          continue;
        }

        $storage = $entity_type_manager->getStorage($entity_type);
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
      $this->loggerFactory->get('component_search')->warning('Error getting search statistics: @error', [
        '@error' => $e->getMessage(),
      ]);
    }

    return $stats;
  }

  /**
   * Queue entity for background indexing.
   */
  public function queueEntityForIndexing(EntityInterface $entity, string $operation): void {
    try {
      if (!\Drupal::hasService('queue')) {
        return;
      }

      $queue = \Drupal::queue('component_search_index');
      $queue->createItem([
        'entity_type' => $entity->getEntityTypeId(),
        'entity_id' => $entity->id(),
        'operation' => $operation,
      ]);
    } catch (\Exception $e) {
      $this->loggerFactory->get('component_search')->warning('Error queueing entity for indexing: @error', [
        '@error' => $e->getMessage(),
      ]);
    }
  }
}