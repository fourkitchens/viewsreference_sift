# Views Reference Sift

Views Reference Sift adds a **Terms** setting to Views Reference fields. Editors
can narrow a shared listing by choosing taxonomy terms in the field widget.
One View display can serve multiple listings without extra fields or displays.

## Requirements

- Drupal 11.3 or later in the Drupal 11 series, or Drupal 12.
- PHP 8.3 or later. Drupal 12 requires PHP 8.5 or later.
- The Views and Taxonomy core modules.
- Views Reference 2.0.0-beta14 or later in the 2.x series.

Run this command from your Drupal site's Composer project root.

```sh
composer require 'fourkitchens/viewsreference_sift:^1.0@beta' \
  'drupal/viewsreference:^2.0.0-beta14'
```

Views Reference 2.x is available as a beta. If the site's root `composer.json`
already sets `"minimum-stability": "beta"`, you can use
`composer require fourkitchens/viewsreference_sift`. Otherwise, the command
above opts in to beta releases for both packages.

## Set up the field

1. Enable the Views Reference Sift module.
2. In the Views Reference field settings, enable **Terms** and leave
   **Argument** unchecked. Both settings write contextual arguments to the
   embedded View.
3. When editing content, select a View and display. **Terms** appears under
   **Options**, alongside **Items per page** when the limit setting is enabled.
   Select terms and choose **Any of these terms** or **All of these terms**.
   Leave the selection empty to use the display's behavior for a missing
   contextual argument.

## Configure the View

Views Reference Sift uses the display's first contextual filter. Add
**Has taxonomy term ID** as that filter and configure it as follows.

- Enable **Allow multiple values**.
- Under **When the filter value is not available**, select
  **Display all results for the specified field**.
- Set the exception value to `all`.
- To limit the autocomplete to specific vocabularies, select the
  **Taxonomy term ID** validator, choose the vocabularies, and set it to accept
  one or more IDs separated by commas or plus signs.
- Enable **Reduce duplicates** if you want a single result for content tagged
  with multiple selected terms in **Any** mode.

You can also use **Has taxonomy term ID (with depth)** as the first contextual
filter. It always matches **Any** selected term. A positive depth also matches
child terms.

AJAX pagers and exposed forms keep the selected terms. A **More** link passes
them to its target page display when that display uses the same contextual
filter.

## Limits

The field stores term IDs in its saved settings. A deleted term stops matching
and drops out of the widget when you reopen the content. When contextual filter
validation is enabled, Views applies its configured validation failure action
if a saved ID no longer exists.
