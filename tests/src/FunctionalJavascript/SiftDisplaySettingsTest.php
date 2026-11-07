<?php

declare(strict_types=1);

namespace Drupal\Tests\viewsreference_sift\FunctionalJavascript;

use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\views\Entity\View;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests saving Sift display options from the Views AJAX dialog.
 */
#[Group('viewsreference_sift')]
#[RunTestsInSeparateProcesses]
class SiftDisplaySettingsTest extends WebDriverTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['node', 'taxonomy', 'views_ui', 'viewsreference_sift'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Tests applying the modal form and persisting the settings on View save.
   */
  public function testSaveDisplaySettings(): void {
    Vocabulary::create(['vid' => 'tags', 'name' => 'Tags'])->save();
    Vocabulary::create(['vid' => 'audience', 'name' => 'Audience'])->save();
    View::create([
      'id' => 'sift_settings_test',
      'label' => 'Sift settings test',
      'base_table' => 'node_field_data',
      'base_field' => 'nid',
      'display' => [
        'default' => [
          'id' => 'default',
          'display_title' => 'Default',
          'display_plugin' => 'default',
          'position' => 0,
          'display_options' => [
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
          ],
        ],
        'embed_terms' => [
          'id' => 'embed_terms',
          'display_title' => 'Terms',
          'display_plugin' => 'embed',
          'position' => 1,
          'display_options' => [
            'defaults' => ['arguments' => FALSE],
            'arguments' => [
              'tid' => [
                'id' => 'tid',
                'table' => 'taxonomy_index',
                'field' => 'tid',
                'plugin_id' => 'taxonomy_index_tid',
                'break_phrase' => TRUE,
                'specify_validation' => TRUE,
                'validate' => ['type' => 'entity:taxonomy_term'],
                'validate_options' => ['bundles' => ['tags' => 'tags', 'audience' => 'audience'], 'multiple' => 1],
              ],
            ],
          ],
        ],
      ],
    ])->save();

    $this->drupalLogin($this->drupalCreateUser(['administer views']));
    $this->drupalGet('admin/structure/views/view/sift_settings_test/edit/embed_terms');
    $session = $this->getSession();
    $page = $session->getPage();
    $assert = $this->assertSession();
    $edit_url = $session->getCurrentUrl();

    $page->clickLink('1 of 1 filter');
    $assert->assertWaitOnAjaxRequest();
    $dialog = $assert->waitForElementVisible('css', '.views-ui-dialog');
    $this->assertNotNull($dialog);
    $this->assertSame($edit_url, $session->getCurrentUrl());
    $dialog->uncheckField('filters[tid][enabled]');
    $dialog->fillField('filters[tid][label]', 'Topics');
    $dialog->fillField('filters[tid][description]', 'Choose topics to show.');
    $dialog->checkField('filters[tid][bundles][tags]');
    $dialog->selectFieldOption('filters[tid][match]', 'all');
    $dialog->checkField('filters[tid][allow_exclude]');
    $dialog->selectFieldOption('filters[tid][widget]', 'checkboxes');
    $dialog->fillField('filters[tid][max]', '2');
    $dialog->checkField('filters[tid][required]');

    $assert->elementExists('css', '.ui-dialog button:contains("Apply")')->press();
    $assert->assertWaitOnAjaxRequest();
    $this->assertTrue($assert->waitForElementRemoved('css', '.views-ui-dialog'));
    $assert->linkExists('0 of 1 filter');
    $page->pressButton('Save');
    $assert->statusMessageContains('The view Sift settings test has been saved.', 'status');

    $name = 'views.view.sift_settings_test';
    $this->container->get('config.factory')->reset($name);
    $saved = $this->config($name)->get('display.embed_terms.display_options.display_extenders.viewsreference_sift.filters.tid');
    $this->assertIsArray($saved);
    $expected = [
      'allow_exclude' => TRUE,
      'enabled' => FALSE,
      'label' => 'Topics',
      'description' => 'Choose topics to show.',
      'bundles' => ['tags'],
      'match' => 'all',
      'widget' => 'checkboxes',
      'max' => 2,
      'required' => TRUE,
      'default_ids' => [],
    ];
    ksort($expected);
    ksort($saved);
    $this->assertSame($expected, $saved);
  }

}
