<?php

namespace Drupal\viewsreference_sift\Plugin\ViewsReferenceSetting;

use Drupal\Component\Plugin\PluginBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\views\ViewExecutable;
use Drupal\views\Views;
use Drupal\viewsreference\Plugin\ViewsReferenceSettingInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Filters an embedded view by taxonomy terms.
 *
 * @ViewsReferenceSetting(
 *   id = "sift_terms",
 *   label = @Translation("Terms"),
 *   default_value = "",
 * )
 */
class ViewsReferenceSiftTerms extends PluginBase implements ViewsReferenceSettingInterface, ContainerFactoryPluginInterface {

  use StringTranslationTrait;

  /**
   * Constructs a ViewsReferenceSiftTerms plugin.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
    );
  }

  /**
   * Gets a display's first contextual filter if it accepts taxonomy term IDs.
   */
  protected function firstTermArgument(string $view_id, string $display_id): ?array {
    $view = Views::getView($view_id);
    return $view && $view->setDisplay($display_id) ? $this->getFirstTermArgument($view) : NULL;
  }

  /**
   * Checks the active display's first contextual filter.
   */
  private function getFirstTermArgument(ViewExecutable $view): ?array {
    $arguments = $view->display_handler?->getOption('arguments') ?? [];
    $argument = reset($arguments);
    return in_array($argument['plugin_id'] ?? NULL, ['taxonomy_index_tid', 'taxonomy_index_tid_depth'], TRUE) ? $argument : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function alterFormField(array &$form_field): void {
    $name_parts = explode('(', $this->configuration['view_name'] ?? '');
    $view_id = rtrim(array_pop($name_parts), ')');
    $display_id = $this->configuration['display_id'] ?? '';
    $argument = $view_id !== '' && $display_id !== '' ? $this->firstTermArgument($view_id, $display_id) : NULL;
    if (!$argument) {
      $form_field['#access'] = FALSE;
      return;
    }

    $value = $form_field['#default_value'] ?? '';
    $term_ids = $this->termIds($value);
    $form_field['#type'] = 'fieldset';
    $form_field['#title'] = $this->t('Terms');
    $form_field['#tree'] = TRUE;
    $form_field['terms'] = [
      '#type' => 'entity_autocomplete',
      '#target_type' => 'taxonomy_term',
      '#tags' => TRUE,
      '#title' => $this->t('Show only content with these terms'),
      '#default_value' => $term_ids ? array_values($this->entityTypeManager->getStorage('taxonomy_term')->loadMultiple($term_ids)) : [],
    ];
    unset($form_field['#default_value']);

    if (($argument['validate']['type'] ?? NULL) === 'entity:taxonomy_term') {
      $bundles = array_filter($argument['validate_options']['bundles'] ?? []);
      if ($bundles) {
        $form_field['terms']['#selection_settings']['target_bundles'] = $bundles;
      }
    }

    if ($argument['plugin_id'] === 'taxonomy_index_tid') {
      $form_field['match'] = [
        '#type' => 'radios',
        '#title' => $this->t('Match'),
        '#options' => [
          'any' => $this->t('Any of these terms'),
          'all' => $this->t('All of these terms'),
        ],
        '#default_value' => ($value['match'] ?? NULL) === 'all' ? 'all' : 'any',
      ];
    }
    else {
      $form_field['#description'] = $this->t('Matches any selected term. A positive depth also includes child terms.');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function alterView(ViewExecutable $view, $value): void {
    $term_ids = $this->termIds($value);
    if (!$term_ids || !($argument = $this->getFirstTermArgument($view))) {
      return;
    }

    $match_all = ($value['match'] ?? NULL) === 'all' && $argument['plugin_id'] === 'taxonomy_index_tid';
    $view->setArguments([implode($match_all ? ',' : '+', $term_ids)]);
  }

  /**
   * Extracts valid term IDs from setting data that can come from an AJAX URL.
   */
  private function termIds(mixed $value): array {
    if (!is_array($value) || !is_array($value['terms'] ?? NULL)) {
      return [];
    }

    $term_ids = [];
    foreach ($value['terms'] as $term) {
      $target_id = is_array($term) ? ($term['target_id'] ?? NULL) : NULL;
      if (is_int($target_id) || is_string($target_id)) {
        $term_id = filter_var($target_id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($term_id !== FALSE) {
          $term_ids[$term_id] = $term_id;
        }
      }
    }
    return array_values($term_ids);
  }

}
