<?php

declare(strict_types=1);

namespace Drupal\Tests\viewsreference_sift\Kernel;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the module Help page guidance for site builders.
 */
#[Group('viewsreference_sift')]
#[RunTestsInSeparateProcesses]
class SiftHelpTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'help',
    'system',
    'views',
    'viewsreference',
    'viewsreference_sift',
  ];

  /**
   * Checks that the module help names both places to configure Sift.
   */
  public function testHelp(): void {
    $handler = $this->container->get('module_handler');
    $route_match = $this->container->get('current_route_match');
    $help = $handler->invoke('viewsreference_sift', 'help', [
      'help.page.viewsreference_sift',
      $route_match,
    ]);
    $this->assertInstanceOf(TranslatableMarkup::class, $help);
    $this->assertStringContainsString('Views Reference field settings', (string) $help);
    $this->assertStringContainsString('<strong>Sift</strong> under <strong>Advanced</strong>', (string) $help);
    $this->assertStringContainsString('href="https://github.com/fourkitchens/viewsreference_sift/blob/1.0.x/README.md"', (string) $help);
    $this->assertNull($handler->invoke('viewsreference_sift', 'help', [
      'help.page.views',
      $route_match,
    ]));
  }

}
