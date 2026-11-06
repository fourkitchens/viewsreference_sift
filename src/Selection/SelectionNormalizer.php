<?php

declare(strict_types=1);

namespace Drupal\viewsreference_sift\Selection;

/**
 * Converts between stored field settings and validated term selections.
 *
 * @phpstan-type StoredFilter array{ids: list<int>, match: value-of<MatchMode>}
 */
final class SelectionNormalizer {

  /**
   * Reads versioned selections and the original single-filter setting.
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
      $first_filter = array_key_first($filters);
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

      $ids = $this->normalizeIds($stored['ids']);
      if ($ids === []) {
        continue;
      }

      $match = $stored['match'] ?? NULL;
      $mode = is_string($match) ? MatchMode::tryFrom($match) : NULL;
      $selections[$filter_id] = new FilterSelection($ids, $filter->resolveMatch($mode ?? MatchMode::Any));
    }
    return new Selection($selections);
  }

  /**
   * Writes the selection as plain data for Views Reference storage.
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
      }
    }
    return [
      'version' => Selection::VERSION,
      'filters' => $filters,
    ];
  }

  /**
   * Keeps unique positive term IDs in the order they were supplied.
   *
   * @phpstan-param array<array-key, mixed> $values
   * @phpstan-return list<int>
   */
  private function normalizeIds(array $values): array {
    $ids = [];
    foreach ($values as $value) {
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
