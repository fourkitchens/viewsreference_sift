<?php

declare(strict_types=1);

namespace Drupal\viewsreference_sift\Plugin\ViewsReferenceSetting;

use Drupal\Component\Plugin\PluginBase;
use Drupal\Component\Utility\Html;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\Element\EntityAutocomplete;
use Drupal\Core\Entity\EntityReferenceSelection\SelectionInterface;
use Drupal\Core\Entity\EntityReferenceSelection\SelectionPluginManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\OptGroup;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\PluralTranslatableMarkup;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\views\ViewExecutable;
use Drupal\viewsreference\Plugin\ViewsReferenceSettingInterface;
use Drupal\viewsreference_sift\Filter\FilterResolver;
use Drupal\viewsreference_sift\Filter\SiftFilter;
use Drupal\viewsreference_sift\Selection\FilterSelection;
use Drupal\viewsreference_sift\Selection\MatchMode;
use Drupal\viewsreference_sift\Selection\Selection;
use Drupal\viewsreference_sift\Selection\SelectionMode;
use Drupal\viewsreference_sift\Selection\SelectionNormalizer;
use Drupal\viewsreference_sift\Selection\SelectionValidator;
use Drupal\viewsreference_sift\Selection\ValueSource;
use Drupal\viewsreference_sift\Widget\SiftAutocompleteWidget;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lets editors narrow an embedded view through its contextual filters.
 *
 * @internal
 *
 * @todo Convert the setting to an attribute after Views Reference releases
 *   its discovery fix.
 *   https://www.drupal.org/project/viewsreference/issues/3583101
 *
 * @ViewsReferenceSetting(
 *   id = "sift_terms",
 *   label = @Translation("Sift filters"),
 *   default_value = "",
 * )
 */
final class ViewsReferenceSiftTerms extends PluginBase implements ViewsReferenceSettingInterface, ContainerFactoryPluginInterface {

  use StringTranslationTrait;

