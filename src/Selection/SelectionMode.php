<?php

declare(strict_types=1);

namespace Drupal\viewsreference_sift\Selection;

/**
 * Determines whether the selection shows or hides matching items.
 *
 * @internal
 */
enum SelectionMode: string {

  case Include = 'include';
  case Exclude = 'exclude';

}
