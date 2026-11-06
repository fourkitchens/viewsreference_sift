<?php

declare(strict_types=1);

namespace Drupal\Tests\viewsreference_sift\FunctionalJavascript;

// cspell:ignore Behat
use Behat\Mink\Element\NodeElement;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\views\Entity\View;
use Drupal\views\ViewExecutable;
use Drupal\views\Views;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that an embedded view keeps its saved filters when paging with AJAX.
 */
#[Group('viewsreference_sift')]
#[RunTestsInSeparateProcesses]
class SiftAjaxPagerTest extends WebDriverTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['node', 'taxonomy', 'viewsreference_sift'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Tests the mini pager and an ordinary page request with forged settings.
   */
  public function testAjaxPager(): void {
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    Vocabulary::create(['vid' => 'tags', 'name' => 'Tags'])->save();
    $saved_term = Term::create(['vid' => 'tags', 'name' => 'Saved topic']);
    $saved_term->save();
    $forged_term = Term::create(['vid' => 'tags', 'name' => 'Forged topic']);
    $forged_term->save();

    FieldStorageConfig::create([
      'field_name' => 'field_tags',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'taxonomy_term'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_tags',
      'entity_type' => 'node',
      'bundle' => 'article',
      'label' => 'Tags',
    ])->save();
    foreach ([
      'Selected article 1' => $saved_term->id(),
      'Other article 1' => $forged_term->id(),
      'Selected article 2' => $saved_term->id(),
      'Selected article 3' => $saved_term->id(),
      'Other article 2' => $forged_term->id(),
      'Selected article 4' => $saved_term->id(),
    ] as $title => $term_id) {
      Node::create([
        'type' => 'article',
        'title' => $title,
        'status' => TRUE,
        'field_tags' => ['target_id' => $term_id],
      ])->save();
    }

    $this->createPagerView();
    FieldStorageConfig::create([
      'field_name' => 'field_listing',
      'entity_type' => 'node',
      'type' => 'viewsreference',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_listing',
      'entity_type' => 'node',
      'bundle' => 'article',
      'label' => 'Listing',
      'settings' => ['enabled_settings' => ['sift_terms' => 'sift_terms']],
    ])->save();
    EntityViewDisplay::create([
      'targetEntityType' => 'node',
      'bundle' => 'article',
      'mode' => 'default',
      'status' => TRUE,
    ])->setComponent('field_listing', ['type' => 'viewsreference_formatter', 'label' => 'hidden'])->save();

    $listing = Node::create([
      'type' => 'article',
      'title' => 'Listing',
      'status' => TRUE,
      'field_listing' => [
        'target_id' => 'sift_ajax_pager',
        'display_id' => 'embed',
        'data' => serialize(['sift_terms' => $this->selection((int) $saved_term->id())]),
      ],
    ]);
    $listing->save();
    $path = 'node/' . $listing->id();

    $this->drupalGet($path);
    $this->assertRows(['Selected article 1', 'Selected article 2']);
    $this->assertSession()->elementExists('css', '.js-pager__items a[rel="next"]')->click();
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->assertSession()->addressEquals($path);
    $this->assertRows(['Selected article 3', 'Selected article 4']);

    $view = Views::getView('sift_ajax_pager');
    $this->assertInstanceOf(ViewExecutable::class, $view);
    $forged = $this->container->get('viewsreference.compression')->compress([
      'enabled_settings' => ['sift_terms'],
      'data' => ['sift_terms' => $this->selection((int) $forged_term->id())],
    ], $view);
    $this->drupalGet($path, ['query' => ['viewsreference' => $forged]]);
    $this->assertRows(['Selected article 1', 'Selected article 2']);
  }

  /**
   * Creates the contextual filter display with an AJAX mini pager.
   */
  private function createPagerView(): void {
    View::create([
      'id' => 'sift_ajax_pager',
      'label' => 'Sift AJAX pager',
      'base_table' => 'node_field_data',
      'base_field' => 'nid',
      'display' => [
        'default' => [
          'id' => 'default',
          'display_title' => 'Default',
          'display_plugin' => 'default',
          'position' => 0,
          'display_options' => [
            'use_ajax' => TRUE,
            'pager' => ['type' => 'mini', 'options' => ['items_per_page' => 2]],
            'fields' => [
              'title' => [
                'id' => 'title',
                'table' => 'node_field_data',
                'field' => 'title',
                'entity_type' => 'node',
                'entity_field' => 'title',
                'plugin_id' => 'field',
              ],
            ],
            'sorts' => [
              'nid' => [
                'id' => 'nid',
                'table' => 'node_field_data',
                'field' => 'nid',
                'plugin_id' => 'standard',
                'order' => 'ASC',
              ],
            ],
            'arguments' => [
              'tid' => [
                'id' => 'tid',
                'table' => 'taxonomy_index',
                'field' => 'tid',
                'plugin_id' => 'taxonomy_index_tid',
                'break_phrase' => TRUE,
                'specify_validation' => TRUE,
                'validate' => ['type' => 'entity:taxonomy_term'],
                'validate_options' => ['bundles' => ['tags' => 'tags'], 'multiple' => 1],
              ],
            ],
          ],
        ],
        'embed' => [
          'id' => 'embed',
          'display_title' => 'Embed',
          'display_plugin' => 'embed',
          'position' => 1,
          'display_options' => [],
        ],
      ],
    ])->save();
  }

  /**
   * Returns the storage shape for a single term selection.
   *
   * @phpstan-return array{version: 2, filters: array{tid: array{ids: list<int>, match: 'any'}}}
   */
  private function selection(int $id): array {
    return ['version' => 2, 'filters' => ['tid' => ['ids' => [$id], 'match' => 'any']]];
  }

  /**
   * Asserts the titles and order of all rows on the current page.
   *
   * @phpstan-param list<string> $titles
   */
  private function assertRows(array $titles): void {
    $rows = $this->getSession()->getPage()->findAll('css', '.views-row');
    $this->assertSame($titles, array_map(static fn (NodeElement $row): string => $row->getText(), $rows));
  }

}
