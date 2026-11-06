<?php

declare(strict_types=1);

namespace Drupal\viewsreference_sift\Filter;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\views\Plugin\ViewsHandlerManager;
use Drupal\views\Plugin\views\argument\ArgumentPluginBase;
use Drupal\views\Plugin\views\argument\ManyToOne;
use Drupal\views\Plugin\views\display\DisplayPluginBase;
use Drupal\views\ViewEntityInterface;
use Drupal\views\ViewExecutable;
use Drupal\views\ViewExecutableFactory;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Resolves Sift filters from a display's configured contextual arguments.
 */
final class FilterResolver {

  /**
   * Constructs the filter resolver.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly EntityTypeBundleInfoInterface $entityTypeBundleInfo,
    private readonly ViewExecutableFactory $executableFactory,
    #[Autowire(service: 'plugin.manager.views.argument')]
    private readonly ViewsHandlerManager $argumentManager,
  ) {}

  /**
   * Resolves eligible filters for the active display in argument order.
   *
   * @param \Drupal\views\ViewExecutable $view
   *   The view whose active display to resolve.
   * @param bool $include_disabled
   *   Keep disabled filters in place when reading legacy selections.
   *
   * @return \Drupal\viewsreference_sift\Filter\SiftFilter[]
   *   The filters keyed by argument handler ID.
   *
   * @phpstan-return array<string, SiftFilter>
   */
  public function forView(ViewExecutable $view, bool $include_disabled = FALSE): array {
    /** @var \Drupal\views\Plugin\views\display\DisplayPluginBase|null $display */
    $display = $view->display_handler;
    if (!$display) {
      return [];
    }
    /** @var array<string, array<string, mixed>> $settings */
    $settings = $display->getOption('display_extenders')['viewsreference_sift']['filters'] ?? [];
    $filters = $this->resolve($display, $settings);
    return $include_disabled ? $filters : array_filter($filters, static fn (SiftFilter $filter): bool => $filter->enabled);
  }

  /**
   * Lists supported filters for the display being edited in Views.
   *
   * @return \Drupal\viewsreference_sift\Filter\SiftFilter[]
   *   The filters keyed by argument handler ID.
   *
   * @phpstan-return array<string, SiftFilter>
   */
  public function supportedFilters(DisplayPluginBase $display): array {
    return $this->resolve($display, []);
  }

  /**
   * Resolves contextual filters and overlays saved settings.
   *
   * @phpstan-param array<string, array<string, mixed>> $settings
   * @phpstan-return array<string, SiftFilter>
   */
  private function resolve(DisplayPluginBase $display, array $settings): array {
    /** @var array<string, \Drupal\views\Plugin\views\ViewsHandlerInterface> $handlers */
    $handlers = $display->getHandlers('argument');

    $filters = [];
    foreach (array_keys($handlers) as $position => $id) {
      $handler = $handlers[$id];
      if (!$handler instanceof ArgumentPluginBase || !array_key_exists('break_phrase', $handler->options)) {
        continue;
      }

      /** @var array{break_phrase: bool, validate?: array{type?: string}, validate_options?: array{bundles?: string[]}, exception?: array{value?: string}, default_action?: string, admin_label?: string} $options */
      $options = $handler->options;
      $plugin_id = $handler->getPluginId();
      $validator = $options['validate']['type'] ?? '';
      $bundles = [];
      if (str_starts_with($validator, 'entity:')) {
        $entity_type_id = substr($validator, strlen('entity:'));
        $bundles = array_values(array_filter($options['validate_options']['bundles'] ?? []));
      }
      else {
        $entity_type_id = $handler->definition['target_entity_type_id'] ?? match ($plugin_id) {
          'taxonomy_index_tid', 'taxonomy_index_tid_depth' => 'taxonomy_term',
          default => NULL,
        };
      }
      if (!is_string($entity_type_id) || $entity_type_id === '') {
        continue;
      }
      $entity_type = $this->entityTypeManager->getDefinition($entity_type_id, FALSE);
      if (!$entity_type || !$entity_type->entityClassImplements(FieldableEntityInterface::class)) {
        continue;
      }
      $id_key = $entity_type->getKey('id');
      if ($id_key === FALSE || ($this->entityFieldManager->getBaseFieldDefinitions($entity_type_id)[$id_key] ?? NULL)?->getType() !== 'integer') {
        continue;
      }

      $multiple = !empty($options['break_phrase']);
      $handler_class = $this->argumentManager->getDefinition($plugin_id, FALSE)['class'] ?? NULL;
      $label = $options['admin_label'] ?? '';
      if ($label === '' && count($bundles) === 1) {
        $label = $this->entityTypeBundleInfo->getBundleInfo($entity_type_id)[$bundles[0]]['label'] ?? '';
      }
      $configured = ($settings[$id] ?? []) + SiftFilter::DEFAULT_OPTIONS;
      $label = (string) $configured['label'] !== '' ? (string) $configured['label'] : $label;
      $enabled = (bool) $configured['enabled'];
      $validator_bundles = $bundles;
      if (is_array($configured['bundles']) && $configured['bundles'] !== [] && $entity_type->getKey('bundle') !== FALSE) {
        $allowed = $bundles ?: array_keys($this->entityTypeBundleInfo->getBundleInfo($entity_type_id));
        $bundles = array_values(array_intersect($allowed, $configured['bundles']));
        // No intersection must not widen the widget to every bundle.
        $enabled = $enabled && $bundles !== [];
      }
      $match = $configured['match'];
      $widget = $configured['widget'];
      $filters[$id] = new SiftFilter(
        id: $id,
        pluginId: $plugin_id,
        position: $position,
        entityTypeId: $entity_type_id,
        bundles: $bundles,
        multiple: $multiple,
        supportsAll: $multiple && is_string($handler_class) && is_subclass_of($handler_class, ManyToOne::class),
        exceptionValue: $options['exception']['value'] ?? 'all',
        defaultAction: $options['default_action'] ?? 'ignore',
        label: $label !== '' ? $label : $entity_type->getPluralLabel(),
        enabled: $enabled,
        description: (string) $configured['description'],
        matchPolicy: in_array($match, ['editor', 'any', 'all'], TRUE) ? $match : 'editor',
        widget: in_array($widget, ['autocomplete', 'checkboxes', 'select'], TRUE) ? $widget : 'autocomplete',
        max: min(SiftFilter::MAX_LIMIT, max(1, (int) $configured['max'])),
        required: (bool) $configured['required'],
        narrowsBundles: $bundles !== $validator_bundles,
      );
    }
    return $filters;
  }

  /**
   * Resolves eligible filters for a configured view and display.
   *
   * @param string $view_id
   *   The machine name of the view.
   * @param string $display_id
   *   The machine name of the display.
   * @param bool $include_disabled
   *   Keep disabled filters in place when reading legacy selections.
   *
   * @return \Drupal\viewsreference_sift\Filter\SiftFilter[]
   *   The filters keyed by argument handler ID.
   *
   * @phpstan-return array<string, SiftFilter>
   */
  public function forDisplay(string $view_id, string $display_id, bool $include_disabled = FALSE): array {
    if ($view_id === '' || $display_id === '') {
      return [];
    }
    $view_entity = $this->entityTypeManager->getStorage('view')->load($view_id);
    if (!$view_entity instanceof ViewEntityInterface) {
      return [];
    }

    $view = $this->executableFactory->get($view_entity);
    return $view->setDisplay($display_id) ? $this->forView($view, $include_disabled) : [];
  }

}
