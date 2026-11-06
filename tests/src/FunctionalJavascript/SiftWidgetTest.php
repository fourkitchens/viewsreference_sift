<?php

declare(strict_types=1);

namespace Drupal\Tests\viewsreference_sift\FunctionalJavascript;

// cspell:ignore Behat
use Behat\Mink\Element\NodeElement;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\FunctionalJavascriptTests\DrupalSelenium2Driver;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Tests\field\Traits\EntityReferenceFieldCreationTrait;
use Drupal\views\Entity\View;
use Drupal\viewsreference_sift\Filter\SiftFilter;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use WebDriver\Key;

/**
 * Tests how editors configure Sift through the Views Reference widget.
 */
#[Group('viewsreference_sift')]
#[RunTestsInSeparateProcesses]
class SiftWidgetTest extends WebDriverTestBase {

  use EntityReferenceFieldCreationTrait;

  private const FIELD = 'field_listing[0]';
  private const SIFT = self::FIELD . '[options][sift_terms]';
  private const OPTIONS = 'details[data-drupal-selector="edit-field-listing-0-options"]';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['inline_form_errors', 'node', 'taxonomy', 'viewsreference_sift'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->drupalCreateContentType(['type' => 'article', 'name' => 'Article']);
    $this->drupalCreateContentType(['type' => 'program', 'name' => 'Program']);
    Vocabulary::create(['vid' => 'tags', 'name' => 'Tags'])->save();
    Vocabulary::create(['vid' => 'audience', 'name' => 'Audience'])->save();
    $this->createEntityReferenceField('node', 'article', 'field_program', 'Program', 'node', 'default', [
      'target_bundles' => ['program' => 'program'],
    ]);
    $this->createEntityReferenceField('node', 'article', 'field_tags', 'Tags', 'taxonomy_term', 'default', [
      'target_bundles' => ['tags' => 'tags'],
    ], FieldStorageConfig::CARDINALITY_UNLIMITED);
    $this->container->get('module_installer')->install(['viewsreference_sift_test']);

    FieldStorageConfig::create([
      'entity_type' => 'node',
      'field_name' => 'field_listing',
      'type' => 'viewsreference',
    ])->save();
    FieldConfig::create([
      'entity_type' => 'node',
      'field_name' => 'field_listing',
      'bundle' => 'article',
      'label' => 'Listing',
      'settings' => [
        'plugin_types' => ['embed' => 'embed'],
        'preselect_views' => ['viewsreference_sift_test' => 'viewsreference_sift_test'],
        'enabled_settings' => ['sift_terms' => 'sift_terms', 'limit' => 'limit'],
      ],
    ])->save();
    $displays = $this->container->get('entity_display.repository');
    $displays->getFormDisplay('node', 'article')
      ->setComponent('field_listing', ['type' => 'viewsreference_select'])
      ->save();
    $displays->getViewDisplay('node', 'article')
      ->setComponent('field_listing', ['type' => 'viewsreference_formatter', 'label' => 'hidden'])
      ->save();
  }

