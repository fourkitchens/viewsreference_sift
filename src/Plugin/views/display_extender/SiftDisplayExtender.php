<?php

declare(strict_types=1);

namespace Drupal\viewsreference_sift\Plugin\views\display_extender;

use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\views\Attribute\ViewsDisplayExtender;
use Drupal\views\Plugin\views\display_extender\DisplayExtenderPluginBase;
use Drupal\viewsreference_sift\Argument\ArgumentBuilder;
use Drupal\viewsreference_sift\Filter\FilterResolver;
use Drupal\viewsreference_sift\Filter\SiftFilter;

/**
 * Lets Views administrators configure Sift per display and contextual filter.
 *
 * @internal
 *
 * @phpstan-type ArgumentLabel string|\Drupal\Component\Render\MarkupInterface
 * @phpstan-type FilterOptions array{enabled: bool, label: string, description: string, bundles: list<string>, match: string, widget: string, max: int, required: bool}
 */
#[ViewsDisplayExtender(
  id: 'viewsreference_sift',
  title: new TranslatableMarkup('Sift'),
  help: new TranslatableMarkup('Configure Sift for individual contextual filters.'),
)]
final class SiftDisplayExtender extends DisplayExtenderPluginBase {

  /**
   * Supplies filter and bundle metadata for the Views settings form.
   *
   * @phpstan-param array<string, mixed> $configuration
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected FilterResolver $filterResolver,
    protected EntityTypeBundleInfoInterface $bundleInfo,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   *
   * @phpstan-return array<string, array<string, mixed>>
   */
  protected function defineOptions(): array {
    return parent::defineOptions() + ['filters' => ['default' => []]];
  }

  /**
   * Summarizes enabled filters under Other in the Views display settings.
   *
   * The Sift entry opens the form for editing the current display's filters.
   *
   * @param array $categories
   *   The Views UI categories.
   * @param array $options
   *   The settings summaries to which the Sift entry is added.
   *
   * @phpstan-param array<string, mixed> $categories
   * @phpstan-param array<string, mixed> $options
   */
  public function optionsSummary(&$categories, &$options): void {
    $filters = $this->filterResolver->supportedFilters($this->displayHandler);
    $dependencies = $this->argumentDependencies();
    $enabled = 0;
    $blocked = 0;
    foreach (array_keys($filters) as $id) {
      if ($this->filterOptions($id)['enabled']) {
        $enabled++;
        $blocked += isset($dependencies[$id]['blocker']) ? 1 : 0;
      }
    }
    $summary = $filters
      ? $this->formatPlural(count($filters), '@enabled of 1 filter', '@enabled of @count filters', ['@enabled' => $enabled])
      : $this->t('No supported filters');
    $options['viewsreference_sift'] = [
      'category' => 'other',
      'title' => $this->t('Sift'),
      'value' => $blocked
        ? $this->formatPlural($blocked, '@summary (1 blocked)', '@summary (@count blocked)', ['@summary' => $summary])
        : $summary,
    ];
  }

