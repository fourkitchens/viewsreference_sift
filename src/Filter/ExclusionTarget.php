<?php

declare(strict_types=1);

namespace Drupal\viewsreference_sift\Filter;

use Drupal\views\Plugin\views\query\Sql;

/**
 * Excludes base entities matching any selected ID in a vetted table.
 *
 * @internal
 */
final readonly class ExclusionTarget {

  /**
   * Identifies the base ID and the storage columns to look up.
   */
  public function __construct(
    private string $baseColumn,
    private string $table,
    private string $entityColumn,
    private string $targetColumn,
    private bool $dedicated = FALSE,
  ) {}

  /**
   * Adds the exclusion to Views' contextual argument conditions.
   *
   * @phpstan-param non-empty-list<int|string> $ids
   */
  public function apply(Sql $query, array $ids): void {
    $connection = $query->getConnection();
    $matches = $connection->select($this->table, 'sift_match');
    $matches->fields('sift_match', [$this->entityColumn]);
    $matches->condition('sift_match.' . $this->targetColumn, $ids, 'IN');
    if ($this->dedicated) {
      $matches->condition('sift_match.deleted', 0);
    }

    // Group zero is combined with filter groups by AND. Preserve any existing
    // OR in that group as a nested condition before adding the exclusion.
    $group = $query->where[0] ?? [];
    if (($group['type'] ?? 'AND') === 'OR' && !empty($group['conditions'])) {
      $or = $connection->condition('OR');
      foreach ($group['conditions'] as $clause) {
        if ($clause['operator'] === 'formula') {
          $or->where($clause['field'], $clause['value']);
        }
        else {
          $or->condition($clause['field'], $clause['value'], $clause['operator']);
        }
      }
      $query->where[0]['conditions'] = [
        ['field' => $or, 'value' => NULL, 'operator' => '='],
      ];
    }

    $query->setWhereGroup('AND', '0');
    // Views passes these clauses to the database condition builder, which
    // supports a Select as the right-hand side of NOT IN.
    $query->where[0]['conditions'][] = [
      'field' => $this->baseColumn,
      'value' => $matches,
      'operator' => 'NOT IN',
    ];
  }

}
