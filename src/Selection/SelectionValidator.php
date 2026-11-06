<?php

declare(strict_types=1);

namespace Drupal\viewsreference_sift\Selection;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Caps selections and checks whether their IDs match the filter definitions.
 */
final class SelectionValidator {

  /**
   * Constructs a selection validator.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Keeps existing IDs in allowed bundles, within the per-filter limit.
   *
   * @phpstan-param array<string, \Drupal\viewsreference_sift\Filter\SiftFilter> $filters
   */
  public function validate(Selection $selection, array $filters): Selection {
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

    foreach ($by_type as $entity_type_id => $selections) {
      $capped = array_replace($capped, $this->validateType($entity_type_id, $selections, $filters));
    }
    return new Selection(array_filter($capped));
  }

  /**
   * Queries all capped selections for one entity type together.
   *
   * @phpstan-param array<string, FilterSelection> $selections
   * @phpstan-param array<string, \Drupal\viewsreference_sift\Filter\SiftFilter> $filters
   * @phpstan-return array<string, FilterSelection|null>
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
      $valid = array_values(array_filter($selected->ids, static fn (int $entity_id): bool =>
        in_array((string) $entity_id, $existing, TRUE)
        && ($bundles_by_id === NULL || $bundles === [] || in_array($bundles_by_id[$entity_id] ?? NULL, $bundles, TRUE))
      ));

      // Views can handle invalid IDs only for restrictions it knows about.
      $validated[$id] = $valid !== []
        ? new FilterSelection($valid, $selected->match)
        : ($filters[$id]->narrowsBundles ? NULL : $selected);
    }
    return $validated;
  }

}
