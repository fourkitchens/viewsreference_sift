<?php

declare(strict_types=1);

namespace Drupal\Tests\viewsreference_sift\Kernel;

use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Field\WidgetInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\entity_test\Entity\EntityTestStringId;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Tests\field\Traits\EntityReferenceFieldCreationTrait;
use Drupal\Tests\SchemaCheckTestTrait;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\views\Entity\View;
use Drupal\views\Plugin\views\query\Sql;
use Drupal\views\ResultRow;
use Drupal\views\ViewExecutable;
use Drupal\views\Views;
use Drupal\viewsreference_sift\Filter\FilterResolver;
use Drupal\viewsreference_sift\Filter\SiftFilter;
use Drupal\viewsreference_sift\Hook\SiftHooks;
use Drupal\viewsreference_sift\Plugin\views\display_extender\SiftDisplayExtender;
use Drupal\viewsreference_sift\Selection\SelectionNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;

/**
 * Tests Sift filters with embedded Views displays.
 */
#[Group('viewsreference_sift')]
#[RunTestsInSeparateProcesses]
class SiftTermsTest extends KernelTestBase {

  use EntityReferenceFieldCreationTrait;
  use SchemaCheckTestTrait;

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
    $role = Role::load('anonymous') ?? Role::create(['id' => 'anonymous', 'label' => 'Anonymous']);
    $role->grantPermission('access content')->grantPermission('access user profiles')->save();

    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    NodeType::create(['type' => 'program', 'name' => 'Program'])->save();
    $this->createEntityReferenceField('node', 'article', 'field_tags', 'Tags', 'taxonomy_term', 'default', [], FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED);
    Vocabulary::create(['vid' => 'tags', 'name' => 'Tags'])->save();
    Vocabulary::create(['vid' => 'other', 'name' => 'Other'])->save();
    Vocabulary::create(['vid' => 'audience', 'name' => 'Audience'])->save();
    $this->createEntityReferenceField('node', 'article', 'field_audience', 'Audience', 'taxonomy_term', 'default', ['target_bundles' => ['audience' => 'audience']]);
    $this->createEntityReferenceField('node', 'article', 'field_program', 'Program', 'node', 'default', ['target_bundles' => ['program' => 'program']]);
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
   * Tests taxonomy exclusion, cache tags, and cached result isolation.
   */
  public function testTaxonomyExclusion(): void {
    $ids = [$this->termIds['A'], $this->termIds['B']];
    $exclude = $this->storedSelection('tid', $ids, 'all');
    $exclude['filters']['tid']['mode'] = 'exclude';
    $this->assertResultTitles(['Both'], $this->executeWithSetting('embed_terms', $exclude));

    $this->saveTermOptions(['allow_exclude' => TRUE, 'match' => 'all']);
    $stored_view = View::load('viewsreference_sift_test');
    $this->assertInstanceOf(View::class, $stored_view);
    $display = &$stored_view->getDisplay('embed_terms');
    $display['display_options']['defaults']['cache'] = FALSE;
    $display['display_options']['cache'] = ['type' => 'tag'];
    $stored_view->save();
    $this->assertConfigSchemaByName('views.view.viewsreference_sift_test');

    $view = $this->executeWithSetting('embed_terms', $exclude);
    $this->assertResultTitles(['Neither'], $view);
    foreach ($ids as $id) {
      $this->assertContains('taxonomy_term:' . $id, $view->element['#cache']['tags']);
    }
    $this->assertResultTitles(['A', 'B', 'Both', 'Neither'], $this->executeWithSetting('embed_terms', '', args: ['all']));
    $this->assertResultTitles(['Neither'], $this->executeWithSetting('embed_terms', $exclude));
    $this->assertResultTitles(['Both'], $this->executeWithSetting('embed_terms', $this->storedSelection('tid', $ids)));

    $exclude['filters']['tid']['ids'] = [$this->termIds['A']];
    $this->assertResultTitles(['B', 'Neither'], $this->executeWithSetting('embed_terms', $exclude));
  }

  /**
   * Tests that exclusion preserves an existing contextual OR group.
   */
  public function testTaxonomyExclusionWithOrGroup(): void {
    $this->saveTermOptions(['allow_exclude' => TRUE]);
    $exclude = $this->storedSelection('tid', [$this->termIds['A']]);
    $exclude['filters']['tid']['mode'] = 'exclude';

    $view = Views::getView('viewsreference_sift_test');
    $this->assertInstanceOf(ViewExecutable::class, $view);
    $this->assertTrue($view->setDisplay('embed_terms'));
    $view->initQuery();
    $query = $view->query;
    $this->assertInstanceOf(Sql::class, $query);
    $query->setWhereGroup('OR', '0');
    $query->addWhere('0', 'node_field_data.nid', $this->nodeIds['A']);
    $query->addWhereExpression('0', 'node_field_data.nid = :sift_test_nid', [':sift_test_nid' => $this->nodeIds['B']]);
    $view->element['#viewsreference'] = [
      'enabled_settings' => ['sift_terms'],
      'data' => ['sift_terms' => $exclude],
    ];
    $view->preExecute();
    try {
      $this->assertTrue($view->execute());
      $this->assertResultTitles(['B'], $view);
    }
    finally {
      $view->postExecute();
    }
  }

  /**
   * Tests exclusion of matching rows on a reference field with multiple values.
   */
  public function testEntityReferenceExclusion(): void {
    $storage = FieldStorageConfig::loadByName('node', 'field_program');
    $this->assertInstanceOf(FieldStorageConfig::class, $storage);
    $storage->setCardinality(FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED)->save();
    $programs = [];
    foreach (['Alpha', 'Beta'] as $title) {
      $node = Node::create(['type' => 'program', 'title' => $title, 'status' => TRUE]);
      $node->save();
      $programs[] = (int) $node->id();
    }
    foreach (['A' => [$programs[0]], 'B' => [$programs[1]], 'Both' => $programs] as $title => $ids) {
      $node = Node::load($this->nodeIds[$title]);
      $this->assertInstanceOf(NodeInterface::class, $node);
      $node->set('field_program', array_map(static fn (int $id): array => ['target_id' => $id], $ids))->save();
    }

    $this->config('views.settings')->set('display_extenders', ['viewsreference_sift'])->save();
    $stored_view = View::load('viewsreference_sift_test');
    $this->assertInstanceOf(View::class, $stored_view);
    $display = &$stored_view->getDisplay('embed_multi');
    $id = 'field_program_target_id';
    $display['display_options']['arguments'][$id]['break_phrase'] = TRUE;
    $display['display_options']['arguments'][$id]['validate_options']['multiple'] = 1;
    $display['display_options']['display_extenders']['viewsreference_sift']['filters'][$id] = ['allow_exclude' => TRUE] + SiftFilter::DEFAULT_OPTIONS;
    $stored_view->save();

    $exclude = $this->storedSelection($id, $programs);
    $exclude['filters'][$id]['mode'] = 'exclude';
    $this->assertResultTitles(['Neither'], $this->executeWithSetting('embed_multi', $exclude));
    $exclude['filters'][$id]['ids'] = [$programs[0]];
    $this->assertResultTitles(['B', 'Neither'], $this->executeWithSetting('embed_multi', $exclude));

    // The earlier validation failure stops Views before the exclusion slot.
    $display['display_options']['arguments']['tid_tags']['validate']['fail'] = 'ignore';
    $stored_view->save();
    $exclude['filters'] += $this->storedSelection('tid_tags', [$this->termIds['C']])['filters'];
    $this->assertResultTitles(['A', 'B', 'Both', 'Neither'], $this->executeWithSetting('embed_multi', $exclude));
  }

  /**
   * Tests that disabled, related, and depth filters cannot use exclusion.
   */
  public function testIneligibleExclusion(): void {
    $this->saveTermOptions(['allow_exclude' => TRUE, 'enabled' => FALSE]);
    $exclude = $this->storedSelection('tid', [$this->termIds['A']]);
    $exclude['filters']['tid']['mode'] = 'exclude';
    $this->assertResultTitles(['A', 'B', 'Both', 'Neither'], $this->executeWithSetting('embed_terms', $exclude));

    $stored_view = View::load('viewsreference_sift_test');
    $this->assertInstanceOf(View::class, $stored_view);
    $display = &$stored_view->getDisplay('embed_depth');
    $id = 'term_node_tid_depth';
    $options = ['allow_exclude' => TRUE] + SiftFilter::DEFAULT_OPTIONS;
    $display['display_options']['display_extenders']['viewsreference_sift']['filters'][$id] = $options;
    $stored_view->save();
    $exclude = $this->storedSelection($id, [$this->termIds['A']]);
    $exclude['filters'][$id]['mode'] = 'exclude';
    $this->assertResultTitles(['A', 'Both'], $this->executeWithSetting('embed_depth', $exclude));
    $this->assertArrayNotHasKey('mode', $this->termsElement('embed_depth')['filters'][$id]);

    $display = &$stored_view->getDisplay('embed_multi');
    $display['display_options']['defaults']['relationships'] = FALSE;
    $display['display_options']['relationships']['field_program'] = [
      'id' => 'field_program',
      'table' => 'node__field_program',
      'field' => 'field_program',
      'plugin_id' => 'standard',
    ];
    $display['display_options']['arguments']['tid_tags']['relationship'] = 'field_program';
    $display['display_options']['display_extenders']['viewsreference_sift']['filters']['tid_tags'] = $options;
    $stored_view->save();
    $this->assertArrayNotHasKey('mode', $this->termsElement('embed_multi')['filters']['tid_tags']);
  }

