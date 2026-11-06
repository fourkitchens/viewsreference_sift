<?php

declare(strict_types=1);

namespace Drupal\viewsreference_sift\Selection;

/**
 * Pairs entity IDs with the match mode for one contextual filter.
 */
final readonly class FilterSelection {

  /**
   * Keeps selected IDs in editor order for argument building and limits.
   *
   * @phpstan-param list<int> $ids
   */
  public function __construct(
    public array $ids,
    public MatchMode $match,
  ) {}

}