  /**
   * Builds the Sift options form for the current Views display.
   *
   * Shows bundle and match controls only where the filter supports them.
   *
   * @param array $form
   *   The Views options form to populate.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state identifying the requested settings section.
   *
   * @phpstan-param array<string, mixed> $form
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state): void {
    if ($form_state->get('section') !== 'viewsreference_sift') {
      return;
    }

    $form['#title'] .= $this->t('Sift settings');
    $form['filters'] = ['#tree' => TRUE];
    $filters = $this->filterResolver->supportedFilters($this->displayHandler);
    $dependencies = $this->argumentDependencies();
    if (!$filters) {
      $form['filters']['#markup'] = $this->t('This display has no supported contextual filters.');
    }

    foreach ($filters as $id => $filter) {
      $settings = $this->filterOptions($id);
      $form['filters'][$id] = [
        '#type' => 'details',
        '#title' => $filter->label,
        '#open' => TRUE,
      ];
      if ($blocker = $dependencies[$id]['blocker'] ?? NULL) {
        $form['filters'][$id]['blocking_filter'] = [
          '#type' => 'item',
          '#title' => $this->t('Warning'),
          '#markup' => $this->t('Selections for @filter apply only when @earlier has a value. Its missing-value action is @action.', [
            '@filter' => $filter->label,
            '@earlier' => $blocker['label'],
            '@action' => $this->missingActionLabel($blocker['action']),
          ]),
        ];
      }
      if (isset($dependencies[$id]['default'])) {
        $form['filters'][$id]['default_filter'] = [
          '#type' => 'item',
          '#title' => $this->t('Default value'),
          '#markup' => $this->t('When @earlier has no value, selections for @filter apply only if the default is available at render time.', [
            '@filter' => $filter->label,
            '@earlier' => $dependencies[$id]['default'],
          ]),
        ];
      }
      $form['filters'][$id] += [
        'enabled' => [
          '#type' => 'checkbox',
          '#title' => $this->t('Enable this filter'),
          '#default_value' => $settings['enabled'],
        ],
        'label' => [
          '#type' => 'textfield',
          '#title' => $this->t('Label'),
          '#description' => $this->t('Leave blank to use the default filter label.'),
          '#default_value' => $settings['label'],
        ],
        'description' => [
          '#type' => 'textarea',
          '#title' => $this->t('Description'),
          '#default_value' => $settings['description'],
        ],
      ];
      if ($bundles = $this->bundleChoices($filter)) {
        $form['filters'][$id]['bundles'] = [
          '#type' => 'checkboxes',
          '#title' => $this->t('Allowed bundles'),
          '#description' => $this->t('Leave all unchecked to use the bundles allowed by the contextual filter.'),
          '#options' => $bundles,
          '#default_value' => $settings['bundles'],
        ];
      }
      $form['filters'][$id]['match'] = $filter->supportsAll
        ? [
          '#type' => 'radios',
          '#title' => $this->t('Match'),
          '#options' => [
            'editor' => $this->t('Let the editor choose'),
            'any' => $this->t('Always match any'),
            'all' => $this->t('Always match all'),
          ],
          '#default_value' => $settings['match'],
        ]
        : [
          '#type' => 'item',
          '#title' => $this->t('Match'),
          '#markup' => $this->t('This filter always matches any'),
        ];
      $form['filters'][$id] += [
        'widget' => [
          '#type' => 'select',
          '#title' => $this->t('Widget'),
          '#options' => [
            'autocomplete' => $this->t('Autocomplete'),
            'checkboxes' => $this->t('Checkboxes'),
            'select' => $this->t('Select list'),
          ],
          '#default_value' => $settings['widget'],
        ],
        'max' => [
          '#type' => 'number',
          '#title' => $this->t('Maximum selections'),
          '#default_value' => $settings['max'],
          '#min' => 1,
          '#max' => SiftFilter::MAX_LIMIT,
          '#step' => 1,
          '#required' => TRUE,
        ],
        'required' => [
          '#type' => 'checkbox',
          '#title' => $this->t('Require a selection'),
          '#default_value' => $settings['required'],
        ],
      ];
    }
  }

  /**
   * Finds the first earlier blocker and runtime default for each argument.
   *
   * Checks all contextual filters in display order, including those Sift
   * cannot expose as selectors. Defaults can only be resolved at render time.
   *
   * @phpstan-return array<string, array{blocker: array{label: ArgumentLabel, action: string}|null, default: ArgumentLabel|null}>
   */
  private function argumentDependencies(): array {
    /** @var array<string, array{default_action?: string}> $arguments */
    $arguments = $this->displayHandler->getOption('arguments') ?? [];
    $dependencies = [];
    $blocker = NULL;
    $default = NULL;
    foreach ($arguments as $id => $argument) {
      $dependencies[$id] = ['blocker' => $blocker, 'default' => $default];
      $action = $argument['default_action'] ?? 'ignore';
      if (!ArgumentBuilder::allowsLaterArguments($action, TRUE)) {
        $blocker ??= [
          'label' => $this->displayHandler->getHandler('argument', $id)?->adminLabel(TRUE) ?? $id,
          'action' => $action,
        ];
      }
      elseif (!ArgumentBuilder::allowsLaterArguments($action, FALSE)) {
        $default ??= $this->displayHandler->getHandler('argument', $id)?->adminLabel(TRUE) ?? $id;
      }
    }
    return $dependencies;
  }

  /**
   * Names a blocking action using the labels shown in Views.
   *
   * @see \Drupal\views\Plugin\views\argument\ArgumentPluginBase::defaultActions()
   */
  private function missingActionLabel(string $action): string|TranslatableMarkup {
    return match ($action) {
      'not found' => $this->displayHandler->hasPath() ? $this->t('Show "Page not found"') : $this->t('Hide view'),
      'summary' => $this->t('Display a summary'),
      'empty' => $this->t('Display contents of "No results found"'),
      'access denied' => $this->t('Display "Access Denied"'),
      default => $action,
    };
  }

