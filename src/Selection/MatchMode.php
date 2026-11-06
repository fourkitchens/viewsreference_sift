<?php

declare(strict_types=1);

namespace Drupal\viewsreference_sift\Selection;

/**
 * Controls how a contextual filter matches selected terms.
 */
enum MatchMode: string {

  case Any = 'any';
  case All = 'all';

}
