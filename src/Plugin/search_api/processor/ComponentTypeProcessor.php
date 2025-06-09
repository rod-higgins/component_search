<?php

namespace Drupal\component_search\Plugin\search_api\processor;

use Drupal\Core\Form\FormStateInterface;
use Drupal\search_api\Datasource\DatasourceInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Processor\ProcessorPluginBase;
use Drupal\search_api\Processor\ProcessorProperty;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\component_field\Service\ComponentDiscovery;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Indexes component types and provides faceting capabilities.
 *
 * @SearchApiProcessor(
 *   id = "component_type",
 *   label = @Translation("Component Type Processor"),
 *   description = @Translation("Indexes component types for filtering and faceting, with enhanced metadata"),
 *   stages = {
 *     "add_properties" = 0,
 *   },
 *   locked = false,
 *   hidden = false,
 * )
 */
class ComponentTypeProcessor extends ProcessorPluginBase implements ContainerFactoryPluginInterface {

  /**
   * The component discovery service.
   */
  protected ComponentDiscovery $componentDiscovery;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    ComponentDiscovery $component_discovery
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->componentDiscovery = $component_discovery;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('component_field.discovery')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getPropertyDefinitions(DatasourceInterface $datasource = NULL) {
    $properties = [];

    if (!$datasource) {
      // Component types field
      $properties['component_types'] = new ProcessorProperty([
        'label' => $this->t('Component Types'),
        'description' => $this->t('Types of components used in the entity'),
        'type' => 'string',
        'processor_id' => $this->getPluginId(),
        'is_list' => TRUE,
      ]);

      // Component type labels (human-readable)
      $properties['component_type_labels'] = new ProcessorProperty([
        'label' => $this->t('Component Type Labels'),
        'description' => $this->t('Human-readable labels of component types'),
        'type' => 'string',
        'processor_id' => $this->getPluginId(),
        'is_list' => TRUE,
      ]);

      // Component categories (if components have categories)
      $properties['component_categories'] = new ProcessorProperty([
        'label' => $this->t('Component Categories'),
        'description' => $this->t('Categories of components used'),
        'type' => 'string',
        'processor_id' => $this->getPluginId(),
        'is_list' => TRUE,
      ]);

      // Component count
      $properties['component_count'] = new ProcessorProperty([
        'label' => $this->t('Component Count'),
        'description' => $this->t('Total number of components in the entity'),
        'type' => 'integer',
        'processor_id' => $this->getPluginId(),
      ]);

      // Has specific component types (for boolean filtering)
      if ($this->configuration['add_boolean_fields'] ?? FALSE) {
        $components = $this->componentDiscovery->discoverComponents();
        foreach ($components as $component_type => $component_info) {
          $properties["has_component_{$component_type}"] = new ProcessorProperty([
            'label' => $this->t('Has @type Component', ['@type' => $component_info['label'] ?? $component_type]),
            'description' => $this->t('Whether the entity contains a @type component', ['@type' => $component_info['label'] ?? $component_type]),
            'type' => 'boolean',
            'processor_id' => $this->getPluginId(),
          ]);
        }
      }
    }

    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public function addFieldValues(ItemInterface $item) {
    try {
      $entity = $item->getOriginalObject()->getValue();
      
      if (!$entity) {
        return;
      }

      // Check if entity has component fields
      $component_data = $this->extractComponentData($entity);
      
      if (empty($component_data)) {
        return;
      }

      $fields = $item->getFields(FALSE);
      
      // Add component types
      if (isset($fields['component_types'])) {
        foreach ($component_data['types'] as $type) {
          $fields['component_types']->addValue($type);
        }
      }

      // Add component type labels
      if (isset($fields['component_type_labels'])) {
        foreach ($component_data['labels'] as $label) {
          $fields['component_type_labels']->addValue($label);
        }
      }

      // Add component categories
      if (isset($fields['component_categories'])) {
        foreach ($component_data['categories'] as $category) {
          $fields['component_categories']->addValue($category);
        }
      }

      // Add component count
      if (isset($fields['component_count'])) {
        $fields['component_count']->addValue($component_data['count']);
      }

      // Add boolean fields for specific component types
      if ($this->configuration['add_boolean_fields'] ?? FALSE) {
        foreach ($component_data['types'] as $type) {
          $field_name = "has_component_{$type}";
          if (isset($fields[$field_name])) {
            $fields[$field_name]->addValue(TRUE);
          }
        }
      }

    } catch (\Exception $e) {
      \Drupal::logger('component_search')->error(
        'Error in ComponentTypeProcessor: @error', [
          '@error' => $e->getMessage(),
        ]
      );
    }
  }

  /**
   * Extract component data from entity.
   */
  protected function extractComponentData($entity): array {
    $data = [
      'types' => [],
      'labels' => [],
      'categories' => [],
      'count' => 0,
    ];

    try {
      $components = $this->componentDiscovery->discoverComponents();
      
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
          
          if (empty($component_type)) {
            continue;
          }

          $data['count']++;
          
          if (!in_array($component_type, $data['types'])) {
            $data['types'][] = $component_type;
            
            // Add label if available
            if (isset($components[$component_type]['label'])) {
              $data['labels'][] = $components[$component_type]['label'];
            }
            
            // Add category if available
            $category = $this->getComponentCategory($component_type, $components[$component_type] ?? []);
            if ($category && !in_array($category, $data['categories'])) {
              $data['categories'][] = $category;
            }
          }
        }
      }
    } catch (\Exception $e) {
      \Drupal::logger('component_search')->warning('Error extracting component data: @error', [
        '@error' => $e->getMessage(),
      ]);
    }

