<?php

declare(strict_types=1);

namespace Drupal\viewsreference_sift\Selection;

/**
 * Stores the term selections for a View display.
 */
final readonly class Selection {

  public const VERSION = 2;

  /**
   * Constructs the selections keyed by contextual filter ID.
   *
   * @phpstan-param array<string, FilterSelection> $filters
   */
  public function __construct(
    public array $filters,
  ) {}

  /**
   * Returns whether the display has any selected terms.
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
