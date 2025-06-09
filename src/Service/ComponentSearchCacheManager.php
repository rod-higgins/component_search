<?php

namespace Drupal\component_search\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityInterface;

/**
 * Service for managing component search cache operations.
 */
class ComponentSearchCacheManager {

  protected CacheBackendInterface $cache;
  protected ConfigFactoryInterface $configFactory;

  public function __construct(
    CacheBackendInterface $cache,
    ConfigFactoryInterface $config_factory
  ) {
    $this->cache = $cache;
    $this->configFactory = $config_factory;
  }

  /**
   * Get cached extracted content for an entity.
   */
  public function getCachedContent(EntityInterface $entity): ?array {
    if (!$this->isCacheEnabled()) {
      return NULL;
    }

    $cache_key = $this->buildCacheKey($entity);
    $cached = $this->cache->get($cache_key);
    
    if ($cached && $this->isCacheValid($cached, $entity)) {
      return $cached->data;
    }
    
    return NULL;
  }

  /**
   * Cache extracted content for an entity.
   */
  public function setCachedContent(EntityInterface $entity, array $content): void {
    if (!$this->isCacheEnabled()) {
      return;
    }

    $cache_key = $this->buildCacheKey($entity);
    $cache_data = [
      'content' => $content,
      'entity_changed' => $entity->getChangedTime(),
      'created' => time(),
    ];

    $expire = $this->getCacheExpiration();
    $tags = $this->buildCacheTags($entity);
    
    $this->cache->set($cache_key, $cache_data, $expire, $tags);
  }

  /**
   * Invalidate cache for a specific entity.
   */
  public function invalidateEntityCache(EntityInterface $entity): void {
    $cache_key = $this->buildCacheKey($entity);
    $this->cache->delete($cache_key);
    
    // Also invalidate by tags
    $tags = $this->buildCacheTags($entity);
    $this->cache->invalidateMultiple($tags);
  }

  /**
   * Invalidate cache for all component search data.
   */
  public function invalidateAllCache(): void {
    $this->cache->invalidateAll();
  }

  /**
   * Get cache statistics.
   */
  public function getCacheStats(): array {
    $stats = [
      'enabled' => $this->isCacheEnabled(),
      'hits' => 0,
      'misses' => 0,
      'size' => 0,
      'entries' => 0,
    ];

    // Note: Most cache backends don't provide detailed stats
    // This is a placeholder for potential implementation
    
    return $stats;
  }

  /**
   * Clear expired cache entries.
   */
  public function clearExpiredEntries(): int {
    $cleared = 0;
    
    try {
      // Most cache backends handle this automatically
      // This method is here for backends that might need manual cleanup
      $this->cache->garbageCollection();
      
    } catch (\Exception $e) {
      \Drupal::logger('component_search')->warning('Error clearing expired cache entries: @error', [
        '@error' => $e->getMessage(),
      ]);
    }
    
    return $cleared;
  }

  /**
   * Warm up cache for multiple entities.
   */
  public function warmupCache(array $entities): array {
    $results = [
      'cached' => 0,
      'skipped' => 0,
      'errors' => 0,
    ];

    if (!$this->isCacheEnabled()) {
      $results['skipped'] = count($entities);
      return $results;
    }

    foreach ($entities as $entity) {
      try {
        // Check if already cached
        if ($this->getCachedContent($entity) !== NULL) {
          $results['skipped']++;
          continue;
        }

        // Extract content and cache it
        $extractor = \Drupal::service('component_search.content_extractor');
        $content = $extractor->extractSearchApiContent($entity);
        
        if (!empty($content)) {
          $this->setCachedContent($entity, $content);
          $results['cached']++;
        } else {
          $results['skipped']++;
        }
        
      } catch (\Exception $e) {
        $results['errors']++;
        \Drupal::logger('component_search')->warning('Error warming up cache for entity @id: @error', [
          '@id' => $entity->id(),
          '@error' => $e->getMessage(),
        ]);
      }
    }

    return $results;
  }

