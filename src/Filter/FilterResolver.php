<?php

declare(strict_types=1);

namespace Drupal\viewsreference_sift\Filter;

use Drupal\Core\Config\Entity\ConfigEntityTypeInterface;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\Sql\DefaultTableMapping;
use Drupal\Core\Entity\Sql\SqlEntityStorageInterface;
use Drupal\views\Plugin\views\argument\ArgumentPluginBase;
use Drupal\views\Plugin\views\argument\EntityArgument;
use Drupal\views\Plugin\views\argument\ManyToOne;
use Drupal\views\Plugin\views\argument\StringArgument;
use Drupal\views\Plugin\views\display\DisplayPluginBase;
use Drupal\views\Plugin\views\query\Sql;
use Drupal\views\ViewEntityInterface;
use Drupal\views\ViewExecutable;
use Drupal\views\ViewExecutableFactory;
use Drupal\views\ViewsData;

/**
 * Resolves which contextual arguments Sift can expose as entity selectors.
 *
 * @internal
 */
final class FilterResolver {

  /**
   * Supplies Views and entity metadata for checking filter eligibility.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly EntityTypeBundleInfoInterface $entityTypeBundleInfo,
    private readonly ViewExecutableFactory $executableFactory,
    private readonly ViewsData $viewsData,
  ) {}

  /**
   * Resolves eligible filters for the active display in argument order.
   *
   * Applies saved Sift settings so the editor widget and view execution use the
   * same filter definitions.
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
   * Uses the contextual filter definitions so administrators can see eligible
   * filters even when they are disabled in Sift.
   *
   * @return \Drupal\viewsreference_sift\Filter\SiftFilter[]
   *   The filters keyed by argument handler ID.
   *
   * @phpstan-return array<string, SiftFilter>
   */
  public function supportedFilters(DisplayPluginBase $display): array {
    return $this->resolve($display, [], check_exclusion: TRUE);
  }

