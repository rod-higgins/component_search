<?php

namespace Drupal\component_search\EventSubscriber;

use Drupal\Core\Entity\EntityInterface;
use Drupal\component_search\Service\ComponentSearchManager;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\Core\Entity\EntityEvents;
use Drupal\Core\Entity\EntityEvent;

/**
 * Event subscriber for component search operations.
 */
class ComponentSearchSubscriber implements EventSubscriberInterface {

  protected ComponentSearchManager $searchManager;

  public function __construct(ComponentSearchManager $search_manager) {
    $this->searchManager = $search_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      EntityEvents::INSERT => 'onEntityInsert',
      EntityEvents::UPDATE => 'onEntityUpdate', 
      EntityEvents::DELETE => 'onEntityDelete',
    ];
  }

  /**
   * Handle entity insert events.
   */
  public function onEntityInsert(EntityEvent $event) {
    $this->handleEntityEvent($event->getEntity(), 'insert');
  }

  /**
   * Handle entity update events.
   */
  public function onEntityUpdate(EntityEvent $event) {
    $this->handleEntityEvent($event->getEntity(), 'update');
  }

  /**
   * Handle entity delete events.
   */
  public function onEntityDelete(EntityEvent $event) {
    $this->handleEntityEvent($event->getEntity(), 'delete');
  }

  /**
   * Process entity events for component search.
   */
  protected function handleEntityEvent(EntityInterface $entity, string $operation) {
    // Check if entity has component fields
    $has_component_fields = FALSE;
    foreach ($entity->getFieldDefinitions() as $field_definition) {
      if ($field_definition->getType() === 'component_field') {
        $has_component_fields = TRUE;
        break;
      }
    }

    if ($has_component_fields) {
      $this->searchManager->handleEntityChange($entity, $operation);
    }
  }
}