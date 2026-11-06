# Changelog

This changelog follows [Keep a Changelog 1.1.0][keep-a-changelog].
Views Reference Sift follows [Semantic Versioning][semver].

## [Unreleased]

### Added

- Adds a Drupal Help page with field and display setup instructions.
- Adds Views UI warnings when an earlier contextual filter's missing-value
  action can block a Sift selection and notes when a selection depends on
  a default value.

### Fixed

- Preserves a contextual filter's configured action when its value is
  missing and a later filter has a Sift selection.
- Uses singular wording in the validation error when a Sift filter allows
  at most one item.
- Skips automatic extender enablement during configuration imports and
  leaves individual View settings to the import on uninstall.

### Changed

- Clarifies which Sift values come from the field or browser and why
  contextual filters must never control access.
- The Composer package is now `drupal/viewsreference_sift`.
- Drupal 12 is no longer declared as supported until Views Reference
  supports it.

### Security

- Removes the Views AJAX parent entity lookup to prevent visitors from
  inferring saved selections on unpublished content, draft revisions, or
  private entities.

## [1.0.0-beta3] - 2026-11-06

### Fixed

- Shows required-selection and configured limit instructions on filter inputs.
- Names filters in their widget labels and inline error summary links.

### Changed

- Resolves supported contextual filters with integer entity IDs in display
  order.
- The setting is labeled Sift filters.
- Taxonomy is no longer required.

### Added

- Runs functional and JavaScript tests in CI with ChromeDriver.
- Covers editor selections, Views settings, and AJAX paging in Chrome.
- Adds a Views display extender for configuring contextual filters.
- Enables the Sift display extender on install and update.
- Adds configuration schema for exported Sift display settings.
- Applies display settings to filter widgets, validation, and arguments.
- Adds kernel and browser coverage for display settings and uninstall.
- Per-display settings in the Views UI: filters, labels, bundles, match,
  widget, maximum, required.
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

[keep-a-changelog]: https://keepachangelog.com/en/1.1.0/
[semver]: https://semver.org/spec/v2.0.0.html
[Unreleased]: https://github.com/fourkitchens/viewsreference_sift/compare/1.0.0-beta3...HEAD
[1.0.0-beta3]: https://github.com/fourkitchens/viewsreference_sift/compare/1.0.0-beta2...1.0.0-beta3
[1.0.0-beta2]: https://github.com/fourkitchens/viewsreference_sift/compare/1.0.0-beta1...1.0.0-beta2
[1.0.0-beta1]: https://github.com/fourkitchens/viewsreference_sift/releases/tag/1.0.0-beta1