  /**
   * Tests switching displays, saving selections, and rendering the listing.
   */
  public function testEditorConfiguresListing(): void {
    $alpha = Term::create(['vid' => 'tags', 'name' => 'Alpha topic']);
    $alpha->save();
    $beta = Term::create(['vid' => 'tags', 'name' => 'Beta topic']);
    $beta->save();

    foreach ([
      'Story tagged with both topics' => [$alpha->id(), $beta->id()],
      'Story tagged with alpha only' => [$alpha->id()],
      'Story tagged with beta only' => [$beta->id()],
      'Story without topics' => [],
    ] as $title => $tags) {
      $this->drupalCreateNode(['type' => 'article', 'title' => $title, 'field_tags' => $tags]);
    }
    $article = $this->drupalCreateNode(['type' => 'article', 'title' => 'Editor listing']);
    $this->drupalLogin($this->drupalCreateUser(['access content', 'edit any article content']));
    $this->drupalGet($article->toUrl('edit-form'));

    $assert = $this->assertSession();
    $assert->fieldExists(self::FIELD . '[target_id]')->selectOption('viewsreference_sift_test');
    $assert->assertWaitOnAjaxRequest();
    $this->selectDisplay('embed_multi');
    $this->openOptions();
    $filters = ['tid_tags', 'tid_audience', 'field_program_target_id', 'uid'];
    foreach ($filters as $id) {
      $this->assertTrue($assert->fieldExists(self::SIFT . '[filters][' . $id . '][ids]')->isVisible());
    }
    $this->chooseTag('tid_tags', 'Alpha topic');

    $this->selectDisplay('embed_plain');
    foreach ($filters as $id) {
      $assert->fieldNotExists(self::SIFT . '[filters][' . $id . '][ids]');
    }
    $assert->fieldExists(self::FIELD . '[options][limit]');
    $this->submitForm([], 'Save');
    $assert->statusMessageContains('has been updated.', 'status');
    $saved = $this->savedListing($article);
    $this->assertSame('embed_plain', $saved['display_id']);
    $this->assertContains($saved['settings']['sift_terms'] ?? NULL, [NULL, '']);

    $this->drupalGet($article->toUrl('edit-form'));
    $this->selectDisplay('embed_terms');
    $this->openOptions();
    $this->chooseTag('tid', 'Alpha topic');
    $this->chooseTag('tid', 'Beta topic');
    $assert->fieldExists('All of these items')->click();
    $this->submitForm([], 'Save');
    $assert->statusMessageContains('has been updated.', 'status');
    $saved = $this->savedListing($article);
    $this->assertSame('embed_terms', $saved['display_id']);
    $this->assertSame([
      'version' => 2,
      'filters' => [
        'tid' => [
          'ids' => [(int) $alpha->id(), (int) $beta->id()],
          'match' => 'all',
        ],
      ],
    ], $saved['settings']['sift_terms']);

    $this->drupalGet($article->toUrl());
    $assert->pageTextContains('Story tagged with both topics');
    $assert->pageTextNotContains('Story tagged with alpha only');
    $assert->pageTextNotContains('Story tagged with beta only');
    $assert->pageTextNotContains('Story without topics');
  }

  /**
   * Tests fieldset legends, help text, and errors in the rendered widget.
   */
  public function testAccessibleWidgetMarkup(): void {
    $view = View::load('viewsreference_sift_test');
    $this->assertInstanceOf(View::class, $view);
    $displays = $view->get('display');
    $displays['embed_terms']['display_options']['display_extenders']['viewsreference_sift']['filters']['tid'] = [
      'label' => 'Topics',
      'description' => 'Choose the topics to show in this listing.',
      'required' => TRUE,
      'max' => 3,
    ] + SiftFilter::DEFAULT_OPTIONS;
    $view->set('display', $displays)->save();
    $article = $this->drupalCreateNode([
      'type' => 'article',
      'title' => 'Editor listing',
      'field_listing' => [
        'target_id' => 'viewsreference_sift_test',
        'display_id' => 'embed_terms',
      ],
    ]);
    $this->drupalLogin($this->drupalCreateUser(['access content', 'edit any article content']));
    $this->drupalGet($article->toUrl('edit-form'));

    $assert = $this->assertSession();
    $summary = $assert->elementExists('css', self::OPTIONS . ' > summary');
    $this->sendKeys($summary, Key::ENTER);
    $assert->elementAttributeExists('css', self::OPTIONS, 'open');

    $filter = 'fieldset[data-drupal-selector="edit-field-listing-0-options-sift-terms-filters-tid"]';
    $fieldset = $assert->elementExists('css', $filter);
    $this->assertSame('Topics', $assert->elementExists('css', $filter . ' > legend')->getText());
    $this->assertLinkedDescription($fieldset, 'Choose the topics to show in this listing.');
    $match = $assert->elementExists('css', $filter . ' fieldset');
    $this->assertSame('Match', $assert->elementExists('css', 'legend', $match)->getText());
    $this->assertCount(2, $match->findAll('css', 'input[type="radio"]'));
    $any = $assert->elementExists('css', 'input[value="any"]', $match);
    $all = $assert->elementExists('css', 'input[value="all"]', $match);
    $this->assertTrue($any->isChecked());
    $this->sendKeys($any, Key::DOWN_ARROW);
    $this->assertTrue($all->isChecked());

    $name = self::SIFT . '[filters][tid][ids]';
    $input = $assert->fieldExists($name);
    $this->assertLinkedDescription($input, 'A selection is required.');
    $this->assertLinkedDescription($input, 'Choose at most 3 items.');
    $this->submitForm([], 'Save');

    $input = $assert->fieldExists($name);
    $this->assertSame('true', $input->getAttribute('aria-invalid'));
    $message = $assert->elementExists('css', '.form-item--error-message', $input->getParent());
    $this->assertSame('Choose at least one item for Topics.', $message->getText());
    $this->assertTrue($message->isVisible());
    $link = $assert->elementExists('css', 'a[href="#' . $input->getAttribute('id') . '"]');
    $this->assertSame('Select items for Topics', $link->getText());
  }

