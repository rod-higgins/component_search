# Component Search Module

## Overview

The Component Search module provides comprehensive search integration for the [Component Field module](https://www.drupal.org/project/component_field), making component configurations and content searchable through both Drupal core search and Search API. This module automatically extracts searchable text from component properties, processes rich text content, and indexes entity references to provide powerful search capabilities for component-driven websites.

## Features

### Core Features
- **Drupal Core Search Integration**: Automatically extracts searchable content from component configurations and adds it to the core search index
- **Search API Integration**: Provides specialized processors for Search API indexes with advanced configuration options
- **Intelligent Content Extraction**: Extracts text content from various component property types including rich text, entity references, and media
- **Entity Reference Support**: Includes content from referenced entities (nodes, media, taxonomy terms) with configurable depth
- **Rich Text Processing**: Properly handles text_format fields and processes HTML content with customizable filtering
- **Component Type Weighting**: Configure search importance of different component types to boost relevance
- **Background Processing**: Queue-based indexing system to prevent blocking during content updates

### Performance Features
- **Smart Caching**: Configurable caching system with automatic invalidation
- **Dynamic Batch Processing**: Automatically adjusts batch sizes based on content complexity
- **Memory Management**: Configurable content length limits and cache size restrictions
- **Optimized Extraction**: Intelligent field detection and processing to minimize overhead

### Advanced Features
- **Multi-depth References**: Follow entity references up to 3 levels deep for comprehensive content indexing
- **Content Categorization**: Automatically categorizes components for faceted search
- **Custom Processors**: Extensible Search API processors for specialized use cases
- **Debug Mode**: Detailed logging and validation for troubleshooting
- **Fallback Systems**: Graceful degradation when components or content are unavailable

## Requirements

- **Drupal**: 10.4+ or 11.x
- **Component Field module**: Required (this module extends Component Field functionality)
- **Search module**: For Drupal core search integration
- **Search API module**: Optional, for advanced search features

## Installation

1. Ensure the Component Field module is installed and enabled
2. Place this module in `web/modules/custom/component_search/`
3. Enable the module: `drush en component_search`
4. Configure settings at `/admin/config/search/component-search`
5. Rebuild search indexes for immediate indexing

## Configuration

### Basic Setup

Visit `/admin/config/search/component-search` to configure:

#### Search Integration
- **Enable Drupal core search integration**: Adds component content to core search index
- **Enable Search API integration**: Provides advanced processors for Search API
- **Enhance search terms**: Expands search queries with component-related synonyms

#### Content Extraction Settings
- **Extract referenced entity content**: Include content from entities referenced by components
- **Maximum reference depth**: How deep to follow entity references (0-3 levels)
- **Title content boost multiplier**: How much more important title content is (0.1-10.0x)
- **Strip HTML tags**: Remove HTML markup from extracted content
- **Normalize whitespace**: Convert multiple spaces to single spaces
- **Maximum content length**: Limit extracted content size (performance optimization)

#### Component Type Weights
Configure the search importance of different component types:
- **Weight 0.0**: Exclude from search completely
- **Weight 1.0**: Normal importance (default)
- **Weight 2.0+**: Higher importance in search results

Common weighting strategies:
- Hero/Banner components: 2.5-3.0 (high visibility content)
- Title/Heading components: 2.0-2.5 (important for discoverability)
- Navigation components: 1.5-2.0 (important for site structure)
- Content cards: 1.0-1.5 (standard content)
- Footer components: 0.5-1.0 (supplementary content)

#### Performance Settings
- **Cache extractions**: Cache extracted content for improved performance
- **Cache maximum age**: How long to keep cached extractions (300-604800 seconds)
- **Batch processing size**: Number of entities to process at once (1-500)
- **Enable background indexing**: Use queue system for non-blocking updates

### Search API Configuration

If using Search API, the module provides these processors:

#### Component Content Extractor
- Extracts searchable text from component configurations
- Separates title content for higher relevance
- Includes entity reference content
- Optionally includes raw configuration data for technical searches

#### Component Type Processor  
- Indexes component types for filtering and faceting
- Creates human-readable component type labels
- Automatically categorizes components
- Optionally creates boolean fields for precise filtering

To configure:
1. Edit your Search API index
2. Add the "Component Content Extractor" processor
3. Add the "Component Type Processor" if needed
4. Configure processor settings for your requirements
5. Rebuild the search index

## Content Extraction Details

### Supported Field Types

The module intelligently extracts content from various component property types:

#### Text Content
- **Plain text fields**: Direct text extraction with normalization
- **Rich text fields**: HTML processing with tag stripping and formatting preservation
- **Text format fields**: Full processing of filtered text with format-aware handling
- **Textarea fields**: JSON parsing for arrays/objects, plain text for simple content

#### Entity References
- **Node references**: Title, body, summary, and custom text fields
- **Media references**: Alt text, captions, file names, and metadata
- **Taxonomy references**: Term names, descriptions, and hierarchy
- **User references**: Display names and profile information

#### Complex Fields
- **Arrays**: Recursive processing of multiple values with intelligent joining
- **Objects**: JSON structure parsing with text content extraction
- **Media library**: Multi-select media processing with metadata
- **File fields**: File names, descriptions, and EXIF data where available

### Content Processing Pipeline

1. **Field Detection**: Automatic identification of searchable content types
2. **Content Extraction**: Type-aware extraction with error handling
3. **Text Processing**: HTML stripping, whitespace normalization, encoding fixes
4. **Reference Resolution**: Entity loading and recursive content extraction
5. **Weight Application**: Component-type-based relevance boosting
6. **Cache Storage**: Optional caching for performance optimization

## Performance Optimization

### Caching Strategy
- **Content Caching**: Extracted content cached by entity and extraction type
- **Automatic Invalidation**: Cache cleared when entities or configurations change
- **Size Limits**: Configurable cache entry size limits to prevent memory issues
- **TTL Management**: Time-based cache expiration with renewal on access

### Background Processing
- **Queue System**: Non-blocking entity processing via Drupal's queue API
- **Batch Operations**: Large-scale reindexing with progress tracking
- **Dynamic Sizing**: Automatic batch size adjustment based on content complexity
- **Error Recovery**: Failed jobs automatically retry with exponential backoff

### Memory Management
- **Content Limits**: Maximum extracted content length per entity
- **Batch Sizing**: Adaptive batch sizes based on entity complexity
- **Reference Depth**: Configurable depth limits to prevent infinite recursion
- **Garbage Collection**: Automatic cleanup of expired cache entries

## Troubleshooting

### Common Issues

#### No Search Results from Components
1. Verify Component Field module is enabled and components are discovered
2. Check that search integration is enabled in module configuration
3. Ensure search indexes have been rebuilt after module installation
4. Verify entities have component field content with actual values

#### Poor Search Relevance
1. Adjust component type weights in module configuration
2. Enable title content separation for better relevance ranking
3. Review entity reference extraction settings and depth
4. Check if content boost multipliers are appropriate for your use case

#### Performance Issues
1. Enable content extraction caching
2. Reduce batch processing size for complex entities
3. Limit entity reference depth to prevent deep recursion
4. Enable background indexing to prevent blocking during updates

#### Content Not Being Indexed
1. Check component field types in extraction settings
2. Verify component configurations contain searchable content
3. Review error logs at `/admin/reports/dblog` for extraction failures
4. Test content extraction with debug mode enabled

#### Memory or Timeout Errors
1. Reduce maximum content length setting
2. Decrease batch processing size
3. Limit entity reference depth
4. Enable background processing for large operations

### Debug Mode

Enable debug mode in advanced settings to:
- Log detailed extraction information
- Validate extracted content quality
- Track processing times and memory usage
- Identify problematic component configurations

### Log Analysis

Check these log entries for troubleshooting:
- `component_search`: General module operations
- Search extraction errors and warnings
- Performance metrics and optimization suggestions
- Cache hit/miss ratios and efficiency data

## API Documentation

### Services

#### ComponentContentExtractor (`component_search.content_extractor`)
Primary service for extracting searchable content:

```php
// Extract content for core search
$extractor = \Drupal::service('component_search.content_extractor');
$content = $extractor->extractSearchableContent($entity);

// Extract structured data for Search API  
$data = $extractor->extractSearchApiContent($entity);

// Preprocess search terms with synonyms
$enhanced_text = $extractor->preprocessSearchText($search_query);
```

#### ComponentSearchManager (`component_search.search_manager`)
Manages search index updates:

```php
// Handle entity changes
$manager = \Drupal::service('component_search.search_manager');
$manager->handleEntityChange($entity, 'update');

// Bulk reindex operations
$results = $manager->bulkReindex(['node', 'media']);

// Get search statistics
$stats = $manager->getSearchStatistics();
```

#### ComponentIndexingHelper (`component_search.indexing_helper`)
Utilities for indexing operations:

```php
// Get entities with component fields
$helper = \Drupal::service('component_search.indexing_helper');
$entities = $helper->getEntitiesWithComponentFields(['node']);

// Get component statistics
$stats = $helper->getGlobalComponentStats();

// Validate entity for indexing
$validation = $helper->validateEntityForIndexing($entity);
```

#### ComponentSearchCacheManager (`component_search.cache_manager`)
Cache management operations:

```php
// Cache extracted content
$cache_manager = \Drupal::service('component_search.cache_manager');
$cache_manager->setCachedContent($entity, $content);

// Invalidate entity cache
$cache_manager->invalidateEntityCache($entity);

// Get cache statistics
$stats = $cache_manager->getCacheStats();
```

### Hooks and Events

#### Entity Lifecycle Integration
```php
// Automatic handling via event subscribers
// Manual handling in custom modules:
function mymodule_entity_update(EntityInterface $entity) {
  if (\Drupal::hasService('component_search.search_manager')) {
    \Drupal::service('component_search.search_manager')
      ->handleEntityChange($entity, 'update');
  }
}
```

#### Search API Integration
```php
// Add custom extraction logic
function mymodule_search_api_item_index_alter(array &$indexed_values, EntityInterface $entity) {
  if (component_search_entity_has_component_fields($entity)) {
    $extractor = \Drupal::service('component_search.content_extractor');
    $custom_data = $extractor->extractCustomContent($entity);
    $indexed_values['custom_component_field'] = $custom_data;
  }
}
```

#### Configuration Hooks
```php
// Alter extraction settings
function mymodule_component_search_extraction_settings_alter(&$settings, EntityInterface $entity) {
  // Customize extraction for specific entity types
  if ($entity->getEntityTypeId() === 'custom_entity') {
    $settings['max_reference_depth'] = 2;
    $settings['boost_titles'] = 3.0;
  }
}
```

## Extending the Module

### Custom Content Extractors

Create custom extraction logic for specialized component types:

```php
namespace Drupal\mymodule\Plugin\ComponentSearch\Extractor;

use Drupal\component_search\Plugin\ComponentSearch\ExtractorBase;

/**
 * @ComponentSearchExtractor(
 *   id = "custom_extractor",
 *   label = @Translation("Custom Content Extractor"),
 *   component_types = {"custom_component"}
 * )
 */
class CustomExtractor extends ExtractorBase {
  
  public function extractContent(array $configuration): array {
    // Custom extraction logic
    return [
      'content' => $this->processCustomContent($configuration),
      'metadata' => $this->extractMetadata($configuration),
    ];
  }
}
```

### Custom Search API Processors

Extend Search API functionality:

```php
namespace Drupal\mymodule\Plugin\search_api\processor;

use Drupal\search_api\Processor\ProcessorPluginBase;

/**
 * @SearchApiProcessor(
 *   id = "custom_component_processor",
 *   label = @Translation("Custom Component Processor"),
 *   description = @Translation("Processes components with custom logic"),
 *   stages = {"add_properties" = 0}
 * )
 */
class CustomComponentProcessor extends ProcessorPluginBase {
  // Custom processor implementation
}
```

### Component-Specific Configuration

Add component-specific extraction rules:

```php
// In your module's .module file
function mymodule_component_search_component_config_alter(&$config, $component_type, $component_info) {
  if ($component_type === 'special_component') {
    $config['extraction_rules'] = [
      'priority_fields' => ['title', 'description'],
      'boost_factor' => 2.5,
      'extract_references' => TRUE,
    ];
  }
}
```

## Security Considerations

- **Content Sanitization**: All extracted content is properly sanitized before indexing
- **Access Control**: Search results respect Drupal's entity access system
- **Permission Checks**: Admin configuration requires appropriate permissions
- **Input Validation**: All user inputs are validated and escaped
- **Error Handling**: Graceful error handling prevents information disclosure

## Maintenance

### Regular Tasks
1. **Monitor search index size** and performance
2. **Review extraction logs** for errors or warnings
3. **Optimize component weights** based on usage analytics
4. **Clean up expired cache entries** if using custom cache backends
5. **Update extraction settings** when adding new component types

### Recommended Monitoring
- Search index build times
- Cache hit/miss ratios
- Queue processing times
- Memory usage during batch operations
- Entity processing error rates

## Contributing

- Report issues and feature requests on the project page
- Submit patches following Drupal coding standards
- Include tests for new functionality
- Update documentation for significant changes

## License

GPL-2.0-or-later

## Related Modules

- **Component Field**: Required dependency providing component discovery and field types
- **Search API**: Optional dependency for advanced search features
- **Search API Solr**: Recommended for high-performance search
- **Facets**: Provides faceted search using component type data
- **Views**: Can display component search results

## Support

- Documentation: This README and inline code documentation
- Issue queue: Project page issue tracker
- Community: Drupal Slack #component-field channel
- Professional support: Available from module maintainers
