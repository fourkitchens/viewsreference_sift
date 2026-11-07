<?php

declare(strict_types=1);

namespace Drupal\viewsreference_sift\Drush\Commands;

use Drupal\viewsreference_sift\Migration\ArgumentMigration;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Provides the optional command for migrating Views Reference arguments.
 *
 * @internal
 */
final class SiftDrushCommands extends DrushCommands {

  use AutowireTrait;

  /**
   * Supplies the field and revision migration service.
   */
  public function __construct(private readonly ArgumentMigration $migration) {
    parent::__construct();
  }

  /**
   * Previews or applies conversion of saved Arguments to Sift selections.
   *
   * @phpstan-param array{entity-type: string|int|null, bundle: string|int|null, field: string|int|null, apply: bool} $options
   */
  #[CLI\Command(name: 'viewsreference-sift:convert-arguments')]
  #[CLI\Option(name: 'entity-type', description: 'Limit the scan to this entity type ID.')]
  #[CLI\Option(name: 'bundle', description: 'Limit the scan to this bundle ID.')]
  #[CLI\Option(name: 'field', description: 'Limit the scan to this field machine name.')]
  #[CLI\Option(name: 'apply', description: 'Save changed entities. Without this flag, only report changes.')]
  public function convertArguments(
    array $options = [
      'entity-type' => self::REQ,
      'bundle' => self::REQ,
      'field' => self::REQ,
      'apply' => FALSE,
    ],
  ): void {
    $output = $this->output();
    if (!$options['apply']) {
      $output->writeln('Dry run. Use --apply to save the reported changes.');
    }
    $entity_type = is_string($options['entity-type']) ? $options['entity-type'] : NULL;
    $bundle = is_string($options['bundle']) ? $options['bundle'] : NULL;
    $field = is_string($options['field']) ? $options['field'] : NULL;
    foreach ($this->migration->run($options['apply'], $entity_type, $bundle, $field) as $line) {
      $output->writeln($line, OutputInterface::OUTPUT_RAW);
    }
  }

}