  /**
   * Resolves contextual filters and overlays saved settings.
   *
   * @phpstan-param array<string, array<string, mixed>> $settings
   * @phpstan-return array<string, SiftFilter>
   */
  private function resolve(DisplayPluginBase $display, array $settings, bool $check_exclusion = FALSE): array {
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
      $base_type_id = $handler->definition['entity_type'] ?? $handler->definition['entity type'] ?? NULL;
      $base_type = is_string($base_type_id) ? $this->entityTypeManager->getDefinition($base_type_id, FALSE) : NULL;
      $field = $handler->definition['entity field'] ?? $handler->realField;
      $bundle_target = $base_type && $field === $base_type->getKey('bundle') ? $base_type->getBundleEntityType() : NULL;
      if ($bundle_target) {
        $entity_type_id = $bundle_target;
      }
      elseif (str_starts_with($validator, 'entity:')) {
        $entity_type_id = substr($validator, strlen('entity:'));
        $bundles = array_values(array_filter($options['validate_options']['bundles'] ?? []));
      }
      else {
        $entity_type_id = $handler->definition['target_entity_type_id'] ?? match ($plugin_id) {
          'taxonomy_index_tid', 'taxonomy_index_tid_depth' => 'taxonomy_term',
          default => NULL,
        };
        // Base field arguments can omit the target metadata, e.g. user roles.
        if ($entity_type_id === NULL && $base_type && is_string($field)) {
          $base_field = $this->entityFieldManager->getBaseFieldDefinitions($base_type->id())[$field] ?? NULL;
          $target = $base_field?->getSetting('target_type');
          if ($base_field?->getType() === 'entity_reference' && is_string($target)
            && $this->entityTypeManager->getDefinition($target, FALSE) instanceof ConfigEntityTypeInterface) {
            $entity_type_id = $target;
          }
        }
      }
      if (!is_string($entity_type_id) || $entity_type_id === '') {
        continue;
      }
      $entity_type = $this->entityTypeManager->getDefinition($entity_type_id, FALSE);
      $id_key = $entity_type?->getKey('id') ?? FALSE;
      $id_type = match (TRUE) {
        $entity_type instanceof ConfigEntityTypeInterface => 'string',
        $id_key !== FALSE && $entity_type?->entityClassImplements(FieldableEntityInterface::class) => ($this->entityFieldManager->getBaseFieldDefinitions($entity_type_id)[$id_key] ?? NULL)?->getType(),
        default => NULL,
      };
      if (!$entity_type || $id_key === FALSE || !in_array($id_type, ['integer', 'string'], TRUE)) {
        continue;
      }
      if ($id_type === 'string') {
        if (!$bundle_target && !isset($handler->definition['target_entity_type_id']) && !$handler instanceof StringArgument && !$handler instanceof ManyToOne) {
          continue;
        }
        // A numeric many-to-one handler would cast these IDs to integers.
        if ($handler instanceof ManyToOne && !empty($handler->definition['numeric'])) {
          continue;
        }
      }

      $multiple = !empty($options['break_phrase']);
      $supports_all = $id_type === 'string'
        ? $handler instanceof ManyToOne || ($handler instanceof StringArgument && !empty($handler->definition['many to one']))
        : is_subclass_of($handler, ManyToOne::class);
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
      $default_widget = $bundle_target ? 'checkboxes' : SiftFilter::DEFAULT_OPTIONS['widget'];
      $widget = $settings[$id]['widget'] ?? $default_widget;
      $allow_exclude = ($configured['allow_exclude'] ?? FALSE) === TRUE;
      $exclusion = $check_exclusion || $allow_exclude ? $this->exclusionTarget($display, $handler, $entity_type_id, $id_type) : NULL;
      $filters[$id] = new SiftFilter(
        id: $id,
        pluginId: $plugin_id,
        position: $position,
        entityTypeId: $entity_type_id,
        bundles: $bundles,
        multiple: $multiple,
        supportsAll: $multiple && $supports_all,
        exceptionValue: $options['exception']['value'] ?? 'all',
        defaultAction: $options['default_action'] ?? 'ignore',
        label: $label !== '' ? $label : $entity_type->getPluralLabel(),
        enabled: $enabled,
        description: (string) $configured['description'],
        matchPolicy: in_array($match, ['editor', 'any', 'all'], TRUE) ? $match : 'editor',
        widget: in_array($widget, ['autocomplete', 'checkboxes', 'select'], TRUE) ? $widget : $default_widget,
        max: min(SiftFilter::MAX_LIMIT, max(1, (int) $configured['max'])),
        required: (bool) $configured['required'],
        narrowsBundles: $bundles !== $validator_bundles,
        idType: $id_type,
        defaultIds: is_array($configured['default_ids']) ? array_values(array_filter($configured['default_ids'], 'is_string')) : [],
        allowExclude: $exclusion !== NULL && $allow_exclude,
        exclusionTarget: $exclusion,
      );
    }
    return $filters;
  }

  /**
   * Resolves only storage whose rows can be matched to the base entity ID.
   */
  private function exclusionTarget(DisplayPluginBase $display, ArgumentPluginBase $handler, string $target_type, string $id_type): ?ExclusionTarget {
    $plugin = $handler->getPluginId();
    $direct = $handler instanceof EntityArgument || in_array($plugin, ['numeric', 'string'], TRUE);
    if ((!$direct && !in_array($plugin, ['taxonomy_index_tid', 'entity_target_id'], TRUE))
      || !in_array($handler->options['relationship'] ?? 'none', ['none', ''], TRUE)
      || !empty($handler->options['not'])
      || !$handler->isException($handler->options['exception']['value'] ?? 'all')) {
      return NULL;
    }

    $view = $display->view;
    $base_table = $view->storage->get('base_table');
    $base_type = $view->getBaseEntityType();
    if (!$base_type instanceof ContentEntityTypeInterface || !is_string($base_table) || $base_table === ''
      || !in_array($base_table, [$base_type->getBaseTable(), $base_type->getDataTable()], TRUE)
      || ($this->viewsData->get($base_table)['table']['base']['database'] ?? 'default') !== 'default'
      || !$display->getPlugin('query') instanceof Sql) {
      return NULL;
    }
    $id_key = $base_type->getKey('id');
    $storage = $this->entityTypeManager->getStorage($base_type->id());
    if ($id_key === FALSE || !$storage instanceof SqlEntityStorageInterface) {
      return NULL;
    }
    $mapping = $storage->getTableMapping();
    if (!$mapping instanceof DefaultTableMapping || !in_array($id_key, $mapping->getAllColumns($base_table), TRUE)) {
      return NULL;
    }
    $base_column = "$base_table.$id_key";

    if ($plugin === 'taxonomy_index_tid') {
      return $base_type->id() === 'node' && $target_type === 'taxonomy_term'
        && $handler->table === 'taxonomy_index' && $handler->realField === 'tid'
        ? new ExclusionTarget($base_column, 'taxonomy_index', 'nid', 'tid')
        : NULL;
    }

    if ($direct) {
      $exact_id = $plugin === 'string'
        ? $id_type === 'string' && empty($handler->options['glossary']) && empty($handler->options['transform_dash']) && ($handler->options['case'] ?? 'none') === 'none'
        : $id_type === 'integer';
      return $exact_id && $target_type === $base_type->id() && $handler->table === $base_table && $handler->realField === $id_key
        ? new ExclusionTarget($base_column, $base_table, $id_key, $id_key)
        : NULL;
    }

    $field_name = $handler->definition['field_name'] ?? $handler->definition['entity field'] ?? NULL;
    $field = is_string($field_name) ? ($this->entityFieldManager->getFieldStorageDefinitions($base_type->id())[$field_name] ?? NULL) : NULL;
    if ($field === NULL || $field->getType() !== 'entity_reference' || $field->getSetting('target_type') !== $target_type) {
      return NULL;
    }
    $column = $mapping->getColumnNames($field->getName())['target_id'] ?? NULL;
    if ($column === NULL || $column !== $handler->realField) {
      return NULL;
    }

    if ($mapping->requiresDedicatedTableStorage($field)) {
      $table = $mapping->getDedicatedDataTableName($field);
      return $handler->table === $base_type->id() . '__' . $field->getName() && ($handler->definition['table'] ?? $handler->table) === $table
        ? new ExclusionTarget($base_column, $table, 'entity_id', $column, TRUE)
        : NULL;
    }

    $table = $handler->table;
    return $mapping->allowsSharedTableStorage($field)
      && in_array($table, [$base_type->getBaseTable(), $base_type->getDataTable()], TRUE)
      && in_array($field->getName(), $mapping->getFieldNames($table), TRUE)
      && in_array($id_key, $mapping->getAllColumns($table), TRUE)
      ? new ExclusionTarget($base_column, $table, $id_key, $column)
      : NULL;
  }

  /**
   * Resolves eligible filters for a configured view and display.
   *
   * Uses the view and display IDs from the field widget, before the view runs.
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
