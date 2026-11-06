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
use Drupal\Tests\SchemaCheckTestTrait;
use Drupal\user\Entity\User;
use Drupal\views\Entity\View;
use Drupal\views\ResultRow;
use Drupal\views\ViewExecutable;
use Drupal\views\Views;
use Drupal\viewsreference_sift\Filter\FilterResolver;
use Drupal\viewsreference_sift\Filter\SiftFilter;
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
   * Tests that a disabled filter contributes no widget or argument.
   */
  public function testDisabledDisplayFilter(): void {
    $this->saveTermOptions(['enabled' => FALSE]);
    $stored = $this->storedSelection('tid', [$this->termIds['A']]);
    $field = $this->termsElement('embed_terms', $stored);
    $this->assertFalse($field['#access']);
    $this->assertArrayNotHasKey('filters', $field);

    $view = $this->executeWithSetting('embed_terms', $stored);
    $this->assertSame([], $view->args);
    $this->assertResultTitles(['A', 'B', 'Both', 'Neither'], $view);
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
      $this->assertSame('More than 100 items are available. Use autocomplete to find an item.', (string) $widget['#description']);
    }
  }

  /**
   * Tests required and maximum selection errors on the editor's field.
   */
  public function testDisplaySelectionValidation(): void {
    $this->saveTermOptions(['label' => 'Topics', 'widget' => 'checkboxes', 'max' => 2, 'required' => TRUE]);
    $field = $this->termsElement('embed_terms');
    $field['#parents'] = ['field_listing', 0, 'options', 'sift_terms'];
    $widget_parents = [...$field['#parents'], 'filters', 'tid', 'ids'];
    $field['filters']['tid']['ids']['#parents'] = $widget_parents;
    $callback = $field['#element_validate'][0];
    $this->assertIsCallable($callback);
    $extra = Term::create(['vid' => 'tags', 'name' => 'Extra topic']);
    $extra->save();
    $ids = [$this->termIds['A'], $this->termIds['B'], (int) $extra->id()];

    foreach ([
      [[], 'Choose at least one item for Topics.'],
      [array_combine($ids, $ids), 'Choose at most 2 items for Topics.'],
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
