<?php

namespace Drupal\component_search\Cache;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\Context\CacheContextInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Cache context for component search operations.
 *
 * Cache context ID: 'component_search'
 */
class ComponentSearchCacheContext implements CacheContextInterface {

  /**
   * The request stack.
   */
  protected RequestStack $requestStack;

  /**
   * Constructs a new ComponentSearchCacheContext.
   */
  public function __construct(RequestStack $request_stack) {
    $this->requestStack = $request_stack;
  }

  /**
   * {@inheritdoc}
   */
  public static function getLabel() {
    return t('Component Search');
  }

  /**
   * {@inheritdoc}
   */
  public function getContext() {
    $request = $this->requestStack->getCurrentRequest();
    
    if (!$request) {
      return 'no-request';
    }

    $context_parts = [];

    // Include search-related query parameters
    if ($request->query->has('search')) {
      $context_parts['search'] = $request->query->get('search');
    }

    // Include component-specific parameters
    if ($request->query->has('component_type')) {
      $context_parts['component_type'] = $request->query->get('component_type');
    }

    // Include language context if available
    $language = \Drupal::languageManager()->getCurrentLanguage()->getId();
    $context_parts['language'] = $language;

    // Include user permissions context for search access
    $user = \Drupal::currentUser();
    if ($user->hasPermission('search content')) {
      $context_parts['search_access'] = 'yes';
    } else {
      $context_parts['search_access'] = 'no';
    }

    return empty($context_parts) ? 'default' : md5(serialize($context_parts));
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheableMetadata() {
    $cacheable_metadata = new CacheableMetadata();
    
    // This context depends on the current request
    $cacheable_metadata->setCacheContexts(['url.query_args', 'user.permissions', 'languages']);
    
    return $cacheable_metadata;
  }
}