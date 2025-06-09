<?php

namespace Drupal\component_search\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\component_field\Service\ComponentDiscovery;

/**
 * Service for extracting searchable content from component fields.
 */
class ComponentContentExtractor {

  /**
   * Component discovery service.
   */
  protected ComponentDiscovery $componentDiscovery;

  /**
   * Entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Config factory.
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * Logger factory.
   */
  protected LoggerChannelFactoryInterface $loggerFactory;

  /**
   * Cache backend.
   */
  protected CacheBackendInterface $cache;

  /**
   * Constructor.
   */
  public function __construct(
    ComponentDiscovery $component_discovery,
    EntityTypeManagerInterface $entity_type_manager,
    ConfigFactoryInterface $config_factory,
    LoggerChannelFactoryInterface $logger_factory,
    CacheBackendInterface $cache
  ) {
    $this->componentDiscovery = $component_discovery;
    $this->entityTypeManager = $entity_type_manager;
    $this->configFactory = $config_factory;
    $this->loggerFactory = $logger_factory;
    $this->cache = $cache;
  }

  /**
   * Extract searchable content from entity for Drupal core search.
   */
  public function extractSearchableContent(EntityInterface $entity): string {
    // Check cache first
    $cache_key = $this->buildCacheKey($entity, 'core_search');
    $cached = $this->cache->get($cache_key);
    
    if ($cached && $this->isCacheValid($cached, $entity)) {
      return $cached->data;
    }

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
          // Apply component weight
          $weight = $config->get('component_weights.' . $component_type) ?? 1.0;
          $weighted_content = str_repeat($extracted['content'] . ' ', max(1, (int)$weight));
          $content_parts[] = $weighted_content;
        }
      }
    }

    $result = implode(' ', $content_parts);
    
    // Cache the result
    $this->setCachedContent($cache_key, $result, $entity);
    
    return $result;
  }

  /**
   * Extract content for Search API indexing.
   */
  public function extractSearchApiContent(EntityInterface $entity): array {
    // Check cache first
    $cache_key = $this->buildCacheKey($entity, 'search_api');
    $cached = $this->cache->get($cache_key);
    
    if ($cached && $this->isCacheValid($cached, $entity)) {
      return $cached->data;
    }

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

    $result = array_filter($extracted_data, function($value) {
      return !empty($value);
    });

    // Cache the result
    $this->setCachedContent($cache_key, $result, $entity);
    
    return $result;
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
      $extraction_settings = $config->get('extraction_settings') ?? [];

      foreach ($configuration as $prop_name => $prop_value) {
        if (empty($prop_value)) {
          continue;
        }

        $content_type = $this->determineContentType($prop_name, $prop_value, $component_info);
        
        $text_content = $this->extractTextFromValue($prop_value, $extraction_settings);
        
        if (!empty($text_content)) {
          switch ($content_type) {
            case 'title':
              $boost = $extraction_settings['boost_titles'] ?? 2.0;
              $boosted_content = str_repeat($text_content . ' ', max(1, (int)$boost));
              $extracted['titles'][] = $boosted_content;
              break;
              
            case 'reference':
              if ($extraction_settings['extract_references'] ?? TRUE) {
                $extracted['references'][] = $text_content;
              }
              break;
              
            default:
              $extracted['content'][] = $text_content;
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

    // Check component metadata for hints
    if (!empty($component_info['properties'][$prop_name]['content_type'])) {
      return $component_info['properties'][$prop_name]['content_type'];
    }

    return 'content';
  }

  /**
   * Extract text content from various value types.
   */
  protected function extractTextFromValue($value, array $settings = []): string {
    $max_length = $settings['max_content_length'] ?? 50000;
    
    // Handle text_format fields (rich text)
    if (is_array($value) && isset($value['value'], $value['format'])) {
      $text = $this->processRichText($value['value'], $settings);
      return $this->cleanText($text, $max_length);
    }

    // Handle processed text
    if (is_array($value) && isset($value['processed'])) {
      $text = $this->processRichText($value['processed'], $settings);
      return $this->cleanText($text, $max_length);
    }

    // Handle entity references
    if (is_array($value) && isset($value['target_id'])) {
      return $this->extractEntityReferenceText($value, $settings);
    }

    // Handle media library selections
    if (is_array($value) && isset($value['selection'])) {
      return $this->extractMediaSelectionText($value['selection'], $settings);
    }

    // Handle simple strings
    if (is_string($value)) {
      return $this->cleanText(strip_tags($value), $max_length);
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
      $combined = implode(' ', array_filter($text_parts));
      return $this->cleanText($combined, $max_length);
    }

    return '';
  }

  /**
   * Process rich text content.
   */
  protected function processRichText(string $text, array $settings): string {
    if ($settings['strip_html_tags'] ?? TRUE) {
      // Strip HTML but preserve some formatting
      $text = preg_replace('/<\/(p|div|h[1-6]|li)>/i', "\n", $text);
      $text = strip_tags($text);
    }
    
    return $text;
  }

  /**
   * Extract text from entity reference.
   */
  protected function extractEntityReferenceText(array $reference, array $settings): string {
    $entity_type = $reference['entity_type'] ?? 'node';
    $max_depth = $settings['max_reference_depth'] ?? 1;
    
    if ($max_depth <= 0) {
      return '';
    }
    
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
      $additional_content = $this->extractAdditionalEntityContent($entity, $settings, $max_depth - 1);
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
  protected function extractAdditionalEntityContent(EntityInterface $entity, array $settings, int $remaining_depth): string {
    if ($remaining_depth <= 0) {
      return '';
    }
    
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
                $text_parts[] = $this->processRichText($field_value['value'], $settings);
              }
            }
          }
        }
      }
      
      // For taxonomy terms, extract description
      if ($entity->getEntityTypeId() === 'taxonomy_term' && $entity->hasField('description')) {
        $description = $entity->get('description')->getValue();
        if (!empty($description[0]['value'])) {
          $text_parts[] = $this->processRichText($description[0]['value'], $settings);
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
  protected function extractMediaSelectionText(array $selection, array $settings): string {
    $text_parts = [];
    
    try {
      foreach ($selection as $media_id) {
        $media = $this->entityTypeManager->getStorage('media')->load($media_id);
        if ($media) {
          $text_parts[] = $this->extractAdditionalEntityContent($media, $settings, 1);
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
  protected function cleanText(string $text, int $max_length = 0): string {
    // Normalize whitespace
    if ($this->configFactory->get('component_search.settings')->get('extraction_settings.normalize_whitespace') ?? TRUE) {
      $text = preg_replace('/\s+/', ' ', $text);
    }
    
    // Remove special characters that might interfere with search
    $text = preg_replace('/[^\p{L}\p{N}\p{P}\p{S}\s]/u', '', $text);
    
    $text = trim($text);
    
    // Truncate if necessary
    if ($max_length > 0 && strlen($text) > $max_length) {
      $text = substr($text, 0, $max_length);
      // Try to break at word boundary
      $last_space = strrpos($text, ' ');
      if ($last_space !== FALSE && $last_space > $max_length * 0.8) {
        $text = substr($text, 0, $last_space);
      }
    }
    
    return $text;
  }

  /**
   * Preprocess search text for core search integration.
   */
  public function preprocessSearchText(string $text, ?string $langcode = NULL): string {
    $config = $this->configFactory->get('component_search.settings');
    
    if ($config->get('enhance_search_terms')) {
      $text = $this->expandSearchTerms($text);
    }
    
    return $text;
  }

  /**
   * Expand search terms to include component-related synonyms.
   */
  protected function expandSearchTerms(string $text): string {
    $expansions = [
      'button' => 'button click action cta call-to-action link',
      'card' => 'card panel widget block section tile',
      'hero' => 'hero banner header featured highlight jumbotron',
      'text' => 'text content copy paragraph description body',
      'image' => 'image picture photo media visual graphic',
      'video' => 'video media player multimedia clip',
      'form' => 'form contact input field submit',
      'navigation' => 'navigation menu nav links sitemap',
    ];

    foreach ($expansions as $term => $expansion) {
      if (stripos($text, $term) !== FALSE) {
        $text .= ' ' . $expansion;
      }
    }

    return $text;
  }

  /**
   * Build cache key for entity and extraction type.
   */
  protected function buildCacheKey(EntityInterface $entity, string $type): string {
    return sprintf(
      'component_search:%s:%s:%s:%s',
      $type,
      $entity->getEntityTypeId(),
      $entity->id(),
      $entity->getChangedTime()
    );
  }

  /**
   * Check if cached data is still valid.
   */
  protected function isCacheValid($cached_data, EntityInterface $entity): bool {
    if (!isset($cached_data->data)) {
      return FALSE;
    }

    $config = $this->configFactory->get('component_search.settings');
    
    // Check if caching is enabled
    if (!$config->get('performance_settings.cache_extractions')) {
      return FALSE;
    }

    // Check cache age
    $max_age = $config->get('performance_settings.cache_max_age') ?? 86400;
    if ($max_age > 0 && (REQUEST_TIME - $cached_data->created) > $max_age) {
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Cache extracted content.
   */
  protected function setCachedContent(string $cache_key, $content, EntityInterface $entity): void {
    $config = $this->configFactory->get('component_search.settings');
    
    if (!$config->get('performance_settings.cache_extractions')) {
      return;
    }

    $max_age = $config->get('performance_settings.cache_max_age') ?? 86400;
    $expire = $max_age > 0 ? REQUEST_TIME + $max_age : CacheBackendInterface::CACHE_PERMANENT;
    
    $tags = [
      'component_search',
      'component_search:entity:' . $entity->getEntityTypeId() . ':' . $entity->id(),
    ];
    
    $this->cache->set($cache_key, $content, $expire, $tags);
  }
}