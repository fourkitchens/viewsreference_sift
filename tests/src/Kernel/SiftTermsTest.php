<?php

declare(strict_types=1);

namespace Drupal\Tests\viewsreference_sift\Kernel;

use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Tests\field\Traits\EntityReferenceFieldCreationTrait;
use Drupal\views\ResultRow;
use Drupal\views\ViewExecutable;
use Drupal\views\Views;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;

/**
 * Tests the Terms setting with embedded Views displays.
 */
#[Group('viewsreference_sift')]
#[RunTestsInSeparateProcesses]
class SiftTermsTest extends KernelTestBase {

  use EntityReferenceFieldCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'node',
    'taxonomy',
    'views',
    'viewsreference',
    'viewsreference_sift',
    'viewsreference_sift_test',
  ];

  /**
   * Fixture term IDs keyed by term name.
   *
   * @var int[]
   */
  protected array $termIds = [];

  /**
   * Fixture node IDs keyed by title.
   *
   * @var string[]
   */
  protected array $nodeIds = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'field', 'filter', 'node', 'taxonomy', 'views']);

    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    $this->createEntityReferenceField('node', 'article', 'field_tags', 'Tags', 'taxonomy_term', 'default', [], FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED);
    Vocabulary::create(['vid' => 'tags', 'name' => 'Tags'])->save();
    Vocabulary::create(['vid' => 'other', 'name' => 'Other'])->save();
    foreach (['A' => 'tags', 'B' => 'tags', 'C' => 'other'] as $name => $vocabulary) {
      $term = Term::create(['vid' => $vocabulary, 'name' => $name]);
      $term->save();
      $this->termIds[$name] = (int) $term->id();
    }

    foreach (['A' => ['A'], 'B' => ['B'], 'Both' => ['A', 'B'], 'Neither' => []] as $title => $terms) {
      $node = Node::create([
        'type' => 'article',
        'title' => $title,
        'status' => TRUE,
        'field_tags' => array_map(fn (string $term): array => ['target_id' => $this->termIds[$term]], $terms),
      ]);
      $node->save();
      $this->nodeIds[$title] = (string) $node->id();
    }
    $this->installConfig(['viewsreference_sift_test']);
  }

  /**
   * Tests the contextual filter and the Views Reference request payload.
   */
  public function testTermArguments(): void {
    $ids = [$this->termIds['A'], $this->termIds['B']];
    $any = $this->storedSelection('tid', $ids);
    $all = $this->storedSelection('tid', $ids, 'all');

    $this->assertResultTitles(['A', 'B', 'Both'], $this->executeWithSetting('embed_terms', $any));
    $this->assertResultTitles(['A', 'B', 'Both'], $this->executeWithSetting('embed_terms', $any, TRUE));
    $this->assertResultTitles(['Both'], $this->executeWithSetting('embed_terms', $all));
    $this->assertResultTitles(['A', 'B', 'Both', 'Neither'], $this->executeWithSetting('embed_terms', $this->storedSelection('tid', [])));
    $this->assertResultTitles(['A', 'B', 'Both', 'Neither'], $this->executeWithSetting('embed_terms', 'legacy'));

    $depth = $this->executeWithSetting('embed_depth', $this->storedSelection('term_node_tid_depth', $ids, 'all'));
    $this->assertResultTitles(['A', 'B', 'Both'], $depth);
    $this->assertSame([implode('+', [$this->termIds['A'], $this->termIds['B']])], $depth->args);

    $plain = $this->executeWithSetting('embed_plain', $any);
    $this->assertResultTitles(['A', 'B', 'Both', 'Neither'], $plain);
    $this->assertSame([], $plain->args);

    $malformed = $this->storedSelection('tid', [
      (string) $this->termIds['A'],
      $this->termIds['B'] . '+' . $this->termIds['C'],
    ]);
    $this->assertResultTitles(['A', 'Both'], $this->executeWithSetting('embed_terms', $malformed, TRUE));
  }

  /**
   * Tests the first supported taxonomy filter after a numeric argument.
   */
  public function testFirstSupportedArgument(): void {
    $field = $this->termsElement('embed_positions');
    $this->assertSame('fieldset', $field['#type']);
    $this->assertSame(['tid'], array_keys($field['filters']));

    $view = $this->executeWithSetting('embed_positions', $this->storedSelection('tid', [$this->termIds['A']]));
    $this->assertSame(['all', (string) $this->termIds['A']], $view->args);
    $this->assertResultTitles(['A', 'Both'], $view);
  }

  /**
   * Tests that both plugin orders yield the same arguments on both passes.
   */
  public function testArgumentPluginOrders(): void {
    $ids = [$this->termIds['A'], $this->termIds['B']];
    $expected = [$this->nodeIds['Both'], implode('+', $ids)];
    foreach ([['argument', 'sift_terms'], ['sift_terms', 'argument']] as $order) {
      $view = $this->viewWithArgumentSettings($this->nodeIds['Both'], $ids, $order);
      $view->preExecute();
      $this->assertSame($expected, $view->args);
      $view->postExecute();

      $view->preExecute();
      $this->assertSame($expected, $view->args);
      $this->assertTrue($view->execute());
      $view->postExecute();
      $this->assertResultTitles(['Both'], $view);
    }
  }

  /**
   * Tests filling an empty leading argument with a default or exception.
   */
  public function testEmptyLeadingArgument(): void {
    $ids = [$this->termIds['A']];
    foreach ([FALSE, TRUE] as $fixed_default) {
      $view = $this->viewWithArgumentSettings('/all', $ids);
      if ($fixed_default) {
        $arguments = $view->display_handler->getOption('arguments');
        $arguments['nid']['default_action'] = 'default';
        $arguments['nid']['default_argument_type'] = 'fixed';
        $arguments['nid']['default_argument_options'] = ['argument' => $this->nodeIds['Both']];
        $view->display_handler->overrideOption('arguments', $arguments);
      }

      $view->preExecute();
      $this->assertSame([$fixed_default ? $this->nodeIds['Both'] : 'all', (string) $ids[0]], $view->args);
      $this->assertTrue($view->execute());
      $view->postExecute();
      $this->assertResultTitles($fixed_default ? ['Both'] : ['A', 'Both'], $view);
    }
  }

  /**
   * Tests that disabling multiple values keeps only the first saved term.
   */
  public function testSingleValueFilter(): void {
    $view = Views::getView('viewsreference_sift_test');
    $this->assertInstanceOf(ViewExecutable::class, $view);
    $this->assertTrue($view->setDisplay('embed_positions'));
    $arguments = $view->display_handler->getOption('arguments');
    $arguments['tid']['break_phrase'] = FALSE;
    $view->display_handler->overrideOption('arguments', $arguments);
    $view->storage->save();

    $stored = $this->storedSelection('tid', [$this->termIds['A'], $this->termIds['B']], 'all');
    $field = $this->termsElement('embed_positions', $stored);
    $filter = $field['filters']['tid'] ?? NULL;
    $this->assertIsArray($filter);
    $this->assertFalse($filter['ids']['#tags']);
    $this->assertArrayNotHasKey('match', $filter);
    $default = $filter['ids']['#default_value'];
    $this->assertInstanceOf(Term::class, $default);
    $this->assertSame($this->termIds['A'], (int) $default->id());

    $legacy = ['terms' => [['target_id' => $this->termIds['C']], ['target_id' => $this->termIds['A']]]];
    $stale = $this->termsElement('embed_positions', $legacy);
    $this->assertNull($stale['filters']['tid']['ids']['#default_value']);
    $this->assertSame('Some saved selections no longer apply to this display and will be removed when you save.', (string) $stale['#description']);

    $field['#parents'] = ['field_listing', 0, 'options', 'sift_terms'];
    $form_state = new FormState();
    $form_state->setValue($field['#parents'], [
      'version' => 2,
      'filters' => ['tid' => ['ids' => (string) $this->termIds['B']]],
    ]);
    $callback = $field['#element_validate'][0];
    $this->assertIsCallable($callback);
    $callback($field, $form_state);
    $this->assertSame($this->storedSelection('tid', [$this->termIds['B']]), $form_state->getValue($field['#parents']));

    $view = $this->executeWithSetting('embed_positions', $stored);
    $this->assertSame(['all', (string) $this->termIds['A']], $view->args);
    $this->assertResultTitles(['A', 'Both'], $view);
  }

  /**
   * Tests that hidden settings clear the old default value.
   */
  public function testHiddenWidgetClearsDefault(): void {
    $stored = $this->storedSelection('tid', [$this->termIds['A']]);
    $hidden = $this->termsElement('embed_plain', $stored);
    $this->assertFalse($hidden['#access']);
    $this->assertSame('', $hidden['#default_value'] ?? '');
  }

  /**
   * Tests that a stale saved key warns while the valid selection remains.
   */
  public function testStaleWidgetSelection(): void {
    $stored = $this->storedSelection('tid', [$this->termIds['A']]);
    $stored['filters']['obsolete'] = ['ids' => [$this->termIds['B']], 'match' => 'any'];
    $field = $this->termsElement('embed_positions', $stored);
    $this->assertSame(['tid'], array_keys($field['filters'] ?? []));
    $this->assertSame('Some saved selections no longer apply to this display and will be removed when you save.', (string) $field['#description']);
    $defaults = $field['filters']['tid']['ids']['#default_value'];
    $this->assertCount(1, $defaults);
    $this->assertSame($this->termIds['A'], (int) $defaults[0]->id());
  }

  /**
   * Tests field visibility, vocabulary restrictions and saved defaults.
   */
  public function testTermsWidget(): void {
    $this->assertFalse($this->termsElement('embed_plain')['#access']);
    $this->assertFalse($this->termsElement('embed_terms', '', 'missing')['#access']);

    $field = $this->termsElement('embed_terms');
    $this->assertSame('fieldset', $field['#type']);
    $this->assertTrue($field['#tree']);
    $this->assertSame(5, $field['#weight']);
    $this->assertSame(['visible' => []], $field['#states']);
    $this->assertArrayNotHasKey('#default_value', $field);
    $this->assertSame(['#type' => 'value', '#value' => 2], $field['version']);
    $filter = $field['filters']['tid'];
    $this->assertSame('entity_autocomplete', $filter['ids']['#type']);
    $this->assertSame('taxonomy_term', $filter['ids']['#target_type']);
    $this->assertTrue($filter['ids']['#tags']);
    $this->assertSame(['tags'], array_values($filter['ids']['#selection_settings']['target_bundles']));
    $this->assertSame('radios', $filter['match']['#type']);
    $this->assertSame(['any', 'all'], array_keys($filter['match']['#options']));
    $this->assertSame('any', $filter['match']['#default_value']);

    $depth = $this->termsElement('embed_depth');
    $this->assertArrayNotHasKey('match', $depth['filters']['term_node_tid_depth']);

    Term::load($this->termIds['B'])->delete();
    $stored = $this->storedSelection('tid', [$this->termIds['B'], $this->termIds['C'], $this->termIds['A']], 'all');
    $field = $this->termsElement('embed_terms', $stored);
    $this->assertSame('Some saved selections no longer apply to this display and will be removed when you save.', (string) $field['#description']);
    $filter = $field['filters']['tid'];
    $defaults = array_values($filter['ids']['#default_value']);
    $this->assertCount(1, $defaults);
    $this->assertSame($this->termIds['A'], (int) $defaults[0]->id());
    $this->assertSame('all', $filter['match']['#default_value']);
  }

  /**
   * Tests that saved beta1 values still filter and populate the widget.
   */
  public function testLegacyValue(): void {
    $legacy = [
      'terms' => [['target_id' => (string) $this->termIds['A']], ['target_id' => $this->termIds['B']]],
      'match' => 'all',
    ];
    $this->assertResultTitles(['Both'], $this->executeWithSetting('embed_terms', $legacy));
    $this->assertResultTitles(['Both'], $this->executeWithSetting('embed_terms', $legacy, TRUE));

    $field = $this->termsElement('embed_terms', $legacy, 'viewsreference_sift_test');
    $filter = $field['filters']['tid'];
    $defaults = $filter['ids']['#default_value'];
    $this->assertCount(2, $defaults);
    $this->assertSame($this->termIds['A'], (int) $defaults[0]->id());
    $this->assertSame($this->termIds['B'], (int) $defaults[1]->id());
    $this->assertSame('all', $filter['match']['#default_value']);
  }

  /**
   * Tests that widget validation writes versioned settings for storage.
   */
  public function testWidgetValidation(): void {
    $field = $this->termsElement('embed_terms');
    $field['#parents'] = ['field_listing', 0, 'options', 'sift_terms'];
    $form_state = new FormState();
    $form_state->setValue($field['#parents'], [
      'version' => 2,
      'filters' => [
        'tid' => [
          'ids' => [
            ['target_id' => (string) $this->termIds['B']],
            ['target_id' => $this->termIds['A']],
            ['target_id' => $this->termIds['B']],
          ],
          'match' => 'all',
        ],
      ],
    ]);

    $callback = $field['#element_validate'][0];
    $this->assertIsCallable($callback);
    $callback($field, $form_state);
    $this->assertSame(
      $this->storedSelection('tid', [$this->termIds['B'], $this->termIds['A']], 'all'),
      $form_state->getValue($field['#parents']),
    );
  }

  /**
   * Tests that a node page renders its saved selection despite forged data.
   */
  public function testPagePrefersSavedSelection(): void {
    $node = $this->createListing([$this->termIds['A']]);
    EntityViewDisplay::create([
      'targetEntityType' => 'node',
      'bundle' => 'article',
      'mode' => 'default',
      'status' => TRUE,
    ])->setComponent('field_listing', [
      'type' => 'viewsreference_formatter',
      'label' => 'hidden',
    ])->save();
    $view = Views::getView('viewsreference_sift_test');
    $this->assertInstanceOf(ViewExecutable::class, $view);
    $forged = $this->storedSelection('tid', [$this->termIds['B']]);
    $request = $this->requestWithSetting($view, $forged, '/node/' . $node->id(), 'entity.node.canonical');
    $request->attributes->set('node', $node);
    $request_stack = $this->container->get('request_stack');
    $request_stack->push($request);
    try {
      $build = $this->container->get('entity_type.manager')->getViewBuilder('node')->view($node);
      $markup = (string) $this->container->get('renderer')->renderRoot($build);
    }
    finally {
      $request_stack->pop();
    }

    $this->assertStringContainsString('>A<', $markup);
    $this->assertStringContainsString('>Both<', $markup);
    $this->assertStringNotContainsString('>B<', $markup);
  }

  /**
   * Tests that a standalone View ignores forged settings on both passes.
   */
  public function testStandaloneViewIgnoresForgedSelection(): void {
    $view = Views::getView('viewsreference_sift_test');
    $this->assertInstanceOf(ViewExecutable::class, $view);
    $this->assertTrue($view->setDisplay('page_terms'));
    $forged = $this->storedSelection('tid', [$this->termIds['B']]);
    $request = $this->requestWithSetting($view, $forged, '/listing', 'view.viewsreference_sift_test.page_terms');
    $view->setRequest($request);
    $request_stack = $this->container->get('request_stack');
    $request_stack->push($request);
    try {
      $view->preExecute();
      $this->assertSame([], $view->args);
      $view->postExecute();

      $view->preExecute();
      $this->assertSame([], $view->args);
      $this->assertTrue($view->execute());
      $view->postExecute();
    }
    finally {
      $request_stack->pop();
    }
    $this->assertResultTitles(['A', 'B', 'Both', 'Neither'], $view);
  }

  /**
   * Tests that Views AJAX reloads the saved field item when available.
   */
  public function testAjaxPrefersSavedSelection(): void {
    $node = $this->createListing([$this->termIds['A']]);
    $parent = $this->parentMetadata($node);
    $forged = $this->storedSelection('tid', [$this->termIds['B']]);
    $item = $node->get('field_listing')->first();
    $this->assertNotNull($item);
    $item->set('data', serialize(['sift_terms' => $forged]));
    $node->setNewRevision(TRUE);
    $node->save();
    $this->assertNotSame($parent['parent_revision_id'], $node->getRevisionId());

    $view = $this->executeWithSetting('embed_terms', $forged, TRUE, $parent);

    $this->assertSame([(string) $this->termIds['A']], $view->args);
    $this->assertResultTitles(['A', 'Both'], $view);
  }

  /**
   * Tests that AJAX fallback checks bundles and retains validation failure.
   */
  public function testAjaxValidatesFallbackSelection(): void {
    $node = $this->createListing([$this->termIds['B']]);
    $missing_item = $this->parentMetadata($node, 1);
    $mixed = $this->storedSelection('tid', [$this->termIds['C'], $this->termIds['A']]);
    $view = $this->executeWithSetting('embed_terms', $mixed, TRUE, $missing_item);
    $this->assertSame([(string) $this->termIds['A']], $view->args);
    $this->assertResultTitles(['A', 'Both'], $view);

    $wrong_bundle = $this->storedSelection('tid', [$this->termIds['C']]);
    $view = $this->executeWithSetting('embed_terms', $wrong_bundle, TRUE, $missing_item, FALSE);
    $this->assertTrue($view->build_info['fail']);
    $this->assertSame([], $view->result);
  }

  /**
   * Tests that a large AJAX fallback selection contributes only ten IDs.
   */
  public function testAjaxCapsFallbackSelection(): void {
    $node = $this->createListing([$this->termIds['B']]);
    $ids = [];
    for ($index = 0; $index < 12; $index++) {
      $term = Term::create(['vid' => 'tags', 'name' => 'Extra term ' . $index]);
      $term->save();
      $ids[] = (int) $term->id();
    }
    $selection = $this->storedSelection('tid', array_merge($ids, range(1000, 1487)));
    $view = $this->executeWithSetting('embed_terms', $selection, TRUE, $this->parentMetadata($node, 1));

    $this->assertSame([implode('+', array_slice($ids, 0, 10))], $view->args);
  }

  /**
   * Tests that each selected term's cache tag bubbles through the embed.
   */
  public function testRenderedEmbedCarriesTermCacheTags(): void {
    $node = $this->createListing([$this->termIds['A'], $this->termIds['B']]);
    $build = $node->get('field_listing')->view(['type' => 'viewsreference_formatter', 'label' => 'hidden']);
    $this->container->get('renderer')->renderRoot($build);

    $this->assertContains('taxonomy_term:' . $this->termIds['A'], $build['#cache']['tags']);
    $this->assertContains('taxonomy_term:' . $this->termIds['B'], $build['#cache']['tags']);
  }

  /**
   * Saves a node with a configured Views Reference field item.
   *
   * @phpstan-param list<int> $ids
   */
  protected function createListing(array $ids): NodeInterface {
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
    $node = Node::create([
      'type' => 'article',
      'title' => 'Listing',
      'status' => TRUE,
      'field_listing' => [
        'target_id' => 'viewsreference_sift_test',
        'display_id' => 'embed_terms',
        'data' => serialize(['sift_terms' => $this->storedSelection('tid', $ids)]),
      ],
    ]);
    $node->save();
    return $node;
  }

  /**
   * Builds parent field metadata using the Views Reference formatter keys.
   *
   * @phpstan-return array<string, mixed>
   */
  protected function parentMetadata(NodeInterface $node, int $delta = 0): array {
    return [
      'parent_entity_type' => $node->getEntityTypeId(),
      'parent_entity_id' => $node->id(),
      'parent_entity_langcode' => $node->language()->getId(),
      'parent_field_name' => 'field_listing',
      'parent_revision_id' => $node->getRevisionId(),
      'field_item_delta' => $delta,
    ];
  }

  /**
   * Builds a stored version 2 selection for one filter.
   *
   * @phpstan-param list<int|string> $ids
   * @phpstan-return array{version: 2, filters: array<string, array{ids: list<int|string>, match: string}>}
   */
  protected function storedSelection(string $id, array $ids, string $match = 'any'): array {
    return [
      'version' => 2,
      'filters' => [$id => ['ids' => $ids, 'match' => $match]],
    ];
  }

  /**
   * Builds the positional embed with Views Reference Argument and Terms.
   *
   * @phpstan-param list<int> $ids
   * @phpstan-param list<string> $settings
   */
  protected function viewWithArgumentSettings(string $argument, array $ids, array $settings = ['argument', 'sift_terms']): ViewExecutable {
    $view = Views::getView('viewsreference_sift_test');
    $this->assertInstanceOf(ViewExecutable::class, $view);
    $this->assertTrue($view->setDisplay('embed_positions'));
    $view->element['#viewsreference'] = [
      'enabled_settings' => $settings,
      'data' => ['argument' => $argument, 'sift_terms' => $this->storedSelection('tid', $ids)],
    ];
    return $view;
  }

  /**
   * Executes an embed through the Views Reference pre-view hook.
   *
   * @phpstan-param array<string, mixed> $parent
   */
  protected function executeWithSetting(string $display, mixed $value, bool $ajax = FALSE, array $parent = [], bool $expected_success = TRUE): ViewExecutable {
    $view = Views::getView('viewsreference_sift_test');
    $this->assertInstanceOf(ViewExecutable::class, $view);
    $this->assertTrue($view->setDisplay($display));
    $request_stack = $this->container->get('request_stack');
    if ($ajax) {
      $ajax_request = $this->requestWithSetting($view, $value, '/views/ajax', 'views.ajax', $parent);
      $view->setRequest($ajax_request);
      $request_stack->push($ajax_request);
    }
    else {
      $view->element['#viewsreference'] = [
        'enabled_settings' => ['sift_terms'],
        'data' => ['sift_terms' => $value],
      ];
    }
    try {
      $view->preExecute();
      $this->assertSame($expected_success, $view->execute());
      $view->postExecute();
    }
    finally {
      if ($ajax) {
        $request_stack->pop();
      }
    }
    return $view;
  }

  /**
   * Creates a routed request with compressed Views Reference settings.
   *
   * @phpstan-param array<string, mixed> $parent
   */
  protected function requestWithSetting(ViewExecutable $view, mixed $value, string $path, string $route, array $parent = []): Request {
    $settings = $parent + [
      'enabled_settings' => ['sift_terms'],
      'data' => ['sift_terms' => $value],
    ];
    $compressed = $this->container->get('viewsreference.compression')->compress($settings, $view);
    $request = Request::create($path, 'GET', ['viewsreference' => $compressed]);
    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, $route);
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, new Route($path));
    return $request;
  }

  /**
   * Asserts which nodes the view returned in display order.
   *
   * @phpstan-param list<string> $titles
   */
  protected function assertResultTitles(array $titles, ViewExecutable $view): void {
    $this->assertSame($titles, array_map(static function (ResultRow $row): string {
      self::assertInstanceOf(NodeInterface::class, $row->_entity);
      return $row->_entity->getTitle();
    }, $view->result));
  }

  /**
   * Builds the Terms element from Views Reference widget configuration.
   *
   * @phpstan-return array<string, mixed>
   */
  protected function termsElement(string $display, mixed $value = '', string $view = 'Test (listing) (viewsreference_sift_test)'): array {
    $plugin = $this->container->get('plugin.manager.viewsreference.setting')->createInstance('sift_terms', [
      'view_name' => $view,
      'display_id' => $display,
    ]);
    $field = [
      '#type' => 'textfield',
      '#default_value' => $value,
      '#states' => ['visible' => []],
      '#weight' => 5,
    ];
    $plugin->alterFormField($field);
    return $field;
  }

}
