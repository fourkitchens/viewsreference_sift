<?php

declare(strict_types=1);

namespace Drupal\viewsreference_sift\Selection;

use Drupal\viewsreference_sift\Filter\SiftFilter;

/**
 * Adapts current and legacy field settings to the selection objects Sift uses.
 *
 * @internal
 *
 * @phpstan-type StoredFilter array{ids: list<int|string>, match: value-of<MatchMode>, mode?: 'exclude'}
 */
final class SelectionNormalizer {

  /**
   * Reads versioned selections and the original single-filter setting.
   *
   * Maps the original setting to the first integer filter and normalizes
   * values against the enabled filters and their current match policies.
   *
   * @phpstan-param array<string, \Drupal\viewsreference_sift\Filter\SiftFilter> $filters
   */
  public function fromStorage(mixed $value, array $filters): Selection {
    if (!is_array($value)) {
      return new Selection([]);
    }

    if (array_key_exists('version', $value)) {
      if ($value['version'] !== Selection::VERSION || !is_array($value['filters'] ?? NULL)) {
        return new Selection([]);
      }
      $stored_filters = $value['filters'];
    }
    else {
      $first_filter = array_key_first(array_filter($filters, static fn (SiftFilter $filter): bool => $filter->idType === 'integer'));
      if ($first_filter === NULL || !is_array($value['terms'] ?? NULL)) {
        return new Selection([]);
      }
      $stored_filters = [
        $first_filter => [
          'ids' => array_map(static fn (mixed $term): mixed => is_array($term) ? ($term['target_id'] ?? NULL) : NULL, $value['terms']),
          'match' => $value['match'] ?? NULL,
        ],
      ];
    }

    $selections = [];
    foreach ($filters as $filter_id => $filter) {
      $stored = $stored_filters[$filter_id] ?? NULL;
      if (!$filter->enabled || !is_array($stored) || !is_array($stored['ids'] ?? NULL)) {
        continue;
      }

      $ids = $this->normalizeIds($stored['ids'], $filter);
      if ($ids === []) {
        continue;
      }

      $match = $stored['match'] ?? NULL;
      $match = is_string($match) ? MatchMode::tryFrom($match) : NULL;
      $exclude = $filter->allowExclude && ($stored['mode'] ?? NULL) === SelectionMode::Exclude->value;
      $selections[$filter_id] = new FilterSelection(
        $ids,
        $exclude ? MatchMode::Any : $filter->resolveMatch($match ?? MatchMode::Any),
        $exclude ? SelectionMode::Exclude : SelectionMode::Include,
      );
    }
    return new Selection($selections);
  }

  /**
   * Reads display defaults using each enabled filter's ID and match settings.
   *
   * @phpstan-param array<string, SiftFilter> $filters
   */
  public function fromDefaults(array $filters): Selection {
    $selections = [];
    foreach ($filters as $id => $filter) {
      if ($filter->enabled && ($ids = $this->normalizeIds($filter->defaultIds, $filter)) !== []) {
        $selections[$id] = new FilterSelection($ids, $filter->resolveMatch(MatchMode::Any));
      }
    }
    return new Selection($selections);
  }

  /**
   * Writes the selection as plain data for Views Reference storage.
   *
   * Uses arrays and scalar values because Views Reference reads serialized
   * field settings with object creation disabled.
   *
   * @phpstan-return array{version: 2, filters: array<string, StoredFilter>}
   */
  public function toStorage(Selection $selection): array {
    $filters = [];
    foreach ($selection->filters as $filter_id => $filter) {
      if ($filter->ids !== []) {
        $filters[$filter_id] = [
          'ids' => $filter->ids,
          'match' => $filter->match->value,
        ];
        if ($filter->mode === SelectionMode::Exclude) {
          $filters[$filter_id]['mode'] = $filter->mode->value;
        }
      }
    }
    return [
      'version' => Selection::VERSION,
      'filters' => $filters,
    ];
  }

  /**
   * Keeps unique valid IDs of the filter's type in their supplied order.
   *
   * @phpstan-param array<array-key, mixed> $values
   * @phpstan-return list<int|string>
   */
  public function normalizeIds(array $values, SiftFilter $filter): array {
    $ids = [];
    foreach ($values as $value) {
      if ($filter->idType === 'string') {
        if (is_string($value) && SiftFilter::isValidStringId($value)) {
          $ids[$value] = $value;
        }
        continue;
      }
      if (!is_int($value) && !is_string($value)) {
        continue;
      }
      $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
      if ($id !== FALSE) {
        $ids[$id] = $id;
      }
    }
    return array_values($ids);
  }

}
