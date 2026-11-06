<?php

declare(strict_types=1);

namespace Drupal\Tests\viewsreference_sift\Kernel;

use Drupal\Core\Config\MemoryStorage;
use Drupal\KernelTests\KernelTestBase;
use Drupal\views\Entity\View;
use Drupal\viewsreference_sift\Filter\SiftFilter;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests how install and uninstall affect Views configuration.
 */
#[Group('viewsreference_sift')]
#[RunTestsInSeparateProcesses]
class DisplayExtenderUninstallTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'field', 'node', 'views', 'views_test_data', 'viewsreference'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('user', ['users_data']);
    $this->installConfig(['system', 'views']);
    $this->config('views.settings')->set('display_extenders', ['display_extender_test'])->save();
  }

  /**
   * Tests cleanup of Sift settings while keeping other display extenders.
   */
  public function testUninstallPreservesViews(): void {
    $this->assertTrue($this->container->get('module_installer')->install(['viewsreference_sift']));
    $this->assertSame(['display_extender_test', 'viewsreference_sift'], $this->config('views.settings')->get('display_extenders'));

    $other = ['test_extender_test_option' => 'Keep this setting'];
    $options = ['display_extender_test' => $other];
    $this->createView('sift_configured', $options + [
      'viewsreference_sift' => ['filters' => ['tid' => SiftFilter::DEFAULT_OPTIONS]],
    ]);
    $this->createView('sift_unrelated', $options);
    $configured = $this->config('views.view.sift_configured')->get('display.default.display_options.display_extenders');
    $this->assertArrayHasKey('viewsreference_sift', $configured);
    $views = $this->container->get('config.factory')->listAll('views.view.');

    $this->assertTrue($this->container->get('module_installer')->uninstall(['viewsreference_sift']));

    $this->assertSame($views, $this->container->get('config.factory')->listAll('views.view.'));
    $this->assertSame(['display_extender_test'], $this->config('views.settings')->get('display_extenders'));
    foreach (['sift_configured', 'sift_unrelated'] as $id) {
      $view = View::load($id);
      $this->assertInstanceOf(View::class, $view);
      $this->assertSame($options, $view->getDisplay('default')['display_options']['display_extenders']);
    }
  }

  /**
   * Tests that installing during a config import preserves Views config.
   */
  public function testSyncingInstallPreservesConfig(): void {
    $this->createView('sift_unrelated');
    $settings = $this->config('views.settings')->getRawData();
    $view = $this->config('views.view.sift_unrelated')->getRawData();

    \Drupal::service('config.installer')->setSyncing(TRUE)->setSourceStorage(new MemoryStorage());
    try {
      $this->assertTrue($this->container->get('module_installer')->install(['viewsreference_sift']));
    }
    finally {
      \Drupal::service('config.installer')->setSyncing(FALSE);
    }

    $this->assertSame($settings, $this->config('views.settings')->getRawData());
    $this->assertSame($view, $this->config('views.view.sift_unrelated')->getRawData());
  }

  /**
   * Tests that a syncing uninstall only removes the global extender entry.
   */
  public function testSyncingUninstallPreservesViewConfig(): void {
    $this->assertTrue($this->container->get('module_installer')->install(['viewsreference_sift']));
    $this->createView('sift_configured', [
      'viewsreference_sift' => ['filters' => ['tid' => SiftFilter::DEFAULT_OPTIONS]],
    ]);
    $expected_settings = $this->config('views.settings')->getRawData();
    $expected_settings['display_extenders'] = ['display_extender_test'];
    $view = $this->config('views.view.sift_configured')->getRawData();

    \Drupal::service('config.installer')->setSyncing(TRUE)->setSourceStorage(new MemoryStorage());
    try {
      $this->assertTrue($this->container->get('module_installer')->uninstall(['viewsreference_sift']));
    }
    finally {
      \Drupal::service('config.installer')->setSyncing(FALSE);
    }

    $this->assertSame($expected_settings, $this->config('views.settings')->getRawData());
    $this->assertSame($view, $this->config('views.view.sift_configured')->getRawData());
  }

  /**
   * Creates a view with the supplied display extender settings.
   *
   * @phpstan-param array<string, mixed> $extenders
   */
  private function createView(string $id, array $extenders = []): void {
    View::create([
      'id' => $id,
      'label' => $id,
      'base_table' => 'node_field_data',
      'base_field' => 'nid',
      'display' => [
        'default' => [
          'id' => 'default',
          'display_title' => 'Default',
          'display_plugin' => 'default',
          'position' => 0,
          'display_options' => ['display_extenders' => $extenders],
        ],
      ],
    ])->save();
  }

}
