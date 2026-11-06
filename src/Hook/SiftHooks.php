<?php

declare(strict_types=1);

namespace Drupal\viewsreference_sift\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Hook\Order\Order;
use Drupal\views\ViewExecutable;
use Drupal\viewsreference_sift\Argument\ArgumentBuilder;
use Drupal\viewsreference_sift\Filter\FilterResolver;
use Drupal\viewsreference_sift\Selection\SelectionNormalizer;

/**
 * Provides Views hooks for Sift.
 */
final class SiftHooks {

  /**
   * Constructs the Views hooks.
   */
  public function __construct(
    private readonly FilterResolver $filterResolver,
    private readonly SelectionNormalizer $selectionNormalizer,
    private readonly ArgumentBuilder $argumentBuilder,
  ) {}

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

  /**
   * Applies the selection after Views Reference has set its arguments.
   *
   * @phpstan-param list<string> $args
   */
  #[Hook('views_pre_view', order: Order::Last)]
  public function applySelection(ViewExecutable $view, string $display_id, array &$args): void {
    $pending = $view->element['#viewsreference_sift']['selection'] ?? NULL;
    unset($view->element['#viewsreference_sift']['selection']);
    if (!$pending || $pending['display_id'] !== $display_id) {
      return;
    }

    $filters = array_intersect_key($this->filterResolver->forView($view), array_fill_keys($pending['filter_ids'], TRUE));
    $selection = $this->selectionNormalizer->fromStorage($pending['data'], $filters);
    if (!$selection->isEmpty()) {
      $args = $this->argumentBuilder->build($filters, $selection, $args);
    }
  }

}
