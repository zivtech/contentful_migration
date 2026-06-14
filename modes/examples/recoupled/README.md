# Recoupled mode

Drupal renders the migrated content: view modes, field formatters, and form
displays against **a theme you provide**. Theme and design-system generation
are explicit non-goals — recoupling targets the provided theme.

This mode has the richest generic/per-space split, and this directory ships
the generic half:

- **[`WIDGET-MAP.md`](WIDGET-MAP.md)** — the `editorInterfaces` → Drupal
  field/widget/formatter derivation (grounded in 211/218 profiled exports
  carrying `editorInterfaces`). This is the load-bearing artifact: the display
  config for any given space is *derived* from data the export already
  contains.
- **Four worked display templates** — reference patterns with
  `EXAMPLE_BUNDLE` placeholder names, one per common display surface:

  | Template | Surface |
  |---|---|
  | `core.entity_form_display.node.EXAMPLE_BUNDLE.default.yml` | The editing form |
  | `core.entity_view_display.node.EXAMPLE_BUNDLE.default.yml` | Full rendered page |
  | `core.entity_view_display.node.EXAMPLE_BUNDLE.teaser.yml` | Listings/cards |
  | `core.entity_view_display.paragraph.EXAMPLE_BUNDLE.default.yml` | A component (Paragraph) inside a page |

  Each component traces back to a WIDGET-MAP row (annotated inline). Like
  `migrations/examples/`, these **cannot import as-is** — the placeholder
  bundle and field names don't exist until your migration defines them.
  Replace the placeholders with your real names and prune to your fields.

## The starting sequence

1. Apply [`recipes/contentful_embed/`](../../../recipes/contentful_embed/) so
   Rich Text bodies render their embed tokens (the one shipped piece of
   standing config — see the recipe README, including the contrib
   `entity_embed` step for embedded *entries*).
2. Derive each bundle's displays: your export's `editorInterfaces` +
   [`WIDGET-MAP.md`](WIDGET-MAP.md), starting from these templates — by hand,
   or via companion display-planning tooling that emits the per-space YAML.
3. Flag the human 20% (Object fields, widget settings) for real decisions —
   see the map's "What the map does NOT cover".

Per-space display config stays out of the module by
[written rule](../README.md), not preference: real displays name real
bundles/fields, and a generator inside the module would be permanent
maintenance surface for per-space output.