  /**
   * Tests native Content: Type arguments, string IDs, and cache tags.
   */
  public function testContentTypeArguments(): void {
    NodeType::create(['type' => 'page', 'name' => 'Basic page'])->save();
    Node::create(['type' => 'page', 'title' => 'Basic page item', 'status' => TRUE])->save();
    Node::create(['type' => 'program', 'title' => 'Program item', 'status' => TRUE])->save();

    $filters = $this->container->get(FilterResolver::class)->forDisplay('viewsreference_sift_test', 'embed_content_types');
    $this->assertSame(['type'], array_keys($filters));
    $this->assertSame('node_type', $filters['type']->entityTypeId);
    $this->assertSame('string', $filters['type']->idType);
    $this->assertSame('checkboxes', $filters['type']->widget);
    $this->assertFalse($filters['type']->supportsAll);

    $stored = [
      'version' => 2,
      'filters' => [
        'type' => [
          'ids' => [
            'article', 'page', 'article', 'missing', '007', 'A-B_c.d',
            7, 7.5, TRUE, [], '', 'article+page', 'article,page',
            'article/page', 'article page', ' article', "page\n", "\u{00e9}",
          ],
          'match' => 'any',
        ],
      ],
    ];
    $normalized = $this->container->get(SelectionNormalizer::class)->fromStorage($stored, $filters);
    $this->assertSame(['article', 'page', 'missing', '007', 'A-B_c.d'], $normalized->filters['type']->ids);

    $view = $this->executeWithSetting('embed_content_types', $stored, TRUE);
    $this->assertSame(['article+page'], $view->args);
    $this->assertResultTitles(['A', 'B', 'Both', 'Neither', 'Basic page item'], $view);
    $missing = $this->executeWithSetting('embed_content_types', $this->storedSelection('type', ['missing']), TRUE);
    $this->assertSame([], $missing->args);

    $node = $this->createListing([]);
    $node->set('field_listing', [
      'target_id' => 'viewsreference_sift_test',
      'display_id' => 'embed_content_types',
      'data' => serialize(['sift_terms' => $this->storedSelection('type', ['article', 'page'])]),
    ])->save();
    $build = $node->get('field_listing')->view(['type' => 'viewsreference_formatter', 'label' => 'hidden']);
    $markup = (string) $this->container->get('renderer')->renderRoot($build);
    $this->assertStringContainsString('>Basic page item<', $markup);
    $this->assertStringNotContainsString('>Program item<', $markup);
    $this->assertContains('config:node.type.article', $build['#cache']['tags']);
    $this->assertContains('config:node.type.page', $build['#cache']['tags']);
  }

  /**
   * Tests widgets and the serialized version 2 format for string IDs.
   */
  public function testContentTypeWidgets(): void {
    NodeType::create(['type' => 'page', 'name' => 'Basic page'])->save();
    NodeType::create(['type' => '123', 'name' => 'Numbered type'])->save();
    $expected = [
      'version' => 2,
      'filters' => ['type' => ['ids' => ['article', 'page', '123'], 'match' => 'any']],
    ];
    $executable = Views::getView('viewsreference_sift_test');
    $this->assertInstanceOf(ViewExecutable::class, $executable);
    $this->assertTrue($executable->setDisplay('embed_content_types'));
    $extender = $this->container->get('plugin.manager.views.display_extender')->createInstance('viewsreference_sift');
    $this->assertInstanceOf(SiftDisplayExtender::class, $extender);
    $extender->init($executable, $executable->display_handler);
    $options = ['#title' => ''];
    $extender->buildOptionsForm($options, (new FormState())->set('section', 'viewsreference_sift'));
    $this->assertArrayHasKey('type', $options['filters']);
    $this->assertSame('checkboxes', $options['filters']['type']['widget']['#default_value']);

    $this->config('views.settings')->set('display_extenders', ['viewsreference_sift'])->save();
    $view = View::load('viewsreference_sift_test');
    $this->assertInstanceOf(View::class, $view);
    $display = &$view->getDisplay('embed_content_types');
    $node = $this->createListing([]);
    $node->get('field_listing')->first()?->set('display_id', 'embed_content_types');
    foreach (['select', 'autocomplete', 'checkboxes'] as $type) {
      $display['display_options']['display_extenders']['viewsreference_sift']['filters']['type'] = ['widget' => $type] + SiftFilter::DEFAULT_OPTIONS;
      $view->save();
      $field = $this->termsElement('embed_content_types', $expected);
      $this->assertArrayHasKey('type', $field['filters'] ?? []);
      $group = $field['filters']['type'];
      $this->assertArrayNotHasKey('match', $group);
      $widget = $group['ids'];
      if ($type === 'autocomplete') {
        $this->assertSame('entity_autocomplete', $widget['#type']);
        $this->assertSame('node_type', $widget['#target_type']);
        $this->assertSame(['article', 'page', '123'], array_map(static fn (NodeType $bundle): string => $bundle->id(), $widget['#default_value']));
      }
      else {
        $this->assertSame($type, $widget['#type']);
        $this->assertSame('Basic page', (string) $widget['#options']['page']);
        $this->assertSame('Numbered type', (string) $widget['#options'][123]);
        $this->assertSame(['article', 'page', '123'], $widget['#default_value']);
      }
      $ids = match ($type) {
        'select' => ['article', 'page', '123'],
        'autocomplete' => 'Article (article), Basic page (page), Numbered type (123)',
        default => ['article' => 'article', 'page' => 'page', 123 => '123'],
      };
      $submitted = ['filters' => ['type' => ['ids' => $ids]]];
      [$form, $state, $reference_widget] = $this->preservedSelectionForm($node, $submitted, display: 'embed_content_types');
      $this->assertEmpty($state->getErrors(), "$type must accept the string IDs.");
      $this->assertSame($expected, $this->savePreservedSelection($node, $reference_widget, $form, $state));
    }
  }

  /**
   * Tests a string ID from native entity-reference Views data.
   */
  public function testStringEntityReferenceArgument(): void {
    $this->createEntityReferenceField('node', 'article', 'field_content_type', 'Content type', 'node_type');
    $node = Node::load($this->nodeIds['A']);
    $this->assertInstanceOf(NodeInterface::class, $node);
    $node->set('field_content_type', 'program')->save();
    $node = Node::load($this->nodeIds['B']);
    $this->assertInstanceOf(NodeInterface::class, $node);
    $node->set('field_content_type', 'article')->save();
    $view = View::load('viewsreference_sift_test');
    $this->assertInstanceOf(View::class, $view);
    $display = &$view->getDisplay('embed_content_types');
    $display['display_options']['arguments'] = [
      'field_content_type_target_id' => [
        'id' => 'field_content_type_target_id',
        'table' => 'node__field_content_type',
        'field' => 'field_content_type_target_id',
        'plugin_id' => 'entity_target_id',
        'break_phrase' => TRUE,
      ],
    ];
    $view->save();

    $stored = $this->storedSelection('field_content_type_target_id', ['program']);
    $view = $this->executeWithSetting('embed_content_types', $stored);
    $this->assertSame(['program'], $view->args);
    $this->assertResultTitles(['A'], $view);
  }

  /**
   * Tests a string argument with an entity validator for a content entity.
   */
  public function testStringContentEntityValidator(): void {
    $this->enableModules(['entity_test']);
    $this->installEntitySchema('entity_test_string_id');
    foreach (['Alpha.ID' => 'allowed', 'beta-2' => 'allowed', 'outside' => 'other'] as $id => $bundle) {
      EntityTestStringId::create(['id' => $id, 'type' => $bundle, 'name' => $id])->save();
      Node::create(['type' => 'article', 'title' => $id, 'status' => TRUE])->save();
    }
    $view = View::load('viewsreference_sift_test');
    $this->assertInstanceOf(View::class, $view);
    $display = &$view->getDisplay('embed_content_types');
    $display['display_options']['arguments'] = [
      'title' => [
        'id' => 'title',
        'table' => 'node_field_data',
        'field' => 'title',
        'plugin_id' => 'string',
        'break_phrase' => TRUE,
        'specify_validation' => TRUE,
        'validate' => ['type' => 'entity:entity_test_string_id', 'fail' => 'not found'],
        'validate_options' => ['bundles' => ['allowed' => 'allowed'], 'multiple' => 1],
      ],
    ];
    $view->save();

    $stored = $this->storedSelection('title', ['Alpha.ID', 'outside', 'missing', 'beta-2']);
    $view = $this->executeWithSetting('embed_content_types', $stored);
    $this->assertSame(['Alpha.ID+beta-2'], $view->args);
    $this->assertResultTitles(['Alpha.ID', 'beta-2'], $view);
  }

