<?php

declare(strict_types=1);

namespace Drupal\Tests\viewsreference_sift\Functional;

use Drupal\taxonomy\Entity\Term;
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
    Vocabulary::create(['vid' => '123', 'name' => 'Tags'])->save();
    Vocabulary::create(['vid' => 'audience', 'name' => 'Audience'])->save();
    $first = Term::create(['vid' => '123', 'name' => 'First topic']);
    $first->save();
    $second = Term::create(['vid' => '123', 'name' => 'Second topic']);
    $second->save();
    $outside = Term::create(['vid' => 'audience', 'name' => 'Audience topic']);
    $outside->save();
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
                'validate_options' => ['bundles' => ['123' => '123', 'audience' => 'audience'], 'multiple' => 1],
              ],
            ],
          ],
        ],
      ],
    ])->save();

    $this->drupalLogin($this->drupalCreateUser(['administer views', 'access content']));
    $edit_path = 'admin/structure/views/view/sift_settings_test/edit/embed_terms';
    $this->drupalGet($edit_path);
    $this->assertSession()->linkExists('1 of 1 filter');
    // cspell:ignore nojs
    $this->drupalGet('admin/structure/views/nojs/display/sift_settings_test/embed_terms/viewsreference_sift');
    $this->assertSession()->fieldExists('Default selection');
    $this->assertSession()->checkboxNotChecked('Allow exclusion');
    $bundles = [
      'filters[tid][bundles][123]' => TRUE,
      'filters[tid][bundles][audience]' => FALSE,
    ];
    $defaults = sprintf('First topic (%s), Second topic (%s)', $first->id(), $second->id());
    $this->submitForm($bundles + [
      'filters[tid][default_ids]' => sprintf('Audience topic (%s)', $outside->id()),
    ], 'Apply');
    $this->assertSession()->statusMessageContains('Choose defaults from the allowed bundles.', 'error');
    $this->submitForm($bundles + [
      'filters[tid][default_ids]' => $defaults,
      'filters[tid][max]' => 1,
    ], 'Apply');
    $this->assertSession()->statusMessageContains('Choose at most 1 default item.', 'error');

    $this->submitForm([
      'filters[tid][enabled]' => FALSE,
      'filters[tid][label]' => 'Topics',
      'filters[tid][description]' => 'Choose topics to show.',
      'filters[tid][bundles][123]' => TRUE,
      'filters[tid][bundles][audience]' => FALSE,
      'filters[tid][match]' => 'all',
      'filters[tid][widget]' => 'checkboxes',
      'filters[tid][max]' => 2,
      'filters[tid][required]' => TRUE,
      'filters[tid][default_ids]' => $defaults,
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
      'bundles' => ['123'],
      'match' => 'all',
      'widget' => 'checkboxes',
      'max' => 2,
      'required' => TRUE,
      'default_ids' => [(string) $first->id(), (string) $second->id()],
    ];
    ksort($expected);
    ksort($saved);
    $this->assertSame($expected, $saved);

    $this->drupalGet($edit_path);
    $this->assertSession()->linkExists('0 of 1 filter');
  }

}
