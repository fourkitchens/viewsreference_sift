<?php

declare(strict_types=1);

namespace Drupal\viewsreference_sift\Selection;

/**
 * Stores the selected terms and match mode for one contextual filter.
 */
final readonly class FilterSelection {

  /**
   * Constructs a contextual filter selection.
   *
   * @phpstan-param list<int> $ids
   */
  public function __construct(
    public array $ids,
    public MatchMode $match,
  ) {}

}