  /**
   * Tests individual and combined filters on a four-argument display.
   */
  public function testMultipleFilterArguments(): void {
    $ids = $this->createMultipleFilterContent();
    $tags = $this->storedSelection('tid_tags', [$this->termIds['A']]);
    $audience = $this->storedSelection('tid_audience', [$ids['audience']]);
    $program = $this->storedSelection('field_program_target_id', [$ids['program']]);
    $author = $this->storedSelection('uid', [$ids['author']]);

    $this->assertResultTitles(['A', 'Both'], $this->executeWithSetting('embed_multi', $tags));
    $this->assertResultTitles(['B', 'Both'], $this->executeWithSetting('embed_multi', $audience));
    $this->assertResultTitles(['A', 'B'], $this->executeWithSetting('embed_multi', $program));
    $this->assertResultTitles(['B', 'Neither'], $this->executeWithSetting('embed_multi', $author));

    $tags_and_audience = $tags;
    $tags_and_audience['filters'] += $audience['filters'];
    $this->assertResultTitles(['Both'], $this->executeWithSetting('embed_multi', $tags_and_audience));

    $program_and_author = $program;
    $program_and_author['filters'] += $author['filters'];
    $this->assertResultTitles(['B'], $this->executeWithSetting('embed_multi', $program_and_author));

    $all_tags = $this->storedSelection('tid_tags', [$this->termIds['A'], $this->termIds['B']], 'all');
    $this->assertResultTitles(['Both'], $this->executeWithSetting('embed_multi', $all_tags));

    $tags_and_program = $tags;
    $tags_and_program['filters'] += $this->storedSelection('field_program_target_id', [$ids['other_program']])['filters'];
    $view = $this->executeWithSetting('embed_multi', $tags_and_program);
    $this->assertSame([(string) $this->termIds['A'], 'all', (string) $ids['other_program']], $view->args);
    $this->assertResultTitles(['Both'], $view);

    $legacy = ['terms' => [['target_id' => (string) $this->termIds['A']]]];
    $view = $this->executeWithSetting('embed_multi', $legacy);
    $this->assertSame([(string) $this->termIds['A']], $view->args);
    $this->assertResultTitles(['A', 'Both'], $view);
  }

  /**
   * Tests that each supported filter gets its own widget group.
   */
  public function testMultipleFilterWidget(): void {
    $field = $this->termsElement('embed_multi');
    $this->assertSame('Filter this list', (string) $field['#title']);
    $expected = [
      'tid_tags' => ['Tags', 'taxonomy_term', ['tags'], TRUE],
      'tid_audience' => ['Audience', 'taxonomy_term', ['audience'], TRUE],
      'field_program_target_id' => ['Program', 'node', ['program'], FALSE],
      'uid' => ['Author', 'user', [], FALSE],
    ];
    $this->assertSame(array_keys($expected), array_keys($field['filters']));
    foreach ($expected as $id => [$label, $target, $bundles, $multiple]) {
      $group = $field['filters'][$id];
      $widget = $group['ids'];
      $this->assertSame('fieldset', $group['#type']);
      $this->assertSame($label, (string) $group['#title']);
      $this->assertSame('entity_autocomplete', $widget['#type']);
      $this->assertSame($target, $widget['#target_type']);
      $this->assertSame($bundles, array_values($widget['#selection_settings']['target_bundles'] ?? []));
      $this->assertSame($multiple, $widget['#tags']);
      if ($multiple) {
        $this->assertSame(['any', 'all'], array_keys($group['match']['#options']));
        $this->assertSame('Choose at most 10 items.', (string) $widget['#description']);
      }
      else {
        $this->assertArrayNotHasKey('match', $group);
      }
    }
  }

  /**
   * Tests that Views target metadata supplies the type without validation.
   */
  public function testReferenceMetadataFallback(): void {
    $view = Views::getView('viewsreference_sift_test');
    $this->assertInstanceOf(ViewExecutable::class, $view);
    $this->assertTrue($view->setDisplay('embed_multi'));
    $arguments = $view->display_handler->getOption('arguments');
    $arguments['field_program_target_id']['specify_validation'] = FALSE;
    $arguments['field_program_target_id']['validate']['type'] = 'none';
    unset($arguments['field_program_target_id']['validate_options']);
    $view->display_handler->overrideOption('arguments', $arguments);
    $view->storage->save();

    $field = $this->termsElement('embed_multi');
    $group = $field['filters']['field_program_target_id'] ?? NULL;
    $this->assertIsArray($group);
    $this->assertSame('content items', (string) $group['#title']);
    $this->assertSame('node', $group['ids']['#target_type']);
    $this->assertFalse($group['ids']['#tags']);
    $this->assertArrayNotHasKey('match', $group);
    $this->assertArrayNotHasKey('#selection_settings', $group['ids']);
  }

  /**
   * Tests saved filter options, strict schema, and the runtime selection cap.
   */
  public function testSavedDisplayOptions(): void {
    $this->saveTermOptions([
      'label' => 'Topics',
      'description' => 'Choose topics to show.',
      'bundles' => ['tags'],
      'match' => 'all',
      'widget' => 'checkboxes',
      'max' => 2,
      'required' => TRUE,
    ], ['tags', 'other']);
    $this->assertConfigSchemaByName('views.view.viewsreference_sift_test');

    $view = Views::getView('viewsreference_sift_test');
    $this->assertInstanceOf(ViewExecutable::class, $view);
    $this->assertTrue($view->setDisplay('embed_terms'));
    $filters = $this->container->get(FilterResolver::class)->forView($view);
    $this->assertSame(['tid'], array_keys($filters));
    $filter = $filters['tid'];
    $this->assertSame('Topics', (string) $filter->label);
    $this->assertSame('Choose topics to show.', (string) $filter->description);
    $this->assertSame(['tags'], $filter->bundles);
    $this->assertSame('checkboxes', $filter->widget);
    $this->assertSame(2, $filter->max);
    $this->assertTrue($filter->required);

    $group = $this->termsElement('embed_terms')['filters']['tid'];
    $this->assertSame('Choose topics to show.', (string) $group['#description']);
    $this->assertArrayNotHasKey('match', $group);

    $extra = Term::create(['vid' => 'tags', 'name' => 'Extra topic']);
    $extra->save();
    $stored = $this->storedSelection('tid', [$this->termIds['A'], $this->termIds['B'], (int) $extra->id()]);
    $view = $this->executeWithSetting('embed_terms', $stored);
    $this->assertSame([$this->termIds['A'] . ',' . $this->termIds['B']], $view->args);
    $this->assertResultTitles(['Both'], $view);

    $outside_bundles = $this->storedSelection('tid', [$this->termIds['C']]);
    $this->assertSame([], $this->executeWithSetting('embed_terms', $outside_bundles)->args);
  }

  /**
   * Tests applying defaults on page and AJAX requests through Views Reference.
   */
  public function testDisplayDefaultSelection(): void {
    $this->saveTermOptions([
      'default_ids' => [(string) $this->termIds['A'], (string) $this->termIds['B']],
      'match' => 'all',
    ]);
    $this->assertConfigSchemaByName('views.view.viewsreference_sift_test');

    foreach ([FALSE, TRUE] as $ajax) {
      $empty = $ajax ? '' : ['version' => 2, 'filters' => []];
      $view = $this->executeWithSetting('embed_terms', $empty, $ajax);
      $this->assertSame([$this->termIds['A'] . ',' . $this->termIds['B']], $view->args);
      $this->assertResultTitles(['Both'], $view);
      foreach (['A', 'B'] as $term) {
        $this->assertContains('taxonomy_term:' . $this->termIds[$term], $view->element['#cache']['tags']);
      }

      $stored = $this->storedSelection('tid', [$this->termIds['A']]);
      $view = $this->executeWithSetting('embed_terms', $stored, $ajax);
      $this->assertSame([(string) $this->termIds['A']], $view->args);
      $this->assertResultTitles(['A', 'Both'], $view);

      $invalid = $this->storedSelection('tid', [$this->termIds['C']]);
      $view = $this->executeWithSetting('embed_terms', $invalid, $ajax);
      $this->assertSame([$this->termIds['A'] . ',' . $this->termIds['B']], $view->args);
      $this->assertResultTitles(['Both'], $view);
    }
  }

  /**
   * Tests filling an empty filter alongside an explicit field selection.
   */
  public function testDisplayDefaultsFillOnlyEmptyFilters(): void {
    $ids = $this->createMultipleFilterContent();
    $this->config('views.settings')->set('display_extenders', ['viewsreference_sift'])->save();
    $view = View::load('viewsreference_sift_test');
    $this->assertInstanceOf(View::class, $view);
    $display = &$view->getDisplay('embed_multi');
    $display['display_options']['display_extenders']['viewsreference_sift']['filters'] = [
      'tid_tags' => ['default_ids' => [(string) $this->termIds['B']]],
      'tid_audience' => ['default_ids' => [(string) $ids['audience']]],
    ];
    $view->save();

    $stored = $this->storedSelection('tid_tags', [$this->termIds['A']]);
    $view = $this->executeWithSetting('embed_multi', $stored);
    $this->assertSame([(string) $this->termIds['A'], (string) $ids['audience']], $view->args);
    $this->assertResultTitles(['Both'], $view);
  }

