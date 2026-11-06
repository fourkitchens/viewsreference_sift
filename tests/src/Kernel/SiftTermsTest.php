<?php

declare(strict_types=1);

namespace Drupal\Tests\viewsreference_sift\Kernel;

use Drupal\Core\Field\FieldStorageDefinitionInterface;
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
      Node::create([
        'type' => 'article',
        'title' => $title,
        'status' => TRUE,
        'field_tags' => array_map(fn (string $term): array => ['target_id' => $this->termIds[$term]], $terms),
      ])->save();
    }
    $this->installConfig(['viewsreference_sift_test']);
  }

  /**
   * Tests the contextual filter and the Views Reference request payload.
   */
  public function testTermArguments(): void {
    $terms = [['target_id' => $this->termIds['A']], ['target_id' => $this->termIds['B']]];
    $any = ['terms' => $terms, 'match' => 'any'];
    $all = ['terms' => $terms, 'match' => 'all'];

    $this->assertResultTitles(['A', 'B', 'Both'], $this->executeWithSetting('embed_terms', $any));
    $this->assertResultTitles(['A', 'B', 'Both'], $this->executeWithSetting('embed_terms', $any, TRUE));
    $this->assertResultTitles(['Both'], $this->executeWithSetting('embed_terms', $all));
    $this->assertResultTitles(['A', 'B', 'Both', 'Neither'], $this->executeWithSetting('embed_terms', ['terms' => []]));
    $this->assertResultTitles(['A', 'B', 'Both', 'Neither'], $this->executeWithSetting('embed_terms', 'legacy'));

    $depth = $this->executeWithSetting('embed_depth', $all);
    $this->assertResultTitles(['A', 'B', 'Both'], $depth);
    $this->assertSame([implode('+', [$this->termIds['A'], $this->termIds['B']])], $depth->args);

    $plain = $this->executeWithSetting('embed_plain', $any);
    $this->assertResultTitles(['A', 'B', 'Both', 'Neither'], $plain);
    $this->assertSame([], $plain->args);

    $malformed = [
      'terms' => [
        ['target_id' => (string) $this->termIds['A']],
        ['target_id' => $this->termIds['B'] . '+' . $this->termIds['C']],
      ],
    ];
    $this->assertResultTitles(['A', 'Both'], $this->executeWithSetting('embed_terms', $malformed, TRUE));
  }

  /**
   * Tests that a taxonomy filter in the second position is ignored.
   */
  public function testFirstArgumentContract(): void {
    $view = Views::getView('viewsreference_sift_test');
    $view->setDisplay('embed_terms');
    $display = $view->display_handler;
    $arguments = [
      'nid' => [
        'id' => 'nid',
        'table' => 'node_field_data',
        'field' => 'nid',
        'entity_type' => 'node',
        'entity_field' => 'nid',
        'plugin_id' => 'numeric',
        'default_action' => 'ignore',
      ],
    ];
    $display->overrideOption('arguments', $arguments + $display->getOption('arguments'));
    $view->storage->save();

    $this->assertFalse($this->termsElement('embed_terms')['#access']);
    $view = $this->executeWithSetting('embed_terms', ['terms' => [['target_id' => $this->termIds['A']]]]);
    $this->assertSame([], $view->args);
    $this->assertResultTitles(['A', 'B', 'Both', 'Neither'], $view);
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
    $this->assertSame('entity_autocomplete', $field['terms']['#type']);
    $this->assertSame('taxonomy_term', $field['terms']['#target_type']);
    $this->assertTrue($field['terms']['#tags']);
    $this->assertSame(['tags'], array_values($field['terms']['#selection_settings']['target_bundles']));
    $this->assertSame('radios', $field['match']['#type']);
    $this->assertSame(['any', 'all'], array_keys($field['match']['#options']));
    $this->assertSame('any', $field['match']['#default_value']);

    $depth = $this->termsElement('embed_depth');
    $this->assertArrayNotHasKey('match', $depth);

    Term::load($this->termIds['B'])->delete();
    $field = $this->termsElement('embed_terms', [
      'terms' => [['target_id' => $this->termIds['B']], ['target_id' => $this->termIds['A']]],
      'match' => 'all',
    ]);
    $defaults = array_values($field['terms']['#default_value']);
    $this->assertCount(1, $defaults);
    $this->assertSame($this->termIds['A'], (int) $defaults[0]->id());
    $this->assertSame('all', $field['match']['#default_value']);
  }

  /**
   * Executes an embed through the Views Reference pre-view hook.
   */
  protected function executeWithSetting(string $display, mixed $value, bool $request = FALSE): ViewExecutable {
    $view = Views::getView('viewsreference_sift_test');
    $this->assertTrue($view->setDisplay($display));
    $settings = [
      'enabled_settings' => ['sift_terms'],
      'data' => ['sift_terms' => $value],
    ];
    if ($request) {
      $settings = $this->container->get('viewsreference.compression')->compress($settings, $view);
      $view->setRequest(Request::create('/', 'GET', ['viewsreference' => $settings]));
    }
    else {
      $view->element['#viewsreference'] = $settings;
    }
    $view->preExecute();
    $this->assertTrue($view->execute());
    $view->postExecute();
    return $view;
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
