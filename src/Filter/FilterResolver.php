<?php

declare(strict_types=1);

namespace Drupal\viewsreference_sift\Filter;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\views\Plugin\ViewsHandlerManager;
use Drupal\views\Plugin\views\argument\ManyToOne;
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
    private readonly ViewExecutableFactory $executableFactory,
    #[Autowire(service: 'plugin.manager.views.argument')]
    private readonly ViewsHandlerManager $argumentManager,
  ) {}

  /**
   * Resolves eligible filters for the active display in argument order.
   *
   * @return \Drupal\viewsreference_sift\Filter\SiftFilter[]
   *   The filters keyed by argument handler ID.
   *
   * @phpstan-return array<string, SiftFilter>
   */
  public function forView(ViewExecutable $view): array {
    /** @var \Drupal\views\Plugin\views\display\DisplayPluginBase|null $display */
    $display = $view->display_handler;
    /** @var array<string, array{plugin_id?: string, break_phrase?: bool, validate?: array{type?: string}, validate_options?: array{bundles?: string[]}, exception?: array{value?: string}, default_action?: string, admin_label?: string}> $arguments */
    $arguments = $display?->getOption('arguments') ?? [];

    // The proof of concept only supports the first contextual argument.
    $id = array_key_first($arguments);
    if ($id === NULL) {
      return [];
    }
    $argument = $arguments[$id];
    $plugin_id = $argument['plugin_id'] ?? '';
    if (!in_array($plugin_id, ['taxonomy_index_tid', 'taxonomy_index_tid_depth'], TRUE)) {
      return [];
    }

    $entity_type_id = 'taxonomy_term';
    $bundles = ($argument['validate']['type'] ?? NULL) === 'entity:taxonomy_term'
      ? array_values(array_filter($argument['validate_options']['bundles'] ?? []))
      : [];
    $multiple = !empty($argument['break_phrase']);
    $handler_class = $this->argumentManager->getDefinition($plugin_id, FALSE)['class'] ?? '';
    $label = $argument['admin_label'] ?? '';

    $filter = new SiftFilter(
      id: $id,
      pluginId: $plugin_id,
      position: 0,
      entityTypeId: $entity_type_id,
      bundles: $bundles,
      multiple: $multiple,
      supportsAll: $multiple && is_a($handler_class, ManyToOne::class, TRUE),
      exceptionValue: $argument['exception']['value'] ?? 'all',
      defaultAction: $argument['default_action'] ?? 'ignore',
      label: $label !== '' ? $label : $this->entityTypeManager->getDefinition($entity_type_id)->getLabel(),
    );
    return [$id => $filter];
  }

  /**
   * Resolves eligible filters for a configured view and display.
   *
   * @return \Drupal\viewsreference_sift\Filter\SiftFilter[]
   *   The filters keyed by argument handler ID.
   *
   * @phpstan-return array<string, SiftFilter>
   */
  public function forDisplay(string $view_id, string $display_id): array {
    if ($view_id === '' || $display_id === '') {
      return [];
    }
    $view_entity = $this->entityTypeManager->getStorage('view')->load($view_id);
    if (!$view_entity instanceof ViewEntityInterface) {
      return [];
    }

    $view = $this->executableFactory->get($view_entity);
    return $view->setDisplay($display_id) ? $this->forView($view) : [];
  }

}
