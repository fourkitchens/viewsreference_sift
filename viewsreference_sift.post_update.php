<?php

/**
 * @file
 * Post-update functions for Views Reference Sift.
 */

declare(strict_types=1);

/**
 * Enables the Sift display extender on existing sites.
 */
function viewsreference_sift_post_update_enable_display_extender(): void {
  require_once __DIR__ . '/viewsreference_sift.install';
  _viewsreference_sift_enable_display_extender();
}
