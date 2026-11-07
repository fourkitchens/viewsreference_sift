<?php

declare(strict_types=1);

namespace Drupal\viewsreference_sift\Selection;

use Drupal\Core\Config\Entity\ConfigEntityTypeInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Enforces selection limits and entity restrictions before the view runs.
 *
 * @internal
 */
final class SelectionValidator {

  /**
   * Supplies entity storage to check selected IDs and bundles.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Caps selections before checking whether IDs exist in allowed bundles.
   *
   * Tries display defaults only for filters without valid editor selections.
   * If neither survives, keeps capped integer IDs so Views can apply its
   * configured failure action, unless Sift narrows the allowed bundles.
   * Strict validation requires all returned IDs to exist.
   *
   * @phpstan-param array<string, \Drupal\viewsreference_sift\Filter\SiftFilter> $filters
   */
  public function validate(Selection $selection, array $filters, bool $strict = FALSE, ?Selection $defaults = NULL): Selection {
    $capped = [];
    $by_type = [];
    foreach ($selection->filters as $id => $selected) {
      if (!isset($filters[$id]) || $selected->ids === []) {
        continue;
      }

      $limit = $filters[$id]->multiple ? $filters[$id]->max : 1;
      $capped[$id] = new FilterSelection(array_slice($selected->ids, 0, $limit), $selected->match);
      $by_type[$filters[$id]->entityTypeId][$id] = $capped[$id];
    }

    $validated = [];
    foreach ($by_type as $entity_type_id => $selections) {
      $validated += $this->validateType($entity_type_id, $selections, $filters);
    }
    if ($defaults !== NULL) {
      $missing = array_diff_key($filters, $validated);
      $validated += $this->validate($defaults, $missing, strict: TRUE)->filters;
    }
    if (!$strict) {
      foreach (array_diff_key($capped, $validated) as $id => $selected) {
        // Views can handle invalid IDs only for restrictions it knows about.
        if (!$filters[$id]->narrowsBundles && $filters[$id]->idType === 'integer') {
          $validated[$id] = $selected;
        }
      }
    }
    return new Selection($validated);
  }

  /**
   * Queries all capped selections for one entity type together.
   *
   * @phpstan-param array<string, FilterSelection> $selections
   * @phpstan-param array<string, \Drupal\viewsreference_sift\Filter\SiftFilter> $filters
   * @phpstan-return array<string, FilterSelection>
   */
  private function validateType(string $entity_type_id, array $selections, array $filters): array {
    $entity_type = $this->entityTypeManager->getDefinition($entity_type_id);
    $id_key = $entity_type->getKey('id');
    if ($id_key === FALSE) {
      return $selections;
    }
    $bundle_key = $entity_type->getKey('bundle');
    $storage = $this->entityTypeManager->getStorage($entity_type_id);
    $query = $storage->getQuery()->accessCheck(FALSE);
    if ($entity_type instanceof ConfigEntityTypeInterface) {
      // A top-level ID condition lets config queries load only these records.
      $ids = array_map(static fn (FilterSelection $selected): array => $selected->ids, $selections);
      $query->condition($id_key, array_merge(...array_values($ids)), 'IN');
    }
    $conditions = $query->orConditionGroup();
    $check_bundles = FALSE;

    foreach ($selections as $id => $selected) {
      $bundles = $filters[$id]->bundles;
      $condition = $query->andConditionGroup()->condition($id_key, $selected->ids, 'IN');
      if ($bundles !== []) {
        if ($bundle_key !== FALSE) {
          $condition->condition($bundle_key, $bundles, 'IN');
        }
        $check_bundles = $bundle_key === FALSE || count($selections) > 1;
      }
      $conditions->condition($condition);
    }

    $existing_ids = $query->condition($conditions)->execute();
    // A match for one filter may be in a bundle excluded by another filter.
    $bundles_by_id = $check_bundles
      ? array_map(static fn (EntityInterface $entity): string => $entity->bundle(), $storage->loadMultiple($existing_ids))
      : NULL;
    $existing = array_map('strval', $existing_ids);
    $validated = [];
    foreach ($selections as $id => $selected) {
      $bundles = $filters[$id]->bundles;
      $valid = array_values(array_filter($selected->ids, static fn (int|string $entity_id): bool =>
        in_array((string) $entity_id, $existing, TRUE)
        && ($bundles_by_id === NULL || $bundles === [] || in_array($bundles_by_id[$entity_id] ?? NULL, $bundles, TRUE))
      ));

      if ($valid !== []) {
        $validated[$id] = new FilterSelection($valid, $selected->match);
      }
    }
    return $validated;
  }

}