  /**
   * Changes the display and waits for Views Reference to rebuild the field.
   */
  private function selectDisplay(string $display): void {
    $assert = $this->assertSession();
    $assert->fieldExists(self::FIELD . '[display_id]')->selectOption($display);
    $assert->assertWaitOnAjaxRequest();
  }

  /**
   * Opens the Views Reference Options disclosure after a field rebuild.
   */
  private function openOptions(): void {
    $assert = $this->assertSession();
    if (!$assert->elementExists('css', self::OPTIONS)->hasAttribute('open')) {
      $assert->elementExists('css', self::OPTIONS . ' > summary')->click();
    }
  }

  /**
   * Checks that visible help is referenced by the element's ARIA attribute.
   */
  private function assertLinkedDescription(NodeElement $element, string $text): void {
    $ids = preg_split('/\s+/', (string) $element->getAttribute('aria-describedby'), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $this->assertNotEmpty($ids);
    $descriptions = [];
    foreach ($ids as $id) {
      $description = $this->assertSession()->elementExists('css', '[id="' . $id . '"]');
      $this->assertTrue($description->isVisible());
      $descriptions[] = $description->getText();
    }
    $this->assertStringContainsString($text, implode(' ', $descriptions));
  }

  /**
   * Selects a tag from the autocomplete results.
   */
  private function chooseTag(string $filter, string $tag): void {
    $assert = $this->assertSession();
    $input = $assert->fieldExists(self::SIFT . '[filters][' . $filter . '][ids]');
    $existing = (string) $input->getValue();
    $this->sendKeys($input, Key::END . ($existing === '' ? '' : ', ') . $tag);
    $result = $assert->waitOnAutocomplete();
    $this->assertNotNull($result);
    $this->assertSame($tag, $result->getText());
    $this->sendKeys($input, Key::DOWN_ARROW . Key::ENTER);
    $assert->assertWaitOnAjaxRequest();
  }

  /**
   * Sends native keyboard events to a browser element.
   */
  private function sendKeys(NodeElement $element, string $keys): void {
    $driver = $this->getSession()->getDriver();
    $this->assertInstanceOf(DrupalSelenium2Driver::class, $driver);
    $driver->getWebDriverSession()->element('xpath', $element->getXpath())->postValue(['text' => $keys]);
  }

  /**
   * Reloads the display ID and stored settings for the listing field.
   *
   * @phpstan-return array{display_id: mixed, settings: array<string, mixed>}
   */
  private function savedListing(NodeInterface $article): array {
    $node = $this->container->get('entity_type.manager')->getStorage('node')->loadUnchanged((string) $article->id());
    $this->assertInstanceOf(NodeInterface::class, $node);
    $item = $node->get('field_listing')->first();
    $this->assertNotNull($item);
    $values = $item->getValue();
    $this->assertIsString($values['data']);
    $settings = unserialize($values['data'], ['allowed_classes' => FALSE]);
    $this->assertIsArray($settings);
    return ['display_id' => $values['display_id'], 'settings' => $settings];
  }

}
