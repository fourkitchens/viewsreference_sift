<?php

declare(strict_types=1);

namespace Drupal\Tests\viewsreference_sift\Functional;

use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Tests\BrowserTestBase;
use Drupal\views\Entity\View;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests editing Sift display options through the Views UI.
 */
#[Group('viewsreference_sift')]
#[RunTestsInSeparateProcesses]
class SiftDisplaySettingsTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['node', 'taxonomy', 'views_ui', 'viewsreference_sift'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Tests applying and saving filter options without JavaScript.
   */
  public function testEditDisplaySettings(): void {
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
    $edit_path = 'admin/structure/views/view/sift_settings_test/edit/embed_terms';
    $this->drupalGet($edit_path);
    $this->assertSession()->linkExists('1 of 1 filter');
    // cspell:ignore nojs
    $this->drupalGet('admin/structure/views/nojs/display/sift_settings_test/embed_terms/viewsreference_sift');
    $this->submitForm([
      'filters[tid][enabled]' => FALSE,
      'filters[tid][label]' => 'Topics',
      'filters[tid][description]' => 'Choose topics to show.',
      'filters[tid][bundles][tags]' => TRUE,
      'filters[tid][bundles][audience]' => FALSE,
      'filters[tid][match]' => 'all',
      'filters[tid][widget]' => 'checkboxes',
      'filters[tid][max]' => 2,
      'filters[tid][required]' => TRUE,
    ], 'Apply');
    $this->submitForm([], 'Save');
    $this->assertSession()->statusMessageContains('The view Sift settings test has been saved.', 'status');

    $name = 'views.view.sift_settings_test';
    $this->container->get('config.factory')->reset($name);
    $saved = $this->config($name)->get('display.embed_terms.display_options.display_extenders.viewsreference_sift.filters.tid');
    $this->assertIsArray($saved);
    $expected = [
      'enabled' => FALSE,
      'label' => 'Topics',
      'description' => 'Choose topics to show.',
      'bundles' => ['tags'],
      'match' => 'all',
      'widget' => 'checkboxes',
      'max' => 2,
      'required' => TRUE,
    ];
    ksort($expected);
    ksort($saved);
    $this->assertSame($expected, $saved);

    $this->drupalGet($edit_path);
    $this->assertSession()->linkExists('0 of 1 filter');
  }

}
