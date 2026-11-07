<?php

declare(strict_types=1);

namespace Drupal\viewsreference_sift\Selection;

/**
 * Groups entity selections for one Views display by contextual filter ID.
 *
 * @internal
 */
final readonly class Selection {

  public const VERSION = 2;

  /**
   * Keeps filter IDs as keys so selections can follow display changes.
   *
   * @phpstan-param array<string, FilterSelection> $filters
   */
  public function __construct(
    public array $filters,
  ) {}

  /**
   * Reports whether all filters are empty so execution can skip argument work.
   */
  public function isEmpty(): bool {
    foreach ($this->filters as $filter) {
      if ($filter->ids !== []) {
        return FALSE;
      }
    }
    return TRUE;
  }

}
