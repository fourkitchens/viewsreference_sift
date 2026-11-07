<?php

declare(strict_types=1);

namespace Drupal\Tests\viewsreference_sift\Kernel;

use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\node\NodeStorageInterface;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Tests\field\Traits\EntityReferenceFieldCreationTrait;
use Drupal\views\Entity\View;
use Drupal\viewsreference_sift\Filter\SiftFilter;
use Drupal\viewsreference_sift\Migration\ArgumentConverter;
use Drupal\viewsreference_sift\Migration\ArgumentMigration;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests conversion of saved Views Reference arguments to Sift selections.
 */
#[Group('viewsreference_sift')]
#[RunTestsInSeparateProcesses]
class ArgumentMigrationTest extends KernelTestBase {

  use EntityReferenceFieldCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'filter', 'text', 'node', 'taxonomy', 'views',
    'language', 'content_translation', 'viewsreference', 'viewsreference_sift',
    'viewsreference_sift_test',
  ];

  /**
   * Fixture term IDs keyed by name.
   *
   * @var array<string, int>
   */
  private array $terms = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'field', 'filter', 'node', 'taxonomy', 'views', 'language']);
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    NodeType::create(['type' => 'program', 'name' => 'Program'])->save();
    foreach (['tags' => 'Tags', 'other' => 'Other', 'audience' => 'Audience'] as $id => $name) {
      Vocabulary::create(['vid' => $id, 'name' => $name])->save();
    }
    foreach (['A' => 'tags', 'B' => 'tags', 'C' => 'other', 'Staff' => 'audience'] as $name => $vocabulary) {
      $term = Term::create(['vid' => $vocabulary, 'name' => $name]);
      $term->save();
      $this->terms[$name] = (int) $term->id();
    }
    $this->createEntityReferenceField('node', 'article', 'field_program', 'Program', 'node');
    $this->installConfig(['viewsreference_sift_test']);
    $this->addListingField('field_listing');
  }

  /**
   * Tests plain IDs and both Views list operators with stable stored types.
   */
  public function testConvertsPlainArguments(): void {
    $ids = [$this->terms['A'], $this->terms['B']];
    foreach (['+' => 'any', ',' => 'all'] as $separator => $match) {
      $settings = ['argument' => implode($separator, $ids), 'limit' => 3, 'title' => TRUE];
      $item = $this->item($this->createListing($settings));
      $converted = $this->convert($item);
      $this->assertNull($converted['argument']);
      $this->assertSame(3, $converted['limit']);
      $this->assertTrue($converted['title']);
      $this->assertSame($this->selection('tid', $ids, $match), $converted['sift_terms']);
      $this->assertSame($settings, $this->settings($item));
    }

    $first = $ids[0];
    $item = $this->item($this->createListing(['argument' => "$first+$first"]));
    $this->assertSame($this->selection('tid', [$first]), $this->convert($item)['sift_terms']);

    $item = $this->item($this->createListing(['argument' => 'article+program'], 'embed_content_types'));
    $converted = $this->convert($item);
    $this->assertSame($this->selection('type', ['article', 'program']), $converted['sift_terms']);
  }

  /**
   * Tests placeholders, trailing cleanup, and preservation of other slots.
   */
  public function testKeepsUnconvertedPositions(): void {
    $ids = [$this->terms['A'], $this->terms['B']];
    $item = $this->item($this->createListing(['argument' => '[node:nid]/' . implode('+', $ids)], 'embed_positions'));
    $converted = $this->convert($item);
    $this->assertSame('[node:nid]', $converted['argument']);
    $this->assertSame($this->selection('tid', $ids), $converted['sift_terms']);

    $view = View::load('viewsreference_sift_test');
    $this->assertInstanceOf(View::class, $view);
    $display = &$view->getDisplay('embed_multi');
    $display['display_options']['arguments']['tid_tags']['exception']['value'] = 'any-tags';
    $view->save();
    $existing = $this->selection('tid_audience', [(string) $this->terms['Staff']]);
    $item = $this->item($this->createListing([
      'argument' => $this->terms['A'] . '/[node:nid]',
      'sift_terms' => $existing,
    ], 'embed_multi'));
    $converted = $this->convert($item);
    $this->assertSame('any-tags/[node:nid]', $converted['argument']);
    $this->assertSame(2, $converted['sift_terms']['version']);
    $this->assertSame($existing['filters']['tid_audience'], $converted['sift_terms']['filters']['tid_audience']);
    $this->assertSame(['ids' => [$this->terms['A']], 'match' => 'any'], $converted['sift_terms']['filters']['tid_tags']);

    $item = $this->item($this->createListing(['argument' => $this->terms['A'] . '/all'], 'embed_multi'));
    $this->assertNull($this->convert($item)['argument']);
    $display['display_options']['arguments']['tid_audience']['title_enable'] = TRUE;
    $display['display_options']['arguments']['tid_audience']['title'] = 'All audiences';
    $view->save();
    $this->assertSame('any-tags/all', $this->convert($item)['argument']);
    $display['display_options']['arguments']['tid_audience']['title_enable'] = FALSE;
    $display['display_options']['arguments']['field_program_target_id']['default_action'] = 'not found';
    $view->save();
    $this->assertSame('any-tags/all', $this->convert($item)['argument']);
  }

  /**
   * Tests that ineligible or lossy conversions leave their data alone.
   */
  public function testKeepsIneligibleArguments(): void {
    $first = $this->terms['A'];
    $second = $this->terms['B'];
    $outside = $this->terms['C'];
    foreach ([
      'all' => 'exception value',
      '[node:nid]' => 'contains a token',
      "$first+$second,$outside" => 'syntax is unsupported',
      "$first+bad" => 'syntax is unsupported',
      "$first+99999" => 'IDs are missing',
      "$first+$outside" => 'outside the allowed bundles',
    ] as $argument => $reason) {
      $item = $this->item($this->createListing(['argument' => $argument]));
      $this->assertSkipped($item, $reason);
    }
    foreach ([
      'embed_plain' => [(string) $first, 'unsupported by Sift'],
      'embed_content_types' => ['article,program', 'change how it matches'],
      'embed_positions' => ["/$first", 'Position 1 is empty'],
    ] as $display => [$argument, $reason]) {
      $item = $this->item($this->createListing(['argument' => $argument], $display));
      $this->assertSkipped($item, $reason);
    }
    foreach ([
      'selection already exists' => $this->selection('tid', [$second]),
      'legacy or unrecognized' => ['terms' => [['target_id' => $second]]],
    ] as $reason => $existing) {
      $item = $this->item($this->createListing(['argument' => (string) $first, 'sift_terms' => $existing]));
      $this->assertSkipped($item, $reason);
    }

    $this->config('views.settings')->set('display_extenders', ['viewsreference_sift'])->save();
    $view = View::load('viewsreference_sift_test');
    $this->assertInstanceOf(View::class, $view);
    $display = &$view->getDisplay('embed_terms');
    $item = $this->item($this->createListing(['argument' => "$first+$second"]));
    foreach ([
      'over the selection limit' => ['max' => 1],
      'Sift filter is disabled' => ['enabled' => FALSE],
      'change how it matches' => ['match' => 'all'],
    ] as $reason => $options) {
      $display['display_options']['display_extenders']['viewsreference_sift']['filters']['tid'] = $options + SiftFilter::DEFAULT_OPTIONS;
      $view->save();
      $this->assertSkipped($item, $reason);
    }
  }

  /**
   * Tests dry run, one new default revision, and translating saved values.
   */
  public function testDryRunAndApply(): void {
    ConfigurableLanguage::createFromLangcode('fr')->save();
    $this->container->get('content_translation.manager')->setEnabled('node', 'article', TRUE);
    $english = ['argument' => (string) $this->terms['A'], 'limit' => 3];
    $french = ['argument' => (string) $this->terms['B'], 'limit' => 4];
    $untouched = ['argument' => '[node:nid]', 'limit' => 5];
    $node = $this->createListing($english);
    $node->get('field_listing')->appendItem($this->reference($untouched));
    $node->addTranslation('fr', [
      'title' => 'French listing',
      'field_listing' => [$this->reference($french)],
    ]);
    $node->setRevisionLogMessage('Before migration')->save();
    $revision = $node->getRevisionId();
    $revision_count = $this->countRevisions($node);
    $migration = $this->container->get(ArgumentMigration::class);

    $report = implode("\n", iterator_to_array($migration->run(FALSE, 'node', 'article', 'field_listing')));
    $this->assertStringContainsString('Dry run:', $report);
    $this->assertStringContainsString('field_listing', $report);
    $unchanged = $this->reload($node);
    $this->assertSame($revision, $unchanged->getRevisionId());
    $this->assertSame($revision_count, $this->countRevisions($node));
    $this->assertSame($english, $this->settings($this->item($unchanged)));
    $this->assertSame($french, $this->settings($this->item($unchanged->getTranslation('fr'))));

    $report = implode("\n", iterator_to_array($migration->run(TRUE, 'node', 'article', 'field_listing')));
    $this->assertStringContainsString('Applied:', $report);
    $saved = $this->reload($node);
    $this->assertNotSame($revision, $saved->getRevisionId());
    $this->assertTrue($saved->isDefaultRevision());
    $this->assertSame($revision_count + 1, $this->countRevisions($node));
    $this->assertSame('Converted Views Reference arguments to Sift selections.', $saved->getRevisionLogMessage());
    foreach (['en' => ['A', 3], 'fr' => ['B', 4]] as $langcode => [$term, $limit]) {
      $settings = $this->settings($this->item($saved->getTranslation($langcode)));
      $this->assertNull($settings['argument']);
      $this->assertSame($limit, $settings['limit']);
      $this->assertSame($this->selection('tid', [$this->terms[$term]]), $settings['sift_terms']);
    }
    $this->assertSame($untouched, $this->settings($this->item($saved, delta: 1)));
    $original = $this->nodeStorage()->loadRevision($revision);
    $this->assertInstanceOf(NodeInterface::class, $original);
    $this->assertSame('Before migration', $original->getRevisionLogMessage());
    $this->assertSame($english, $this->settings($this->item($original)));
    $this->assertSame($french, $this->settings($this->item($original->getTranslation('fr'))));

    iterator_to_array($migration->run(TRUE, 'node', 'article', 'field_listing'));
    $this->assertSame($saved->getRevisionId(), $this->reload($node)->getRevisionId());
    $this->assertSame($revision_count + 1, $this->countRevisions($node));
  }

  /**
   * Tests that a newer pending revision prevents migration of the entity.
   */
  public function testSkipsNewerPendingRevision(): void {
    $settings = ['argument' => (string) $this->terms['A']];
    $node = $this->createListing($settings);
    $default = $node->getRevisionId();
    $draft_settings = ['argument' => (string) $this->terms['B']];
    $node->setNewRevision();
    $node->isDefaultRevision(FALSE);
    $node->set('field_listing', [$this->reference($draft_settings)])->save();
    $draft = $node->getRevisionId();
    $migration = $this->container->get(ArgumentMigration::class);

    $report = implode("\n", iterator_to_array($migration->run(TRUE, 'node', 'article', 'field_listing')));
    $this->assertStringContainsString('newer pending revision', $report);
    $unchanged = $this->reload($node);
    $this->assertSame($default, $unchanged->getRevisionId());
    $this->assertSame($settings, $this->settings($this->item($unchanged)));
    $this->assertSame((string) $draft, (string) $this->nodeStorage()->getLatestRevisionId($node->id()));
    $pending = $this->nodeStorage()->loadRevision($draft);
    $this->assertInstanceOf(NodeInterface::class, $pending);
    $this->assertFalse($pending->isDefaultRevision());
    $this->assertSame($draft_settings, $this->settings($this->item($pending)));
  }

  /**
   * Tests scope flags and fields whose Sift setting is disabled.
   */
  public function testScopeAndMissingSiftSetting(): void {
    $this->addListingField('field_listing', 'program');
    $this->addListingField('field_other_listing');
    $this->addListingField('field_argument_only', enabled: ['argument']);
    $settings = ['argument' => (string) $this->terms['A']];
    $article = $this->createListing($settings);
    $article->set('field_other_listing', [$this->reference($settings)]);
    $article->set('field_argument_only', [$this->reference($settings)])->save();
    $program = $this->createListing($settings, bundle: 'program');
    $migration = $this->container->get(ArgumentMigration::class);

    iterator_to_array($migration->run(TRUE, 'taxonomy_term'));
    $this->assertSame($article->getRevisionId(), $this->reload($article)->getRevisionId());
    $report = implode("\n", iterator_to_array($migration->run(TRUE, 'node', 'article', 'field_argument_only')));
    $this->assertStringContainsString('enable Sift filters', $report);
    $this->assertSame($article->getRevisionId(), $this->reload($article)->getRevisionId());

    iterator_to_array($migration->run(TRUE, 'node', 'article', 'field_listing'));
    $saved = $this->reload($article);
    $this->assertSame($this->selection('tid', [$this->terms['A']]), $this->settings($this->item($saved))['sift_terms']);
    $this->assertSame($settings, $this->settings($this->item($saved, 'field_other_listing')));
    $this->assertSame($settings, $this->settings($this->item($saved, 'field_argument_only')));
    $unchanged_program = $this->reload($program);
    $this->assertSame($program->getRevisionId(), $unchanged_program->getRevisionId());
    $this->assertSame($settings, $this->settings($this->item($unchanged_program)));
  }

  /**
   * Adds a translatable Views Reference field to a content type.
   *
   * @phpstan-param list<string> $enabled
   */
  private function addListingField(
    string $field,
    string $bundle = 'article',
    array $enabled = ['argument', 'sift_terms', 'limit'],
  ): void {
    if (!FieldStorageConfig::loadByName('node', $field)) {
      FieldStorageConfig::create([
        'field_name' => $field,
        'entity_type' => 'node',
        'type' => 'viewsreference',
        'cardinality' => FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED,
      ])->save();
    }
    FieldConfig::create([
      'field_name' => $field,
      'entity_type' => 'node',
      'bundle' => $bundle,
      'label' => 'Listing',
      'translatable' => TRUE,
      'settings' => [
        'plugin_types' => ['embed' => 'embed'],
        'enabled_settings' => array_combine($enabled, $enabled),
      ],
    ])->save();
  }

  /**
   * Creates a saved listing with the given field settings.
   *
   * @phpstan-param array<string, mixed> $settings
   */
  private function createListing(array $settings, string $display = 'embed_terms', string $bundle = 'article'): NodeInterface {
    $node = Node::create([
      'type' => $bundle,
      'title' => 'Listing',
      'langcode' => 'en',
      'status' => TRUE,
      'field_listing' => [$this->reference($settings, $display)],
    ]);
    $node->save();
    return $node;
  }

  /**
   * Builds a Views Reference field item value.
   *
   * @phpstan-param array<string, mixed> $settings
   * @phpstan-return array{target_id: string, display_id: string, data: string}
   */
  private function reference(array $settings, string $display = 'embed_terms'): array {
    return [
      'target_id' => 'viewsreference_sift_test',
      'display_id' => $display,
      'data' => serialize($settings),
    ];
  }

  /**
   * Gets a populated field item from a listing.
   */
  private function item(NodeInterface $node, string $field = 'field_listing', int $delta = 0): FieldItemInterface {
    $item = $node->get($field)->get($delta);
    $this->assertInstanceOf(FieldItemInterface::class, $item);
    return $item;
  }

  /**
   * Decodes the settings stored on a field item.
   *
   * @phpstan-return array<string, mixed>
   */
  private function settings(FieldItemInterface $item): array {
    $data = $item->get('data')->getValue();
    $this->assertIsString($data);
    $settings = unserialize($data, ['allowed_classes' => FALSE]);
    $this->assertIsArray($settings);
    return $settings;
  }

  /**
   * Converts a field item and decodes its proposed settings.
   *
   * @phpstan-return array<string, mixed>
   */
  private function convert(FieldItemInterface $item): array {
    $result = $this->container->get(ArgumentConverter::class)->convert($item);
    $this->assertIsString($result['data'], implode("\n", $result['messages']));
    $settings = unserialize($result['data'], ['allowed_classes' => FALSE]);
    $this->assertIsArray($settings);
    return $settings;
  }

  /**
   * Asserts an ineligible argument has no proposed changes and a reason.
   */
  private function assertSkipped(FieldItemInterface $item, string $reason): void {
    $result = $this->container->get(ArgumentConverter::class)->convert($item);
    $this->assertNull($result['data']);
    $this->assertStringContainsString($reason, implode(' ', $result['messages']));
  }

  /**
   * Builds the expected version 2 selection for a filter.
   *
   * @phpstan-param list<int|string> $ids
   * @phpstan-return array{version: 2, filters: array<string, array{ids: list<int|string>, match: string}>}
   */
  private function selection(string $id, array $ids, string $match = 'any'): array {
    return ['version' => 2, 'filters' => [$id => ['ids' => $ids, 'match' => $match]]];
  }

  /**
   * Loads the latest default revision directly from storage.
   */
  private function reload(NodeInterface $node): NodeInterface {
    $saved = $this->nodeStorage()->loadUnchanged($node->id());
    $this->assertInstanceOf(NodeInterface::class, $saved);
    return $saved;
  }

  /**
   * Counts all revisions belonging to the node.
   */
  private function countRevisions(NodeInterface $node): int {
    $ids = $this->nodeStorage()->getQuery()->allRevisions()->accessCheck(FALSE)->condition('nid', $node->id())->execute();
    return count($ids);
  }

  /**
   * Returns the node storage handler.
   */
  private function nodeStorage(): NodeStorageInterface {
    return $this->container->get('entity_type.manager')->getStorage('node');
  }

}