  /**
   * Checks that each supported filter has an integer maximum in range.
   *
   * Reports invalid maxima on their input before settings can be applied.
   *
   * @param array $form
   *   The Views options form containing the maximum inputs.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The submitted settings and validation state.
   *
   * @phpstan-param array<string, mixed> $form
   */
  public function validateOptionsForm(&$form, FormStateInterface $form_state): void {
    if ($form_state->get('section') !== 'viewsreference_sift') {
      return;
    }

    $filters = $this->filterResolver->supportedFilters($this->displayHandler);
    foreach (array_keys($filters) as $id) {
      if ($this->parseMaximum($form_state->getValue(['filters', $id, 'max'])) === NULL) {
        $form_state->setError($form['filters'][$id]['max'], $this->t('Maximum selections must be a whole number from 1 to @max.', ['@max' => SiftFilter::MAX_LIMIT]));
      }
    }
  }

  /**
   * Stores normalized settings for the display's supported filters.
   *
   * Drops settings for removed or ineligible filters.
   *
   * @param array $form
   *   The Views options form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The submitted Sift settings.
   *
   * @phpstan-param array<string, mixed> $form
   */
  public function submitOptionsForm(&$form, FormStateInterface $form_state): void {
    if ($form_state->get('section') !== 'viewsreference_sift') {
      return;
    }

    $defaults = SiftFilter::DEFAULT_OPTIONS;
    $submitted = $form_state->getValue('filters');
    $settings = [];
    foreach ($this->filterResolver->supportedFilters($this->displayHandler) as $id => $filter) {
      $values = is_array($submitted) && is_array($submitted[$id] ?? NULL) ? $submitted[$id] : [];
      $bundles = is_array($values['bundles'] ?? NULL) ? array_filter($values['bundles'], 'is_string') : [];
      $match = $values['match'] ?? NULL;
      $widget = $values['widget'] ?? NULL;
      $settings[$id] = [
        'enabled' => (bool) ($values['enabled'] ?? $defaults['enabled']),
        'label' => is_string($values['label'] ?? NULL) ? $values['label'] : $defaults['label'],
        'description' => is_string($values['description'] ?? NULL) ? $values['description'] : $defaults['description'],
        'bundles' => array_values(array_intersect(array_keys($this->bundleChoices($filter)), $bundles)),
        'match' => $filter->supportsAll && in_array($match, ['editor', 'any', 'all'], TRUE) ? $match : $defaults['match'],
        'widget' => in_array($widget, ['autocomplete', 'checkboxes', 'select'], TRUE) ? $widget : $defaults['widget'],
        'max' => $this->parseMaximum($values['max'] ?? NULL) ?? $defaults['max'],
        'required' => (bool) ($values['required'] ?? $defaults['required']),
      ];
    }
    $this->options['filters'] = $settings;
  }

  /**
   * Gets stored filter options with defaults for any unset values.
   *
   * @phpstan-return FilterOptions
   */
  private function filterOptions(string $id): array {
    /** @var array<string, FilterOptions> $configured */
    $configured = $this->options['filters'] ?? [];
    return ($configured[$id] ?? []) + SiftFilter::DEFAULT_OPTIONS;
  }

  /**
   * Provides bundle choices when the validator allows narrowing them.
   *
   * @phpstan-return array<string, string>
   */
  private function bundleChoices(SiftFilter $filter): array {
    if (count($filter->bundles) < 2) {
      return [];
    }
    $bundle_info = $this->bundleInfo->getBundleInfo($filter->entityTypeId);
    $choices = [];
    foreach ($filter->bundles as $bundle) {
      if (isset($bundle_info[$bundle]['label'])) {
        $choices[$bundle] = (string) $bundle_info[$bundle]['label'];
      }
    }
    return count($choices) > 1 ? $choices : [];
  }

  /**
   * Reads the submitted maximum as an integer in the supported range.
   */
  private function parseMaximum(mixed $value): ?int {
    if (!is_int($value) && !is_string($value)) {
      return NULL;
    }
    $maximum = filter_var($value, FILTER_VALIDATE_INT, [
      'options' => ['min_range' => 1, 'max_range' => SiftFilter::MAX_LIMIT],
    ]);
    return $maximum !== FALSE ? $maximum : NULL;
  }

}
