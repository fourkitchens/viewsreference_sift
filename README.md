# Views Reference Sift

Views Reference Sift lets editors select entities to narrow an embedded
View. It is for site builders who want to reuse one listing across several
pages. [Views Reference Field Filter][exposed] reuses exposed filters, while
Sift supplies contextual filter values without requiring an exposed form.

[Repository][repo] · [Issue queue][issues] · [Drupal.org project][project]


## Table of contents

- [Requirements](#requirements)
- [Recommended modules](#recommended-modules)
- [Installation](#installation)
- [Configuration](#configuration)
    - [Field](#field)
    - [View](#view)
    - [Display settings](#display-settings)
    - [Recipes](#recipes)
- [API and stability](#api-and-stability)
- [Troubleshooting](#troubleshooting)
- [FAQ](#faq)
- [Maintainers](#maintainers)


## Requirements

- Drupal 11.3 or later in the Drupal 11 series.
- PHP 8.3 or later.
- Views, which is part of Drupal core.
- [Views Reference Field][viewsreference] 2.0.0-beta14 or later in the 2.x
  series.

Enable Taxonomy if you plan to filter by terms.

Drupal 12 support depends on Views Reference releasing its setting discovery
fix. [Views Reference issue #3583101][drupal-12]


## Recommended modules

- [Views Reference Field Filter][exposed] gives editors controls for a View's
  exposed filters.
- [Inline Form Errors][inline-errors] is a core module that shows an error
  next to the input and links to it from the error summary.


## Installation

1. Once a release is available on [Drupal.org][project], run these commands
   from your Drupal project's Composer root.

   ```sh
   composer require 'drupal/viewsreference_sift:^1.0@beta' \
     'drupal/viewsreference:^2.0.0-beta14'
   drush en viewsreference_sift
   ```

1. Set up the field and View as described below. Sift enables its Views
   display extender during installation. On a site upgrading from an older
   beta, run `drush updb` to enable the extender.

Views Reference 2.x requires beta stability until it has a stable release.
The Composer constraints above allow beta versions for these two packages
without changing the stability setting for the whole site.


## Configuration


### Field

1. Add a **Views Reference** field to an entity bundle, such as an Article
   content type.
1. Edit the field settings. Allow the View and display type you plan to use
   and enable **Sift filters**.
1. Edit a content item and select the View and display. Open **Options** and
   use **Filter this list** to select items. Choose a match mode if offered.
   Save the content item.

You can also enable Views Reference's **Argument** setting. Use it to fill
other contextual filter positions. If both settings fill the same position,
the Sift selection wins.


### View

1. Edit a View display under **Structure → Views**. Add a contextual filter
   that accepts entity IDs. Supported options include **Has taxonomy term
   ID** with or without depth, an entity reference field's target ID, and
   numeric ID filters with an entity validator.
1. Use entity types with integer IDs. Enable **Specify validation criteria**
   and choose an entity validator to restrict the allowed bundles.
1. Set **Administrative title** to label the widget. Without it, Sift uses
   the name of the single allowed bundle or the entity type's plural label.
1. Turn on **Allow multiple values** if editors should select several
   items. **All** is available for filters that support it. Numeric and
   taxonomy depth filters use **Any**.
1. Under **When the filter value is not available**, choose how Views handles
   an empty contextual filter. If a later filter has a Sift selection, Sift
   passes the exception value for **Display all results for the specified
   field**. For **Provide default value**, Sift uses the default when one is
   available. Otherwise, Views stops at the empty filter and handles it as
   it would without Sift.

Sift shows one widget per supported and enabled filter, in display order.
Selections on different filters apply together. For taxonomy filters in
**Any** mode, enable **Reduce duplicates** if the View repeats rows. A
positive taxonomy depth also matches child terms.


### Display settings

Edit a display and open **Advanced → Other → Sift**. Set the options for
each filter, apply the changes, and save the View.

- **Enable this filter** shows the widget and applies the selection. On by
  default.
- **Label** replaces the default widget label. Blank by default.
- **Description** adds help for editors. Blank by default. The taxonomy depth
  filter has built-in help.
- **Allowed bundles** narrows the validator's list when it specifies two or
  more bundles. The default uses all bundles the validator allows.
- **Match** lets the editor choose Any or All by default. You can also
  require Any or All on filters that support both modes.
- **Widget** uses autocomplete by default. You can choose checkboxes or a
  select list. Both switch to autocomplete above 100 referenceable items.
- **Maximum selections** defaults to 10. Set it from 1 to 50 for a filter
  that accepts multiple items. A single-value filter accepts one item.
- **Require a selection** asks the editor to choose at least one item before
  saving. Off by default.

Sift warns when the action for an earlier empty contextual filter can prevent
a later Sift selection from applying. The warning names the earlier filter
and its configured action. The display summary flags enabled filters with
these warnings. If an earlier filter provides a default, a note explains that
the later selection applies only when the default is available at render time.


### Recipes

#### Show related content

In the contextual filter, choose **Provide default value** and a source
from the current page. For example, use the current page's taxonomy term.
Leave the Sift selection empty when editing the listing so Views supplies
the default value.

#### Name the selection in the heading

In the contextual filter settings, enable **Override title** under **When
the filter value is available or a default is provided**. Use
`{{ arguments.<filter id> }}` in the title. For example, enter
`More about {{ arguments.tid }}` for the `tid` filter. Find the ID under
**Replacement patterns**. Enable **Include View Title** in the Views
Reference field settings and select it on the field item.

#### Add a See all link

Create a Page display with the same contextual filters in the same order
as the embedded display. On the embedded display, set **Link display** to
that page and enable **Use more link**. Set the link text to **See all**.
Views passes the current arguments to the Page display.

#### Ship display settings in configuration

Save the View and export its configuration. For a recipe, include the
exported View file in the recipe's `config` directory and install Sift as
part of the recipe. Keep the complete View export. This excerpt shows the
Sift settings for contextual filter `tid` on display `embed_1`.

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


## API and stability

These contracts are stable across the 1.x series.

- The Views Reference setting plugin ID `sift_terms`.
- The stored selection format described below.
- The display extender ID `viewsreference_sift` and its configuration schema.
  [Configuration schema](config/schema/viewsreference_sift.schema.yml)

A saved selection uses version `2`, with `filters` keyed by contextual filter
ID. Each filter has a list of positive integer `ids` and a `match` value of
`any` or `all`. Views Reference serializes the containing `data` array on the
field item. This example shows the decoded data.

```yaml
data:
  sift_terms:
    version: 2
    filters:
      tid:
        ids: [12, 34]
        match: any
```

All PHP classes and services are internal and may change in minor releases.


## Troubleshooting

- **Filter this list is missing.** Check that the field enables **Sift
  filters** and that the chosen display has a supported contextual filter
  enabled in Sift.
- **Sift is missing in the Views UI.** Run `drush updb` after upgrading from
  an earlier beta. Open **Advanced → Other** on the display.
- **A selection is missing.** Reopen the content item and check whether the
  entity still exists and is in an allowed bundle. Sift filters out IDs
  outside the allowed bundles. If all selected IDs fail the View's
  validation, Views uses its configured failure action. If the Sift bundle
  list is narrower and no IDs qualify, Sift skips the selection.
- **The selection disappears while paging.** Enable **Use AJAX** on the View
  display. Sift carries the current selection through AJAX paging.


## FAQ

**Why use contextual filters?** Site builders can reuse a View with different
saved selections on each embed. Visitors can use exposed filters separately
if the View provides them.

**Does it work with Layout Builder blocks and Paragraphs?** Yes. Use a Views
Reference field on the block content or paragraph entity. The same setup
works on other entities that have a Views Reference field.

**Does Sift trust values from URLs?** On normal page requests, Sift uses only
the selection saved on the field and ignores Sift values in the query string.

On Views AJAX requests, the values come from the browser. Sift caps the
selection and checks that IDs exist and belong to allowed bundles. Visitors
can change the values. Drupal core already lets visitors change contextual
filter values on AJAX-enabled displays. Never use contextual filters,
including Sift's, for access control.

Views Reference applies `?viewsreference[...]` overrides on every request, so
visitors can turn off Sift for their own page view. Drupal core varies the
cache by URL for displays with contextual filters, so the override is confined
to requests for that URL.


## Maintainers

[Four Kitchens][four-kitchens] maintains this project. Use the [issue
queue][issues] for bugs and feature requests. See [CONTRIBUTING.md] for
development and [SECURITY.md] for private reports.

[repo]: https://github.com/fourkitchens/viewsreference_sift
[issues]: https://github.com/fourkitchens/viewsreference_sift/issues
[project]: https://www.drupal.org/project/viewsreference_sift
[exposed]: https://www.drupal.org/project/viewsreference_filter
[viewsreference]: https://www.drupal.org/project/viewsreference
[inline-errors]: https://www.drupal.org/docs/8/core/modules/inline-form-errors
[drupal-12]: https://www.drupal.org/project/viewsreference/issues/3583101
[four-kitchens]: https://github.com/fourkitchens
[CONTRIBUTING.md]: CONTRIBUTING.md
[SECURITY.md]: SECURITY.md
