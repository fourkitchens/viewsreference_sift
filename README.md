# Views Reference Sift

Views Reference Sift adds a **Sift filters** setting to Views Reference fields.
Editors can narrow a shared listing by selecting entities for its contextual
filters. One View display can serve multiple listings without extra fields
or displays.

## Requirements

- Drupal 11.3 or later in the Drupal 11 series, or Drupal 12.
- PHP 8.3 or later. Drupal 12 requires PHP 8.5 or later.
- The Views core module. Enable Taxonomy to use term filters.
- Views Reference 2.0.0-beta14 or later in the 2.x series.

Drupal 12 support depends on Views Reference providing an attribute class for
setting discovery ([upstream issue #3583101](https://www.drupal.org/project/viewsreference/issues/3583101)).

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
2. In the Views Reference field settings, enable **Sift filters**.
3. When editing content, select a View and display. **Filter this list**
   appears under **Options**, alongside **Items per page** when the limit
   setting is enabled.
   Choose items for each filter and pick a match mode where offered.
   Leave an optional filter empty to let the Argument setting or the display
   supply the value.

### Using Sift with the Argument setting

Enable both **Sift filters** and **Argument** when they fill different
contextual argument positions. Sift's selection wins when both settings fill
the same position. The display's configured default or exception value fills
any gap before the last supplied argument.

## Configure the view

Sift shows one widget per supported and enabled contextual filter in display
order.
The target entity type must use integer IDs.

- **Has taxonomy term ID**, with or without depth.
- **Entity reference field IDs**, such as a field referencing program nodes.
- **Numeric ID arguments** validated as an entity type, such as a user ID.

Set **Administrative title** to give the widget a default label. Otherwise,
Sift uses the label of the single allowed bundle or the entity type's plural
label.

- Enable **Allow multiple values** to select several IDs. Sift offers **All**
  for handlers that support it. Numeric ID and depth filters use **Any**.
- Set the exception value to `all` so gaps can skip that argument. Under
  **When the filter value is not available**, choose
  **Display all results for the specified field** for an unfiltered empty
  state.
- Use an entity validator to limit the allowed bundles. For taxonomy terms,
  choose the vocabularies and let the validator accept multiple IDs when
  the filter allows them.
- For taxonomy filters in **Any** mode, enable **Reduce duplicates** to return
  each matching result once.

A positive taxonomy depth also matches child terms.

AJAX pagers and exposed forms keep the selections. A **More** link passes the
argument values to its target page display when that display uses the same
contextual filters.

### Display settings

Edit the View display and open **Advanced → Other → Sift**. Configure each
supported contextual filter for that display, apply the settings, then save
the View. After updating an existing site, run the pending database updates to
enable the Sift display extender.

| Option | Choices | Default |
| --- | --- | --- |
| Enable this filter (`enabled`) | On or off. Turning it off hides the widget and skips its saved selection. | On |
| Label (`label`) | Override the default widget label. | Blank, use the default filter label. |
| Description (`description`) | Add help text to the editor's filter control. | Blank. A taxonomy depth filter shows its built-in help. |
| Allowed bundles (`bundles`) | Choose a subset when the contextual filter allows multiple specified bundles. | Use the contextual filter's allowed bundles. |
| Match (`match`) | Let the editor choose (`editor`), always match any (`any`), or always match all (`all`). Filters without All support use Any. | `editor`, with Any selected initially. |
| Widget (`widget`) | Autocomplete (`autocomplete`), checkboxes (`checkboxes`), or a select list (`select`). | `autocomplete` |
| Maximum selections (`max`) | A whole number from 1 to 50 for a filter that allows multiple values. A single-value filter accepts one item. | 10 |
| Require a selection (`required`) | On or off. When on, the editor must select at least one item before saving. | Off |

Checkboxes and select lists use autocomplete when more than 100 referenceable
items are available. Taxonomy terms in lists that fit show their hierarchy.

The exported configuration uses contextual filter IDs as keys. This example
configures `tid` on the `embed_1` display of a View.

```yaml
display:
  embed_1:
    display_options:
      display_extenders:
        viewsreference_sift:
          filters:
            tid:
              enabled: true
              label: Topics
              description: Choose topics to show in this listing.
              bundles:
                - tags
              match: any
              widget: checkboxes
              max: 3
              required: false
```

## Limits

Sift applies the display's selection limits when rendering saved or AJAX
selections. It ignores URL values outside Views AJAX requests.

Deleted entities drop out of the widget when you reopen the content. Sift
filters out missing entities and those outside the allowed bundles. If no
selected IDs survive, Views normally handles those IDs using the display's
validation failure action. A filter with a narrower bundle list configured
in Sift skips the selection when no IDs qualify.
