<?php

namespace Drupal\component_search\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\component_field\Service\ComponentDiscovery;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configuration form for Component Search module.
 */
class ComponentSearchConfigForm extends ConfigFormBase {

  protected ComponentDiscovery $componentDiscovery;

  public function __construct(ConfigFactoryInterface $config_factory, ComponentDiscovery $component_discovery) {
    parent::__construct($config_factory);
    $this->componentDiscovery = $component_discovery;
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('component_field.discovery')
    );
  }

  public function getFormId() {
    return 'component_search_config_form';
  }

  protected function getEditableConfigNames() {
    return ['component_search.settings'];
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('component_search.settings');

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

    $form['search_integration']['enable_search_api'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Search API integration'),
      '#description' => $this->t('Add component content processors to Search API.'),
      '#default_value' => $config->get('enable_search_api'),
      '#access' => \Drupal::moduleHandler()->moduleExists('search_api'),
    ];

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
            ],
            'description' => [
              '#markup' => $component_info['description'] ?? $this->t('No description available.'),
            ],
          ];
        }
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

    $form['performance']['batch_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Batch processing size'),
      '#description' => $this->t('Number of entities to process at once during bulk operations.'),
      '#default_value' => $performance_settings['batch_size'] ?? 50,
      '#min' => 1,
      '#max' => 500,
    ];

    $form['actions']['rebuild_indexes'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save and rebuild search indexes'),
      '#button_type' => 'primary',
      '#submit' => ['::submitForm', '::rebuildIndexes'],
    ];

    return parent::buildForm($form, $form_state);
  }

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
  }

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
    ];
    $config->set('extraction_settings', $extraction_settings);

    // Save performance settings
    $performance_settings = [
      'cache_extractions' => $form_state->getValue('cache_extractions'),
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

    parent::submitForm($form, $form_state);
  }

  /**
   * Submit handler to rebuild search indexes.
   */
  public function rebuildIndexes(array &$form, FormStateInterface $form_state) {
    try {
      // Clear Drupal core search index
      if (\Drupal::moduleHandler()->moduleExists('search')) {
        \Drupal::service('search.index')->clear();
        $this->messenger()->addStatus($this->t('Drupal core search index cleared and will be rebuilt.'));
      }

      // Clear Search API indexes
      if (\Drupal::moduleHandler()->moduleExists('search_api')) {
        $index_storage = \Drupal::entityTypeManager()->getStorage('search_api_index');
        $indexes = $index_storage->loadMultiple();
        
        foreach ($indexes as $index) {
          if ($index->status()) {
            $index->clear();
            $this->messenger()->addStatus($this->t('Search API index "@name" cleared and will be rebuilt.', [
              '@name' => $index->label(),
            ]));
          }
        }
      }

      // Clear component search caches
      \Drupal::cache()->invalidateAll();

    } catch (\Exception $e) {
      $this->messenger()->addError($this->t('Error rebuilding search indexes: @error', [
        '@error' => $e->getMessage(),
      ]));
    }
  }
}