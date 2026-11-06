<?php

declare(strict_types=1);

namespace Drupal\Tests\viewsreference_sift\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\viewsreference_sift\Filter\SiftFilter;
use Drupal\viewsreference_sift\Selection\MatchMode;
use Drupal\viewsreference_sift\Selection\SelectionNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests normalization of stored filter selections.
 *
 * @phpstan-type StoredFilter array{ids: list<int>, match: string}
 */
#[Group('viewsreference_sift')]
class SelectionNormalizerTest extends UnitTestCase {

  /**
   * Tests normalizing and serializing a stored value.
   *
   * @param mixed $stored
   *   The input stored with the field.
   * @param array<string, StoredFilter> $expected
   *   The expected filter selections in storage format.
   */
  #[DataProvider('storedSelections')]
  public function testStoredSelection(mixed $stored, array $expected): void {
    $filters = [
      'tid' => new SiftFilter('tid', 'taxonomy_index_tid', 0, 'taxonomy_term', [], TRUE, TRUE, 'all', 'ignore', 'Terms'),
      'tid_depth' => new SiftFilter('tid_depth', 'taxonomy_index_tid_depth', 1, 'taxonomy_term', [], TRUE, FALSE, 'all', 'ignore', 'Terms with depth'),
    ];
    $normalizer = new SelectionNormalizer();
    $selection = $normalizer->fromStorage($stored, $filters);

    $this->assertSame($expected === [], $selection->isEmpty());
    $this->assertSame(array_keys($expected), array_keys($selection->filters));
    foreach ($expected as $id => $filter_selection) {
      $this->assertSame($filter_selection['ids'], $selection->filters[$id]->ids);
      $this->assertSame(MatchMode::from($filter_selection['match']), $selection->filters[$id]->match);
    }
    $this->assertSame(['version' => 2, 'filters' => $expected], $normalizer->toStorage($selection));
  }

  /**
   * Provides current, legacy, and malformed stored values.
   *
   * @return iterable<string, array{mixed, array<string, StoredFilter>}>
   *   The stored values and expected filter selections.
   */
  public static function storedSelections(): iterable {
    yield 'version 2 selections' => [
      [
        'version' => 2,
        'filters' => [
          'tid' => ['ids' => [12, 15], 'match' => 'all'],
          'tid_depth' => ['ids' => [27], 'match' => 'any'],
        ],
      ],
      [
        'tid' => ['ids' => [12, 15], 'match' => 'all'],
        'tid_depth' => ['ids' => [27], 'match' => 'any'],
      ],
    ];
    yield 'beta1 integer and string IDs' => [
      ['terms' => [['target_id' => 12], ['target_id' => '15']], 'match' => 'all'],
      ['tid' => ['ids' => [12, 15], 'match' => 'all']],
    ];
    yield 'empty string' => ['', []];
    yield 'null' => [NULL, []];
    yield 'malformed version 2 filters' => [
      ['version' => 2, 'filters' => 'invalid'],
      [],
    ];
    yield 'malformed filter entries' => [
      ['version' => 2, 'filters' => ['tid' => 'invalid', 'tid_depth' => ['ids' => '12']]],
      [],
    ];
    yield 'malformed beta1 term entries' => [
      ['terms' => [12, '15', ['id' => 27], ['target_id' => [31]]]],
      [],
    ];
    yield 'zero, negative, and nonnumeric IDs' => [
      [
        'version' => 2,
        'filters' => ['tid' => ['ids' => [0, -12, '0', '-15', 'invalid', '12+15', TRUE, 12.5, []]]],
      ],
      [],
    ];
    yield 'duplicate IDs retain their first position' => [
      ['version' => 2, 'filters' => ['tid' => ['ids' => [15, '15', '12', 15, 12], 'match' => 'any']]],
      ['tid' => ['ids' => [15, 12], 'match' => 'any']],
    ];
    yield 'unknown match uses any' => [
      ['version' => 2, 'filters' => ['tid' => ['ids' => [12], 'match' => 'none']]],
      ['tid' => ['ids' => [12], 'match' => 'any']],
    ];
    yield 'unknown filter is discarded' => [
      [
        'version' => 2,
        'filters' => [
          'unknown' => ['ids' => [99], 'match' => 'all'],
          'tid' => ['ids' => [12], 'match' => 'any'],
        ],
      ],
      ['tid' => ['ids' => [12], 'match' => 'any']],
    ];
  }

}
