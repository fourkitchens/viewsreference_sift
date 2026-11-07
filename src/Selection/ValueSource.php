<?php

declare(strict_types=1);

namespace Drupal\viewsreference_sift\Selection;

use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\views\ViewExecutable;

/**
 * Identifies which Sift selection may be used during view execution.
 *
 * @internal
 */
final class ValueSource {

  /**
   * Supplies the current route to identify Views AJAX requests.
   */
  public function __construct(
    private readonly RouteMatchInterface $routeMatch,
  ) {}

  /**
   * Reads the selection from the trusted snapshot or a Views AJAX request.
   *
   * A snapshot is authoritative even when empty. Without one, only Views AJAX
   * can supply a selection. That selection comes from the browser and requires
   * validation before use.
   *
   * @see \Drupal\viewsreference_sift\Hook\SiftHooks::snapshot()
   */
  public function resolve(ViewExecutable $view, mixed $supplied): mixed {
    $trusted = $view->element['#viewsreference_sift']['trusted'] ?? NULL;
    if (is_array($trusted)) {
      return $trusted['data'] ?? NULL;
    }
    return $this->isViewsAjax() ? $supplied : NULL;
  }

  /**
   * Allows defaults only for a Sift-enabled embed or Views AJAX.
   */
  public function allowsDefaults(ViewExecutable $view): bool {
    $trusted = $view->element['#viewsreference_sift']['trusted'] ?? NULL;
    return is_array($trusted) ? !empty($trusted['enabled']) : $this->isViewsAjax();
  }

  /**
   * Identifies a request to the core Views AJAX controller.
   */
  private function isViewsAjax(): bool {
    return $this->routeMatch->getRouteName() === 'views.ajax';
  }

}
