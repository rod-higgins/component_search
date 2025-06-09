<?php

namespace Drupal\component_search\Plugin\QueueWorker;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\component_search\Service\ComponentSearchManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Processes component search indexing queue items.
 *
 * @QueueWorker(
 *   id = "component_search_index",
 *   title = @Translation("Component Search Index Worker"),
 *   cron = {"time" = 30}
 * )
 */
class ComponentSearchIndexWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The component search manager.
   */
  protected ComponentSearchManager $searchManager;

  /**
   * Constructs a new ComponentSearchIndexWorker.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    EntityTypeManagerInterface $entity_type_manager,
    ComponentSearchManager $search_manager
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
    $this->searchManager = $search_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('component_search.search_manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data) {
    if (!isset($data['entity_type']) || !isset($data['entity_id']) || !isset($data['operation'])) {
      throw new \InvalidArgumentException('Queue item must contain entity_type, entity_id, and operation.');
    }

    try {
      $storage = $this->entityTypeManager->getStorage($data['entity_type']);
      $entity = $storage->load($data['entity_id']);

      if (!$entity) {
        // Entity may have been deleted, which is fine for delete operations
        if ($data['operation'] !== 'delete') {
          \Drupal::logger('component_search')->warning('Entity @type:@id not found for indexing.', [
            '@type' => $data['entity_type'],
            '@id' => $data['entity_id'],
          ]);
        }
        return;
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

      if (!$has_component_fields && $data['operation'] !== 'delete') {
        return;
      }

      // Process the entity through the search manager
      $this->searchManager->handleEntityChange($entity, $data['operation']);

      \Drupal::logger('component_search')->debug('Processed @operation for entity @type:@id', [
        '@operation' => $data['operation'],
        '@type' => $data['entity_type'],
        '@id' => $data['entity_id'],
      ]);

    } catch (\Exception $e) {
      \Drupal::logger('component_search')->error('Error processing queue item for entity @type:@id: @error', [
        '@type' => $data['entity_type'] ?? 'unknown',
        '@id' => $data['entity_id'] ?? 'unknown',
        '@error' => $e->getMessage(),
      ]);
      
      // Re-throw to mark the queue item as failed
      throw $e;
    }
  }
}