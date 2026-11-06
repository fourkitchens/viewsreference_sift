<?php

declare(strict_types=1);

namespace Drupal\viewsreference_sift\Argument;

use Drupal\viewsreference_sift\Selection\MatchMode;
use Drupal\viewsreference_sift\Selection\Selection;

/**
 * Builds Views contextual arguments from a Sift selection.
 */
final class ArgumentBuilder {

  /**
   * Replaces the argument at the selected filter's position.
   *
   * @phpstan-param array<string, \Drupal\viewsreference_sift\Filter\SiftFilter> $filters
   * @phpstan-param list<string> $existing
   * @phpstan-return list<string>
   */
  public function build(array $filters, Selection $selection, array $existing): array {
    $filter = reset($filters);
    if (!$filter) {
      return $existing;
    }
    $value = $selection->filters[$filter->id] ?? NULL;
    if (!$value || !$value->ids) {
      return $existing;
    }

    $delimiter = $filter->supportsAll && $value->match === MatchMode::All ? ',' : '+';
    $existing[$filter->position] = implode($delimiter, $value->ids);
    ksort($existing);
    return array_values($existing);
  }

}