  /**
   * Supplies the services for building widgets and applying saved selections.
   *
   * @phpstan-param array<string, mixed> $configuration
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly SelectionPluginManagerInterface $selectionManager,
    private readonly FilterResolver $filterResolver,
    private readonly SelectionNormalizer $selectionNormalizer,
    private readonly SelectionValidator $selectionValidator,
    private readonly ValueSource $valueSource,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * Creates the setting plugin with its Drupal services.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The container for widget and selection services.
   * @param array $configuration
   *   The setting plugin configuration.
   * @param string $plugin_id
   *   The ID of the setting plugin.
   * @param mixed $plugin_definition
   *   The definition of the setting plugin.
   *
   * @return static
   *   The setting plugin instance.
   *
   * @phpstan-param array<string, mixed> $configuration
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('plugin.manager.entity_reference_selection'),
      $container->get(FilterResolver::class),
      $container->get(SelectionNormalizer::class),
      $container->get(SelectionValidator::class),
      $container->get(ValueSource::class),
    );
  }

  /**
   * Builds a selection widget for each enabled filter on the chosen display.
   *
   * Preserves saved settings when the display has no enabled filters. Saved
   * entities the editor cannot reference are kept outside the visible widget.
   *
   * @param array $form_field
   *   The Views Reference setting element to replace with filter widgets.
   *
   * @phpstan-param array<string, mixed> $form_field
   */
  public function alterFormField(array &$form_field): void {
    $view_name = $this->configuration['view_name'] ?? '';
    $view_id = EntityAutocomplete::extractEntityIdFromAutocompleteInput($view_name) ?? $view_name;
    $configured = $this->filterResolver->forDisplay($view_id, $this->configuration['display_id'] ?? '', TRUE);
    $filters = array_filter($configured, static fn (SiftFilter $filter): bool => $filter->enabled);
    $stored = $form_field['#default_value'] ?? '';
    if (!$filters) {
      $form_field['#type'] = 'value';
      $form_field['#value'] = $stored;
      unset($form_field['#default_value']);
      return;
    }

    $selection = $this->selectionNormalizer->fromStorage($stored, $configured);
    $defaults = $this->validatedDefaults($filters);
    $form_field['#type'] = 'fieldset';
    $form_field['#title'] = $this->t('Filter this list');
    $form_field['#tree'] = TRUE;
    $form_field['#sift_filters'] = $filters;
    $form_field['#element_validate'][] = [self::class, 'validateSelection'];
    $form_field['version'] = ['#type' => 'value', '#value' => Selection::VERSION];
    unset($form_field['#default_value']);

    $has_stale_selections = is_array($stored) && ($stored['version'] ?? NULL) === Selection::VERSION
      && is_array($stored['filters'] ?? NULL) && array_diff_key($stored['filters'], $filters) !== [];
    foreach ($filters as $filter) {
      $selected = $selection->filters[$filter->id] ?? NULL;
      $ids = $selected->ids ?? [];
      $entities = $ids ? $this->entityTypeManager->getStorage($filter->entityTypeId)->loadMultiple($ids) : [];
      if ($filter->bundles) {
        $entities = array_filter($entities, static fn (EntityInterface $entity): bool => in_array($entity->bundle(), $filter->bundles, TRUE));
      }
      $has_stale_selections = $has_stale_selections || count($entities) !== count($ids);

      $handler = $this->selectionManager->getInstance([
        'target_type' => $filter->entityTypeId,
        'target_bundles' => $filter->bundles ?: NULL,
      ]);
      $referenceable = $handler instanceof SelectionInterface ? $handler->validateReferenceableEntities(array_keys($entities)) : [];
      $visible = array_filter(array_intersect_key($entities, array_fill_keys($referenceable, TRUE)), static fn (EntityInterface $entity): bool => $entity->access('view'));
      $retained = self::entityIds($filter, array_diff_key($entities, $visible));
      $default = $defaults->filters[$filter->id] ?? NULL;
      $required = $filter->required && $default === NULL;
      $widget = $this->buildWidget($filter, $visible, $handler, $retained, $default !== NULL);
      $help = [];
      if ($default !== NULL) {
        $help[] = $this->defaultHelp($filter, $default);
        if ($filter->required) {
          $help[] = $this->t('The default satisfies the selection requirement.');
        }
      }
      if ($required) {
        $help[] = $this->t('A selection is required.');
        if ($widget['#type'] === 'checkboxes' && $retained === []) {
          $widget['#required'] = TRUE;
          $widget['#required_error'] = $this->t('Choose at least one item for @label.', ['@label' => $filter->label]);
        }
        elseif (!in_array($widget['#type'], ['radios', 'checkboxes'], TRUE)) {
          $widget['#attributes']['aria-required'] = 'true';
        }
      }
      if ($filter->multiple) {
        $help[] = $this->formatPlural($filter->max, 'Choose at most one item.', 'Choose at most @count items.');
      }
      if ($retained !== []) {
        $help[] = $this->formatPlural(count($retained), "1 selected item isn't shown because you don't have access to it. It will be kept.", "@count selected items aren't shown because you don't have access to them. They will be kept.");
      }
      if ($help) {
        $help[] = $widget['#description'] ?? '';
        $widget['#description'] = rtrim(implode(' ', $help));
        $widget['#description_display'] = 'before';
      }

      $group = [
        '#type' => 'fieldset',
        '#title' => $filter->label,
        'ids' => $widget,
        'retained' => ['#type' => 'value', '#value' => $retained],
      ];
      if ($widget['#type'] === 'radios') {
        $group['#attributes']['role'] = 'radiogroup';
        if ($required) {
          $group['#attributes']['aria-required'] = 'true';
        }
      }
      if ($filter->description !== '') {
        $group['#description'] = $filter->description;
      }
      elseif ($filter->pluginId === 'taxonomy_index_tid_depth') {
        $group['#description'] = $this->t('Matches any selected term. A positive depth also includes child terms.');
      }
      if ($filter->allowExclude) {
        $group['mode'] = [
          '#type' => 'radios',
          '#title' => $this->t('Selection mode'),
          '#options' => [
            SelectionMode::Include->value => $this->t('Show items that match'),
            SelectionMode::Exclude->value => $this->t('Hide items that match'),
          ],
          '#description' => $this->t('Hiding items matches any selected item, regardless of the Match setting.'),
          '#default_value' => ($selected->mode ?? SelectionMode::Include)->value,
        ];
      }
      if ($filter->supportsAll && $filter->matchPolicy === 'editor') {
        $group['match'] = [
          '#type' => 'radios',
          '#title' => $this->t('Match'),
          '#options' => [
            MatchMode::Any->value => $this->t('Match at least one selected item'),
            MatchMode::All->value => $this->t('Match every selected item'),
          ],
          '#default_value' => ($selected->match ?? MatchMode::Any)->value,
        ];
      }
      $form_field['filters'][$filter->id] = $group;
    }
    if ($has_stale_selections) {
      $form_field['#description'] = $this->t('Some saved selections no longer apply to this display and will be removed when you save.');
    }
  }

