<?php

declare(strict_types=1);

namespace Drupal\viewsreference_sift\Migration;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionableStorageInterface;
use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Converts saved Arguments in batches of default entity revisions.
 *
 * @internal
 */
final class ArgumentMigration {

  private const BATCH_SIZE = 100;

  /**
   * Supplies field discovery, conversion, and revision metadata.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly ArgumentConverter $converter,
    private readonly TimeInterface $time,
    private readonly AccountProxyInterface $account,
  ) {}

  /**
   * Reports each conversion and saves only when explicitly requested.
   *
   * @return \Generator<int, string>
   *   Lines of the migration report, produced as the scan runs.
   */
  public function run(bool $apply = FALSE, ?string $entity_type = NULL, ?string $bundle = NULL, ?string $field = NULL): \Generator {
    $groups = [];
    foreach ($this->fields($entity_type, $bundle, $field) as [$type, $bundle_id, $name, $sift_enabled]) {
      if (!$sift_enabled) {
        yield "$type.$bundle_id.$name: enable Sift filters before converting arguments.";
        continue;
      }
      $groups[$type][$bundle_id][] = $name;
    }
    if ($groups === []) {
      yield 'No fields enable both Argument and Sift filters in this scan.';
      return;
    }
    foreach ($groups as $type => $bundles) {
      foreach ($bundles as $bundle_id => $names) {
        foreach ($this->scan($type, (string) $bundle_id, $names, $apply) as $line) {
          yield $line;
        }
      }
    }
  }

  /**
   * Finds Views Reference fields that enable the Argument setting.
   *
   * @phpstan-return \Generator<int, array{string, string, string, bool}>
   */
  private function fields(?string $entity_type, ?string $bundle, ?string $field): \Generator {
    /** @var array<string, array<string, array{bundles: array<string, string>}>> $map */
    $map = $this->entityFieldManager->getFieldMapByFieldType('viewsreference');
    foreach ($map as $type => $fields) {
      if ($entity_type !== NULL && $entity_type !== $type) {
        continue;
      }
      foreach ($fields as $name => $info) {
        if ($field !== NULL && $field !== $name) {
          continue;
        }
        foreach ($info['bundles'] as $bundle_id) {
          if ($bundle !== NULL && $bundle !== $bundle_id) {
            continue;
          }
          $definition = $this->entityFieldManager->getFieldDefinitions($type, $bundle_id)[$name] ?? NULL;
          $settings = $definition?->getSetting('enabled_settings');
          if (is_array($settings) && in_array('argument', $settings, TRUE)) {
            yield [$type, $bundle_id, $name, in_array('sift_terms', $settings, TRUE)];
          }
        }
      }
    }
  }

  /**
   * Walks a bundle in ID order without loading the entire result set.
   *
   * @phpstan-param list<string> $fields
   * @phpstan-return \Generator<int, string>
   */
  private function scan(string $type, string $bundle, array $fields, bool $apply): \Generator {
    $definition = $this->entityTypeManager->getDefinition($type);
    $id_key = $definition->getKey('id');
    if ($id_key === FALSE) {
      return;
    }
    $bundle_key = $definition->getKey('bundle');
    $storage = $this->entityTypeManager->getStorage($type);
    $last_id = NULL;
    do {
      $query = $storage->getQuery()->accessCheck(FALSE)->sort($id_key)->range(0, self::BATCH_SIZE);
      if ($bundle_key !== FALSE) {
        $query->condition($bundle_key, $bundle);
      }
      if ($last_id !== NULL) {
        $query->condition($id_key, $last_id, '>');
      }
      $has_field = $query->orConditionGroup();
      foreach ($fields as $field) {
        $has_field->exists($field);
      }
      $ids = $query->condition($has_field)->execute();
      foreach ($storage->loadMultiple($ids) as $entity) {
        if (!$entity instanceof ContentEntityInterface) {
          continue;
        }
        $context = "$type:$bundle:" . $entity->id();
        if ($definition->isRevisionable()) {
          if (!$storage instanceof RevisionableStorageInterface) {
            yield "$context: skipped because the latest revision could not be checked.";
            continue;
          }
          if (!$entity->isDefaultRevision() || (string) $storage->getLatestRevisionId($entity->id()) !== (string) $entity->getRevisionId()) {
            yield "$context: skipped because it has a newer pending revision.";
            continue;
          }
        }
        yield from $this->convertEntity($entity, $fields, $context, $apply);
      }
      $last_id = end($ids);
    } while (count($ids) === self::BATCH_SIZE);
  }

  /**
   * Converts all selected fields and translations in a single entity save.
   *
   * @phpstan-param list<string> $fields
   * @phpstan-return \Generator<int, string>
   */
  private function convertEntity(ContentEntityInterface $entity, array $fields, string $context, bool $apply): \Generator {
    $changed = FALSE;
    $report = [];
    foreach (array_keys($entity->getTranslationLanguages()) as $langcode) {
      $translation = $entity->getTranslation($langcode);
      foreach ($fields as $field) {
        $items = $translation->get($field);
        if (!$translation->isDefaultTranslation() && !$items->getFieldDefinition()->isTranslatable()) {
          continue;
        }
        foreach ($items as $delta => $item) {
          $result = $this->converter->convert($item);
          $data = $result['data'];
          $status = $data === NULL ? 'Unchanged' : ($apply ? 'Applied' : 'Dry run');
          $message = $result['messages'] === [] ? 'No saved Argument value.' : implode(' ', $result['messages']);
          $report[] = "$status: $context $langcode {$field}[$delta]: $message";
          if ($apply && $data !== NULL) {
            $item->set('data', $data);
            $changed = TRUE;
          }
        }
      }
    }
    if ($changed) {
      if ($entity->getEntityType()->isRevisionable()) {
        $entity->setNewRevision(TRUE);
        if ($entity instanceof RevisionLogInterface) {
          $entity->setRevisionCreationTime($this->time->getCurrentTime());
          $entity->setRevisionUserId((int) $this->account->id());
          $entity->setRevisionLogMessage('Converted Views Reference arguments to Sift selections.');
        }
      }
      $entity->save();
    }
    yield from $report;
  }

}
