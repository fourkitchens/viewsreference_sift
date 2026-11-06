<?php

declare(strict_types=1);

namespace Drupal\Tests\viewsreference_sift\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\views\Entity\View;
use Drupal\viewsreference_sift\Filter\SiftFilter;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that uninstall removes Sift settings without removing Views.
 */
#[Group('viewsreference_sift')]
#[RunTestsInSeparateProcesses]
class DisplayExtenderUninstallTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'field', 'node', 'views', 'views_test_data', 'viewsreference'];

  /**
   * Tests cleanup of Sift settings while keeping other display extenders.
   */
  public function testUninstallPreservesViews(): void {
    $this->installSchema('user', ['users_data']);
    $this->installConfig(['system', 'views']);
    $this->config('views.settings')->set('display_extenders', ['display_extender_test'])->save();
    $this->container->get('module_installer')->install(['viewsreference_sift']);
    $this->assertSame(['display_extender_test', 'viewsreference_sift'], $this->config('views.settings')->get('display_extenders'));

    $other = ['test_extender_test_option' => 'Keep this setting'];
    $options = ['display_extender_test' => $other];
    foreach (['sift_configured', 'sift_unrelated'] as $id) {
      $display = [
        'id' => 'default',
        'display_title' => 'Default',
        'display_plugin' => 'default',
        'position' => 0,
        'display_options' => ['display_extenders' => $options],
      ];
      if ($id === 'sift_configured') {
        $display['display_options']['display_extenders']['viewsreference_sift']['filters']['tid'] = SiftFilter::DEFAULT_OPTIONS;
      }
      View::create([
        'id' => $id,
        'label' => $id,
        'base_table' => 'node_field_data',
        'base_field' => 'nid',
        'display' => ['default' => $display],
      ])->save();
    }
    $configured = $this->config('views.view.sift_configured')->get('display.default.display_options.display_extenders');
    $this->assertArrayHasKey('viewsreference_sift', $configured);
    $views = $this->container->get('config.factory')->listAll('views.view.');

    $this->container->get('module_installer')->uninstall(['viewsreference_sift']);

    $this->assertSame($views, $this->container->get('config.factory')->listAll('views.view.'));
    $this->assertSame(['display_extender_test'], $this->config('views.settings')->get('display_extenders'));
    foreach (['sift_configured', 'sift_unrelated'] as $id) {
      $view = View::load($id);
      $this->assertInstanceOf(View::class, $view);
      $this->assertSame($options, $view->getDisplay('default')['display_options']['display_extenders']);
    }
  }

}
