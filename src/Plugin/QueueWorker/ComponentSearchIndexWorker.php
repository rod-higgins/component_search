<?php

namespace Drupal\component_search\Batch;

use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Batch operations for rebuilding search indexes.
 */
class RebuildSearchIndexBatch {

  use StringTranslationTrait;

  /**
   * Clear Drupal core search index.
   */
  public static function clearCoreSearch(&$context) {
    if (!isset($context['sandbox']['progress'])) {
      $context['sandbox']['progress'] = 0;
      $context['sandbox']['max'] = 1;
      $context['message'] = t('Clearing Drupal core search index...');
    }

    try {
      if (\Drupal::hasService('search.index')) {
        \Drupal::service('search.index')->clear();
        $context['results']['core_search'] = TRUE;
        $context['message'] = t('Drupal core search index cleared successfully.');
      } else {
        $context['results']['errors'][] = t('Search index service not available.');
      }
    } catch (\Exception $e) {
      $context['results']['errors'][] = t('Error clearing core search index: @error', [
        '@error' => $e->getMessage(),
      ]);
    }

    $context['sandbox']['progress']++;
    $context['finished'] = 1;
  }

  /**
   * Clear Search API indexes.
   */
  public static function clearSearchApiIndexes(&$context) {
    if (!isset($context['sandbox']['progress'])) {
      $context['sandbox']['progress'] = 0;
      $context['sandbox']['indexes'] = [];
      
      try {
        if (!\Drupal::moduleHandler()->moduleExists('search_api')) {
          $context['results']['errors'][] = t('Search API module is not enabled.');
          $context['finished'] = 1;
          return;
        }

        $index_storage = \Drupal::entityTypeManager()->getStorage('search_api_index');
        $indexes = $index_storage->loadMultiple();
        
        foreach ($indexes as $index) {
          if ($index->status()) {
            $context['sandbox']['indexes'][] = $index;
          }
        }
        
        $context['sandbox']['max'] = count($context['sandbox']['indexes']);
      } catch (\Exception $e) {
        $context['results']['errors'][] = t('Error loading Search API indexes: @error', [
          '@error' => $e->getMessage(),
        ]);
        $context['finished'] = 1;
        return;
      }
    }

    if (empty($context['sandbox']['indexes'])) {
      $context['message'] = t('No active Search API indexes found.');
      $context['finished'] = 1;
      return;
    }

    $index = $context['sandbox']['indexes'][$context['sandbox']['progress']];
    
    try {
      $index->clear();
      $context['results']['search_api_indexes'][] = $index->label();
      $context['message'] = t('Cleared Search API index: @name', [
        '@name' => $index->label(),
      ]);
    } catch (\Exception $e) {
      $context['results']['errors'][] = t('Error clearing Search API index @name: @error', [
        '@name' => $index->label(),
        '@error' => $e->getMessage(),
      ]);
    }

    $context['sandbox']['progress']++;
    $context['finished'] = $context['sandbox']['progress'] / $context['sandbox']['max'];
  }

  /**
   * Batch finished callback.
   */
  public static function finished($success, $results, $operations) {
    $messenger = \Drupal::messenger();

    if ($success) {
      if (!empty($results['core_search'])) {
        $messenger->addStatus(t('Drupal core search index cleared and will be rebuilt.'));
      }

      if (!empty($results['search_api_indexes'])) {
        $messenger->addStatus(t('Search API indexes cleared: @indexes', [
          '@indexes' => implode(', ', $results['search_api_indexes']),
        ]));
      }

      if (empty($results['core_search']) && empty($results['search_api_indexes'])) {
        $messenger->addWarning(t('No search indexes were found to rebuild.'));
      }
    } else {
      $messenger->addError(t('Search index rebuild completed with errors.'));
    }

    if (!empty($results['errors'])) {
      foreach ($results['errors'] as $error) {
        $messenger->addError($error);
      }
    }

    // Clear component search cache
    try {
      \Drupal::cache()->invalidateAll();
      if (\Drupal::hasService('component_search.cache_manager')) {
        \Drupal::service('component_search.cache_manager')->invalidateAllCache();
      }
    } catch (\Exception $e) {
      $messenger->addWarning(t('Error clearing component search cache: @error', [
        '@error' => $e->getMessage(),
      ]));
    }
  }
}