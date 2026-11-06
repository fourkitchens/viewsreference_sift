<?php

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
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\views\ViewExecutable;
use Drupal\viewsreference\Plugin\ViewsReferenceSettingInterface;
use Drupal\viewsreference_sift\Filter\FilterResolver;
use Drupal\viewsreference_sift\Filter\SiftFilter;
use Drupal\viewsreference_sift\Selection\MatchMode;
use Drupal\viewsreference_sift\Selection\Selection;
use Drupal\viewsreference_sift\Selection\SelectionNormalizer;
use Drupal\viewsreference_sift\Selection\SelectionValidator;
use Drupal\viewsreference_sift\Selection\ValueSource;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Filters an embedded view by selected entities.
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
   * Constructs a ViewsReferenceSiftTerms plugin.
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
   * {@inheritdoc}
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
   * {@inheritdoc}
   *
   * @phpstan-param array<string, mixed> $form_field
   */
  public function alterFormField(array &$form_field): void {
    $view_name = $this->configuration['view_name'] ?? '';
    $view_id = EntityAutocomplete::extractEntityIdFromAutocompleteInput($view_name) ?? $view_name;
    $configured = $this->filterResolver->forDisplay($view_id, $this->configuration['display_id'] ?? '', TRUE);
    $filters = array_filter($configured, static fn (SiftFilter $filter): bool => $filter->enabled);
    if (!$filters) {
      unset($form_field['#default_value']);
      $form_field['#access'] = FALSE;
      return;
    }

    $stored = $form_field['#default_value'] ?? '';
    $selection = $this->selectionNormalizer->fromStorage($stored, $configured);
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

      $group = [
        '#type' => 'fieldset',
        '#title' => $filter->label,
        'ids' => $this->buildWidget($filter, $entities, $ids),
      ];
      if ($filter->description !== '') {
        $group['#description'] = $filter->description;
      }
      elseif ($filter->pluginId === 'taxonomy_index_tid_depth') {
        $group['#description'] = $this->t('Matches any selected term. A positive depth also includes child terms.');
      }
      if ($filter->supportsAll && $filter->matchPolicy === 'editor') {
        $group['match'] = [
          '#type' => 'radios',
          '#title' => $this->t('Match'),
          '#options' => [
            MatchMode::Any->value => $this->t('Any of these items'),
            MatchMode::All->value => $this->t('All of these items'),
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
   * @phpstan-param list<int> $ids
   * @phpstan-return array<string, mixed>
   */
  private function buildWidget(SiftFilter $filter, array $entities, array $ids): array {
    $title = $filter->multiple ? $this->t('Select items') : $this->t('Select an item');
    $autocomplete = [
      '#type' => 'entity_autocomplete',
      '#target_type' => $filter->entityTypeId,
      '#tags' => $filter->multiple,
      '#title' => $title,
      '#default_value' => $filter->multiple ? array_values($entities) : ($entities[$ids[0] ?? 0] ?? NULL),
    ];
    if ($filter->bundles) {
      $autocomplete['#selection_settings']['target_bundles'] = $filter->bundles;
    }
    if ($filter->widget === 'autocomplete') {
      return $autocomplete;
    }

    $handler = $this->selectionManager->getInstance([
      'target_type' => $filter->entityTypeId,
      'target_bundles' => $filter->bundles ?: NULL,
    ]);
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
    if ($filter->widget === 'select') {
      $widget['#multiple'] = $filter->multiple;
      if (!$filter->multiple) {
        $widget['#empty_option'] = $this->t('- Select -');
        $widget['#default_value'] = $default_ids[0] ?? '';
      }
    }
    return $widget;
  }

  /**
   * {@inheritdoc}
   */
  public function alterView(ViewExecutable $view, $value): void {
    unset($view->element['#viewsreference_sift']['selection']);
    $filters = $this->filterResolver->forView($view, TRUE);
    if (!$filters) {
      return;
    }
    $value = $this->valueSource->resolve($view, $value);
    $selection = $this->selectionNormalizer->fromStorage($value, $filters);
    $selection = $this->selectionValidator->validate($selection, $filters);
    foreach ($selection->filters as $id => $selected) {
      $tags = Cache::buildTags($filters[$id]->entityTypeId, $selected->ids);
      $view->element['#cache']['tags'] = Cache::mergeTags($view->element['#cache']['tags'] ?? [], $tags);
    }
    if (!$selection->isEmpty()) {
      $view->element['#viewsreference_sift']['selection'] = [
        'data' => $this->selectionNormalizer->toStorage($selection),
        'filter_ids' => array_keys($filters),
        'display_id' => $view->current_display,
      ];
    }
  }

  /**
   * Validates widget limits and converts values into the stored shape.
   *
   * @phpstan-param array<string, mixed> $element
   */
  public static function validateSelection(array $element, FormStateInterface $form_state): void {
    /** @var array<string, SiftFilter> $filters */
    $filters = $element['#sift_filters'];
    $submitted = $form_state->getValue([...$element['#parents'], 'filters'], []);
    $values = [];
    foreach (is_array($submitted) ? $submitted : [] as $id => $value) {
      $filter = $filters[$id] ?? NULL;
      if (!$filter || !is_array($value)) {
        continue;
      }
      $ids = $value['ids'] ?? [];
      $ids = is_array($ids) ? $ids : [$ids];
      $values[$id] = [
        'ids' => array_map(static fn (mixed $item): mixed => is_array($item) ? ($item['target_id'] ?? NULL) : $item, $ids),
        'match' => $filter->supportsAll ? ($value['match'] ?? NULL) : NULL,
      ];
    }

    $normalizer = \Drupal::service(SelectionNormalizer::class);
    $selection = $normalizer->fromStorage(['version' => Selection::VERSION, 'filters' => $values], $filters);
    $display = $form_state->getValue([...array_slice($element['#parents'], 0, -2), 'display_id']);
    if (is_string($display) && $display !== '') {
      foreach ($filters as $id => $filter) {
        $count = count($selection->filters[$id]->ids ?? []);
        $max = $filter->multiple ? $filter->max : 1;
        $message = match (TRUE) {
          $filter->required && $count === 0 => new TranslatableMarkup('Choose at least one item for @label.', ['@label' => $filter->label]),
          $count > $max => new TranslatableMarkup('Choose at most @max items for @label.', [
            '@max' => $max,
            '@label' => $filter->label,
          ]),
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
