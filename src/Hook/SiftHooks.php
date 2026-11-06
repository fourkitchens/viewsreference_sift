<?php

declare(strict_types=1);

namespace Drupal\viewsreference_sift\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Hook\Order\Order;
use Drupal\views\ViewExecutable;

/**
 * Provides Views hooks for Sift.
 */
final class SiftHooks {

  /**
   * Snapshots the server-set selection on the first pre-view pass.
   *
   * @phpstan-param array<array-key, mixed> $args
   */
  #[Hook('views_pre_view', order: Order::First)]
  public function snapshot(ViewExecutable $view, string $display_id, array &$args): void {
    if (isset($view->element['#viewsreference_sift']['checked'])) {
      return;
    }
    // Mark bare views too, before Views Reference can add request metadata.
    $view->element['#viewsreference_sift']['checked'] = TRUE;

    if (isset($view->element['#viewsreference']) && !isset($view->element['#viewsreference_sift']['trusted'])) {
      $data = $view->element['#viewsreference']['data'] ?? NULL;
      $view->element['#viewsreference_sift']['trusted'] = [
        'data' => is_array($data) ? ($data['sift_terms'] ?? NULL) : NULL,
      ];
    }
  }

}
