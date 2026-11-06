<?php

declare(strict_types=1);

namespace Drupal\viewsreference_sift\Filter;

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Describes an eligible contextual filter on a Views display.
 */
final readonly class SiftFilter {

  /**
   * Constructs a Sift filter description.
   *
   * @param string $id
   *   The argument handler ID.
   * @param string $pluginId
   *   The Views argument plugin ID.
   * @param int $position
   *   The zero-based position among the display's arguments.
   * @param string $entityTypeId
   *   The entity type the argument accepts.
   * @param string[] $bundles
   *   The allowed bundle IDs. An empty list allows all bundles.
   * @param bool $multiple
   *   Whether the argument accepts multiple values.
   * @param bool $supportsAll
   *   Whether the argument can require all supplied values to match.
   * @param string $exceptionValue
   *   The argument value that skips this filter.
   * @param string $defaultAction
   *   The Views action to take when the argument is missing.
   * @param string|\Drupal\Core\StringTranslation\TranslatableMarkup $label
   *   The display label for the filter.
   *
   * @phpstan-param list<string> $bundles
   */
  public function __construct(
    public string $id,
    public string $pluginId,
    public int $position,
    public string $entityTypeId,
    public array $bundles,
    public bool $multiple,
    public bool $supportsAll,
    public string $exceptionValue,
    public string $defaultAction,
    public string|TranslatableMarkup $label,
  ) {}

}
