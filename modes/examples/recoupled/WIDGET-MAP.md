# `editorInterfaces` → Drupal widget map

Contentful exports carry the editor configuration alongside the content model:
`editorInterfaces.controls[]` pairs each field (`fieldId`) with the widget an
editor used (`widgetId`), and `contentTypes` carries each field's type. In the
profiled corpus this data is nearly universal — **211 of 218 real space
exports** include `editorInterfaces` — which makes the Drupal display config
largely *derivable* rather than designed from scratch.

This table is that derivation. Combined with a field's Contentful type, the
`widgetId` maps deterministically to a Drupal field type, form widget, and
view formatter:

| Contentful type | widgetId | → Drupal field type | → Form widget | → View formatter |
|---|---|---|---|---|
| Symbol | `singleLine` | `string` | `string_textfield` | `string` |
| Symbol (enum) | `dropdown` / `radio` | `list_string` | `options_select` / `options_buttons` | `list_default` |
| Symbol (slug) | `slugEditor` | `string` → path alias | (node `path` widget) | (alias — no formatter) |
| Text | `multipleLine` | `string_long` | `string_textarea` | `basic_string` |
| Text | `markdown` | `text_long` (basic_html) | `text_textarea` | `text_default` |
| RichText | `richTextEditor` | `text_long` (format: `contentful_embed`) | `text_textarea` (CKEditor 5; node `body` is `text_with_summary` → `text_textarea_with_summary`) | `text_default` |
| Integer / Number | `numberEditor` | `integer` / `decimal` | `number` | `number_integer` / `number_decimal` |
| Boolean | `boolean` | `boolean` | `boolean_checkbox` | `boolean` |
| Date | `datePicker` | `datetime` | `datetime_default` | `datetime_default` |
| Location | `locationEditor` | `geofield` *(suggest)* | `geofield_latlon` | `geofield_default` |
| Link → Asset | `assetLinkEditor` / `assetLinksEditor` | `entity_reference` → media *(core)* | `media_library_widget` | rendered (`entity_reference_entity_view`) / `media_thumbnail` |
| Link → Entry | `entryLinkEditor` | `entity_reference` → node | `entity_reference_autocomplete` | `entity_reference_label` / rendered (`entity_reference_entity_view`) |
| Array\<Link → Entry\> (component pattern) | `entryLinksEditor` | `entity_reference_revisions` → paragraph *(suggest)* | `paragraphs` | `entity_reference_revisions_entity_view` |
| Array\<Symbol\> | `tagEditor` / `checkbox` | `list_string` (multi) / `string` (multi) | `options_buttons` | `list_default` / `string` |
| Object | `objectEditor` | **no auto-map — human decision** | — | — |

## What the map does NOT cover (the human 20%)

This is the deterministic 80%; two things are deliberately flagged for review
rather than auto-mapped:

- **`Object` fields** (~12% of corpus content types use them): free-form JSON
  with no fixed shape. The right Drupal target ranges from a serialized blob
  to a real field group to "this should have been three fields" — a modeling
  decision, not a mapping.
- **Widget `settings`**: CKEditor toolbar configurations, validation hints,
  help text, and appearance settings on the Contentful side don't transfer
  mechanically. Treat them as requirements input for the Drupal form display,
  not as data to convert.

Contrib targets (`geofield`, `paragraphs`/`entity_reference_revisions`) are
suggests, consistent with the module's migration examples — the map names
them, your space's needs decide whether to install them.

## Using the map

Hand-adapt the worked templates in this directory (placeholder names →
your real bundles/fields, rows from this table), or feed the map plus your
export's `editorInterfaces`/`contentTypes` to companion display-planning
tooling to generate per-space display config — the same workflow split as
migration YAML. The module itself ships no per-space display config either
way (see [the boundary rule](../README.md)).
