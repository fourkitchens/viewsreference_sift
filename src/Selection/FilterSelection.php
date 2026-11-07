<?php

declare(strict_types=1);

namespace Drupal\viewsreference_sift\Selection;

/**
 * Pairs entity IDs with their matching and inclusion settings.
 *
 * @internal
 */
final readonly class FilterSelection {

  /**
   * Keeps selected IDs in editor order for argument building and limits.
   *
   * @phpstan-param list<int|string> $ids
   */
  public function __construct(
    public array $ids,
    public MatchMode $match,
    public SelectionMode $mode = SelectionMode::Include,
  ) {}

}
