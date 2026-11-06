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
   * Tests formatting selected IDs while keeping later arguments.
   *
   * @param string $plugin_id
   *   The contextual filter plugin ID.
   * @param bool $multiple
   *   Whether the filter supports multiple values.
   * @param bool $supports_all
   *   Whether the filter supports matching all selected values.
   * @param \Drupal\viewsreference_sift\Selection\MatchMode $match
   *   The requested match mode.
   * @param string $expected
   *   The formatted argument.
   */
  #[DataProvider('matchModes')]
  public function testBuild(string $plugin_id, bool $multiple, bool $supports_all, MatchMode $match, string $expected): void {
    $arguments = ['tid' => [], 'second' => []];
    $filters = [
      'tid' => new SiftFilter('tid', $plugin_id, 0, 'taxonomy_term', [], $multiple, $supports_all, 'all', 'ignore', 'Terms'),
    ];
    $selection = new Selection(['tid' => new FilterSelection([12, 15], $match)]);
    $builder = new ArgumentBuilder();
    $existing = ['first', 'second'];
    $default_for = static fn (string $id): ?string => NULL;

    $this->assertSame([$expected], $builder->build($arguments, $filters, $selection, [], $default_for));
    $this->assertSame([$expected, 'second'], $builder->build($arguments, $filters, $selection, $existing, $default_for));
    $this->assertSame($existing, $builder->build($arguments, $filters, new Selection([]), $existing, $default_for));
  }

  /**
   * Provides supported and unsupported match modes.
   *
   * @return iterable<string, array{string, bool, bool, MatchMode, string}>
   *   The plugin, capabilities, requested mode, and formatted argument.
   */
  public static function matchModes(): iterable {
    yield 'any' => ['taxonomy_index_tid', TRUE, TRUE, MatchMode::Any, '12+15'];
    yield 'all' => ['taxonomy_index_tid', TRUE, TRUE, MatchMode::All, '12,15'];
    yield 'all on the depth filter uses any' => ['taxonomy_index_tid_depth', TRUE, FALSE, MatchMode::All, '12+15'];
    yield 'single-value filter uses the first ID' => ['taxonomy_index_tid', FALSE, FALSE, MatchMode::All, '12'];
  }

  /**
   * Tests that only positions with a nonempty selection are replaced.
   */
  public function testBuildPreservesUnownedValues(): void {
    $arguments = [
      'before' => [],
      'tid' => [],
      'unselected' => [],
      'tid_depth' => [],
      'after' => [],
    ];
    $filters = [
      'tid' => new SiftFilter('tid', 'taxonomy_index_tid', 1, 'taxonomy_term', [], TRUE, TRUE, 'all', 'ignore', 'Terms'),
      'unselected' => new SiftFilter('unselected', 'taxonomy_index_tid', 2, 'taxonomy_term', [], TRUE, TRUE, 'all', 'ignore', 'Terms'),
      'tid_depth' => new SiftFilter('tid_depth', 'taxonomy_index_tid_depth', 3, 'taxonomy_term', [], TRUE, FALSE, 'all', 'ignore', 'Terms'),
    ];
    $selection = new Selection([
      'tid_depth' => new FilterSelection([30, 31], MatchMode::All),
      'unselected' => new FilterSelection([], MatchMode::Any),
      'tid' => new FilterSelection([12, 15], MatchMode::All),
    ]);
    $builder = new ArgumentBuilder();
    $existing = ['0', 'replace', 'keep', 'old', 'after', 6 => 'extra'];
    $default_for = static fn (string $id): ?string => NULL;

    $expected = ['0', '12,15', 'keep', '30+31', 'after', 'all', 'extra'];
    $actual = $builder->build($arguments, $filters, $selection, $existing, $default_for);
    $this->assertSame($expected, $actual);
    $this->assertSame($expected, $builder->build($arguments, $filters, $selection, $actual, $default_for));
  }

  /**
   * Tests that defaults and exceptions fill interior gaps only.
   */
  public function testBuildFillsInteriorGaps(): void {
    $arguments = [
      'with_default' => ['default_action' => 'default'],
      'with_exception' => ['default_action' => 'ignore', 'exception' => ['value' => 'skip']],
      'with_implicit_exception' => [],
      'tid' => [],
      'unused_trailing' => ['default_action' => 'default'],
    ];
    $filters = [
      'tid' => new SiftFilter('tid', 'taxonomy_index_tid', 3, 'taxonomy_term', [], TRUE, TRUE, 'all', 'ignore', 'Terms'),
    ];
    $selection = new Selection(['tid' => new FilterSelection([12, 15], MatchMode::Any)]);
    $builder = new ArgumentBuilder();
    $called = [];
    $default_for = static function (string $id) use (&$called): ?string {
      $called[] = $id;
      return $id === 'with_default' ? '0' : NULL;
    };

    $this->assertSame(['0', 'skip', 'all', '12+15'], $builder->build($arguments, $filters, $selection, [1 => ''], $default_for));
    $this->assertSame(['with_default'], $called);
  }

  /**
   * Tests how each missing-value action affects later arguments.
   *
   * @param string $action
   *   The action configured for the missing argument.
   * @param string|null $default
   *   The computed default value, if available.
   * @param string[] $expected
   *   The arguments that Sift passes to Views.
   */
  #[DataProvider('missingValueActions')]
  public function testBuildHonorsMissingValueAction(string $action, ?string $default, array $expected): void {
    $arguments = [
      'before' => [],
      'missing' => ['default_action' => $action, 'exception' => ['value' => 'skip']],
      'tid' => [],
      'after' => [],
    ];
    $filters = [
      'tid' => new SiftFilter('tid', 'taxonomy_index_tid', 2, 'taxonomy_term', [], TRUE, TRUE, 'all', 'ignore', 'Terms'),
    ];
    $selection = new Selection(['tid' => new FilterSelection([12, 15], MatchMode::Any)]);
    $builder = new ArgumentBuilder();
    $existing = ['keep', NULL, NULL, 'tail'];

    $this->assertSame($expected, $builder->build($arguments, $filters, $selection, $existing, static fn (string $id): ?string => $default));
  }

  /**
   * Provides Views actions and the arguments expected after a gap.
   *
   * @return iterable<string, array{string, ?string, list<string>}>
   *   The action, computed default, and expected arguments.
   */
  public static function missingValueActions(): iterable {
    yield 'display all' => ['ignore', NULL, ['keep', 'skip', '12+15', 'tail']];
    yield 'provide a default' => ['default', '0', ['keep', '0', '12+15', 'tail']];
    yield 'provide an empty default' => ['default', '', ['keep', '', '12+15', 'tail']];
    yield 'unavailable default' => ['default', NULL, ['keep']];
    yield 'hide view' => ['not found', NULL, ['keep']];
    yield 'show no results content' => ['empty', NULL, ['keep']];
    yield 'access denied' => ['access denied', NULL, ['keep']];
    yield 'show summary' => ['summary', NULL, ['keep']];
  }

}
