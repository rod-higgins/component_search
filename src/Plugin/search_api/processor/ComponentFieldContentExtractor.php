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
 * Search API processor that extracts content from Component Field components.
 *
 * @SearchApiProcessor(
 *   id = "component_field_content_extractor",
 *   label = @Translation("Component Field Content Extractor"),
 *   description = @Translation("Extracts searchable text content from Component Field configurations with advanced processing"),
 *   stages = {
 *     "add_properties" = 0,
 *   },
 *   locked = false,
 *   hidden = false,
 * )
 */
class ComponentFieldContentExtractor extends ProcessorPluginBase implements ContainerFactoryPluginInterface {

  /**
   * The component content extractor service.
   */
  protected ComponentContentExtractor $contentExtractor;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    ComponentContentExtractor $content_extractor
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->contentExtractor = $content_extractor;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('component_search.content_extractor')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getPropertyDefinitions(DatasourceInterface $datasource = NULL) {
    $properties = [];

    if (!$datasource) {
      // Main content field - all extracted text
      $properties['component_field_content'] = new ProcessorProperty([
        'label' => $this->t('Component Field Content'),
        'description' => $this->t('All searchable text content extracted from Component Field components'),
        'type' => 'text',
        'processor_id' => $this->getPluginId(),
        'is_list' => FALSE,
      ]);

      // Title content field (higher relevance)
      $properties['component_field_titles'] = new ProcessorProperty([
        'label' => $this->t('Component Field Titles'),
        'description' => $this->t('Title and heading content from components (higher search weight)'),
        'type' => 'text',
        'processor_id' => $this->getPluginId(),
        'is_list' => FALSE,
      ]);

      // Referenced entity content
      if ($this->configuration['extract_references'] ?? TRUE) {
        $properties['component_field_references'] = new ProcessorProperty([
          'label' => $this->t('Component Field References'),
          'description' => $this->t('Content from entities referenced by components'),
          'type' => 'text',
          'processor_id' => $this->getPluginId(),
          'is_list' => FALSE,
        ]);
      }

      // Component configuration data (for advanced search)
      if ($this->configuration['include_config_data'] ?? FALSE) {
        $properties['component_field_config'] = new ProcessorProperty([
          'label' => $this->t('Component Field Configuration'),
          'description' => $this->t('Raw configuration data from components (for technical searches)'),
          'type' => 'string',
          'processor_id' => $this->getPluginId(),
          'is_list' => FALSE,
        ]);
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
      if (!$this->entityHasComponentFields($entity)) {
        return;
      }

      // Extract content using the dedicated service
      $extracted_data = $this->contentExtractor->extractSearchApiContent($entity);
      
      if (empty($extracted_data)) {
        return;
      }

      $fields = $item->getFields(FALSE);
      
      // Add main content
      if (isset($fields['component_field_content']) && !empty($extracted_data['component_content'])) {
        $content = is_array($extracted_data['component_content']) 
          ? implode(' ', $extracted_data['component_content']) 
          : $extracted_data['component_content'];
        $fields['component_field_content']->addValue($this->processContent($content));
      }

      // Add title content
      if (isset($fields['component_field_titles']) && !empty($extracted_data['component_titles'])) {
        $titles = is_array($extracted_data['component_titles']) 
          ? implode(' ', $extracted_data['component_titles']) 
          : $extracted_data['component_titles'];
        $fields['component_field_titles']->addValue($this->processContent($titles));
      }

      // Add reference content
      if (isset($fields['component_field_references']) && !empty($extracted_data['component_references'])) {
        $references = is_array($extracted_data['component_references']) 
          ? implode(' ', $extracted_data['component_references']) 
          : $extracted_data['component_references'];
        $fields['component_field_references']->addValue($this->processContent($references));
      }

      // Add configuration data if enabled
      if (isset($fields['component_field_config']) && ($this->configuration['include_config_data'] ?? FALSE)) {
        $config_data = $this->extractConfigurationData($entity);
        if (!empty($config_data)) {
          $fields['component_field_config']->addValue($config_data);
        }
      }

    } catch (\Exception $e) {
      \Drupal::logger('component_search')->error(
        'Error in ComponentFieldContentExtractor: @error', [
          '@error' => $e->getMessage(),
        ]
      );
    }
  }

  /**
   * Check if entity has component fields.
   */
  protected function entityHasComponentFields($entity): bool {
    foreach ($entity->getFieldDefinitions() as $field_definition) {
      if ($field_definition->getType() === 'component_field') {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Process content for indexing.
   */
  protected function processContent(string $content): string {
    // Clean up content for better search indexing
    $content = trim($content);
    
    // Remove excessive whitespace
    $content = preg_replace('/\s+/', ' ', $content);
    
    // Apply content boost if configured
    $boost_factor = $this->configuration['content_boost'] ?? 1.0;
    if ($boost_factor > 1.0) {
      // Repeat content to boost relevance (simple but effective)
      $repeats = min(5, (int) $boost_factor);
      $content = str_repeat($content . ' ', $repeats);
    }
    
    return $content;
  }

  /**
   * Extract raw configuration data for technical searches.
   */
  protected function extractConfigurationData($entity): string {
    $config_data = [];
    
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
          $configuration = $field_item->getConfiguration();
          
          if (!empty($component_type) && !empty($configuration)) {
            // Create searchable representation of config
            $config_text = "component:{$component_type}";
            foreach ($configuration as $key => $value) {
              if (is_scalar($value)) {
                $config_text .= " {$key}:{$value}";
              } elseif (is_array($value)) {
                $config_text .= " {$key}:" . json_encode($value);
              }
            }
            $config_data[] = $config_text;
          }
        }
      }
    } catch (\Exception $e) {
      \Drupal::logger('component_search')->warning('Error extracting config data: @error', [
        '@error' => $e->getMessage(),
      ]);
    }

    return implode(' ', $config_data);
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['processing_options'] = [
      '#type' => 'details',
      '#title' => $this->t('Content Processing Options'),
      '#open' => TRUE,
    ];

    $form['processing_options']['extract_references'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Extract content from entity references'),
      '#description' => $this->t('Include content from referenced entities (nodes, terms, media) in search index.'),
      '#default_value' => $this->configuration['extract_references'] ?? TRUE,
    ];

    $form['processing_options']['content_boost'] = [
      '#type' => 'number',
      '#title' => $this->t('Content boost factor'),
      '#description' => $this->t('Multiply content importance for search relevance. Higher values make component content more important.'),
      '#default_value' => $this->configuration['content_boost'] ?? 1.0,
      '#min' => 0.1,
      '#max' => 5.0,
      '#step' => 0.1,
    ];

    $form['processing_options']['include_config_data'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Include raw configuration data'),
      '#description' => $this->t('Add raw component configuration data for technical searches. Useful for developers and advanced users.'),
      '#default_value' => $this->configuration['include_config_data'] ?? FALSE,
    ];

    $form['advanced_options'] = [
      '#type' => 'details',
      '#title' => $this->t('Advanced Options'),
      '#open' => FALSE,
    ];

    $form['advanced_options']['max_content_length'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum content length per field'),
      '#description' => $this->t('Limit the length of extracted content to prevent oversized search index entries. 0 = no limit.'),
      '#default_value' => $this->configuration['max_content_length'] ?? 0,
      '#min' => 0,
      '#max' => 50000,
    ];

    $form['advanced_options']['strip_html_tags'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Strip HTML tags from content'),
      '#description' => $this->t('Remove HTML tags from extracted content. Recommended for better search quality.'),
      '#default_value' => $this->configuration['strip_html_tags'] ?? TRUE,
    ];

    $form['advanced_options']['normalize_whitespace'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Normalize whitespace'),
      '#description' => $this->t('Convert multiple whitespace characters to single spaces.'),
      '#default_value' => $this->configuration['normalize_whitespace'] ?? TRUE,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::validateConfigurationForm($form, $form_state);

    $boost = $form_state->getValue(['processors', $this->getPluginId(), 'settings', 'processing_options', 'content_boost']);
    if ($boost !== NULL && ($boost < 0.1 || $boost > 5.0)) {
      $form_state->setError(
        $form['processing_options']['content_boost'],
        $this->t('Content boost factor must be between 0.1 and 5.0.')
      );
    }

    $max_length = $form_state->getValue(['processors', $this->getPluginId(), 'settings', 'advanced_options', 'max_content_length']);
    if ($max_length !== NULL && $max_length < 0) {
      $form_state->setError(
        $form['advanced_options']['max_content_length'],
        $this->t('Maximum content length must be 0 or greater.')
      );
    }
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'extract_references' => TRUE,
      'content_boost' => 1.0,
      'include_config_data' => FALSE,
      'max_content_length' => 0,
      'strip_html_tags' => TRUE,
      'normalize_whitespace' => TRUE,
    ] + parent::defaultConfiguration();
  }
}