  /**
   * Tests dropping invalid defaults and preserving editor validation failures.
   */
  public function testInvalidDisplayDefaults(): void {
    $deleted = Term::create(['vid' => 'tags', 'name' => 'Deleted default']);
    $deleted->save();
    $invalid = [(string) $deleted->id(), (string) $this->termIds['C']];
    $this->saveTermOptions(['default_ids' => [...$invalid, (string) $this->termIds['A']]]);
    $deleted->delete();

    $view = $this->executeWithSetting('embed_terms', '');
    $this->assertSame([(string) $this->termIds['A']], $view->args);
    $this->assertResultTitles(['A', 'Both'], $view);

    $this->saveTermOptions(['default_ids' => $invalid]);
    $view = $this->executeWithSetting('embed_terms', '');
    $this->assertSame([], $view->args);
    $this->assertResultTitles(['A', 'B', 'Both', 'Neither'], $view);

    $stored = $this->storedSelection('tid', [$this->termIds['C']]);
    $view = $this->executeWithSetting('embed_terms', $stored, expected_success: FALSE);
    $this->assertSame([(string) $this->termIds['C']], $view->args);
    $this->assertTrue($view->build_info['fail']);
  }

  /**
   * Tests that a display default satisfies required without storing an ID.
   */
  public function testRequiredDisplayDefault(): void {
    $this->saveTermOptions([
      'default_ids' => [(string) $this->termIds['A']],
      'required' => TRUE,
      'widget' => 'checkboxes',
    ]);
    $node = $this->createListing([]);
    [$form, $state, $widget] = $this->preservedSelectionForm($node, ['filters' => ['tid' => ['ids' => []]]]);
    $input = $form['listing']['widget'][0]['options']['sift_terms']['filters']['tid']['ids'];
    $this->assertStringStartsWith('Leave empty to use the default: A.', (string) $input['#description']);
    $this->assertStringContainsString('The default satisfies the selection requirement.', (string) $input['#description']);
    $this->assertSame([], $input['#default_value']);
    $this->assertFalse($input['#required'] ?? FALSE);
    $this->assertEmpty($state->getErrors());
    $this->assertSame(['version' => 2, 'filters' => []], $this->savePreservedSelection($node, $widget, $form, $state));
  }

  /**
   * Tests that a disabled filter contributes no widget or argument.
   */
  public function testDisabledDisplayFilter(): void {
    $this->saveTermOptions(['enabled' => FALSE, 'default_ids' => [(string) $this->termIds['A']]]);
    $stored = $this->storedSelection('tid', [$this->termIds['A']]);
    $field = $this->termsElement('embed_terms', $stored);
    $this->assertSame('value', $field['#type']);
    $this->assertSame($stored, $field['#value']);
    $this->assertArrayNotHasKey('filters', $field);

    $view = $this->executeWithSetting('embed_terms', $stored);
    $this->assertSame([], $view->args);
    $this->assertResultTitles(['A', 'B', 'Both', 'Neither'], $view);
  }

  /**
   * Tests display notices for earlier contextual filters with missing values.
   */
  public function testDisplayNoticesForMissingFilterValues(): void {
    $view = Views::getView('viewsreference_sift_test');
    $this->assertInstanceOf(ViewExecutable::class, $view);
    $this->assertTrue($view->setDisplay('embed_multi'));
    $display = $view->display_handler;
    $arguments = $display->getOption('arguments');
    $arguments['tid_tags']['default_action'] = 'default';
    $arguments['tid_tags']['default_argument_type'] = 'node';
    // Add an unsupported contextual filter before the last two Sift filters.
    $nid = $view->storage->getDisplay('embed_positions')['display_options']['arguments']['nid'];
    $nid['admin_label'] = 'Article ID';
    $nid['default_action'] = 'not found';
    $display->overrideOption('arguments', array_slice($arguments, 0, 2, TRUE) + ['nid' => $nid] + $arguments);

    $extender = $this->container->get('plugin.manager.views.display_extender')->createInstance('viewsreference_sift');
    $this->assertInstanceOf(SiftDisplayExtender::class, $extender);
    $settings = ['filters' => ['uid' => ['enabled' => FALSE] + SiftFilter::DEFAULT_OPTIONS]];
    $extender->init($view, $display, $settings);

    $categories = $summary = [];
    $extender->optionsSummary($categories, $summary);
    $this->assertSame('3 of 4 filters (1 blocked)', (string) $summary['viewsreference_sift']['value']);

    $form = ['#title' => ''];
    $form_state = (new FormState())->set('section', 'viewsreference_sift');
    $extender->buildOptionsForm($form, $form_state);
    $filters = $form['filters'];

    $this->assertArrayNotHasKey('nid', $filters);
    $this->assertArrayNotHasKey('blocking_filter', $filters['tid_tags']);
    $this->assertArrayNotHasKey('default_filter', $filters['tid_tags']);
    $this->assertArrayNotHasKey('blocking_filter', $filters['tid_audience']);

    $note = $filters['tid_audience']['default_filter'];
    $this->assertSame('item', $note['#type']);
    $this->assertSame('Default value', (string) $note['#title']);
    $this->assertSame('When Tags has no value, selections for Audience apply only if the default is available at render time.', (string) $note['#markup']);

    $program = $filters['field_program_target_id'];
    $warning = $program['blocking_filter'];
    $this->assertSame('item', $warning['#type']);
    $this->assertSame('Warning', (string) $warning['#title']);
    $this->assertSame('Selections for Program apply only when Article ID has a value. Its missing-value action is Hide view.', (string) $warning['#markup']);
    $this->assertSame('When Tags has no value, selections for Program apply only if the default is available at render time.', (string) $program['default_filter']['#markup']);
  }

  /**
   * Tests the configured checkbox and select widget options.
   */
  public function testDisplayChoiceWidgets(): void {
    $child = Term::create([
      'vid' => 'tags',
      'name' => 'Child & topics',
      'parent' => ['target_id' => $this->termIds['A']],
    ]);
    $child->save();
    foreach (['checkboxes', 'select'] as $type) {
      $this->saveTermOptions(['widget' => $type]);
      $widget = $this->termsElement('embed_terms')['filters']['tid']['ids'];
      $this->assertSame($type, $widget['#type']);
      $this->assertSame([
        $this->termIds['A'] => 'A',
        (int) $child->id() => '-Child & topics',
        $this->termIds['B'] => 'B',
      ], array_map('strval', $widget['#options']));
      if ($type === 'select') {
        $this->assertTrue($widget['#multiple']);
      }
    }
  }

  /**
   * Tests single-value radios and normalization of scalar values.
   */
  #[DataProvider('singleValueRequirements')]
  public function testSingleValueChoiceWidget(bool $required): void {
    $view = View::load('viewsreference_sift_test');
    $this->assertInstanceOf(View::class, $view);
    $displays = $view->get('display');
    $displays['embed_terms']['display_options']['arguments']['tid']['break_phrase'] = FALSE;
    $view->set('display', $displays)->save();
    $this->saveTermOptions(['label' => 'Topics', 'widget' => 'checkboxes', 'required' => $required]);

    $stored = $this->storedSelection('tid', [$this->termIds['B']]);
    $field = $this->termsElement('embed_terms', $stored);
    $widget = $field['filters']['tid']['ids'];
    $this->assertSame('radios', $widget['#type']);
    $this->assertSame((string) $this->termIds['B'], $widget['#default_value']);
    $options = [$this->termIds['A'] => 'A', $this->termIds['B'] => 'B'];
    $this->assertSame($required ? $options : ['' => 'None'] + $options, array_map('strval', $widget['#options']));
    $this->assertSame('radiogroup', $field['filters']['tid']['#attributes']['role']);
    $this->assertSame($required ? 'true' : NULL, $field['filters']['tid']['#attributes']['aria-required'] ?? NULL);
    $this->assertArrayNotHasKey('#required', $widget);
    $this->assertSame($required ? 'A selection is required.' : '', (string) ($widget['#description'] ?? ''));

    $empty = $this->termsElement('embed_terms')['filters']['tid']['ids'];
    $this->assertSame($required ? NULL : '', $empty['#default_value']);
    $field['#parents'] = ['field_listing', 0, 'options', 'sift_terms'];
    $callback = $field['#element_validate'][0];
    $this->assertIsCallable($callback);
    foreach ([(string) $this->termIds['B'], ''] as $value) {
      $form_state = new FormState();
      $form_state->setValue(['field_listing', 0, 'display_id'], 'embed_terms');
      $form_state->setValue([...$field['#parents'], 'filters', 'tid', 'ids'], $value);
      $callback($field, $form_state);
      $expected = $value === '' ? ['version' => 2, 'filters' => []] : $stored;
      $this->assertSame($expected, $form_state->getValue($field['#parents']));
      $errors = $required && $value === ''
        ? ['field_listing][0][options][sift_terms][filters][tid][ids' => 'Choose at least one item for Topics.']
        : [];
      $this->assertSame($errors, array_map('strval', $form_state->getErrors()));
      $form_state->clearErrors();
    }
  }

