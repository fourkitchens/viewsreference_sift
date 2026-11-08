<?php

declare(strict_types=1);

namespace Drupal\viewsreference_sift\Widget;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\Field\Plugin\Field\FieldWidget\EntityReferenceAutocompleteTagsWidget;
use Drupal\Core\Field\WidgetInterface;
use Drupal\Core\Field\WidgetPluginManager;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element\Container;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\viewsreference_sift\Filter\SiftFilter;

/**
 * Embeds a field widget without adding a stored field to the host entity.
 *
 * @internal
 */
final class SiftAutocompleteWidget {

  /**
   * Lists installed widgets that support an in-memory reference field.
   *
   * @return array<string, string|\Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Widget labels keyed by plugin ID.
   */
  public static function options(): array {
    $options = ['' => new TranslatableMarkup('Core autocomplete (default)')];
    foreach (self::manager()->getOptions('entity_reference') as $id => $label) {
      $definition = self::manager()->getDefinition($id);
      if (!empty($definition['multiple_values']) && (
        is_a($definition['class'], EntityReferenceAutocompleteTagsWidget::class, TRUE)
        || $id === 'tagify_entity_reference_autocomplete_widget'
        || !empty($definition['viewsreference_sift'])
      )) {
        $options[$id] = $label;
      }
    }
    return $options;
  }

  /**
   * Substitutes the chosen widget when it applies to this filter.
   *
   * @phpstan-param array<string, mixed> $autocomplete
   * @phpstan-param list<int|string> $parents
   * @phpstan-return array<string, mixed>
   */
  public static function wrap(array $autocomplete, SiftFilter $filter, string $plugin_id, FieldableEntityInterface $host, array $parents, bool $has_retained): array {
    // Tagify uses the field name to identify its inputs, including on forms
    // with multiple Views Reference fields, deltas, or contextual filters.
    $name = 'sift_' . hash('sha256', serialize([...$parents, $filter->id]));
    $definition = BaseFieldDefinition::create('entity_reference')
      ->setName($name)
      ->setLabel($autocomplete['#title'])
      ->setDescription($autocomplete['#description'] ?? '')
      ->setTargetEntityTypeId($host->getEntityTypeId())
      ->setTargetBundle($host->bundle())
      ->setCardinality($filter->multiple ? $filter->max : 1)
      ->setRequired(!$has_retained && !empty($autocomplete['#attributes']['aria-required']))
      ->setSettings([
        'target_type' => $filter->entityTypeId,
        'handler' => 'default',
        'handler_settings' => [
          'target_bundles' => $filter->bundles ?: NULL,
          'auto_create' => FALSE,
        ],
      ]);
    $plugin = self::manager()->getDefinition($plugin_id);
    if (!$plugin['class']::isApplicable($definition)) {
      return $autocomplete;
    }

    $defaults = $autocomplete['#default_value'];
    return [
      '#type' => 'container',
      '#title' => $autocomplete['#title'],
      '#tree' => TRUE,
      '#process' => [
        [Container::class, 'processGroup'],
        [Container::class, 'processContainer'],
        [self::class, 'process'],
      ],
      '#sift_widget' => $plugin_id,
      '#sift_definition' => $definition,
      '#sift_host' => $host,
      '#sift_defaults' => array_map(
        static fn ($entity): array => ['target_id' => $entity->id()],
        is_array($defaults) ? $defaults : ($defaults === NULL ? [] : [$defaults]),
      ),
    ] + array_intersect_key($autocomplete, array_flip(['#access', '#disabled', '#states', '#weight']));
  }

  /**
   * Builds the field widget once Form API assigns the input parents.
   *
   * @phpstan-param array<string, mixed> $element
   * @phpstan-return array<string, mixed>
   */
  public static function process(array $element, FormStateInterface $form_state): array {
    $items = self::items($element, $element['#sift_defaults']);
    $element['field'] = self::widget($element)->form($items, $element, $form_state);
    return $element;
  }

  /**
   * Extracts and validates IDs from the widget's submitted value shape.
   *
   * @phpstan-param array<string, mixed> $element
   * @phpstan-return list<int|string>
   */
  public static function extractIds(array $element, FormStateInterface $form_state): array {
    $items = self::items($element, []);
    self::widget($element)->extractFormValues($items, $element, $form_state);
    $values = $items->getValue();
    if ($values === []) {
      return [];
    }
    $ids = array_filter(array_column($values, 'target_id'), static fn (mixed $id): bool => is_int($id) || is_string($id));

    // Native widgets can defer reference validation to entity validation.
    // This field is not stored on the entity, so check its references here.
    $definition = $items->getFieldDefinition();
    $handler = \Drupal::service('plugin.manager.entity_reference_selection')->getSelectionHandler($definition, $items->getEntity());
    $referenceable = $handler->validateReferenceableEntities($ids);
    $entities = \Drupal::entityTypeManager()->getStorage($definition->getSetting('target_type'))->loadMultiple($referenceable);
    $valid = [];
    foreach ($ids as $id) {
      if (isset($entities[$id]) && $entities[$id]->access('view')) {
        $valid[] = $id;
      }
    }
    if (count($valid) !== count($values)) {
      $form_state->setError($element, new TranslatableMarkup('One or more of the selected items are unavailable.'));
    }
    return $valid;
  }

  /**
   * Creates an entity reference item list for the virtual field.
   *
   * @phpstan-param array<string, mixed> $element
   * @phpstan-param list<array{target_id: int|string}> $values
   * @phpstan-return \Drupal\Core\Field\EntityReferenceFieldItemListInterface<\Drupal\Core\Entity\EntityInterface>
   */
  private static function items(array $element, array $values): EntityReferenceFieldItemListInterface {
    $definition = $element['#sift_definition'];
    $items = \Drupal::typedDataManager()->create($definition, $values, $definition->getName(), $element['#sift_host']->getTypedData());
    if (!$items instanceof EntityReferenceFieldItemListInterface) {
      throw new \LogicException('An entity reference widget requires an entity reference item list.');
    }
    return $items;
  }

  /**
   * Creates the configured field widget with its default settings.
   *
   * @phpstan-param array<string, mixed> $element
   */
  private static function widget(array $element): WidgetInterface {
    $widget = self::manager()->getInstance([
      'field_definition' => $element['#sift_definition'],
      'configuration' => ['type' => $element['#sift_widget']],
    ]);
    assert($widget instanceof WidgetInterface);
    return $widget;
  }

  /**
   * Gets the field widget plugin manager.
   */
  private static function manager(): WidgetPluginManager {
    return \Drupal::service('plugin.manager.field.widget');
  }

}
