<?php

declare(strict_types=1);

namespace Drupal\viewsreference_sift_test\Plugin\ViewsReferenceSetting;

use Drupal\Component\Plugin\PluginBase;
use Drupal\views\ViewExecutable;
use Drupal\viewsreference\Plugin\ViewsReferenceSettingInterface;

/**
 * Records calls from the upstream widget and pre-view hook.
 *
 * @ViewsReferenceSetting(
 *   id = "sift_contract",
 *   label = @Translation("Contract setting"),
 *   default_value = "",
 * )
 */
final class ContractSetting extends PluginBase implements ViewsReferenceSettingInterface {

  /**
   * Calls recorded during the current contract test.
   *
   * @var list<array{metadata: mixed, value: mixed}>
   */
  public static array $viewCalls = [];

  /**
   * {@inheritdoc}
   *
   * @phpstan-param array<string, mixed> $form_field
   */
  public function alterFormField(array &$form_field): void {
    $form_field['#contract_configuration'] = $this->configuration;
  }

  /**
   * {@inheritdoc}
   */
  public function alterView(ViewExecutable $view, $value): void {
    self::$viewCalls[] = [
      'metadata' => $view->element['#viewsreference'] ?? NULL,
      'value' => $value,
    ];
  }

}