  /**
   * Provides optional and required single-value filters.
   *
   * @phpstan-return array<string, array{bool}>
   */
  public static function singleValueRequirements(): array {
    return ['optional' => [FALSE], 'required' => [TRUE]];
  }

  /**
   * Tests required state for the multiple-value widget types.
   */
  public function testRequiredWidgetState(): void {
    foreach (['autocomplete', 'select', 'checkboxes'] as $type) {
      $this->saveTermOptions(['label' => 'Topics', 'widget' => $type, 'required' => TRUE]);
      $field = $this->termsElement('embed_terms');
      $widget = $field['filters']['tid']['ids'];
      $this->assertSame('A selection is required. Choose at most 10 items.', (string) $widget['#description']);
      if ($type === 'checkboxes') {
        $this->assertTrue($widget['#required']);
        $this->assertSame('Choose at least one item for Topics.', (string) $widget['#required_error']);
      }
      else {
        $this->assertSame('true', $widget['#attributes']['aria-required']);
        $this->assertArrayNotHasKey('#required', $widget);
      }
    }
  }

  /**
   * Tests the option count limit for non-autocomplete widgets.
   */
  public function testLargeDisplayChoiceWidgetFallsBack(): void {
    // The fixture already provides two terms in the Tags vocabulary.
    for ($index = 0; $index < 98; $index++) {
      Term::create(['vid' => 'tags', 'name' => 'Extra topic ' . $index])->save();
    }
    $this->saveTermOptions(['widget' => 'checkboxes']);
    $widget = $this->termsElement('embed_terms')['filters']['tid']['ids'];
    $this->assertSame('checkboxes', $widget['#type']);
    $this->assertCount(100, $widget['#options']);

    Term::create(['vid' => 'tags', 'name' => 'One more topic'])->save();
    foreach (['checkboxes', 'select'] as $type) {
      $this->saveTermOptions(['widget' => $type]);
      $widget = $this->termsElement('embed_terms')['filters']['tid']['ids'];
      $this->assertSame('entity_autocomplete', $widget['#type']);
      $this->assertSame('Choose at most 10 items. More than 100 items are available. Use autocomplete to find an item.', (string) $widget['#description']);
    }
  }

  /**
   * Tests required and maximum selection errors on the editor's field.
   *
   * @param int $maximum
   *   The configured maximum number of selections.
   * @param string $limit_message
   *   The expected error for too many selections.
   */
  #[DataProvider('maximumSelectionMessages')]
  public function testDisplaySelectionValidation(int $maximum, string $limit_message): void {
    $this->saveTermOptions(['label' => 'Topics', 'widget' => 'checkboxes', 'max' => $maximum, 'required' => TRUE]);
    $field = $this->termsElement('embed_terms');
    $field['#parents'] = ['field_listing', 0, 'options', 'sift_terms'];
    $widget_parents = [...$field['#parents'], 'filters', 'tid', 'ids'];
    $field['filters']['tid']['ids']['#parents'] = $widget_parents;
    $callback = $field['#element_validate'][0];
    $this->assertIsCallable($callback);
    $ids = [$this->termIds['A'], $this->termIds['B']];
    for ($index = count($ids); $index <= $maximum; $index++) {
      $extra = Term::create(['vid' => 'tags', 'name' => 'Extra topic ' . $index]);
      $extra->save();
      $ids[] = (int) $extra->id();
    }

    foreach ([
      [[], 'Choose at least one item for Topics.'],
      [array_combine($ids, $ids), $limit_message],
    ] as [$values, $expected]) {
      $form_state = new FormState();
      $form_state->setValue(['field_listing', 0, 'display_id'], 'embed_terms');
      $form_state->setValue($widget_parents, $values);
      $callback($field, $form_state);
      $this->assertSame([implode('][', $widget_parents) => $expected], array_map('strval', $form_state->getErrors()));
      $form_state->clearErrors();
    }
  }

