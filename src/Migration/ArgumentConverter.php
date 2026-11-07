<?php

declare(strict_types=1);

namespace Drupal\viewsreference_sift\Migration;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Utility\Token;
use Drupal\views\Plugin\views\argument\ArgumentPluginBase;
use Drupal\views\ViewEntityInterface;
use Drupal\views\ViewExecutableFactory;
use Drupal\viewsreference_sift\Filter\FilterResolver;
use Drupal\viewsreference_sift\Selection\FilterSelection;
use Drupal\viewsreference_sift\Selection\MatchMode;
use Drupal\viewsreference_sift\Selection\Selection;
use Drupal\viewsreference_sift\Selection\SelectionNormalizer;
use Drupal\viewsreference_sift\Selection\SelectionValidator;

/**
 * Converts literal Views Reference arguments into Sift selections.
 *
 * @internal
 */
final class ArgumentConverter {

  /**
   * Supplies the display metadata and selection checks used by Sift.
   */
  public function __construct(
    private readonly FilterResolver $filterResolver,
    private readonly SelectionNormalizer $normalizer,
    private readonly SelectionValidator $validator,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ViewExecutableFactory $executableFactory,
    private readonly Token $token,
  ) {}

  /**
   * Plans changes to a field item's serialized settings without saving it.
   *
   * @phpstan-return array{data: string|null, messages: list<string>}
   */
  public function convert(FieldItemInterface $item): array {
    $value = $item->getValue();
    $raw = $value['data'] ?? NULL;
    if (!is_string($raw) || $raw === '') {
      return ['data' => NULL, 'messages' => []];
    }
    $data = @unserialize($raw, ['allowed_classes' => FALSE]);
    if (!is_array($data)) {
      return ['data' => NULL, 'messages' => ['The stored field settings could not be read.']];
    }
    // Views Reference also treats the whole string "0" as empty.
    if (empty($data['argument'])) {
      return ['data' => NULL, 'messages' => []];
    }
    if (!is_string($data['argument'])) {
      return ['data' => NULL, 'messages' => ['The stored Argument setting is not text.']];
    }
    $parts = explode('/', $data['argument']);
    if (($empty_position = array_search('', $parts, TRUE)) !== FALSE) {
      return [
        'data' => NULL,
        'messages' => [sprintf('Position %d is empty and cannot be preserved by Sift.', $empty_position + 1)],
      ];
    }

    $stored = $data['sift_terms'] ?? '';
    if ($stored === '' || $stored === []) {
      $stored = ['version' => Selection::VERSION, 'filters' => []];
    }
    if (!is_array($stored) || ($stored['version'] ?? NULL) !== Selection::VERSION || !is_array($stored['filters'] ?? NULL)) {
      return ['data' => NULL, 'messages' => ['Existing legacy or unrecognized Sift selections were preserved.']];
    }

    $view_id = $value['target_id'] ?? NULL;
    $display_id = $value['display_id'] ?? NULL;
    $view_entity = is_string($view_id) && $view_id !== '' ? $this->entityTypeManager->getStorage('view')->load($view_id) : NULL;
    if (!$view_entity instanceof ViewEntityInterface || !is_string($display_id) || !isset($view_entity->get('display')[$display_id])) {
      return ['data' => NULL, 'messages' => ['The referenced View or display is unavailable.']];
    }
    $view = $this->executableFactory->get($view_entity);
    if (!$view->setDisplay($display_id)) {
      return ['data' => NULL, 'messages' => ['The referenced display could not be initialized.']];
    }
    $filters = $this->filterResolver->forView($view, TRUE);
    $by_position = [];
    foreach ($filters as $filter) {
      $by_position[$filter->position] = $filter;
    }
    $handlers = array_values($view->display_handler->getHandlers('argument'));
    $messages = $candidates = $exceptions = [];
    foreach ($parts as $position => $part) {
      $handler = $handlers[$position] ?? NULL;
      $filter = $by_position[$position] ?? NULL;
      if ($this->token->scan($part) !== []) {
        $messages[$position] = 'kept because it contains a token.';
        continue;
      }
      if ($handler instanceof ArgumentPluginBase && $handler->isException($part)) {
        $exceptions[$position] = ($handler->options['default_action'] ?? 'ignore') === 'ignore'
          && empty($handler->options['title_enable'])
          && empty($handler->options['exception']['title_enable']);
        $messages[$position] = 'kept as an exception value.';
        continue;
      }
      if ($filter === NULL || !$filter->enabled) {
        $messages[$position] = $filter === NULL
          ? 'kept because this filter is unsupported by Sift.'
          : 'kept because its Sift filter is disabled.';
        continue;
      }
      $existing = $stored['filters'][$filter->id] ?? NULL;
      if ($existing !== NULL && (!is_array($existing) || ($existing['ids'] ?? NULL) !== [])) {
        $messages[$position] = 'kept because a Sift selection already exists.';
        continue;
      }
      if (str_contains($filter->exceptionValue, '/') || $this->token->scan($filter->exceptionValue) !== []) {
        $messages[$position] = 'kept because its exception cannot be used as a literal placeholder.';
        continue;
      }

      $mode = str_contains($part, ',') ? MatchMode::All : MatchMode::Any;
      $raw_ids = explode($mode === MatchMode::All ? ',' : '+', $part);
      if (count($raw_ids) > 1 && !$filter->multiple) {
        $messages[$position] = 'kept because its Sift filter accepts only one ID.';
        continue;
      }
      if ($filter->idType === 'integer' && array_filter($raw_ids, 'ctype_digit') !== $raw_ids) {
        $messages[$position] = 'kept because its syntax is unsupported.';
        continue;
      }
      $raw_ids = array_unique($raw_ids);
      $ids = $this->normalizer->normalizeIds($raw_ids, $filter);
      if (count($ids) !== count($raw_ids)) {
        $messages[$position] = 'kept because it contains invalid IDs.';
        continue;
      }
      $resolved_mode = $filter->resolveMatch($mode);
      if (($mode === MatchMode::All && !$filter->supportsAll) || (count($ids) > 1 && $resolved_mode !== $mode)) {
        $messages[$position] = 'kept because Sift would change how it matches.';
        continue;
      }
      if ($handler instanceof ArgumentPluginBase && $handler->isException(implode($resolved_mode === MatchMode::All ? ',' : '+', $ids))) {
        $messages[$position] = 'kept because the converted IDs would match the exception value.';
        continue;
      }
      $candidates[$filter->id] = new FilterSelection($ids, $resolved_mode);
    }

    $valid = $this->validator->validate(new Selection($candidates), $filters, strict: TRUE);
    $converted = $converted_positions = [];
    foreach ($candidates as $id => $candidate) {
      $filter = $filters[$id];
      if (($valid->filters[$id]->ids ?? []) !== $candidate->ids) {
        $messages[$filter->position] = 'kept because some IDs are missing, outside the allowed bundles, or over the selection limit.';
        continue;
      }
      $converted[$id] = $candidate;
      $converted_positions[$filter->position] = TRUE;
      $parts[$filter->position] = $filter->exceptionValue;
      $messages[$filter->position] = 'converted to a Sift selection.';
    }

    $serialized = NULL;
    if ($converted !== []) {
      // A later non-ignore missing action can make an exception significant.
      $next = $handlers[count($parts)] ?? NULL;
      $can_trim_exceptions = !$next instanceof ArgumentPluginBase || ($next->options['default_action'] ?? 'ignore') === 'ignore';
      $last_converted = max(array_keys($converted_positions));
      while ($parts !== []) {
        $position = count($parts) - 1;
        // A standalone "0" would no longer run the Argument setting.
        if ($position === 1 && $parts[0] === '0' && !isset($converted_positions[0])) {
          break;
        }
        if (!isset($converted_positions[$position])) {
          if (empty($exceptions[$position]) || (!$can_trim_exceptions && $position > $last_converted)) {
            break;
          }
          $messages[$position] = 'removed because the trailing exception is redundant.';
        }
        array_pop($parts);
      }
      $data['argument'] = $parts === [] ? NULL : implode('/', $parts);
      $migrated = $this->normalizer->toStorage(new Selection($converted));
      $stored['filters'] = array_replace($stored['filters'], $migrated['filters']);
      $data['sift_terms'] = $stored;
      $serialized = serialize($data);
    }

    ksort($messages);
    $report = [];
    foreach ($messages as $position => $message) {
      $report[] = sprintf('Position %d %s', $position + 1, $message);
    }
    return ['data' => $serialized, 'messages' => $report];
  }

}
