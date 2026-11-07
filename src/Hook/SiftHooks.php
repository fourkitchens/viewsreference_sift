<?php

declare(strict_types=1);

namespace Drupal\viewsreference_sift\Hook;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Entity\Element\EntityAutocomplete;
use Drupal\Core\Form\FormStateInterface;
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
use Drupal\viewsreference_sift\Selection\SelectionNormalizer;

/**
 * Provides help and preserves saved Sift choices in forms and Views.
 *
 * @internal
 */
final class SiftHooks {

  use StringTranslationTrait;

  /**
   * Injects the services that turn saved choices into contextual arguments.
   */
  public function __construct(
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
   * Preserves server-side defaults across rebuilds in the same context.
   *
   * Views Reference rebuilds from submitted options without saved item data.
   * Track the field item's data in FormState, clearing it when the selected
   * view or display changes. Raw serialized input cannot seed this history.
   *
   * @phpstan-param array<array-key, mixed> $element
   * @phpstan-param array{items: \Drupal\Core\Field\FieldItemListInterface<\Drupal\Core\Field\FieldItemInterface>, delta: int, widget: \Drupal\Core\Field\WidgetInterface, form: array<array-key, mixed>} $context
   */
  #[Hook('field_widget_single_element_viewsreference_autocomplete_form_alter')]
  #[Hook('field_widget_single_element_viewsreference_select_form_alter')]
  public function protectWidgetDefaults(array &$element, FormStateInterface $form_state, array $context): void {
    $field_parents = $context['form']['#parents'] ?? [];
    $parents = [...$field_parents, $context['items']->getName(), $context['delta']];
    if (!isset($element['options']['sift_terms'])) {
      return;
    }
    $input = (array) $form_state->getUserInput();
    $saved = $context['items'][$context['delta']]->getValue();
    // Separate histories when another saved item moves into this delta.
    $identity = serialize([$saved['target_id'] ?? '', $saved['display_id'] ?? '', $saved['data'] ?? '']);
    $history_key = ['viewsreference_sift_widget', ...$parents, hash('sha256', $identity)];
    $history = $form_state->get($history_key) ?? $saved;
    $item_input = NestedArray::getValue($input, $parents);
    $current = is_array($item_input) ? $item_input : $saved;
    $view_name = $current['target_id'] ?? '';
    $view_id = is_string($view_name) ? (EntityAutocomplete::extractEntityIdFromAutocompleteInput($view_name) ?? $view_name) : '';
    $display_id = $current['display_id'] ?? '';
    if ($view_id !== ($history['target_id'] ?? NULL) || $display_id !== ($history['display_id'] ?? NULL)) {
      $history = ['target_id' => $view_id, 'display_id' => $display_id, 'data' => ''];
    }
    $form_state->set($history_key, $history);
    if (!is_array($item_input)) {
      return;
    }
    unset($item_input['data']);
    NestedArray::setValue($input, $parents, $item_input);
    $item_input['data'] = $history['data'] ?? '';
    $trusted_input = $input;
    NestedArray::setValue($trusted_input, $parents, $item_input);
    $form_state->setUserInput($trusted_input);
    $base = array_intersect_key($element['target_id'] ?? $element, array_flip([
      '#field_parents', '#required', '#title', '#title_display', '#description', '#delta', '#weight',
    ]));
    $base += [
      '#field_parents' => $field_parents,
      '#required' => $context['delta'] === 0 && $context['items']->getFieldDefinition()->isRequired(),
    ];
    $rebuilt = $context['widget']->formElement($context['items'], $context['delta'], $base, $context['form'], $form_state);
    $setting = $rebuilt['options']['sift_terms'];
    $access_settings = array_intersect_key($element['options']['sift_terms'], [
      '#access' => TRUE,
      '#disabled' => TRUE,
    ]);
    $setting = array_replace($setting, $access_settings);
    $setting['#attributes'] = ($element['options']['sift_terms']['#attributes'] ?? []) + ($setting['#attributes'] ?? []);
    $element['options']['sift_terms'] = $setting;
    $form_state->setUserInput($input);
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

    $filters = $pending['filters'];
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