  /**
   * Provides selection limits with singular and plural messages.
   *
   * @return iterable<string, array{int, string}>
   *   The configured maximum and expected error message.
   */
  public static function maximumSelectionMessages(): iterable {
    yield 'singular' => [1, 'Choose at most 1 item for Topics.'];
    yield 'plural' => [3, 'Choose at most 3 items for Topics.'];
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
   * Tests filling an empty leading argument with a computed default.
   */
  public function testEmptyLeadingArgument(): void {
    $view = $this->viewWithArgumentSettings('/all', [$this->termIds['A']]);
    $arguments = $view->display_handler->getOption('arguments');
    $arguments['nid']['default_action'] = 'default';
    $arguments['nid']['default_argument_type'] = 'fixed';
    $arguments['nid']['default_argument_options'] = ['argument' => $this->nodeIds['Both']];
    $view->display_handler->overrideOption('arguments', $arguments);

    $view->preExecute();
    $this->assertSame([$this->nodeIds['Both'], (string) $this->termIds['A']], $view->args);
    $this->assertTrue($view->execute());
    $view->postExecute();
    $this->assertResultTitles(['Both'], $view);
  }

  /**
   * Tests that an empty leading argument can still hide the view.
   */
  public function testEmptyLeadingArgumentHidesView(): void {
    $view = $this->viewWithArgumentSettings('', [$this->termIds['A']], ['sift_terms']);
    $arguments = $view->display_handler->getOption('arguments');
    $arguments['nid']['default_action'] = 'not found';
    $view->display_handler->overrideOption('arguments', $arguments);

    $view->preExecute();
    $this->assertFalse($view->execute());
    $view->postExecute();
    $this->assertTrue($view->build_info['fail']);
    $this->assertSame([], $view->args);
    $this->assertSame([], $view->result);
  }

  /**
   * Tests that ignoring an empty leading argument allows the Sift filter.
   */
  public function testEmptyLeadingArgumentAllowsSiftWhenIgnored(): void {
    $view = $this->viewWithArgumentSettings('', [$this->termIds['A']], ['sift_terms']);
    $arguments = $view->display_handler->getOption('arguments');
    $arguments['nid']['default_action'] = 'ignore';
    $arguments['nid']['exception']['value'] = 'skip';
    $view->display_handler->overrideOption('arguments', $arguments);

    $view->preExecute();
    $this->assertSame(['skip', (string) $this->termIds['A']], $view->args);
    $this->assertTrue($view->execute());
    $view->postExecute();
    $this->assertResultTitles(['A', 'Both'], $view);
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
    $default = $stale['filters']['tid']['ids']['#default_value'];
    $this->assertInstanceOf(Term::class, $default);
    $this->assertSame($this->termIds['A'], (int) $default->id());
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
   * Tests that saving a disabled filter preserves its saved selection.
   */
  public function testHiddenWidgetPreservesSavedSelection(): void {
    $node = $this->createListing([$this->termIds['A']]);
    $this->saveTermOptions(['enabled' => FALSE]);

    $saved = $this->saveListingThroughWidget($node, [
      'target_id' => 'viewsreference_sift_test',
      'display_id' => 'embed_terms',
      'options' => ['sift_terms' => $this->storedSelection('tid', [$this->termIds['B']])],
    ]);

    $data = $saved->get('field_listing')->first()?->get('data')->getValue();
    $this->assertIsString($data);
    $settings = unserialize($data, ['allowed_classes' => FALSE]);
    $this->assertIsArray($settings);
    $this->assertSame($this->storedSelection('tid', [$this->termIds['A']]), $settings['sift_terms'], 'Saving a disabled filter must preserve the saved selection and ignore submitted values.');
  }

  /**
   * Tests that changing the selected view or display clears hidden settings.
   */
  #[DataProvider('hiddenWidgetRebuilds')]
  public function testWidgetRebuildClearsSelection(bool $change_view): void {
    $node = $this->createListing([$this->termIds['A']]);
    $view_id = 'viewsreference_sift_test';
    $display_id = 'embed_plain';
    if ($change_view) {
      $view = View::load($view_id);
      $this->assertInstanceOf(View::class, $view);
      $view = $view->createDuplicate();
      $view_id = 'other_listing';
      $display_id = 'embed_terms';
      $displays = $view->get('display');
      $displays['embed_terms']['display_options']['arguments'] = [];
      $view->set('id', $view_id)
        ->set('display', array_intersect_key($displays, array_flip(['default', 'embed_terms'])))
        ->save();
    }

    $saved = $this->saveListingThroughWidget($node, [
      'target_id' => $view_id,
      'display_id' => $display_id,
      'options' => ['sift_terms' => $this->storedSelection('tid', [$this->termIds['A']])],
    ], TRUE);
    $item = $saved->get('field_listing')->first();
    $this->assertNotNull($item);
    $this->assertSame($view_id, $item->get('target_id')->getValue());
    $this->assertSame($display_id, $item->get('display_id')->getValue());
    $data = $item->get('data')->getValue();
    $this->assertIsString($data);
    $settings = unserialize($data, ['allowed_classes' => FALSE]);
    $this->assertIsArray($settings);
    $this->assertSame('', $settings['sift_terms'], 'Rebuilding for a different view or display must clear the previous selection.');
  }

  /**
   * Provides changes that rebuild the Views Reference widget.
   *
   * @phpstan-return array<string, array{bool}>
   */
  public static function hiddenWidgetRebuilds(): array {
    return ['display' => [FALSE], 'view' => [TRUE]];
  }

  /**
   * Processes input through Views Reference and saves the resulting field data.
   *
   * @phpstan-param array<string, mixed> $input
   */
  private function saveListingThroughWidget(NodeInterface $node, array $input, bool $rebuild = FALSE): NodeInterface {
    $items = $node->get('field_listing');
    $definition = $items->getFieldDefinition();
    $this->assertInstanceOf(FieldConfig::class, $definition);
    $definition->setSetting('plugin_types', ['embed' => 'embed']);
    $widget = $this->container->get('plugin.manager.field.widget')->getInstance([
      'field_definition' => $definition,
      'form_mode' => 'default',
      'configuration' => ['type' => 'viewsreference_select'],
    ]);
    $this->assertInstanceOf(WidgetInterface::class, $widget);
    $form_state = new FormState();
    if ($rebuild) {
      $form_state->setUserInput(['field_listing' => [$input]]);
      $trigger = $input['target_id'] === $items->first()?->get('target_id')->getValue() ? 'display_id' : 'target_id';
      $form_state->setTriggeringElement(['#parents' => ['field_listing', 0, $trigger]]);
    }
    $form = ['#type' => 'form', '#parents' => [], '#tree' => TRUE, '#action' => '/'];
    $form['field_listing'][0] = $widget->formElement($items, 0, ['#field_parents' => [], '#required' => FALSE], $form, $form_state);
    $form_state->setUserInput(['form_id' => 'sift_listing_test', 'field_listing' => [$input]]);
    $this->container->get('form_builder')->doBuildForm('sift_listing_test', $form, $form_state);
    $values = $form_state->getValue('field_listing');
    $this->assertIsArray($values);
    $node->set('field_listing', $widget->massageFormValues($values, $form, $form_state))->save();
    $saved = $this->container->get('entity_type.manager')->getStorage('node')->loadUnchanged($node->id());
    $this->assertInstanceOf(NodeInterface::class, $saved);
    return $saved;
  }

  /**
   * Tests retaining inaccessible saved IDs with every widget type.
   */
  #[DataProvider('preservedWidgetTypes')]
  public function testInaccessibleSelectionsSurvive(string $type, bool $multiple): void {
    $node = $this->createListing([$this->termIds['B']]);
    $this->configurePreservedWidget($type, $multiple);
    $term = Term::load($this->termIds['B']);
    $this->assertInstanceOf(Term::class, $term);
    $term->setUnpublished()->save();
    $forged = Term::create(['vid' => 'tags', 'name' => 'Unselected private term', 'status' => FALSE]);
    $forged->save();
    $this->useRestrictedEditor();

    $input = ['filters' => ['tid' => ['retained' => [$forged->id()]]]];
    if ($type !== 'radios') {
      $input['filters']['tid']['ids'] = $type === 'autocomplete' ? '' : [];
    }
    [$form, $state, $widget] = $this->preservedSelectionForm($node, $input);
    $field = $form['listing']['widget'][0]['options']['sift_terms'];
    $this->assertArrayNotHasKey('#description', $field, 'Inaccessible selections must not be reported as stale.');
    $this->assertSame([
      '#type' => 'value',
      '#value' => [$this->termIds['B']],
    ], array_intersect_key($field['filters']['tid']['retained'], array_flip(['#type', '#value'])));
    $this->assertStringContainsString("1 selected item isn't shown because you don't have access to it. It will be kept.", (string) $field['filters']['tid']['ids']['#description']);
    $this->assertEmpty($state->getErrors(), 'An inaccessible saved selection must not block saving.');
    $this->assertSame($this->storedSelection('tid', [$this->termIds['B']]), $this->savePreservedSelection($node, $widget, $form, $state));
  }

  /**
   * Tests that deleted and out-of-bundle selections are removed on save.
   */
  public function testStaleSelectionsAreRemovedOnSave(): void {
    $node = $this->createListing([$this->termIds['B'], $this->termIds['C']]);
    $this->configurePreservedWidget('checkboxes', TRUE, FALSE);
    $term = Term::load($this->termIds['B']);
    $this->assertInstanceOf(Term::class, $term);
    $term->delete();
    $this->useRestrictedEditor();
    [$form, $state, $widget] = $this->preservedSelectionForm($node, ['filters' => ['tid' => ['ids' => []]]]);
    $field = $form['listing']['widget'][0]['options']['sift_terms'];
    $this->assertSame('Some saved selections no longer apply to this display and will be removed when you save.', (string) $field['#description']);
    $this->assertSame([], $field['filters']['tid']['retained']['#value']);
    $this->assertEmpty($state->getErrors());
    $this->assertSame(['version' => 2, 'filters' => []], $this->savePreservedSelection($node, $widget, $form, $state));
  }

  /**
   * Tests that retained IDs count toward the selection maximum.
   */
  public function testRetainedSelectionsCountTowardMaximum(): void {
    $node = $this->createListing([$this->termIds['B']]);
    $this->configurePreservedWidget('checkboxes', TRUE);
    $this->saveTermOptions(['widget' => 'checkboxes', 'label' => 'Topics', 'max' => 1]);
    $term = Term::load($this->termIds['B']);
    $this->assertInstanceOf(Term::class, $term);
    $term->setUnpublished()->save();
    $this->useRestrictedEditor();
    [, $state] = $this->preservedSelectionForm($node, ['filters' => ['tid' => ['ids' => [$this->termIds['A'] => $this->termIds['A']]]]]);
    $this->assertContains('Choose at most 1 item for Topics.', array_map('strval', $state->getErrors()));
    $state->clearErrors();
  }

  /**
   * Tests that a retained item deleted after form construction is removed.
   */
  public function testRetainedSelectionDeletedBeforeValidation(): void {
    $this->configurePreservedWidget('checkboxes', TRUE, FALSE);
    $term = Term::load($this->termIds['B']);
    $this->assertInstanceOf(Term::class, $term);
    $term->setUnpublished()->save();
    $this->useRestrictedEditor();
    $field = $this->termsElement('embed_terms', $this->storedSelection('tid', [$this->termIds['B']]));
    $this->assertSame([$this->termIds['B']], $field['filters']['tid']['retained']['#value']);
    $term->delete();
    $field['#parents'] = ['field_listing', 0, 'options', 'sift_terms'];
    $state = new FormState();
    $callback = $field['#element_validate'][0];
    $this->assertIsCallable($callback);
    $callback($field, $state);
    $this->assertSame(['version' => 2, 'filters' => []], $state->getValue($field['#parents']));
  }

  /**
   * Tests that inaccessible IDs cannot be submitted as new visible choices.
   */
  #[DataProvider('preservedWidgetTypes')]
  public function testForgedVisibleSelectionsAreRejected(string $type, bool $multiple): void {
    $node = $this->createListing([]);
    $this->configurePreservedWidget($type, $multiple, FALSE);
    $forged = Term::create(['vid' => 'tags', 'name' => 'Forged choice', 'status' => FALSE]);
    $forged->save();
    $this->useRestrictedEditor();
    $ids = match ($type) {
      'autocomplete' => 'Forged choice (' . $forged->id() . ')',
      'radios' => (string) $forged->id(),
      default => [$forged->id() => $forged->id()],
    };
    [, $state] = $this->preservedSelectionForm($node, ['filters' => ['tid' => ['ids' => $ids]]]);
    $this->assertNotEmpty($state->getErrors(), 'An inaccessible new choice must fail the normal widget validation.');
    $state->clearErrors();
  }

  /**
   * Tests native autocomplete selections and rejects forged bundle IDs.
   */
  public function testNativeAutocompleteValidatesSelections(): void {
    $node = $this->createListing([]);
    $this->saveTermOptions(['label' => 'Topics'], ['tags']);
    $this->useRestrictedEditor();
    $plugin_id = 'entity_reference_autocomplete_tags';
    [$form] = $this->preservedSelectionForm($node, [], autocomplete_widget: $plugin_id);
    $ids = $form['listing']['widget'][0]['options']['sift_terms']['filters']['tid']['ids'];
    $field_name = $ids['field']['widget']['#field_name'];
    $this->assertFalse($ids['field']['widget']['target_id']['#validate_reference']);

    $selection['filters']['tid']['ids'][$field_name]['target_id'] = 'A (' . $this->termIds['A'] . ')';
    [$form, $state, $widget] = $this->preservedSelectionForm($node, $selection, autocomplete_widget: $plugin_id);
    $this->assertEmpty($state->getErrors());
    $this->assertSame($this->storedSelection('tid', [$this->termIds['A']]), $this->savePreservedSelection($node, $widget, $form, $state));

    $selection['filters']['tid']['ids'][$field_name]['target_id'] = 'C (' . $this->termIds['C'] . ')';
    [, $state] = $this->preservedSelectionForm($node, $selection, autocomplete_widget: $plugin_id);
    $this->assertContains('One or more of the selected items are unavailable.', array_map('strval', $state->getErrors()));
    $state->clearErrors();

    $fallback = ['filters' => ['tid' => ['ids' => 'B (' . $this->termIds['B'] . ')']]];
    [$form, $state, $widget] = $this->preservedSelectionForm($node, $fallback, autocomplete_widget: 'unavailable_widget');
    $this->assertSame('entity_autocomplete', $form['listing']['widget'][0]['options']['sift_terms']['filters']['tid']['ids']['#type']);
    $this->assertEmpty($state->getErrors());
    $this->assertSame($this->storedSelection('tid', [$this->termIds['B']]), $this->savePreservedSelection($node, $widget, $form, $state));
  }

  /**
   * Tests that forged serialized defaults cannot introduce retained IDs.
   */
  public function testForgedSerializedDefaultsCannotAddSelections(): void {
    $node = $this->createListing([$this->termIds['B']]);
    $this->configurePreservedWidget('checkboxes', TRUE);
    $term = Term::load($this->termIds['B']);
    $this->assertInstanceOf(Term::class, $term);
    $term->setUnpublished()->save();
    $forged = Term::create(['vid' => 'tags', 'name' => 'Forged private term', 'status' => FALSE]);
    $forged->save();
    $this->useRestrictedEditor();
    [$form, $state, $widget] = $this->preservedSelectionForm($node, ['filters' => ['tid' => ['ids' => []]]], serialize(['sift_terms' => $this->storedSelection('tid', [(int) $forged->id()])]));
    $this->assertEmpty($state->getErrors());
    $this->assertSame($this->storedSelection('tid', [$this->termIds['B']]), $this->savePreservedSelection($node, $widget, $form, $state));
  }

  /**
   * Tests history isolation when a saved item moves into another item's delta.
   */
  public function testMovedWidgetPreservesIdenticalSavedData(): void {
    $node = $this->createListing([$this->termIds['B']]);
    $this->configurePreservedWidget('autocomplete', TRUE, FALSE);
    $term = Term::load($this->termIds['B']);
    $this->assertInstanceOf(Term::class, $term);
    $term->setUnpublished()->save();
    $this->useRestrictedEditor();
    $input = ['filters' => ['tid' => ['ids' => '']]];
    [, $state] = $this->preservedSelectionForm($node, $input);
    $this->assertEmpty($state->getErrors());
    $this->assertSame(
      $this->storedSelection('tid', [$this->termIds['B']]),
      $state->getValue(['field_listing', 0, 'options', 'sift_terms']),
    );

    $item = $node->get('field_listing')->first();
    $this->assertNotNull($item);
    $data = $item->get('data')->getValue();
    $item->set('display_id', 'embed_positions');
    $node->save();
    $this->assertSame($data, $node->get('field_listing')->first()?->get('data')->getValue());

    [$form, $state, $widget] = $this->preservedSelectionForm($node, $input, NULL, $state, 'embed_positions');
    $this->assertEmpty($state->getErrors());
    $this->assertSame($this->storedSelection('tid', [$this->termIds['B']]), $this->savePreservedSelection($node, $widget, $form, $state));
  }

  /**
   * Tests rebuild preservation and clearing after a display switch and return.
   */
  public function testPreservedSelectionRebuildHistory(): void {
    $node = $this->createListing([$this->termIds['B']]);
    $this->configurePreservedWidget('checkboxes', TRUE, FALSE);
    $term = Term::load($this->termIds['B']);
    $this->assertInstanceOf(Term::class, $term);
    $term->setUnpublished()->save();
    $this->useRestrictedEditor();
    $input = ['filters' => ['tid' => ['ids' => []]]];
    [, $state] = $this->preservedSelectionForm($node, $input);
    foreach ([
      ['embed_terms', $this->storedSelection('tid', [$this->termIds['B']])],
      ['embed_plain', ''],
      ['embed_terms', ['version' => 2, 'filters' => []]],
    ] as [$display, $expected]) {
      [, $state] = $this->preservedSelectionForm($node, $input, NULL, $state, $display);
      $this->assertEmpty($state->getErrors());
      $this->assertSame($expected, $state->getValue(['field_listing', 0, 'options', 'sift_terms']));
    }
  }

  /**
   * Provides the visible widget types and cardinalities.
   *
   * @phpstan-return array<string, array{string, bool}>
   */
  public static function preservedWidgetTypes(): array {
    return [
      'autocomplete' => ['autocomplete', TRUE],
      'checkboxes' => ['checkboxes', TRUE],
      'select' => ['select', TRUE],
      'radios' => ['radios', FALSE],
    ];
  }

  /**
   * Configures the filter used by the preservation tests.
   */
  private function configurePreservedWidget(string $type, bool $multiple, bool $required = TRUE): void {
    $view = View::load('viewsreference_sift_test');
    $this->assertInstanceOf(View::class, $view);
    $displays = $view->get('display');
    $displays['embed_terms']['display_options']['arguments']['tid']['break_phrase'] = $multiple;
    $view->set('display', $displays)->save();
    $this->saveTermOptions([
      'widget' => $type === 'radios' ? 'checkboxes' : $type,
      'label' => 'Topics',
      'required' => $required,
    ]);
  }

  /**
   * Switches to an editor who can reference published content.
   */
  private function useRestrictedEditor(): void {
    User::create(['uid' => 1, 'name' => 'Administrator', 'status' => TRUE])->save();
    Role::create([
      'id' => 'sift_editor',
      'label' => 'Sift editor',
      'permissions' => ['access content', 'edit any article content'],
    ])->save();
    $editor = User::create(['name' => 'Limited editor', 'status' => TRUE, 'roles' => ['sift_editor']]);
    $editor->save();
    $this->container->get('current_user')->setAccount($editor);
  }

  /**
   * Builds and validates the real field widget, including server-only values.
   *
   * @phpstan-param array<string, mixed> $selection
   * @phpstan-return array{array<array-key, mixed>, FormStateInterface, WidgetInterface}
   */
  private function preservedSelectionForm(NodeInterface $node, array $selection, ?string $forged_data = NULL, ?FormStateInterface $state = NULL, string $display = 'embed_terms', string $autocomplete_widget = ''): array {
    $items = $node->get('field_listing');
    $definition = $items->getFieldDefinition();
    $this->assertInstanceOf(FieldConfig::class, $definition);
    $definition->setSetting('plugin_types', ['embed' => 'embed']);
    $widget = $this->container->get('plugin.manager.field.widget')->getInstance([
      'field_definition' => $definition,
      'form_mode' => 'default',
      'configuration' => ['type' => 'viewsreference_select'],
    ]);
    $this->assertInstanceOf(WidgetInterface::class, $widget);
    if ($autocomplete_widget !== '') {
      $widget->setThirdPartySetting('viewsreference_sift', 'autocomplete_widget', $autocomplete_widget);
    }
    $rebuild = $state !== NULL || $forged_data !== NULL;
    $state ??= new FormState();
    $state->setValues([])->setValidationComplete(FALSE);
    $input = [
      'form_id' => 'sift_preservation_test',
      'field_listing' => [
        [
          'target_id' => 'viewsreference_sift_test',
          'display_id' => $display,
          'options' => ['sift_terms' => $selection],
        ],
      ],
    ];
    if ($forged_data !== NULL) {
      $input['field_listing'][0]['data'] = $forged_data;
    }
    if ($rebuild) {
      $state->setUserInput($input);
    }
    $form = ['#type' => 'form', '#parents' => [], '#tree' => TRUE, '#action' => '/', '#validate' => []];
    $form['listing'] = $widget->form($items, $form, $state, 0);
    $state->setUserInput($input)->setSubmitted();
    $this->container->get('form_builder')->doBuildForm('sift_preservation_test', $form, $state);
    $this->container->get('form_validator')->validateForm('sift_preservation_test', $form, $state);
    return [$form, $state, $widget];
  }

  /**
   * Serializes validated widget values and reloads the saved selection.
   *
   * @phpstan-param array<array-key, mixed> $form
   * @phpstan-return array<string, mixed>
   */
  private function savePreservedSelection(NodeInterface $node, WidgetInterface $widget, array $form, FormStateInterface $state): array {
    $values = $state->getValue('field_listing');
    $this->assertIsArray($values);
    $node->set('field_listing', $widget->massageFormValues($values, $form, $state))->save();
    $saved = $this->container->get('entity_type.manager')->getStorage('node')->loadUnchanged($node->id());
    $this->assertInstanceOf(NodeInterface::class, $saved);
    $data = $saved->get('field_listing')->first()?->get('data')->getValue();
    $this->assertIsString($data);
    $settings = unserialize($data, ['allowed_classes' => FALSE]);
    $this->assertIsArray($settings);
    return $settings['sift_terms'];
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
    $this->assertSame('value', $this->termsElement('embed_plain')['#type']);
    $this->assertSame('value', $this->termsElement('embed_terms', '', 'missing')['#type']);

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
   * Pins the public stored format through widget validation and serialization.
   *
   * @phpstan-param array{mode?: string} $submitted_mode
   * @phpstan-param array{mode?: 'exclude'} $stored_mode
   */
  #[DataProvider('storedSelectionProvider')]
  public function testWidgetValidation(string $match, array $submitted_mode = [], array $stored_mode = [], bool $allow_exclude = FALSE): void {
    if ($allow_exclude) {
      $this->saveTermOptions(['allow_exclude' => TRUE]);
    }
    $field = $this->termsElement('embed_terms');
    if ($allow_exclude) {
      $this->assertSame(['include', 'exclude'], array_keys($field['filters']['tid']['mode']['#options']));
    }
    else {
      $this->assertArrayNotHasKey('mode', $field['filters']['tid']);
    }
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
          'match' => $match,
        ] + $submitted_mode,
      ],
    ]);

