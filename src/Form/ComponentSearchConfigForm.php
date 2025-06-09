<?php

namespace Drupal\component_search\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\component_field\Service\ComponentDiscovery;
use Drupal\component_search\Service\ComponentIndexingHelper;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configuration form for Component Search module.
 */
class ComponentSearchConfigForm extends ConfigFormBase {

  /**
   * Component discovery service.
   */
  protected ComponentDiscovery $componentDiscovery;

  /**
   * Module handler service.
   */
  protected ModuleHandlerInterface $moduleHandler;

  /**
   * Indexing helper service.
   */
  protected ComponentIndexingHelper $indexingHelper;

  /**
   * Constructor.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    ComponentDiscovery $component_discovery,
    ModuleHandlerInterface $module_handler,
    ComponentIndexingHelper $indexing_helper
  ) {
    parent::__construct($config_factory);
    $this->componentDiscovery = $component_discovery;
    $this->moduleHandler = $module_handler;
    $this->indexingHelper = $indexing_helper;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('component_field.discovery'),
      $container->get('module_handler'),
      $container->get('component_search.indexing_helper')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'component_search_config_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['component_search.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('component_search.settings');

    // Add status information
    $form['status'] = [
      '#type' => 'details',
      '#title' => $this->t('Current Status'),
      '#open' => TRUE,
    ];

    $stats = $this->indexingHelper->getGlobalComponentStats();
    $form['status']['statistics'] = [
      '#type' => 'item',
      '#title' => $this->t('Statistics'),
      '#markup' => $this->t('Entities with components: @entities<br>Total components: @components<br>Component types: @types', [
        '@entities' => $stats['entities_with_components'],
        '@components' => $stats['total_components'],
        '@types' => count($stats['component_types']),
      ]),
    ];

    $form['search_integration'] = [
      '#type' => 'details',
      '#title' => $this->t('Search Integration'),
      '#description' => $this->t('Configure which search systems to integrate with.'),
      '#open' => TRUE,
    ];

    $form['search_integration']['enable_core_search'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Drupal core search integration'),
      '#description' => $this->t('Add component content to the core search index.'),
      '#default_value' => $config->get('enable_core_search'),
    ];

    $search_api_available = $this->moduleHandler->moduleExists('search_api');
    $form['search_integration']['enable_search_api'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Search API integration'),
      '#description' => $search_api_available 
        ? $this->t('Add component content processors to Search API.')
        : $this->t('Search API module is not installed.'),
      '#default_value' => $config->get('enable_search_api') && $search_api_available,
      '#disabled' => !$search_api_available,
    ];

    if (!$search_api_available) {
      $form['search_integration']['search_api_info'] = [
        '#markup' => '<div class="messages messages--warning">' . 
          $this->t('Install the Search API module for advanced search features.') . 
          '</div>',
      ];
    }

    $form['search_integration']['enhance_search_terms'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enhance search terms'),
      '#description' => $this->t('Expand search queries with component-related synonyms.'),
      '#default_value' => $config->get('enhance_search_terms'),
    ];

    $form['extraction_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('Content Extraction'),
      '#description' => $this->t('Configure how content is extracted from components.'),
      '#open' => TRUE,
    ];

    $extraction_settings = $config->get('extraction_settings') ?: [];

    $form['extraction_settings']['extract_references'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Extract referenced entity content'),
      '#description' => $this->t('Include content from entities referenced by components.'),
      '#default_value' => $extraction_settings['extract_references'] ?? TRUE,
    ];

    $form['extraction_settings']['max_reference_depth'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum reference depth'),
      '#description' => $this->t('How deep to follow entity references (0 = no references, 1 = direct references only).'),
      '#default_value' => $extraction_settings['max_reference_depth'] ?? 1,
      '#min' => 0,
      '#max' => 3,
      '#states' => [
        'visible' => [
          ':input[name="extract_references"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['extraction_settings']['boost_titles'] = [
      '#type' => 'number',
      '#title' => $this->t('Title content boost multiplier'),
      '#description' => $this->t('How much more important title content is compared to regular content.'),
      '#default_value' => $extraction_settings['boost_titles'] ?? 2.0,
      '#min' => 0.1,
      '#max' => 10.0,
      '#step' => 0.1,
    ];

    $form['extraction_settings']['strip_html_tags'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Strip HTML tags'),
      '#description' => $this->t('Remove HTML markup from extracted content.'),
      '#default_value' => $extraction_settings['strip_html_tags'] ?? TRUE,
    ];

    $form['extraction_settings']['normalize_whitespace'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Normalize whitespace'),
      '#description' => $this->t('Convert multiple whitespace characters to single spaces.'),
      '#default_value' => $extraction_settings['normalize_whitespace'] ?? TRUE,
    ];

    $form['extraction_settings']['max_content_length'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum content length'),
      '#description' => $this->t('Maximum length of extracted content in characters (0 = no limit).'),
      '#default_value' => $extraction_settings['max_content_length'] ?? 50000,
      '#min' => 0,
      '#max' => 1000000,
    ];

    // Component type weights
    $form['component_weights'] = [
      '#type' => 'details',
      '#title' => $this->t('Component Type Weights'),
      '#description' => $this->t('Adjust the search importance of different component types. Higher values make content from that component type more important in search results.'),
      '#open' => FALSE,
    ];

    try {
      $components = $this->componentDiscovery->discoverComponents();
      $current_weights = $config->get('component_weights') ?: [];

      if (!empty($components)) {
        $form['component_weights']['weights_table'] = [
          '#type' => 'table',
          '#header' => [
            $this->t('Component Type'),
            $this->t('Weight'),
            $this->t('Description'),
          ],
          '#empty' => $this->t('No components found.'),
        ];

        foreach ($components as $component_type => $component_info) {
          $form['component_weights']['weights_table'][$component_type] = [
            'label' => [
              '#markup' => '<strong>' . ($component_info['label'] ?? $component_type) . '</strong><br><small><code>' . $component_type . '</code></small>',
            ],
            'weight' => [
              '#type' => 'number',
              '#default_value' => $current_weights[$component_type] ?? 1.0,
              '#min' => 0.0,
              '#max' => 5.0,
              '#step' => 0.1,
              '#size' => 8,
              '#title' => $this->t('Weight for @type', ['@type' => $component_type]),
              '#title_display' => 'invisible',
            ],
            'description' => [
              '#markup' => $component_info['description'] ?? $this->t('No description available.'),
            ],
          ];
        }

        // Add recommendation button
        $form['component_weights']['get_recommendations'] = [
          '#type' => 'submit',
          '#value' => $this->t('Get recommended weights'),
          '#submit' => ['::getRecommendedWeights'],
          '#ajax' => [
            'callback' => '::updateWeightsTable',
            'wrapper' => 'weights-table-wrapper',
          ],
        ];
      } else {
        $form['component_weights']['no_components'] = [
          '#markup' => '<p>' . $this->t('No components found. Make sure the Component Field module is enabled and components are discovered.') . '</p>',
        ];
      }
    } catch (\Exception $e) {
      $form['component_weights']['error'] = [
        '#markup' => '<p class="color-error">' . $this->t('Error loading components: @error', ['@error' => $e->getMessage()]) . '</p>',
      ];
    }

    $form['performance'] = [
      '#type' => 'details',
      '#title' => $this->t('Performance Settings'),
      '#description' => $this->t('Settings to optimize search performance.'),
      '#open' => FALSE,
    ];

    $performance_settings = $config->get('performance_settings') ?: [];

    $form['performance']['cache_extractions'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Cache content extractions'),
      '#description' => $this->t('Cache extracted content to improve performance. Clear caches after making changes.'),
      '#default_value' => $performance_settings['cache_extractions'] ?? TRUE,
    ];

    $form['performance']['cache_max_age'] = [
      '#type' => 'number',
      '#title' => $this->t('Cache maximum age (seconds)'),
      '#description' => $this->t('How long to keep cached extractions (86400 = 24 hours).'),
      '#default_value' => $performance_settings['cache_max_age'] ?? 86400,
      '#min' => 300,
      '#max' => 604800,
      '#states' => [
        'visible' => [
          ':input[name="cache_extractions"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['performance']['batch_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Batch processing size'),
      '#description' => $this->t('Number of entities to process at once during bulk operations.'),
      '#default_value' => $performance_settings['batch_size'] ?? 50,
      '#min' => 1,
      '#max' => 500,
    ];

    // Show recommended settings
    $recommendations = $this->indexingHelper->getRecommendedSettings();
    if (!empty($recommendations)) {
      $form['performance']['recommendations'] = [
        '#type' => 'details',
        '#title' => $this->t('Recommendations'),
        '#open' => FALSE,
      ];

      $rec_text = [];
      foreach ($recommendations as $key => $value) {
        $rec_text[] = $this->t('@key: @value', ['@key' => $key, '@value' => $value]);
      }

      $form['performance']['recommendations']['list'] = [
        '#markup' => '<ul><li>' . implode('</li><li>', $rec_text) . '</li></ul>',
      ];
    }

    $form['actions']['save'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save configuration'),
    ];

    $form['actions']['rebuild_indexes'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save and rebuild search indexes'),
      '#button_type' => 'primary',
      '#submit' => ['::submitForm', '::rebuildIndexes'],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    // Validate component weights
    if ($form_state->hasValue(['component_weights', 'weights_table'])) {
      $weights = $form_state->getValue(['component_weights', 'weights_table']);
      foreach ($weights as $component_type => $row) {
        $weight = $row['weight'];
        if (!is_numeric($weight) || $weight < 0 || $weight > 5) {
          $form_state->setError(
            $form['component_weights']['weights_table'][$component_type]['weight'],
            $this->t('Weight must be a number between 0 and 5.')
          );
        }
      }
    }

    // Validate cache settings
    $cache_enabled = $form_state->getValue('cache_extractions');
    $cache_max_age = $form_state->getValue('cache_max_age');
    
    if ($cache_enabled && $cache_max_age < 300) {
      $form_state->setError(
        $form['performance']['cache_max_age'],
        $this->t('Cache maximum age must be at least 300 seconds (5 minutes).')
      );
    }

    // Validate reference depth
    $extract_refs = $form_state->getValue('extract_references');
    $max_depth = $form_state->getValue('max_reference_depth');
    
    if ($extract_refs && $max_depth > 2) {
      $this->messenger()->addWarning($this->t('Reference depth greater than 2 may impact performance significantly.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('component_search.settings');

    // Save basic settings
    $config->set('enable_core_search', $form_state->getValue('enable_core_search'));
    $config->set('enable_search_api', $form_state->getValue('enable_search_api'));
    $config->set('enhance_search_terms', $form_state->getValue('enhance_search_terms'));

    // Save extraction settings
    $extraction_settings = [
      'extract_references' => $form_state->getValue('extract_references'),
      'max_reference_depth' => $form_state->getValue('max_reference_depth'),
      'boost_titles' => $form_state->getValue('boost_titles'),
      'strip_html_tags' => $form_state->getValue('strip_html_tags'),
      'normalize_whitespace' => $form_state->getValue('normalize_whitespace'),
      'max_content_length' => $form_state->getValue('max_content_length'),
    ];
    $config->set('extraction_settings', $extraction_settings);

    // Save performance settings
    $performance_settings = [
      'cache_extractions' => $form_state->getValue('cache_extractions'),
      'cache_max_age' => $form_state->getValue('cache_max_age'),
      'batch_size' => $form_state->getValue('batch_size'),
    ];
    $config->set('performance_settings', $performance_settings);

    // Save component weights
    $weights = [];
    if ($form_state->hasValue(['component_weights', 'weights_table'])) {
      $weights_table = $form_state->getValue(['component_weights', 'weights_table']);
      foreach ($weights_table as $component_type => $row) {
        $weights[$component_type] = (float) $row['weight'];
      }
    }
    $config->set('component_weights', $weights);

    $config->save();

    // Clear component search cache
    if (\Drupal::hasService('component_search.cache_manager')) {
      \Drupal::service('component_search.cache_manager')->invalidateAllCache();
    }

    parent::submitForm($form, $form_state);
  }

  /**
   * Submit handler to get recommended weights.
   */
  public function getRecommendedWeights(array &$form, FormStateInterface $form_state) {
    try {
      $components = $this->componentDiscovery->discoverComponents();
      $stats = $this->indexingHelper->getGlobalComponentStats();
      
      // Generate recommended weights based on usage and type
      foreach ($components as $component_type => $component_info) {
        $type_lower = strtolower($component_type);
        
        // Base weight on component type patterns
        if (strpos($type_lower, 'hero') !== FALSE || strpos($type_lower, 'banner') !== FALSE) {
          $weight = 2.5;
        } elseif (strpos($type_lower, 'title') !== FALSE || strpos($type_lower, 'heading') !== FALSE) {
          $weight = 2.0;
        } elseif (strpos($type_lower, 'footer') !== FALSE || strpos($type_lower, 'sidebar') !== FALSE) {
          $weight = 0.7;
        } else {
          $weight = 1.0;
        }
        
        // Adjust based on usage if available
        if (isset($stats['entity_types']) && !empty($stats['entity_types'])) {
          // Higher usage components get slightly more weight
          $usage_count = 0;
          foreach ($stats['entity_types'] as $entity_data) {
            if (in_array($component_type, $entity_data['types'])) {
              $usage_count += $entity_data['components'];
            }
          }
          
          if ($usage_count > 100) {
            $weight += 0.2;
          } elseif ($usage_count < 10) {
            $weight -= 0.2;
          }
        }
        
        $form_state->setValue(['component_weights', 'weights_table', $component_type, 'weight'], 
          max(0.1, min(5.0, $weight)));
      }
      
      $this->messenger()->addStatus($this->t('Recommended weights have been applied. Review and save to keep changes.'));
    } catch (\Exception $e) {
      $this->messenger()->addError($this->t('Error generating recommendations: @error', [
        '@error' => $e->getMessage(),
      ]));
    }
    
    $form_state->setRebuild(TRUE);
  }

