# Component Search Module

## Overview

The Component Search module provides comprehensive search integration for the Component Field module, making component configurations searchable through both Drupal core search and Search API.

## Features

- **Drupal Core Search Integration**: Automatically extracts searchable content from component configurations and adds it to the core search index
- **Search API Integration**: Provides specialized processors for Search API indexes
- **Content Extraction**: Intelligently extracts text content from various component property types
- **Entity Reference Support**: Includes content from referenced entities (nodes, media, taxonomy terms)
- **Rich Text Processing**: Properly handles text_format fields and processes HTML content
- **Component Type Weighting**: Configure search importance of different component types
- **Performance Optimization**: Caching and batch processing for large sites

## Installation

1. Ensure the Component Field module is installed and enabled
2. Place this module in `web/modules/custom/component_search/`
3. Enable the module: `drush en component_search`
4. Configure settings at `/admin/config/search/component-search`

## Configuration

### Basic Settings

Visit `/admin/config/search/component-search` to configure:

- **Search Integration**: Enable/disable core search and Search API integration
- **Content Extraction**: Configure how content is extracted from components
- **Component Weights**: Set search importance for different component types
- **Performance Settings**: Cache and batch processing options

### Search API Integration

If using Search API:

1. Add the "Component Content Extractor" processor to your search index
2. Configure the processor settings for your needs
3. Rebuild your search index

### Core Search Integration

The module automatically integrates with Drupal core search when enabled. Content from component fields will be included in search results.

## Content Extraction

The module extracts searchable content from various component property types:

### Text Content
- **Plain text fields**: Direct text extraction
- **Rich text fields**: HTML stripped, formatted content preserved
- **Text format fields**: Processed content with proper markup handling

### Entity References
- **Node references**: Title, body, and summary fields
- **Media references**: Alt text, captions, and metadata
- **Taxonomy references**: Term names and descriptions

### Complex Fields
- **Arrays**: Multiple values processed and combined
- **Objects**: JSON structures parsed for text content

## Component Type Weighting

You can configure how important different component types are in search results:

- **Weight 0.0**: Exclude from search
- **Weight 1.0**: Normal importance (default)
- **Weight 2.0+**: Higher importance in search results

Common weighting strategies:
- Hero components: 2.0-3.0 (high visibility content)
- Navigation components: 1.5-2.0 (important for site structure)
- Footer components: 0.5-1.0 (less important)

## Search API Fields

The module creates these Search API fields:

- **component_content**: Main text content from components
- **component_titles**: Title and heading content (weighted higher)
- **component_types**: Component types used (for filtering)
- **component_references**: Content from referenced entities

## Performance Considerations

### Caching
- Enable content extraction caching for better performance
- Cache is automatically cleared when components are updated

### Batch Processing
- Configure batch size based on your server capabilities
- Larger batch sizes are faster but use more memory

### Index Rebuilding
- Component content changes require search index rebuilds
- Use the "Save and rebuild search indexes" button for immediate updates

## Hooks and Events

The module integrates with Drupal's entity system:

- **Entity Insert/Update**: Automatically updates search indexes
- **Entity Delete**: Removes content from search indexes
- **Search Preprocessing**: Enhances search terms with component-related synonyms

## Developer API

### Content Extraction Service

```php
$extractor = \Drupal::service('component_search.content_extractor');

// Extract content for core search
$content = $extractor->extractSearchableContent($entity);

// Extract content for Search API
$data = $extractor->extractSearchApiContent($entity);
```

### Search Manager Service

```php
$manager = \Drupal::service('component_search.search_manager');

// Handle entity changes
$manager->handleEntityChange($entity, 'update');

// Bulk reindex
$results = $manager->bulkReindex(['node', 'media']);
```

## Troubleshooting

### No Search Results
1. Check that component content extraction is enabled
2. Verify search indexes have been rebuilt
3. Ensure entities have component field content

### Poor Search Relevance
1. Adjust component type weights
2. Enable title content separation
3. Review entity reference extraction settings

### Performance Issues
1. Enable content extraction caching
2. Reduce batch processing size
3. Limit entity reference depth

### Content Not Indexed
1. Check field type detection in extraction settings
2. Verify component configurations are valid
3. Review error logs for extraction failures

## Security

- All extracted content is properly sanitized
- HTML tags are stripped from indexed content
- Entity access permissions are respected
- Admin permissions required for configuration

## Compatibility

- Drupal 10.4+ and Drupal 11
- Component Field module (required)
- Search API module (optional, for advanced features)
- Works with standard Drupal core search

## License

GPL-2.0-or-later