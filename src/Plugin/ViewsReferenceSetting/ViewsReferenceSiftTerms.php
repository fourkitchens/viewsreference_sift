<?php

namespace Drupal\viewsreference_sift\Plugin\ViewsReferenceSetting;

use Drupal\Component\Plugin\PluginBase;
use Drupal\Core\Entity\Element\EntityAutocomplete;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\views\ViewExecutable;
use Drupal\viewsreference\Plugin\ViewsReferenceSettingInterface;
use Drupal\viewsreference_sift\Argument\ArgumentBuilder;
use Drupal\viewsreference_sift\Filter\FilterResolver;
use Drupal\viewsreference_sift\Selection\MatchMode;
use Drupal\viewsreference_sift\Selection\Selection;
use Drupal\viewsreference_sift\Selection\SelectionNormalizer;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Filters an embedded view by taxonomy terms.
 *
 * @ViewsReferenceSetting(
 *   id = "sift_terms",
 *   label = @Translation("Terms"),
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
    private readonly FilterResolver $filterResolver,
    private readonly SelectionNormalizer $selectionNormalizer,
    private readonly ArgumentBuilder $argumentBuilder,
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
      $container->get(FilterResolver::class),
      $container->get(SelectionNormalizer::class),
      $container->get(ArgumentBuilder::class),
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
    $filters = $this->filterResolver->forDisplay($view_id, $this->configuration['display_id'] ?? '');
    $filter = reset($filters);
    if (!$filter) {
      $form_field['#access'] = FALSE;
      return;
    }

    $selection = $this->selectionNormalizer->fromStorage($form_field['#default_value'] ?? '', $filters);
    $selected = $selection->filters[$filter->id] ?? NULL;
    $ids = $selected->ids ?? [];
    $form_field['#type'] = 'fieldset';
    $form_field['#title'] = $this->t('Terms');
    $form_field['#tree'] = TRUE;
    $form_field['#sift_filters'] = $filters;
    $form_field['#element_validate'][] = [self::class, 'validateSelection'];
    $form_field['version'] = ['#type' => 'value', '#value' => Selection::VERSION];
    unset($form_field['#default_value']);

    $widget = [
      '#type' => 'entity_autocomplete',
      '#target_type' => $filter->entityTypeId,
      '#tags' => TRUE,
      '#title' => $this->t('Show only content with these terms'),
      '#default_value' => $ids ? array_values($this->entityTypeManager->getStorage($filter->entityTypeId)->loadMultiple($ids)) : [],
    ];
    if ($filter->bundles) {
      $widget['#selection_settings']['target_bundles'] = $filter->bundles;
    }
    $form_field['filters'][$filter->id]['ids'] = $widget;

    if ($filter->supportsAll) {
      $form_field['filters'][$filter->id]['match'] = [
        '#type' => 'radios',
        '#title' => $this->t('Match'),
        '#options' => [
          MatchMode::Any->value => $this->t('Any of these terms'),
          MatchMode::All->value => $this->t('All of these terms'),
        ],
        '#default_value' => ($selected->match ?? MatchMode::Any)->value,
      ];
    }
    elseif ($filter->pluginId === 'taxonomy_index_tid_depth') {
      $form_field['#description'] = $this->t('Matches any selected term. A positive depth also includes child terms.');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function alterView(ViewExecutable $view, $value): void {
    $filters = $this->filterResolver->forView($view);
    $selection = $this->selectionNormalizer->fromStorage($value, $filters);
    $arguments = $this->argumentBuilder->build($filters, $selection, $view->args);
    if ($arguments !== $view->args) {
      $view->setArguments($arguments);
    }
  }

  /**
   * Converts validated autocomplete values into the stored selection shape.
   *
   * @phpstan-param array<string, mixed> $element
   */
  public static function validateSelection(array $element, FormStateInterface $form_state): void {
    /** @var array<string, \Drupal\viewsreference_sift\Filter\SiftFilter> $filters */
    $filters = $element['#sift_filters'];
    $submitted = $form_state->getValue([...$element['#parents'], 'filters'], []);
    $values = [];
    foreach (is_array($submitted) ? $submitted : [] as $id => $value) {
      $filter = $filters[$id] ?? NULL;
      if (!$filter || !is_array($value) || !is_array($value['ids'] ?? NULL)) {
        continue;
      }
      $values[$id] = [
        'ids' => array_map(static fn (mixed $item): mixed => is_array($item) ? ($item['target_id'] ?? NULL) : $item, $value['ids']),
        'match' => $filter->supportsAll ? ($value['match'] ?? NULL) : NULL,
      ];
    }

    $normalizer = \Drupal::service(SelectionNormalizer::class);
    $selection = $normalizer->fromStorage(['version' => Selection::VERSION, 'filters' => $values], $filters);
    $form_state->setValueForElement($element, $normalizer->toStorage($selection));
  }

}
