<?php

namespace Drupal\component_search\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\component_field\Service\ComponentDiscovery;

/**
 * Service for extracting searchable content from component fields.
 */
class ComponentContentExtractor {

  protected ComponentDiscovery $componentDiscovery;
  protected EntityTypeManagerInterface $entityTypeManager;
  protected ConfigFactoryInterface $configFactory;
  protected LoggerChannelFactoryInterface $loggerFactory;

  public function __construct(
    ComponentDiscovery $component_discovery,
    EntityTypeManagerInterface $entity_type_manager,
    ConfigFactoryInterface $config_factory,
    LoggerChannelFactoryInterface $logger_factory
  ) {
    $this->componentDiscovery = $component_discovery;
    $this->entityTypeManager = $entity_type_manager;
    $this->configFactory = $config_factory;
    $this->loggerFactory = $logger_factory;
  }

  /**
   * Extract searchable content from entity for Drupal core search.
   */
  public function extractSearchableContent(EntityInterface $entity): string {
    $content_parts = [];
    $config = $this->configFactory->get('component_search.settings');
    
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

        if (empty($component_type) || empty($configuration)) {
          continue;
        }

        $extracted = $this->extractComponentContent($component_type, $configuration, $config);
        if (!empty($extracted['content'])) {
          $content_parts[] = $extracted['content'];
        }
      }
    }

    return implode(' ', $content_parts);
  }

  /**
   * Extract content for Search API indexing.
   */
  public function extractSearchApiContent(EntityInterface $entity): array {
    $extracted_data = [
      'component_content' => [],
      'component_titles' => [],
      'component_types' => [],
      'component_references' => [],
    ];

    $config = $this->configFactory->get('component_search.settings');
    
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

        if (empty($component_type) || empty($configuration)) {
          continue;
        }

        $extracted = $this->extractComponentContent($component_type, $configuration, $config);
        
        // Distribute content to appropriate search fields
        if (!empty($extracted['content'])) {
          $extracted_data['component_content'][] = $extracted['content'];
        }
        
        if (!empty($extracted['titles'])) {
          $extracted_data['component_titles'] = array_merge(
            $extracted_data['component_titles'], 
            $extracted['titles']
          );
        }
        
        $extracted_data['component_types'][] = $component_type;
        
        if (!empty($extracted['references'])) {
          $extracted_data['component_references'] = array_merge(
            $extracted_data['component_references'], 
            $extracted['references']
          );
        }
      }
    }

    return array_filter($extracted_data, function($value) {
      return !empty($value);
    });
  }

  /**
   * Extract content from a single component configuration.
   */
  protected function extractComponentContent(string $component_type, array $configuration, $config): array {
    $extracted = [
      'content' => [],
      'titles' => [],
      'references' => [],
    ];

    try {
      $component_info = $this->componentDiscovery->getComponent($component_type);
      $weight_config = $config->get('component_weights.' . $component_type) ?? [];

      foreach ($configuration as $prop_name => $prop_value) {
        if (empty($prop_value)) {
          continue;
        }

        $content_type = $this->determineContentType($prop_name, $prop_value, $component_info);
        $weight = $weight_config[$prop_name] ?? 1.0;
        
        $text_content = $this->extractTextFromValue($prop_value);
        
        if (!empty($text_content)) {
          // Apply weight by repeating content
          $weighted_content = str_repeat($text_content . ' ', max(1, (int)$weight));
          
          switch ($content_type) {
            case 'title':
              $extracted['titles'][] = $weighted_content;
              break;
            case 'reference':
              $extracted['references'][] = $text_content;
              break;
            default:
              $extracted['content'][] = $weighted_content;
          }
        }
      }
    } catch (\Exception $e) {
      $this->loggerFactory->get('component_search')->warning(
        'Error extracting content from component @type: @error', [
          '@type' => $component_type,
          '@error' => $e->getMessage(),
        ]
      );
    }

    return [
      'content' => implode(' ', $extracted['content']),
      'titles' => $extracted['titles'],
      'references' => $extracted['references'],
    ];
  }

  /**
   * Determine the type of content based on property name and structure.
   */
  protected function determineContentType(string $prop_name, $prop_value, ?array $component_info): string {
    // Check if it's likely a title field
    $title_patterns = ['title', 'heading', 'header', 'name', 'label', 'headline', 'subject'];
    $prop_name_lower = strtolower($prop_name);
    
    foreach ($title_patterns as $pattern) {
      if (strpos($prop_name_lower, $pattern) !== FALSE) {
        return 'title';
      }
    }

    // Check if it's an entity reference
    if (is_array($prop_value) && isset($prop_value['target_id'])) {
      return 'reference';
    }

    return 'content';
  }

  /**
   * Extract text content from various value types.
   */
  protected function extractTextFromValue($value): string {
    // Handle text_format fields (rich text)
    if (is_array($value) && isset($value['value'], $value['format'])) {
      $text = strip_tags($value['value']);
      return $this->cleanText($text);
    }

    // Handle processed text
    if (is_array($value) && isset($value['processed'])) {
      $text = strip_tags($value['processed']);
      return $this->cleanText($text);
    }

    // Handle entity references
    if (is_array($value) && isset($value['target_id'])) {
      return $this->extractEntityReferenceText($value);
    }

    // Handle media library selections
    if (is_array($value) && isset($value['selection'])) {
      return $this->extractMediaSelectionText($value['selection']);
    }

    // Handle simple strings
    if (is_string($value)) {
      return $this->cleanText(strip_tags($value));
    }

    // Handle arrays of values
    if (is_array($value)) {
      $text_parts = [];
      foreach ($value as $item) {
        if (is_string($item)) {
          $text_parts[] = $this->cleanText(strip_tags($item));
        } elseif (is_array($item) && isset($item['value'])) {
          $text_parts[] = $this->cleanText(strip_tags($item['value']));
        }
      }
      return implode(' ', array_filter($text_parts));
    }

    return '';
  }

  /**
   * Extract text from entity reference.
   */
  protected function extractEntityReferenceText(array $reference): string {
    $entity_type = $reference['entity_type'] ?? 'node';
    
    try {
      $entity = $this->entityTypeManager->getStorage($entity_type)->load($reference['target_id']);
      
      if (!$entity) {
        return '';
      }

      $text_parts = [];
      
      // Get entity label
      if (method_exists($entity, 'label')) {
        $text_parts[] = $entity->label();
      }

      // Extract additional content based on entity type
      $additional_content = $this->extractAdditionalEntityContent($entity);
      if (!empty($additional_content)) {
        $text_parts[] = $additional_content;
      }

      return implode(' ', $text_parts);
      
    } catch (\Exception $e) {
      $this->loggerFactory->get('component_search')->warning(
        'Error extracting entity reference text: @error', [
          '@error' => $e->getMessage(),
        ]
      );
      return '';
    }
  }

  /**
   * Extract additional content from referenced entities.
   */
  protected function extractAdditionalEntityContent(EntityInterface $entity): string {
    $text_parts = [];
    
    try {
      // For nodes, extract common text fields
      if ($entity->getEntityTypeId() === 'node') {
        $text_fields = ['body', 'field_summary', 'field_description', 'field_excerpt'];
        
        foreach ($text_fields as $field_name) {
          if ($entity->hasField($field_name)) {
            $field_values = $entity->get($field_name)->getValue();
            foreach ($field_values as $field_value) {
              if (!empty($field_value['value'])) {
                $text_parts[] = strip_tags($field_value['value']);
              }
            }
          }
        }
      }
      
      // For taxonomy terms, extract description
      if ($entity->getEntityTypeId() === 'taxonomy_term' && $entity->hasField('description')) {
        $description = $entity->get('description')->getValue();
        if (!empty($description[0]['value'])) {
          $text_parts[] = strip_tags($description[0]['value']);
        }
      }
      
      // For media entities, extract name and alt text
      if ($entity->getEntityTypeId() === 'media') {
        if ($entity->hasField('field_media_image')) {
          $image_field = $entity->get('field_media_image')->getValue();
          if (!empty($image_field[0]['alt'])) {
            $text_parts[] = $image_field[0]['alt'];
          }
        }
      }
      
    } catch (\Exception $e) {
      $this->loggerFactory->get('component_search')->debug(
        'Could not extract additional content from entity: @error', [
          '@error' => $e->getMessage(),
        ]
      );
    }

    return implode(' ', $text_parts);
  }

  /**
   * Extract text from media library selection.
   */
  protected function extractMediaSelectionText(array $selection): string {
    $text_parts = [];
    
    try {
      foreach ($selection as $media_id) {
        $media = $this->entityTypeManager->getStorage('media')->load($media_id);
        if ($media) {
          $text_parts[] = $this->extractAdditionalEntityContent($media);
        }
      }
    } catch (\Exception $e) {
      $this->loggerFactory->get('component_search')->warning(
        'Error extracting media selection text: @error', [
          '@error' => $e->getMessage(),
        ]
      );
    }

    return implode(' ', array_filter($text_parts));
  }

  /**
   * Clean and normalize text content.
   */
  protected function cleanText(string $text): string {
    // Remove extra whitespace
    $text = preg_replace('/\s+/', ' ', $text);
    
    // Remove special characters that might interfere with search
    $text = preg_replace('/[^\p{L}\p{N}\p{P}\p{S}\s]/u', '', $text);
    
    return trim($text);
  }

  /**
   * Preprocess search text for core search integration.
   */
  public function preprocessSearchText(string $text, ?string $langcode = NULL): string {
    // Apply any component-specific text preprocessing
    $config = $this->configFactory->get('component_search.settings');
    
    if ($config->get('enhance_search_terms')) {
      // Add component-related search term expansions
      $text = $this->expandSearchTerms($text);
    }
    
    return $text;
  }

  /**
   * Expand search terms to include component-related synonyms.
   */
  protected function expandSearchTerms(string $text): string {
    $expansions = [
      'button' => 'button click action cta call-to-action',
      'card' => 'card panel widget block section',
      'hero' => 'hero banner header featured highlight',
      'text' => 'text content copy paragraph description',
    ];

    foreach ($expansions as $term => $expansion) {
      if (stripos($text, $term) !== FALSE) {
        $text .= ' ' . $expansion;
      }
    }

    return $text;
  }
}