<?php

declare(strict_types=1);

namespace Drupal\viewsreference_sift\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Hook\Attribute\ReorderHook;
use Drupal\Core\Hook\Order\Order;
use Drupal\Core\Hook\Order\OrderBefore;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\views\Plugin\views\argument\ArgumentPluginBase;
use Drupal\views\ViewExecutable;
use Drupal\viewsreference_sift\Argument\ArgumentBuilder;
use Drupal\viewsreference_sift\Filter\FilterResolver;
use Drupal\viewsreference_sift\Selection\SelectionNormalizer;

/**
 * Provides module help and preserves saved Sift choices during View rendering.
 */
final class SiftHooks {

  use StringTranslationTrait;

  /**
   * Injects the services that turn saved choices into contextual arguments.
   */
  public function __construct(
    private readonly FilterResolver $filterResolver,
    private readonly SelectionNormalizer $selectionNormalizer,
    private readonly ArgumentBuilder $argumentBuilder,
  ) {}

  /**
   * Directs site builders to the field settings and Views display options.
   *
   * @see \hook_help()
   */
  #[Hook('help')]
  public function help(string $route_name, RouteMatchInterface $route_match): ?TranslatableMarkup {
    if ($route_name !== 'help.page.viewsreference_sift') {
      return NULL;
    }

    return $this->t('<p>Enable <strong>Sift filters</strong> in the Views Reference field settings. To configure filters for a display, open <strong>Sift</strong> under <strong>Advanced</strong> in the Views UI.</p><p><a href=":readme">Read the Views Reference Sift README</a>.</p>', [
      ':readme' => 'https://github.com/fourkitchens/viewsreference_sift/blob/1.0.x/README.md',
    ]);
  }

  /**
   * Captures the trusted selection from the field render array.
   *
   * Runs before Views Reference can replace the field metadata with the
   * viewsreference query parameter. Page requests trust this snapshot.
   * Drupal applies ReorderHook after ordering declared on Hook attributes.
   *
   * @phpstan-param array<array-key, mixed> $args
   */
  #[Hook('views_pre_view')]
  #[ReorderHook('views_pre_view', self::class, 'snapshot', order: new OrderBefore(modules: ['viewsreference']))]
  public function snapshot(ViewExecutable $view, string $display_id, array &$args): void {
    if (isset($view->element['#viewsreference_sift']['checked'])) {
      return;
    }
    // Mark bare views too, before Views Reference can add request metadata.
    $view->element['#viewsreference_sift']['checked'] = TRUE;

    if (isset($view->element['#viewsreference']) && !isset($view->element['#viewsreference_sift']['trusted'])) {
      $data = $view->element['#viewsreference']['data'] ?? NULL;
      $view->element['#viewsreference_sift']['trusted'] = [
        'data' => is_array($data) ? ($data['sift_terms'] ?? NULL) : NULL,
      ];
    }
  }

  /**
   * Merges Sift choices with the arguments already supplied by Views Reference.
   *
   * Runs last so the validated Sift selection takes precedence after the
   * other Views Reference settings have supplied their arguments.
   *
   * @phpstan-param array<int, int|string|null> $args
   */
  #[Hook('views_pre_view', order: Order::Last)]
  public function applySelection(ViewExecutable $view, string $display_id, array &$args): void {
    $pending = $view->element['#viewsreference_sift']['selection'] ?? NULL;
    unset($view->element['#viewsreference_sift']['selection']);
    if (!$pending || $pending['display_id'] !== $display_id) {
      return;
    }

    $filters = array_intersect_key($this->filterResolver->forView($view), array_fill_keys($pending['filter_ids'], TRUE));
    $selection = $this->selectionNormalizer->fromStorage($pending['data'], $filters);
    if ($selection->isEmpty()) {
      return;
    }

    $display = $view->display_handler;
    $arguments = $display->getOption('arguments') ?? [];
    $default_for = static function (string $argument_id) use ($display): ?string {
      $handler = $display->getHandler('argument', $argument_id);
      $default = $handler instanceof ArgumentPluginBase ? $handler->getDefaultArgument() : NULL;
      return is_scalar($default) && $default !== FALSE ? (string) $default : NULL;
    };
    $args = $this->argumentBuilder->build($arguments, $filters, $selection, $args, $default_for);
  }

}