    $callback = $field['#element_validate'][0];
    $this->assertIsCallable($callback);
    $callback($field, $form_state);
    $expected = [
      'version' => 2,
      'filters' => [
        'tid' => [
          'ids' => [$this->termIds['B'], $this->termIds['A']],
          'match' => $stored_mode === [] ? $match : 'any',
        ] + $stored_mode,
      ],
    ];
    $validated = $form_state->getValue($field['#parents']);
    $this->assertSame($expected, $validated, 'Widget validation must preserve the public version 2 selection format.');

    $items = $this->createListing([])->get('field_listing');
    $widget = $this->container->get('plugin.manager.field.widget')->getInstance([
      'field_definition' => $items->getFieldDefinition(),
      'form_mode' => 'default',
      'configuration' => ['type' => 'viewsreference_autocomplete'],
    ]);
    $this->assertInstanceOf(WidgetInterface::class, $widget);
    $values = $widget->massageFormValues([
      [
        'target_id' => 'viewsreference_sift_test',
        'display_id' => 'embed_terms',
        'options' => ['sift_terms' => $validated],
      ],
    ], [], $form_state);
    $this->assertIsString($values[0]['data']);
    $stored = unserialize($values[0]['data'], ['allowed_classes' => FALSE]);
    $this->assertIsArray($stored);
    $this->assertSame($expected, $stored['sift_terms'] ?? NULL, 'Views Reference must serialize the exact public selection array under sift_terms.');
  }

  /**
   * Provides the public stored match and mode values.
   *
   * @phpstan-return array<string, array{string, 1?: array{mode: string}, 2?: array{mode?: 'exclude'}, 3?: bool}>
   */
  public static function storedSelectionProvider(): array {
    return [
      'any' => ['any'],
      'all' => ['all'],
      'exclude' => ['all', ['mode' => 'exclude'], ['mode' => 'exclude'], TRUE],
      'include' => ['all', ['mode' => 'include'], [], TRUE],
      'unknown mode' => ['all', ['mode' => 'unknown'], [], TRUE],
      'disallowed exclude' => ['all', ['mode' => 'exclude']],
    ];
  }

  /**
   * Tests that Sift hooks run on either side of Views Reference.
   */
  public function testPreViewHookOrder(): void {
    $order = [];
    \Drupal::moduleHandler()->invokeAllWith('views_pre_view', static function (callable $implementation, string $module) use (&$order): void {
      $order[] = is_array($implementation) && $implementation[0] instanceof SiftHooks ? $implementation[1] : $module;
    });

    $this->assertSame(['snapshot', 'viewsreference', 'applySelection'], $order);
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
    $this->config('views.settings')->set('display_extenders', ['viewsreference_sift'])->save();
    $config = View::load('viewsreference_sift_test');
    $this->assertInstanceOf(View::class, $config);
    $display = &$config->getDisplay('page_terms');
    $display['display_options']['display_extenders']['viewsreference_sift']['filters']['tid']['default_ids'] = [(string) $this->termIds['A']];
    $config->save();
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
   * Tests that Views AJAX validates supplied values and ignores the parent.
   */
  public function testAjaxUsesSuppliedSelectionDespiteParentMetadata(): void {
    $node = $this->createListing([$this->termIds['A']]);
    $node->setUnpublished()->save();
    $missing_id = max($this->termIds) + 1;
    $supplied = $this->storedSelection('tid', [$this->termIds['C'], $missing_id, (string) $this->termIds['B']]);
    $view = $this->executeWithSetting('embed_terms', $supplied, TRUE, $this->parentMetadata($node));

    $this->assertSame([(string) $this->termIds['B']], $view->args);
    $this->assertResultTitles(['B', 'Both'], $view);
  }

  /**
   * Tests that AJAX checks supplied bundles and retains validation failure.
   */
  public function testAjaxValidatesSuppliedSelection(): void {
    $mixed = $this->storedSelection('tid', [$this->termIds['C'], $this->termIds['A']]);
    $view = $this->executeWithSetting('embed_terms', $mixed, TRUE);
    $this->assertSame([(string) $this->termIds['A']], $view->args);
    $this->assertResultTitles(['A', 'Both'], $view);

    $wrong_bundle = $this->storedSelection('tid', [$this->termIds['C']]);
    $view = $this->executeWithSetting('embed_terms', $wrong_bundle, TRUE, expected_success: FALSE);
    $this->assertTrue($view->build_info['fail']);
    $this->assertSame([], $view->result);
  }

  /**
   * Tests that a large supplied AJAX selection contributes only ten IDs.
   */
  public function testAjaxCapsSuppliedSelection(): void {
    $ids = [];
    for ($index = 0; $index < 12; $index++) {
      $term = Term::create(['vid' => 'tags', 'name' => 'Extra term ' . $index]);
      $term->save();
      $ids[] = (int) $term->id();
    }
    $selection = $this->storedSelection('tid', array_merge($ids, range(1000, 1487)));
    $view = $this->executeWithSetting('embed_terms', $selection, TRUE);

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
   * Adds audience, program, and author values to the fixture articles.
   *
   * @phpstan-return array{audience: int, program: int, other_program: int, author: int}
   */
  protected function createMultipleFilterContent(): array {
    $audiences = [];
    foreach (['Public', 'Staff'] as $name) {
      $term = Term::create(['vid' => 'audience', 'name' => $name]);
      $term->save();
      $audiences[$name] = (int) $term->id();
    }
    $programs = [];
    foreach (['Alpha', 'Beta'] as $title) {
      $node = Node::create(['type' => 'program', 'title' => $title, 'status' => TRUE]);
      $node->save();
      $programs[$title] = (int) $node->id();
    }
    $authors = [];
    foreach (['one', 'two'] as $name) {
      $user = User::create(['name' => 'Writer ' . $name, 'mail' => 'writer-' . $name . '@example.com', 'status' => TRUE]);
      $user->save();
      $authors[$name] = (int) $user->id();
    }

    $assignments = [
      'A' => ['Public', 'Alpha', 'two'],
      'B' => ['Staff', 'Alpha', 'one'],
      'Both' => ['Staff', 'Beta', 'two'],
      'Neither' => ['Public', 'Beta', 'one'],
    ];
    foreach ($assignments as $title => [$audience, $program, $author]) {
      $node = Node::load($this->nodeIds[$title]);
      $this->assertInstanceOf(NodeInterface::class, $node);
      $node->set('field_audience', ['target_id' => $audiences[$audience]]);
      $node->set('field_program', ['target_id' => $programs[$program]]);
      $node->setOwnerId($authors[$author]);
      $node->save();
    }

    return [
      'audience' => $audiences['Staff'],
      'program' => $programs['Alpha'],
      'other_program' => $programs['Beta'],
      'author' => $authors['one'],
    ];
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
  protected function parentMetadata(NodeInterface $node): array {
    return [
      'parent_entity_type' => $node->getEntityTypeId(),
      'parent_entity_id' => $node->id(),
      'parent_entity_langcode' => $node->language()->getId(),
      'parent_field_name' => 'field_listing',
      'parent_revision_id' => $node->getRevisionId(),
      'field_item_delta' => 0,
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
   * Saves display settings through the View config entity.
   *
   * @phpstan-param array<string, mixed> $options
   * @phpstan-param list<string>|null $validator_bundles
   */
  protected function saveTermOptions(array $options, ?array $validator_bundles = NULL): void {
    $this->config('views.settings')->set('display_extenders', ['viewsreference_sift'])->save();
    $view = View::load('viewsreference_sift_test');
    $this->assertInstanceOf(View::class, $view);
    $display = &$view->getDisplay('embed_terms');
    $display['display_options']['display_extenders']['viewsreference_sift']['filters']['tid'] = $options + SiftFilter::DEFAULT_OPTIONS;
    if ($validator_bundles !== NULL) {
      $display['display_options']['arguments']['tid']['validate_options']['bundles'] = array_combine($validator_bundles, $validator_bundles);
    }
    $view->save();
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
   * @phpstan-param list<string> $args
   */
  protected function executeWithSetting(string $display, mixed $value, bool $ajax = FALSE, array $parent = [], bool $expected_success = TRUE, array $args = []): ViewExecutable {
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
      $view->preExecute($args);
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
