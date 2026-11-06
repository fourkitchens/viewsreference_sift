<?php

declare(strict_types=1);

namespace Drupal\Tests\viewsreference_sift\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\viewsreference_sift\Argument\ArgumentBuilder;
use Drupal\viewsreference_sift\Filter\SiftFilter;
use Drupal\viewsreference_sift\Selection\FilterSelection;
use Drupal\viewsreference_sift\Selection\MatchMode;
use Drupal\viewsreference_sift\Selection\Selection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests building positional Views arguments from filter selections.
 */
#[Group('viewsreference_sift')]
class ArgumentBuilderTest extends UnitTestCase {

  /**
   * Tests replacing arguments when the first filter has selected IDs.
   *
   * @param string $plugin_id
   *   The contextual filter plugin ID.
   * @param bool $supports_all
   *   Whether the filter supports matching all selected values.
   * @param \Drupal\viewsreference_sift\Selection\MatchMode $match
   *   The requested match mode.
   * @param string $expected
   *   The formatted argument.
   */
  #[DataProvider('matchModes')]
  public function testBuild(string $plugin_id, bool $supports_all, MatchMode $match, string $expected): void {
    $filters = [
      'tid' => new SiftFilter('tid', $plugin_id, 0, 'taxonomy_term', [], TRUE, $supports_all, 'all', 'ignore', 'Terms'),
    ];
    $selection = new Selection(['tid' => new FilterSelection([12, 15], $match)]);
    $builder = new ArgumentBuilder();
    $existing = ['first', 'second'];

    $this->assertSame([$expected], $builder->build($filters, $selection, []));
    $this->assertSame([$expected], $builder->build($filters, $selection, $existing));
    $this->assertSame($existing, $builder->build($filters, new Selection([]), $existing));
  }

  /**
   * Provides supported and unsupported match modes.
   *
   * @return iterable<string, array{string, bool, MatchMode, string}>
   *   The plugin, supported mode, requested mode, and formatted argument.
   */
  public static function matchModes(): iterable {
    yield 'any' => ['taxonomy_index_tid', TRUE, MatchMode::Any, '12+15'];
    yield 'all' => ['taxonomy_index_tid', TRUE, MatchMode::All, '12,15'];
    yield 'all on the depth filter uses any' => ['taxonomy_index_tid_depth', FALSE, MatchMode::All, '12+15'];
  }

}
