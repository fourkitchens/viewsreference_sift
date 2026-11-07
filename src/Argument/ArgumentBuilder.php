<?php

declare(strict_types=1);

namespace Drupal\viewsreference_sift\Argument;

use Drupal\viewsreference_sift\Selection\MatchMode;
use Drupal\viewsreference_sift\Selection\Selection;
use Drupal\viewsreference_sift\Selection\SelectionMode;

/**
 * Keeps Sift selections aligned with the display's contextual arguments.
 *
 * @internal
 */
final class ArgumentBuilder {

  /**
   * Merges selected values into the display's positional arguments.
   *
   * Preserves values for other filters and fills gaps where the missing-value
   * action allows it. Stops at the first gap Views needs to handle itself.
   *
   * @phpstan-param array<string, array{default_action?: string, exception?: array{value?: string}}> $arguments
   * @phpstan-param array<string, \Drupal\viewsreference_sift\Filter\SiftFilter> $filters
   * @phpstan-param array<int, string|int|null> $existing
   * @phpstan-param callable(string): ?string $defaultFor
   * @phpstan-return list<string>
   */
  public function build(array $arguments, array $filters, Selection $selection, array $existing, callable $defaultFor): array {
    $values = [];
    foreach ($existing as $position => $value) {
      if ($value !== NULL && $value !== '') {
        $values[$position] = (string) $value;
      }
    }

    foreach ($filters as $id => $filter) {
      $selected = $selection->filters[$id] ?? NULL;
      if (!$selected || $selected->ids === []) {
        continue;
      }

      if ($filter->allowExclude && $selected->mode === SelectionMode::Exclude) {
        // The query hook applies exclusions instead of the argument handler.
        $values[$filter->position] = $filter->exceptionValue;
        continue;
      }

      $ids = $filter->multiple ? $selected->ids : [$selected->ids[0]];
      $delimiter = $filter->supportsAll && $selected->match === MatchMode::All ? ',' : '+';
      $values[$filter->position] = implode($delimiter, $ids);
    }

    if ($values === []) {
      return [];
    }

    $argument_ids = array_keys($arguments);
    $last_position = max(array_keys($values));
    $merged = [];
    for ($position = 0; $position <= $last_position; $position++) {
      if (isset($values[$position])) {
        $merged[] = $values[$position];
        continue;
      }

      $id = $argument_ids[$position] ?? NULL;
      $argument = $id !== NULL ? $arguments[$id] : [];
      $action = $argument['default_action'] ?? 'ignore';
      $default = $id !== NULL && $action === 'default' ? $defaultFor($id) : NULL;
      if (!self::allowsLaterArguments($action, $default !== NULL)) {
        break;
      }
      $merged[] = $default ?? $argument['exception']['value'] ?? 'all';
    }
    return $merged;
  }

  /**
   * Determines whether Sift can fill a gap so later arguments apply.
   *
   * @param string $action
   *   The Views action configured for the missing argument.
   * @param bool $has_default
   *   Whether a default value is available for the argument.
   */
  public static function allowsLaterArguments(string $action, bool $has_default): bool {
    return $action === 'ignore' || ($action === 'default' && $has_default);
  }

}