  /**
   * Builds the configured widget, using autocomplete for large option sets.
   *
   * @phpstan-param array<int|string, EntityInterface> $entities
   * @phpstan-param list<int|string> $retained
   * @phpstan-return array<string, mixed>
   */
  private function buildWidget(SiftFilter $filter, array $entities, mixed $handler, array &$retained, bool $has_default): array {
    $title = $filter->multiple
      ? $this->t('Select items for @label', ['@label' => $filter->label])
      : $this->t('Select an item for @label', ['@label' => $filter->label]);
    $autocomplete = [
      '#type' => 'entity_autocomplete',
      '#target_type' => $filter->entityTypeId,
      '#tags' => $filter->multiple,
      '#title' => $title,
      '#default_value' => $filter->multiple ? array_values($entities) : (reset($entities) ?: NULL),
    ];
    if ($filter->bundles) {
      $autocomplete['#selection_settings']['target_bundles'] = $filter->bundles;
    }
    if ($filter->widget === 'autocomplete') {
      return $autocomplete;
    }

    if (!$handler instanceof SelectionInterface) {
      return $autocomplete;
    }
    $options = OptGroup::flattenOptions($handler->getReferenceableEntities(NULL, 'CONTAINS', 101));
    if (count($options) > 100) {
      $autocomplete['#description'] = $this->t('More than 100 items are available. Use autocomplete to find an item.');
      return $autocomplete;
    }
    if ($filter->entityTypeId === 'taxonomy_term' && $options !== []) {
      // Core adds term hierarchy only when the lookup has no limit.
      $options = array_intersect_key(OptGroup::flattenOptions($handler->getReferenceableEntities()), $options);
    }
    if ($filter->idType === 'string') {
      $options = array_filter($options, static fn (int|string $id): bool => SiftFilter::isValidStringId((string) $id), ARRAY_FILTER_USE_KEY);
    }
    $option_entities = $this->entityTypeManager->getStorage($filter->entityTypeId)->loadMultiple(array_keys($options));
    $option_entities = array_filter($option_entities, static fn (EntityInterface $entity): bool => $entity->access('view'));
    $options = array_intersect_key($options, $option_entities);
    $retained = [...$retained, ...self::entityIds($filter, array_diff_key($entities, $options))];
    $options = array_diff_key($options, array_fill_keys($retained, TRUE));

    $defaults = array_values(array_intersect_key($entities, $options));
    $default_ids = array_map(static fn (EntityInterface $entity): string => (string) $entity->id(), $defaults);
    if (!$filter->multiple) {
      $default_ids = array_slice($default_ids, 0, 1);
    }
    $widget = [
      '#type' => $filter->widget,
      '#title' => $title,
      '#options' => array_map([Html::class, 'decodeEntities'], $options),
      '#default_value' => $default_ids,
    ];
    if (!$filter->multiple && $filter->widget === 'checkboxes') {
      $widget['#type'] = 'radios';
      $required = $filter->required && !$has_default;
      $widget['#default_value'] = $default_ids[0] ?? ($required ? NULL : '');
      if (!$required) {
        $widget['#options'] = ['' => $has_default ? $this->t('Use the default') : $this->t('None')] + $widget['#options'];
      }
    }
    if ($filter->widget === 'select') {
      $widget['#multiple'] = $filter->multiple;
      if (!$filter->multiple) {
        $widget['#empty_option'] = $has_default ? $this->t('Use the default') : $this->t('- Select -');
        $widget['#default_value'] = $default_ids[0] ?? '';
      }
    }
    return $widget;
  }