    return $data;
  }

  /**
   * Determine component category based on type and metadata.
   */
  protected function getComponentCategory(string $component_type, array $component_info): ?string {
    // Check if category is explicitly defined in component metadata
    if (!empty($component_info['metadata']['category'])) {
      return $component_info['metadata']['category'];
    }

    // Infer category from component type name
    $type_lower = strtolower($component_type);
    
    if (strpos($type_lower, 'hero') !== FALSE || strpos($type_lower, 'banner') !== FALSE) {
      return 'Hero';
    }
    
    if (strpos($type_lower, 'card') !== FALSE || strpos($type_lower, 'tile') !== FALSE) {
      return 'Content';
    }
    
    if (strpos($type_lower, 'button') !== FALSE || strpos($type_lower, 'cta') !== FALSE) {
      return 'Interactive';
    }
    
    if (strpos($type_lower, 'nav') !== FALSE || strpos($type_lower, 'menu') !== FALSE) {
      return 'Navigation';
    }
    
    if (strpos($type_lower, 'footer') !== FALSE || strpos($type_lower, 'header') !== FALSE) {
      return 'Layout';
    }
    
    if (strpos($type_lower, 'form') !== FALSE || strpos($type_lower, 'input') !== FALSE) {
      return 'Forms';
    }
    
    if (strpos($type_lower, 'media') !== FALSE || strpos($type_lower, 'image') !== FALSE || strpos($type_lower, 'video') !== FALSE) {
      return 'Media';
    }

    return 'General';
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['add_boolean_fields'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Add boolean fields for each component type'),
      '#description' => $this->t('Creates "has_component_[type]" boolean fields for precise filtering. Warning: This can create many fields if you have many component types.'),
      '#default_value' => $this->configuration['add_boolean_fields'] ?? FALSE,
    ];

    $form['enable_categories'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable component categories'),
      '#description' => $this->t('Automatically categorize components based on their type and metadata.'),
      '#default_value' => $this->configuration['enable_categories'] ?? TRUE,
    ];

    $form['category_mapping'] = [
      '#type' => 'details',
      '#title' => $this->t('Custom Category Mapping'),
      '#description' => $this->t('Define custom categories for component types. Format: component_type|Category Name, one per line.'),
      '#open' => FALSE,
      '#states' => [
        'visible' => [
          ':input[name="processors[component_type][settings][enable_categories]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['category_mapping']['mapping'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Category mapping'),
      '#default_value' => $this->configuration['category_mapping'] ?? '',
      '#description' => $this->t('Example:<br>hero_banner|Hero Components<br>product_card|Content Cards<br>contact_form|Forms'),
      '#rows' => 6,
    ];

    // Show current component types
    $form['discovered_components'] = [
      '#type' => 'details',
      '#title' => $this->t('Discovered Components'),
      '#description' => $this->t('Currently discovered component types that will be indexed.'),
      '#open' => FALSE,
    ];

    try {
      $components = $this->componentDiscovery->discoverComponents();
      
      if (!empty($components)) {
        $rows = [];
        foreach ($components as $component_type => $component_info) {
          $category = $this->getComponentCategory($component_type, $component_info);
          $rows[] = [
            'type' => $component_type,
            'label' => $component_info['label'] ?? $component_type,
            'category' => $category ?: 'General',
          ];
        }

        $form['discovered_components']['table'] = [
          '#type' => 'table',
          '#header' => [
            $this->t('Component Type'),
            $this->t('Label'),
            $this->t('Category'),
          ],
          '#rows' => $rows,
          '#empty' => $this->t('No components discovered.'),
        ];
      } else {
        $form['discovered_components']['empty'] = [
          '#markup' => '<p>' . $this->t('No components discovered. Make sure the Component Field module is enabled and components are available.') . '</p>',
        ];
      }
    } catch (\Exception $e) {
      $form['discovered_components']['error'] = [
        '#markup' => '<p class="color-error">' . $this->t('Error loading components: @error', ['@error' => $e->getMessage()]) . '</p>',
      ];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::validateConfigurationForm($form, $form_state);

    // Validate category mapping format
    $mapping = $form_state->getValue(['processors', $this->getPluginId(), 'settings', 'category_mapping', 'mapping']);
    if (!empty($mapping)) {
      $lines = array_filter(array_map('trim', explode("\n", $mapping)));
      foreach ($lines as $line) {
        if (strpos($line, '|') === FALSE) {
          $form_state->setError(
            $form['category_mapping']['mapping'],
            $this->t('Invalid mapping format. Each line must contain "component_type|Category Name".')
          );
          break;
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);

    // Process category mapping
    $mapping = $form_state->getValue(['processors', $this->getPluginId(), 'settings', 'category_mapping', 'mapping']);
    if (!empty($mapping)) {
      $processed_mapping = [];
      $lines = array_filter(array_map('trim', explode("\n", $mapping)));
      foreach ($lines as $line) {
        if (strpos($line, '|') !== FALSE) {
          [$type, $category] = array_map('trim', explode('|', $line, 2));
          if (!empty($type) && !empty($category)) {
            $processed_mapping[$type] = $category;
          }
        }
      }
      $this->configuration['category_mapping_processed'] = $processed_mapping;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'add_boolean_fields' => FALSE,
      'enable_categories' => TRUE,
      'category_mapping' => '',
      'category_mapping_processed' => [],
    ] + parent::defaultConfiguration();
  }
}