  /**
   * Ajax callback to update weights table.
   */
  public function updateWeightsTable(array &$form, FormStateInterface $form_state) {
    return $form['component_weights']['weights_table'];
  }

  /**
   * Submit handler to rebuild search indexes.
   */
  public function rebuildIndexes(array &$form, FormStateInterface $form_state) {
    try {
      $operations = [];
      
      // Clear Drupal core search index
      if ($this->moduleHandler->moduleExists('search')) {
        $operations[] = [
          '\Drupal\component_search\Batch\RebuildSearchIndexBatch::clearCoreSearch',
          []
        ];
      }

      // Clear Search API indexes
      if ($this->moduleHandler->moduleExists('search_api')) {
        $operations[] = [
          '\Drupal\component_search\Batch\RebuildSearchIndexBatch::clearSearchApiIndexes',
          []
        ];
      }

      if (!empty($operations)) {
        $batch = [
          'title' => $this->t('Rebuilding search indexes'),
          'operations' => $operations,
          'finished' => '\Drupal\component_search\Batch\RebuildSearchIndexBatch::finished',
          'file' => drupal_get_path('module', 'component_search') . '/src/Batch/RebuildSearchIndexBatch.php',
        ];
        
        batch_set($batch);
      } else {
        $this->messenger()->addWarning($this->t('No search modules are enabled.'));
      }

    } catch (\Exception $e) {
      $this->messenger()->addError($this->t('Error rebuilding search indexes: @error', [
        '@error' => $e->getMessage(),
      ]));
    }
  }
}