  /**
   * Describes the fallback without exposing restricted entity labels.
   */
  private function defaultHelp(SiftFilter $filter, FilterSelection $default): TranslatableMarkup {
    $entities = $this->entityTypeManager->getStorage($filter->entityTypeId)->loadMultiple($default->ids);
    $visible = array_filter($entities, static fn (EntityInterface $entity): bool => $entity->access('view'));
    if (count($visible) !== count($default->ids)) {
      return $this->t('Leave empty to use the display default.');
    }
    $labels = array_map(static fn (EntityInterface $entity): string => (string) $entity->label(), $visible);
    return $this->t('Leave empty to use the default: @items.', ['@items' => implode(', ', $labels)]);
  }

  /**
   * Drops defaults that no longer fit the display or reference missing items.
   *
   * @phpstan-param array<string, SiftFilter> $filters
   */
  private function validatedDefaults(array $filters): Selection {
    return $this->selectionValidator->validate($this->selectionNormalizer->fromDefaults($filters), $filters, strict: TRUE);
  }

  /**
   * Restores the ID type after PHP casts numeric string array keys.
   *
   * @phpstan-param array<int|string, EntityInterface> $entities
   * @phpstan-return list<int|string>
   */
  private static function entityIds(SiftFilter $filter, array $entities): array {
    return array_map($filter->idType === 'string' ? 'strval' : 'intval', array_keys($entities));
  }

  /**
   * Prepares a selection for the late Views hook to apply.
   *
   * Resolves and validates the selection before adding entity cache tags and
   * staging it for this display. The hook applies it after Views Reference has
   * set the view's other arguments.
   *
   * @param \Drupal\views\ViewExecutable $view
   *   The view that will receive the selection.
   * @param mixed $value
   *   The selection supplied by Views Reference.
   *
   * @see \Drupal\viewsreference_sift\Hook\SiftHooks::applySelection()
   */
  public function alterView(ViewExecutable $view, $value): void {
    unset($view->element['#viewsreference_sift']['selection']);
    $filters = $this->filterResolver->forView($view, TRUE);
    if (!$filters) {
      return;
    }
    $value = $this->valueSource->resolve($view, $value);
    $selection = $this->selectionNormalizer->fromStorage($value, $filters);
    $defaults = $this->valueSource->allowsDefaults($view) ? $this->selectionNormalizer->fromDefaults($filters) : NULL;
    $selection = $this->selectionValidator->validate($selection, $filters, defaults: $defaults);
    foreach ($selection->filters as $id => $selected) {
      $storage = $this->entityTypeManager->getStorage($filters[$id]->entityTypeId);
      foreach ($storage->loadMultiple($selected->ids) as $entity) {
        $view->element['#cache']['tags'] = Cache::mergeTags($view->element['#cache']['tags'] ?? [], $entity->getCacheTags());
      }
    }
    if (!$selection->isEmpty()) {
      $view->element['#viewsreference_sift']['selection'] = [
        'data' => $this->selectionNormalizer->toStorage($selection),
        'filters' => array_filter($filters, static fn (SiftFilter $filter): bool => $filter->enabled),
        'display_id' => $view->current_display,
      ];
    }
  }

