<?php

declare(strict_types=1);

namespace Drupal\Tests\viewsreference_sift\Kernel;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetInterface;
use Drupal\Core\Form\FormState;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\views\Entity\View;
use Drupal\views\ViewExecutable;
use Drupal\views\Views;
use Drupal\viewsreference_sift_test\Plugin\ViewsReferenceSetting\ContractSetting;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Guards the upstream Views Reference behavior Sift relies on.
 */
#[Group('viewsreference_sift')]
#[RunTestsInSeparateProcesses]
class ViewsReferenceContractTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'filter', 'text', 'node', 'views',
    'viewsreference', 'viewsreference_sift', 'viewsreference_sift_test',
  ];

  /**
   * Settings with a nested Sift value to detect accidental serialization.
   */
  private const SETTINGS = [
    'sift_terms' => ['version' => 2, 'filters' => ['nid' => ['ids' => [7, 9], 'match' => 'any']]],
    'sift_contract' => ['nested' => ['value' => 'saved setting']],
  ];

  /**
   * Enabled settings in the field configuration's checkbox format.
   */
  private const ENABLED_SETTINGS = [
    'sift_terms' => 'sift_terms',
    'sift_contract' => 'sift_contract',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    ContractSetting::$viewCalls = [];
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'field', 'filter', 'node', 'views']);
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
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
      'settings' => [
        'plugin_types' => ['embed' => 'embed'],
        'enabled_settings' => self::ENABLED_SETTINGS,
      ],
    ])->save();
    View::create([
      'id' => 'sift_contract',
      'label' => 'Contract view',
      'base_table' => 'node_field_data',
      'base_field' => 'nid',
      'display' => [
        'default' => [
          'id' => 'default',
          'display_title' => 'Default',
          'display_plugin' => 'default',
          'position' => 0,
          'display_options' => [
            'access' => ['type' => 'none'],
            'cache' => ['type' => 'none'],
            'pager' => ['type' => 'none'],
            'style' => ['type' => 'default'],
            'row' => ['type' => 'fields'],
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
        'embed' => [
          'id' => 'embed',
          'display_title' => 'Embedded contract',
          'display_plugin' => 'embed',
          'position' => 1,
        ],
      ],
    ])->save();
  }

  /**
   * Tests that both formatters expose decoded field settings to the view.
   */
  #[DataProvider('formatterProvider')]
  public function testFormatterMetadata(string $formatter): void {
    $items = $this->listingItems();
    $build = $items->view(['type' => $formatter, 'label' => 'hidden']);
    $this->container->get('renderer')->renderRoot($build);

    $calls = ContractSetting::$viewCalls;
    $this->assertNotEmpty($calls, "$formatter must pass enabled settings to alterView().");
    foreach ($calls as $call) {
      $this->assertIsArray($call['metadata'], "$formatter must populate #viewsreference with field metadata.");
      $this->assertSame(self::SETTINGS['sift_terms'], $call['metadata']['data']['sift_terms'] ?? NULL, "$formatter must expose the decoded sift_terms selection in #viewsreference data.");
      $enabled_settings = $call['metadata']['enabled_settings'] ?? NULL;
      $this->assertIsArray($enabled_settings, "$formatter must expose enabled_settings as an array in #viewsreference.");
      $this->assertEqualsCanonicalizing(array_values(self::ENABLED_SETTINGS), array_values($enabled_settings), "$formatter must expose the field's enabled_settings in #viewsreference.");
      $this->assertSame(self::SETTINGS['sift_contract'], $call['value'], "$formatter must supply each setting's decoded value to alterView().");
    }
  }

  /**
   * Tests widget plugin configuration and serialized, flattened storage.
   */
  #[DataProvider('widgetProvider')]
  public function testWidgetSettings(string $widget_id): void {
    $items = $this->listingItems();
    $widget = $this->container->get('plugin.manager.field.widget')->getInstance([
      'field_definition' => $items->getFieldDefinition(),
      'form_mode' => 'default',
      'configuration' => ['type' => $widget_id],
    ]);
    $this->assertInstanceOf(WidgetInterface::class, $widget, "$widget_id must remain available for Views Reference settings.");
    $form = [];
    $form_state = new FormState();
    $element = $widget->formElement($items, 0, ['#field_parents' => [], '#required' => FALSE], $form, $form_state);
    $configuration = $element['options']['sift_contract']['#contract_configuration'] ?? [];
    $this->assertIsArray($configuration, "$widget_id must call alterFormField() with setting plugin configuration.");
    $this->assertSame('sift_contract', $configuration['view_name'] ?? NULL, "$widget_id must call alterFormField() with the selected view_name configuration.");
    $this->assertSame('embed', $configuration['display_id'] ?? NULL, "$widget_id must call alterFormField() with the selected display_id configuration.");
    $this->assertSame(self::SETTINGS['sift_contract'], $element['options']['sift_contract']['#default_value'] ?? NULL, "$widget_id must restore serialized setting values as widget defaults.");

    $values = $widget->massageFormValues([
      [
        'target_id' => 'sift_contract',
        'display_id' => 'embed',
        'options' => self::SETTINGS,
      ],
    ], $form, $form_state);
    $this->assertIsString($values[0]['data'] ?? NULL, "$widget_id must store setting values as a serialized string in data.");
    $stored = unserialize($values[0]['data'], ['allowed_classes' => FALSE]);
    $this->assertIsArray($stored, "$widget_id must store a serialized settings array in data.");
    foreach (self::SETTINGS as $id => $value) {
      $this->assertSame($value, $stored[$id] ?? NULL, "$widget_id must preserve the flattened $id value inside serialized data.");
      $this->assertArrayNotHasKey($id, $values[0], "$widget_id must move $id from the item root into serialized data.");
    }
    $items->setValue($values);
    $items->getEntity()->save();
    $this->container->get('entity_type.manager')->getStorage('node')->resetCache([$items->getEntity()->id()]);
    $saved = Node::load($items->getEntity()->id());
    $this->assertNotNull($saved, "$widget_id must produce field values that can be saved.");
    $this->assertSame($values[0]['data'], $saved->get('field_listing')->first()?->get('data')->getValue(), "$widget_id serialized data must survive a field item save.");
  }

  /**
   * Tests query decoding, metadata replacement, and enabled plugin dispatch.
   */
  #[DataProvider('requestProvider')]
  public function testRequestSettings(bool $compressed, bool $enabled): void {
    $view = Views::getView('sift_contract');
    $this->assertInstanceOf(ViewExecutable::class, $view, 'The Views Reference request contract requires the configured view.');
    $view->setDisplay('embed');
    $view->element['#viewsreference'] = [
      'data' => self::SETTINGS,
      'enabled_settings' => self::ENABLED_SETTINGS,
    ];
    $settings = [
      'data' => array_replace(self::SETTINGS, ['sift_contract' => 'query setting']),
      'enabled_settings' => $enabled ? ['sift_contract'] : [],
    ];
    $query = $settings;
    if ($compressed) {
      $query = $this->container->get('viewsreference.compression')->compress($settings, $view);
      $this->assertArrayHasKey('compressed', $query, 'Views Reference must support the compressed viewsreference query payload.');
    }
    $request = Request::create('/listing', 'GET', ['viewsreference' => $query]);
    $view->setRequest($request);
    $request_stack = $this->container->get('request_stack');
    $request_stack->push($request);
    try {
      $view->preExecute();
      $this->assertSame($settings, $view->element['#viewsreference'], 'Views Reference must replace field metadata with decoded viewsreference query settings before plugin dispatch.');
      $values = array_column(ContractSetting::$viewCalls, 'value');
      $this->assertSame($enabled ? ['query setting'] : [], $values, 'Views Reference must call alterView() exactly for settings listed in enabled_settings, using their decoded query values.');
    }
    finally {
      $view->postExecute();
      $request_stack->pop();
    }
  }

  /**
   * Provides both field formatter implementations.
   *
   * @phpstan-return array<string, array{string}>
   */
  public static function formatterProvider(): array {
    return [
      'regular' => ['viewsreference_formatter'],
      'lazy' => ['viewsreference_lazy_formatter'],
    ];
  }

  /**
   * Provides both field widget implementations.
   *
   * @phpstan-return array<string, array{string}>
   */
  public static function widgetProvider(): array {
    return [
      'autocomplete' => ['viewsreference_autocomplete'],
      'select' => ['viewsreference_select'],
    ];
  }

  /**
   * Provides plain and compressed queries with a setting enabled or disabled.
   *
   * @phpstan-return array<string, array{bool, bool}>
   */
  public static function requestProvider(): array {
    return [
      'plain enabled' => [FALSE, TRUE],
      'plain disabled' => [FALSE, FALSE],
      'compressed enabled' => [TRUE, TRUE],
      'compressed disabled' => [TRUE, FALSE],
    ];
  }

  /**
   * Creates a saved Views Reference field item with nested Sift settings.
   *
   * @phpstan-return FieldItemListInterface<\Drupal\Core\Field\FieldItemInterface>
   */
  private function listingItems(): FieldItemListInterface {
    $node = Node::create([
      'type' => 'article',
      'title' => 'Contract listing',
      'status' => TRUE,
      'field_listing' => [
        [
          'target_id' => 'sift_contract',
          'display_id' => 'embed',
          'data' => serialize(self::SETTINGS),
        ],
      ],
    ]);
    $node->save();
    return $node->get('field_listing');
  }

}
