# Changelog

## [Unreleased]

### Changed

- Resolves supported contextual filters with integer entity IDs in display
  order.
- The setting is labeled Sift filters.
- Taxonomy is no longer required.

### Added

- One widget per supported contextual filter.
- Entity reference filters for nodes, users, media and other entity types with
  integer IDs.
- Added kernel coverage for combined filters and references to nodes and users.

## [1.0.0-beta2] - 2026-11-06

### Security

- Ignores Sift values from the query string outside Views AJAX requests.

### Fixed

- Keeps arguments set by other Views Reference settings.
- Clears a hidden selection when the display changes.

### Changed

- Requires Drupal 11.3 or later; Drupal 10 is no longer supported.
- Requires PHP 8.3 or later.
- Requires Views Reference 2.0.0-beta14 or later.
- Added type information to the Terms setting and kernel test helpers.
- Expanded CI to check the lowest tested Drupal 11 release, latest Drupal 11,
  and Drupal 12, with PHPStan and cspell.
- Excluded static analysis, coding standards, and spell-check files from
  package archives.
- Applied the Views Reference setting discovery patch in Drupal 12 CI.
- Documented the Views Reference requirement for Drupal 12 setting discovery.
- Extracted resolution of the first supported Views contextual filter.
- Added selection value objects and normalization for saved term settings.
- Extracted construction of Views arguments from term selections.
- Rewired the Terms setting to resolve filters and normalize widget submissions.
- Stores selections in a versioned format; values saved by 1.0.0-beta1 keep
  working.
- Snapshots the server-set Terms selection before Views Reference runs.
- Reloads the saved selection for Views AJAX when the field item can be found.
- Preserves the display's validation failure action when every capped ID is
  invalid.
- Adds cache tags for selected entities to the rendered View.
- Caps each filter at 10 selections.
- Drops selected IDs that no longer exist or fall outside the allowed bundles.
- Applies validated Sift selections after the other Views Reference settings.
- Fills gaps in the argument list with the display's defaults or exceptions.
- Uses a single-value Terms widget when the filter accepts one argument.
- Uses the first supported filter at any position.

### Added

- Added level 6 PHPStan analysis for module code and tests.
- Added cspell with a project dictionary.
- Added a kernel test for Terms, Argument, and Limit setting discovery.
- Added kernel coverage for forged values, AJAX fallback, and rendered cache
  tags.
- Added unit and kernel coverage for argument positions and the Terms widget.

## [1.0.0-beta1] - 2026-11-06

### Added

- Terms setting for Views Reference fields.
- Any and All matching for the **Has taxonomy term ID** contextual filter.
- Any matching for the **Has taxonomy term ID (with depth)** contextual filter.
- AJAX pager and exposed form support that preserves selected terms.