  /**
   * Validates widget limits and converts values into the stored shape.
   *
   * Normalizes the different widget value shapes and reports required or
   * maximum-selection errors on the affected filter's input.
   *
   * @phpstan-param array<string, mixed> $element
   */
  public static function validateSelection(array $element, FormStateInterface $form_state): void {
    /** @var array<string, SiftFilter> $filters */
    $filters = $element['#sift_filters'];
    $submitted = $form_state->getValue([...$element['#parents'], 'filters'], []);
    $values = [];
    foreach ($filters as $id => $filter) {
      $value = is_array($submitted) && is_array($submitted[$id] ?? NULL) ? $submitted[$id] : [];
      $widget = $element['filters'][$id]['ids'];
      if (isset($widget['#sift_widget'])) {
        $ids = SiftAutocompleteWidget::extractIds($widget, $form_state);
      }
      else {
        $ids = $value['ids'] ?? [];
        $ids = array_map(static fn (mixed $item): mixed => is_array($item) ? ($item['target_id'] ?? NULL) : $item, is_array($ids) ? $ids : [$ids]);
      }
      if ($filter->idType === 'string') {
        // Form API may use integer option keys for numeric string IDs.
        $checkboxes = ($element['filters'][$id]['ids']['#type'] ?? NULL) === 'checkboxes';
        $ids = array_map(static fn (mixed $id): mixed => is_int($id) ? ($checkboxes && $id === 0 ? NULL : (string) $id) : $id, $ids);
      }
      $retained = $element['filters'][$id]['retained']['#value'] ?? [];
      $entities = $retained ? \Drupal::entityTypeManager()->getStorage($filter->entityTypeId)->loadMultiple($retained) : [];
      $entities = array_filter($entities, static fn (EntityInterface $entity): bool => $filter->bundles === [] || in_array($entity->bundle(), $filter->bundles, TRUE));
      $retained = self::entityIds($filter, $entities);
      $values[$id] = [
        'ids' => [...$retained, ...$ids],
        'match' => $filter->supportsAll ? ($value['match'] ?? NULL) : NULL,
        'mode' => $value['mode'] ?? NULL,
      ];
    }

    $normalizer = \Drupal::service(SelectionNormalizer::class);
    $selection = $normalizer->fromStorage(['version' => Selection::VERSION, 'filters' => $values], $filters);
    $display = $form_state->getValue([...array_slice($element['#parents'], 0, -2), 'display_id']);
    if (is_string($display) && $display !== '') {
      $required = array_filter(array_diff_key($filters, $selection->filters), static fn (SiftFilter $filter): bool => $filter->required);
      $defaults = \Drupal::service(SelectionValidator::class)->validate($normalizer->fromDefaults($required), $required, strict: TRUE);
      foreach ($filters as $id => $filter) {
        $count = count($selection->filters[$id]->ids ?? []);
        $max = $filter->multiple ? $filter->max : 1;
        $message = match (TRUE) {
          $filter->required && $count === 0 && !isset($defaults->filters[$id]) => new TranslatableMarkup('Choose at least one item for @label.', ['@label' => $filter->label]),
          $count > $max => new PluralTranslatableMarkup(
            $max,
            'Choose at most 1 item for @label.',
            'Choose at most @count items for @label.',
            ['@label' => $filter->label],
          ),
          default => NULL,
        };
        if ($message !== NULL) {
          $name = implode('][', [...$element['#parents'], 'filters', $id, 'ids']);
          $form_state->setErrorByName($name, $message);
        }
      }
    }
    $form_state->setValueForElement($element, $normalizer->toStorage($selection));
  }

}
