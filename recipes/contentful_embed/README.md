# Contentful embed rendering recipe

Migrated Rich Text bodies carry `<drupal-media>` (embedded assets) and
`<drupal-entity-embed>` (embedded entries) tokens that a text format must
render — without one, bodies show raw tokens on Drupal pages and in JSON:API's
`body.processed` alike. This recipe ships that format.

## What it does

Applying it creates the **`contentful_embed`** text format:

- **`media_embed`** (core media) renders `<drupal-media>` tokens — embedded
  *assets* work out of the box, no contrib needed.
- **`filter_html`** with a restrictive allow-list covering exactly the markup
  the module's renderers emit (plus `filter_align`/`filter_caption`). Migrating
  off `full_html` *narrows* allowed markup — a security improvement, not just a
  rendering fix.
- The `<drupal-entity-embed>` tag is **already on the allow-list**, so embedded
  *entry* tokens survive rendering untouched (invisible, not destroyed) until
  you add the contrib filter below.

It installs only core `filter` + `media`. The site owns the format after the
first apply: re-applying leaves an existing `contentful_embed` as-is, and
evolving this recipe in a later release never alters sites that already
applied it.

## Apply it

From the Drupal root (adjust the module path if yours differs):

```sh
drush recipe modules/contrib/contentful_migration/recipes/contentful_embed
drush cr
```

The example migrations already point `body/format` at `contentful_embed`.
For migrations you authored before this recipe existed, the switch is two
steps: apply the recipe, then change your Pass-B `body/format` default and
re-import (or edit the affected nodes' format by hand).

## Embedded entries need one contrib module

`<drupal-entity-embed>` rendering is contrib, not core (disclosed in the
module README). Spaces without embedded-entry blocks — most, in the profiled
corpus — can skip this entirely:

```sh
composer require drupal/entity_embed
drush en entity_embed
```

Then add the **Display embedded entities** filter to the `contentful_embed`
format (`/admin/config/content/formats/manage/contentful_embed`). The tag is
already allowed, so previously migrated bodies start rendering their embedded
entries immediately — no re-import needed.

## Permissions

The recipe deliberately grants nothing: decide which roles may **use** the
format (`/admin/people/permissions`, "Use the Contentful embed text format")
as part of your editorial model. Viewing rendered bodies needs no format
permission.
