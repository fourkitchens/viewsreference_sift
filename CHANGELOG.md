# Changelog

## [Unreleased]

### Changed

- Requires Drupal 11.3 or later; Drupal 10 is no longer supported.
- Requires PHP 8.3 or later.
- Requires Views Reference 2.0.0-beta14 or later.
- Added type information to the Terms setting and kernel test helpers.
- Expanded CI to check the lowest tested Drupal 11 release, latest Drupal 11,
  and Drupal 12, with PHPStan and cspell.
- Excluded static analysis, coding standards, and spell-check files from
  package archives.

### Added

- Added level 6 PHPStan analysis for module code and tests.
- Added cspell with a project dictionary.
- Added a kernel test for Terms, Argument, and Limit setting discovery.

## [1.0.0-beta1] - 2026-11-06

### Added

- Terms setting for Views Reference fields.
- Any and All matching for the **Has taxonomy term ID** contextual filter.
- Any matching for the **Has taxonomy term ID (with depth)** contextual filter.
- AJAX pager and exposed form support that preserves selected terms.
