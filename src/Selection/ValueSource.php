<?php

declare(strict_types=1);

namespace Drupal\viewsreference_sift\Selection;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\RevisionableStorageInterface;
use Drupal\Core\Entity\TranslatableInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\views\ViewExecutable;

/**
 * Identifies which Sift selection may be used during view execution.
 */
final class ValueSource {

  /**
   * Supplies route and entity storage access for Views AJAX recovery.
   */
  public function __construct(
    private readonly RouteMatchInterface $routeMatch,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Reads the selection from the trusted snapshot or a Views AJAX request.
   *
   * A snapshot is authoritative even when empty. On Views AJAX, a matching
   * stored field item takes precedence over the supplied selection. Other
   * requests need a trusted snapshot to supply a selection.
   *
   * @see \Drupal\viewsreference_sift\Hook\SiftHooks::snapshot()
   */
  public function resolve(ViewExecutable $view, mixed $supplied): mixed {
    $trusted = $view->element['#viewsreference_sift']['trusted'] ?? NULL;
    if (is_array($trusted)) {
      return $trusted['data'] ?? NULL;
    }
    if ($this->routeMatch->getRouteName() !== 'views.ajax') {
      return NULL;
    }

    $reference = $view->element['#viewsreference'] ?? NULL;
    $item = is_array($reference) ? $this->loadItem($reference, $view) : NULL;
    if (!$item) {
      return $supplied;
    }

    $stored = $item->getValue()['data'] ?? NULL;
    $data = is_string($stored) && $stored !== '' ? @unserialize($stored, ['allowed_classes' => FALSE]) : NULL;
    return is_array($data) ? ($data['sift_terms'] ?? NULL) : NULL;
  }

  /**
   * Finds the field item associated with this view and display.
   *
   * @phpstan-param array<array-key, mixed> $reference
   */
  private function loadItem(array $reference, ViewExecutable $view): ?FieldItemInterface {
    $entity_type_id = $reference['parent_entity_type'] ?? NULL;
    $entity_id = $reference['parent_entity_id'] ?? NULL;
    $field_name = $reference['parent_field_name'] ?? NULL;
    $delta = $reference['field_item_delta'] ?? NULL;
    if (!is_string($entity_type_id) || !is_string($field_name)
      || (!is_int($entity_id) && !is_string($entity_id)) || $entity_id === ''
      || (!is_int($delta) && !is_string($delta))) {
      return NULL;
    }

    $delta = filter_var($delta, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    $entity_type = $this->entityTypeManager->getDefinition($entity_type_id, FALSE);
    if ($delta === FALSE || !$entity_type?->entityClassImplements(FieldableEntityInterface::class)) {
      return NULL;
    }

    // Selections only narrow a listing whose rows enforce their own access.
    // Checking parent access here would break AJAX paging in previews.
    $storage = $this->entityTypeManager->getStorage($entity_type_id);
    $revision_id = $reference['parent_revision_id'] ?? NULL;
    if ($revision_id !== NULL && $storage instanceof RevisionableStorageInterface) {
      if ((!is_int($revision_id) && !is_string($revision_id)) || $revision_id === '') {
        return NULL;
      }
      $entity = $storage->loadRevision($revision_id);
    }
    else {
      $entity = $storage->load($entity_id);
    }
    if (!$entity instanceof FieldableEntityInterface || (string) $entity->id() !== (string) $entity_id) {
      return NULL;
    }

    $langcode = $reference['parent_entity_langcode'] ?? NULL;
    if (is_string($langcode) && $entity instanceof TranslatableInterface && $entity->hasTranslation($langcode)) {
      $entity = $entity->getTranslation($langcode);
    }
    if (!$entity->hasField($field_name)) {
      return NULL;
    }
    $items = $entity->get($field_name);
    $definition = $items->getFieldDefinition();
    $enabled = $definition->getSetting('enabled_settings');
    if ($definition->getType() !== 'viewsreference' || !is_array($enabled) || !in_array('sift_terms', $enabled, TRUE)) {
      return NULL;
    }

    $item = $items->get($delta);
    $values = $item?->getValue() ?? [];
    if (($values['target_id'] ?? NULL) !== $view->storage->id() || ($values['display_id'] ?? NULL) !== $view->current_display) {
      return NULL;
    }
    return $item;
  }

}
