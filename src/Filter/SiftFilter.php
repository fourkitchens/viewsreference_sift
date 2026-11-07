<?php

declare(strict_types=1);

namespace Drupal\viewsreference_sift\Filter;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\viewsreference_sift\Selection\MatchMode;

/**
 * Carries the filter settings shared by the field widget and view execution.
 *
 * @internal
 */
final readonly class SiftFilter {

  public const DEFAULT_MAX = 10;
  public const MAX_LIMIT = 50;

  public const DEFAULT_OPTIONS = [
    'enabled' => TRUE,
    'label' => '',
    'description' => '',
    'bundles' => [],
    'match' => 'editor',
    'widget' => 'autocomplete',
    'max' => self::DEFAULT_MAX,
    'required' => FALSE,
  ];

  /**
   * Pairs Views argument metadata with the display's Sift settings.
   *
   * @param string $id
   *   The argument handler ID.
   * @param string $pluginId
   *   The Views argument plugin ID.
   * @param int $position
   *   The zero-based position among the display's arguments.
   * @param string $entityTypeId
   *   The entity type the argument accepts.
   * @param string[] $bundles
   *   The allowed bundle IDs. An empty list allows all bundles.
   * @param bool $multiple
   *   Whether the argument accepts multiple values.
   * @param bool $supportsAll
   *   Whether the argument can require all supplied values to match.
   * @param string $exceptionValue
   *   The argument value that skips this filter.
   * @param string $defaultAction
   *   The Views action to take when the argument is missing.
   * @param string|\Drupal\Core\StringTranslation\TranslatableMarkup $label
   *   The display label for the filter.
   * @param bool $enabled
   *   Whether this filter participates in Sift.
   * @param string $description
   *   The optional help text for the editor.
   * @param string $matchPolicy
   *   The configured match policy.
   * @param string $widget
   *   The configured widget type.
   * @param int $max
   *   The maximum number of selected IDs for a multiple-value filter.
   * @param bool $required
   *   Whether the editor must select a value.
   * @param bool $narrowsBundles
   *   Whether the display settings further restrict the Views bundles.
   *
   * @phpstan-param list<string> $bundles
   */
  public function __construct(
    public string $id,
    public string $pluginId,
    public int $position,
    public string $entityTypeId,
    public array $bundles,
    public bool $multiple,
    public bool $supportsAll,
    public string $exceptionValue,
    public string $defaultAction,
    public string|TranslatableMarkup $label,
    public bool $enabled = TRUE,
    public string $description = '',
    public string $matchPolicy = 'editor',
    public string $widget = 'autocomplete',
    public int $max = self::DEFAULT_MAX,
    public bool $required = FALSE,
    public bool $narrowsBundles = FALSE,
  ) {}

  /**
   * Applies the display's match policy to a selection.
   *
   * An explicit policy takes precedence over the editor's choice. A filter
   * without All support falls back to Any when the policy requires All.
   */
  public function resolveMatch(MatchMode $editor_choice): MatchMode {
    return match ($this->matchPolicy) {
      'any' => MatchMode::Any,
      'all' => $this->supportsAll ? MatchMode::All : MatchMode::Any,
      default => $editor_choice,
    };
  }

}
