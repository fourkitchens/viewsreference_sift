<?php

declare(strict_types=1);

namespace Drupal\viewsreference_sift\Selection;

/**
 * Names the match modes shared by the editor widget and stored selections.
 */
enum MatchMode: string {

  case Any = 'any';
  case All = 'all';

}