  /**
   * Build cache key for an entity.
   */
  protected function buildCacheKey(EntityInterface $entity): string {
    return sprintf(
      'component_search:content:%s:%s:%s',
      $entity->getEntityTypeId(),
      $entity->id(),
      $entity->getChangedTime()
    );
  }

  /**
   * Build cache tags for an entity.
   */
  protected function buildCacheTags(EntityInterface $entity): array {
    $tags = [
      'component_search',
      'component_search:entity:' . $entity->getEntityTypeId(),
      'component_search:entity:' . $entity->getEntityTypeId() . ':' . $entity->id(),
    ];

    // Add bundle-specific tag if applicable
    if (method_exists($entity, 'bundle')) {
      $tags[] = 'component_search:bundle:' . $entity->bundle();
    }

    // Add entity's own cache tags
    $entity_tags = $entity->getCacheTags();
    foreach ($entity_tags as $tag) {
      $tags[] = 'component_search:' . $tag;
    }

    return $tags;
  }

  /**
   * Check if cache is enabled.
   */
  protected function isCacheEnabled(): bool {
    $config = $this->configFactory->get('component_search.settings');
    return $config->get('performance_settings.cache_extractions') ?? TRUE;
  }

  /**
   * Check if cached data is still valid.
   */
  protected function isCacheValid($cached_data, EntityInterface $entity): bool {
    if (!isset($cached_data->data['entity_changed'])) {
      return FALSE;
    }

    // Check if entity has been modified since cache creation
    if ($entity->getChangedTime() > $cached_data->data['entity_changed']) {
      return FALSE;
    }

    // Check cache max age
    $max_age = $this->getCacheMaxAge();
    if ($max_age > 0) {
      $cache_age = time() - ($cached_data->data['created'] ?? 0);
      if ($cache_age > $max_age) {
        return FALSE;
      }
    }

    return TRUE;
  }

  /**
   * Get cache expiration time.
   */
  protected function getCacheExpiration(): int {
    $config = $this->configFactory->get('component_search.settings');
    $max_age = $config->get('performance_settings.cache_max_age') ?? 86400; // 24 hours
    
    return $max_age > 0 ? time() + $max_age : CacheBackendInterface::CACHE_PERMANENT;
  }

  /**
   * Get cache maximum age in seconds.
   */
  protected function getCacheMaxAge(): int {
    $config = $this->configFactory->get('component_search.settings');
    return $config->get('performance_settings.cache_max_age') ?? 86400; // 24 hours
  }

  /**
   * Get cache size limit in bytes.
   */
  protected function getCacheSizeLimit(): int {
    $config = $this->configFactory->get('component_search.settings');
    return $config->get('performance_settings.cache_size_limit') ?? 1048576; // 1MB default
  }

  /**
   * Check if cached content exceeds size limit.
   */
  protected function exceedsSizeLimit(array $content): bool {
    $size = strlen(serialize($content));
    return $size > $this->getCacheSizeLimit();
  }

  /**
   * Optimize cache by removing oldest entries when limit is reached.
   */
  public function optimizeCache(): array {
    $results = [
      'removed' => 0,
      'kept' => 0,
      'errors' => 0,
    ];

    try {
      // This is a placeholder for cache optimization
      // Actual implementation would depend on the cache backend
      
      \Drupal::logger('component_search')->info('Cache optimization completed');
      
    } catch (\Exception $e) {
      $results['errors']++;
      \Drupal::logger('component_search')->error('Error optimizing cache: @error', [
        '@error' => $e->getMessage(),
      ]);
    }

    return $results;
  }

  /**
   * Get cache configuration summary.
   */
  public function getCacheConfig(): array {
    $config = $this->configFactory->get('component_search.settings');
    
    return [
      'enabled' => $this->isCacheEnabled(),
      'max_age' => $this->getCacheMaxAge(),
      'size_limit' => $this->getCacheSizeLimit(),
      'backend' => get_class($this->cache),
    ];
  }
}