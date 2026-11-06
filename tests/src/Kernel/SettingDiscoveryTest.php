<?php

declare(strict_types=1);

namespace Drupal\Tests\viewsreference_sift\Kernel;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests discovery of Sift and Views Reference setting plugins.
 */
#[Group('viewsreference_sift')]
#[RunTestsInSeparateProcesses]
class SettingDiscoveryTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'views',
    'viewsreference',
    'viewsreference_sift',
  ];

  /**
   * Checks that the settings are discovered by the shared plugin manager.
   */
  public function testSettingDiscovery(): void {
    $manager = $this->container->get('plugin.manager.viewsreference.setting');
    $this->assertTrue($manager->hasDefinition('sift_terms'));
    $definition = $manager->getDefinition('sift_terms');
    $this->assertIsArray($definition);
    $this->assertSame('Sift filters', (string) $definition['label']);
    $this->assertTrue($manager->hasDefinition('argument'));
    $this->assertTrue($manager->hasDefinition('limit'));
  }

}
