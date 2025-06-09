<?php

namespace Drupal\component_search\Plugin\search_api\processor;

use Drupal\Core\Form\FormStateInterface;
use Drupal\search_api\Datasource\DatasourceInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Processor\ProcessorPluginBase;
use Drupal\search_api\Processor\ProcessorProperty;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\component_search\Service\ComponentContentExtractor;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Extracts searchable content from Component Field configurations.
 *
 * @SearchApiProcessor(
 *   id = "component_content",
 *   label = @Translation("Component Content Extractor"),
 *   description = @Translation("Extracts and indexes searchable content from Component Field components with configurable weights"),
 *   stages = {
 *     "add_properties" = 0,
 *   },
 *   locked = false,
 *   hidden = false,
 * )
 */
class ComponentContentProcessor extends ProcessorPluginBase implements ContainerFactoryPluginInterface {

  protected ComponentContentExtractor $contentExtractor;

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    ComponentContentExtractor $content_extractor
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->contentExtractor = $content_extractor;
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('component_search.content_extractor')
    );
  }

  public function getPropertyDefinitions(DatasourceInterface $datasource = NULL) {
    $properties = [];

    if (!$datasource) {
      // Main content field
      $properties['component_content'] = new ProcessorProperty([
        'label' => $this->t('Component Content'),
        'description' => $this->t('Searchable text content extracted from Component Field components'),
        'type' => 'text',
        'processor_id' => $this->getPluginId(),
        'is_list' => TRUE,
      ]);

      // Title content field (higher weight)
      $properties['component_titles'] = new ProcessorProperty([
        'label' => $this->t('Component Titles'),
        'description' => $this->t('Title and heading content from components'),
        'type' => 'text',
        'processor_id' => $this->getPluginId(),
        'is_list' => TRUE,
      ]);

      // Component types field
      $properties['component_types'] = new ProcessorProperty([
        'label' => $this->t('Component Types'),
        'description' => $this->t('Types of components used'),
        'type' => 'string',
        'processor_id' => $this->getPluginId(),
        'is_list' => TRUE,
      ]);

      // Referenced content field
      $properties['component_references'] = new ProcessorProperty([
        'label' => $this->t('Component References'),
        'description' => $this->t('Content from entities referenced by components'),
        'type' => 'text',
        'processor_id' => $this->getPluginId(),
        'is_list' => TRUE,
      ]);
    }

    return $properties;
  }

  public function addFieldValues(ItemInterface $item) {
    try {
      $entity = $item->getOriginalObject()->getValue();
      
      if (!$entity) {
        return;
      }

      // Check if entity has component fields
      $has_component_fields = FALSE;
      foreach ($entity->getFieldDefinitions() as $field_definition) {
        if ($field_definition->getType() === 'component_field') {
          $has_component_fields = TRUE;
          break;
        }
      }

      if (!$has_component_fields) {
        return;
      }

      // Extract content using the service
      $extracted_data = $this->contentExtractor->extractSearchApiContent($entity);
      
      // Add extracted content to search fields
      $fields = $item->getFields(FALSE);
      
      foreach ($extracted_data as $field_name => $values) {
        if (isset($fields[$field_name]) && !empty($values)) {
          foreach ((array)$values as $value) {
            if (!empty($value)) {
              $fields[$field_name]->addValue($value);
            }
          }
        }
      }

    } catch (\Exception $e) {
      \Drupal::logger('component_search')->error(
        'Error in ComponentContentProcessor: @error', [
          '@error' => $e->getMessage(),
        ]
      );
    }
  }

  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['extract_references'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Extract referenced entity content'),
      '#description' => $this->t('Include content from entities referenced by components (nodes, terms, media).'),
      '#default_value' => $this->configuration['extract_references'] ?? TRUE,
    ];

    $form['boost_titles'] = [
      '#type' => 'number',
      '#title' => $this->t('Title content boost'),
      '#description' => $this->t('Multiplier for title content weight (higher = more important).'),
      '#default_value' => $this->configuration['boost_titles'] ?? 2.0,
      '#min' => 0.1,
      '#max' => 10.0,
      '#step' => 0.1,
    ];

    $form['max_reference_depth'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum reference depth'),
      '#description' => $this->t('How deep to follow entity references for content extraction.'),
      '#default_value' => $this->configuration['max_reference_depth'] ?? 1,
      '#min' => 0,
      '#max' => 3,
    ];

    $form['component_weights'] = [
      '#type' => 'details',
      '#title' => $this->t('Component Type Weights'),
      '#description' => $this->t('Adjust the search importance of different component types.'),
      '#open' => FALSE,
    ];

    // Get available component types
    $discovery = \Drupal::service('component_field.discovery');
    $components = $discovery->discoverComponents();
    
    foreach ($components as $component_type => $component_info) {
      $form['component_weights'][$component_type] = [
        '#type' => 'number',
        '#title' => $component_info['label'] ?? $component_type,
        '#default_value' => $this->configuration['component_weights'][$component_type] ?? 1.0,
        '#min' => 0.0,
        '#max' => 5.0,
        '#step' => 0.1,
        '#size' => 10,
      ];
    }

    return $form;
  }

  public function defaultConfiguration() {
    return [
      'extract_references' => TRUE,
      'boost_titles' => 2.0,
      'max_reference_depth' => 1,
      'component_weights' => [],
    ] + parent::defaultConfiguration();
  }
}