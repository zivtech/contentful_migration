# Contentful Migration

Migrate a [Contentful](https://www.contentful.com/) space export into Drupal
using the core Migrate API, with custom process plugins for the transforms YAML
can't express: Rich Text AST → HTML with embedded-entry/asset resolution,
asset → Media (self-contained staging + SHA-256 dedupe), and Contentful
reference → Drupal internal-link rewriting.

This module is a **runtime**, not a fixed content model. Every Contentful space
has a different model, so the module executes per-space migration YAML rather
than shipping one schema. The worked migrations in
[`migrations/examples/`](migrations/examples/) are templates you adapt; the same
YAML can also be produced by companion content-model analysis tooling.

## Status

Early development (`1.0.x-dev`). The migration runtime and the
`contentful:export` Drush command are built and covered by unit and kernel
tests — including a real two-pass Migrate run that resolves embeds and stages
assets to Media. Presentation-mode profiles are on the [roadmap](#roadmap). This
module is not yet covered by Drupal's security advisory policy — use at your own
risk.

## Table of contents

- [Requirements](#requirements)
- [How it works](#how-it-works-hybrid-approach)
- [Embedded entry/asset resolution](#embedded-entryasset-resolution-the-central-problem)
- [Installation](#installation)
- [Configuration](#configuration)
- [Repeatable / delta imports](#repeatable--delta-imports)
- [What's included](#whats-included)
- [Upgrading](#upgrading)
- [Roadmap](#roadmap)
- [Not in scope](#not-in-scope)
- [Maintainers](#maintainers)

## Requirements

- Drupal `^11`
- PHP 8.3+
- Core **Migrate** — the only hard dependency
- `contentful/rich-text ^4.0` (Composer) — the PHP Rich Text AST renderer,
  verified to install conflict-free on Drupal 11.3 / PHP 8.x

Situational dependencies — enable only when your mapping uses them:

| When the source space has…              | Enable                                          |
| --------------------------------------- | ----------------------------------------------- |
| Component types mapped to Paragraphs    | `paragraphs`, `entity_reference_revisions`      |
| Assets mapped to Media                  | core `media`, `image`, `file`                   |
| Config-entity migrations + Drush        | `migrate_plus`, `migrate_tools`                 |
| Location fields                         | `geofield`                                      |

The source plugin extends core `SourcePluginBase` and migrations are discovered
from a `migrations/` directory (core, not `migrate_plus`), so a node-only
migration needs none of the above. See `composer.json` `suggest` and the
comments in `contentful_migration.info.yml`.

## How it works (hybrid approach)

1. **Extract.** `drush contentful:export --space-id=…` wraps the
   `contentful-export` CLI (assets included) and stages the JSON dump + asset
   binaries at a Drupal stream-wrapper location (default `private://contentful`)
   the migration reads. The management token is read from the
   `CONTENTFUL_MANAGEMENT_TOKEN` environment variable, never the process argv.
   (You can also run `contentful-export` by hand.)
2. **Ingest + track.** The `contentful_export` Migrate **source plugin** reads
   that JSON, filters by content type, and flattens per-locale fields
   (no-fallback). Migrate's map tables give you idempotent re-runs and
   `drush migrate:rollback`.
3. **Transform.** Custom **process plugins** handle what YAML can't:
   - `contentful_rich_text` — Rich Text AST → HTML, resolving embedded
     entries/assets to `<drupal-entity-embed>` / `<drupal-media>` tokens via the
     migrate map, and inline `entry-hyperlink` nodes to anchors on the target
     entity's canonical path. Two-pass: entities first, bodies second.
   - `contentful_asset_to_media` + `contentful_media_bundle` — stage an asset
     into a Media entity (MIME → bundle), deduplicating identical bytes by
     SHA-256. Self-contained: it does **not** use `migrate_file_to_media` (a
     Drupal-to-Drupal tool that does not fit external ingestion).
   - `contentful_internal_link` — rewrite a Contentful entry/asset reference to
     an `entity:{type}/{id}` link URI, resolved through the migrate map and
     alias-safe (Drupal resolves the URI at render, so links survive later path
     changes).
   - Multi-value Paragraph references use the idiomatic core
     [#2890844](https://www.drupal.org/project/drupal/issues/2890844) workaround
     (`sub_process` + `migration_lookup` + `extract`) — no custom plugin needed.

The migration targets standard Drupal entities (nodes, Paragraphs, Media,
menus). How you present them — decoupled, recoupled, or semi-decoupled — is a
separate layer (see [Roadmap](#roadmap)); the content migration is identical
across all three.

## Embedded entry/asset resolution (the central problem)

A naive Rich Text migration loses content silently. `contentful/rich-text`'s
default embed renderers emit Contentful-id placeholders (`<div>Entry#ID</div>`) —
visible garbage in Drupal, not empty — its default catch-all silently drops
unknown node types, and the Parser throws on an unrecognised node type.

This module owns the full renderer list: custom `NodeRenderer`s resolve `sys.id`
→ Drupal entity via the migrate map and emit clean embed tokens, ending in a
**logging** catch-all that never silently empties, with graceful degradation on
parse failure. This path — the project's biggest risk — is proven by
`tests/src/Unit/RichText/ContentfulRichTextTest.php` and the end-to-end
`tests/src/Kernel/ContentfulMigrationTest.php`.

### Rendering the embed tokens

Migrated bodies carry `<drupal-media>` and `<drupal-entity-embed>` tokens that
a text format must render: `<drupal-media>` renders via core `media`'s
`media_embed` filter; `<drupal-entity-embed>` requires the contrib
[Entity Embed](https://www.drupal.org/project/entity_embed) filter. Without
one, bodies show raw tokens — on Drupal pages and in JSON:API's
`body.processed` alike. The bundled recipe ships that format:

```sh
drush recipe modules/contrib/contentful_migration/recipes/contentful_embed
```

creates the **`contentful_embed`** text format — `media_embed` plus a
restrictive `filter_html` allow-list covering exactly the markup the migration
emits (narrower than `full_html`, a security improvement). The example
migrations point `body/format` at it, and a kernel test renders a freshly
migrated body through it. Spaces with no embedded-*entry* blocks (most, in the
profiled corpus) are done there: embedded assets render on core media.
Embedded *entries* need contrib `entity_embed` added to the format — the tag
is pre-allowed so tokens survive until then. See
[`recipes/contentful_embed/README.md`](recipes/contentful_embed/README.md).

### Inline hyperlinks

Inline `entry-hyperlink` nodes (a link, inside body text, to another entry)
resolve through the same `embed_migrations` map to an anchor on the target
entity's canonical path, carrying `data-entity-type` / `data-entity-uuid`. The
bare path always resolves on its own; the data attributes let the contrib
[Linkit](https://www.drupal.org/project/linkit) filter rewrite the href
alias-safely **if** it is enabled on the destination text format (and that
format permits those attributes). This is deliberately weaker than the
link-**field** case (`contentful_internal_link`), which Drupal core resolves
alias-safely with no contrib module — a raw body `href` has no equivalent core
mechanism. A target with no canonical URL (e.g. a Paragraph) or an unresolved
target degrades to its plain link text: the words are kept, the dead link
dropped, and the loss logged.

Inline `asset-hyperlink` nodes (a link, inside body text, to a *file*: a PDF,
a download) resolve to the migrated **file's URL** when the `media` and `file`
modules are installed — the author-intended target, not the Media entity's
canonical page (a Drupal-ism that is often access-restricted). Resolution rides
the same `embed_migrations.Asset` candidates as embedded assets, then hops
media → source field → file → URL through the media-source API, so
non-standard source field names work; with a private files scheme the URL is
`/system/files/…`, which keeps `file_download` access control. Without
`media`/`file` — or for an asset that cannot resolve to a local file (not
migrated, since deleted, oEmbed remote video) — the node degrades exactly as
above: link text kept, dead link dropped, loss logged.

## Installation

Install with Composer (this pulls in `contentful/rich-text`):

```sh
composer require 'drupal/contentful_migration:^1.0@dev'
drush en contentful_migration
```

Drop the `@dev` once a tagged release is available. Then enable the situational
modules your mapping needs (see [Requirements](#requirements)).

## Configuration

The module is driven by Migrate YAML — one migration per Contentful content
type — placed in a module's `migrations/` directory (core discovery) or imported
as `migrate_plus` config entities. Start from
[`migrations/examples/`](migrations/examples/), which demonstrates every pattern
the toolkit emits:

- single and multi-value Paragraph references (two-pass, #2890844)
- asset → Media with MIME → bundle mapping
- a two-pass Rich Text body with embed resolution
- a translation pass
- a self-referential type resolved to a Drupal menu

Rich Text embed resolution and internal-link rewriting are configured per
migration via an `embed_migrations` / `link_migrations` map — each keys a
Contentful `linkType` to an ordered list of candidate migrations to resolve
against. See `contentful_blog_post_body.yml` (Pass B) for the embed map.

## Repeatable / delta imports

Migrate's id-map makes re-imports idempotent: re-run `contentful:export` and
`drush migrate:import`, and changed entries re-import onto the **same** Drupal
entities. The stock `track_changes` source option controls re-run cost —
verified on this source plugin by a real kernel migrate run:

```yaml
source:
  plugin: contentful_export
  # …
  track_changes: true          # re-import only rows whose content changed
```

Without `track_changes`, already-imported rows are **skipped even if their
content changed** — set it for any space you intend to re-export.

(`high_water_property` is deliberately not documented here: this source yields
rows in export-file order, not date order, and the high-water interaction with
an unordered iterator is unverified. `track_changes` alone covers the
re-import case; high-water support may follow once it has a test.)

**Deletions do not propagate.** A full export is a snapshot with no deletion
tombstones, and `migrate:import` never deletes destination content — an entry
deleted in Contentful lingers in Drupal until you reconcile it (diff the
migration's id-map source ids against the new export's `sys.id` set, then
`drush migrate:rollback` the missing ids, or remove them by hand). This module
is deliberately not a sync engine.

## What's included

```
src/Plugin/migrate/source/ContentfulExport.php       JSON source: locale flatten, content_type filter
src/Plugin/migrate/process/
  ContentfulRichText.php                             AST → HTML, embed resolution
  ContentfulAssetToMedia.php                         asset → Media, SHA-256 dedupe
  ContentfulMediaBundle.php                          MIME → media bundle
  ContentfulInternalLink.php                         reference → entity: link URI
src/RichText/                                         NodeRenderer impls + sys.id resolver
src/Source/ContentfulEntryFlattener.php              pure locale/field flattener
src/Export/                                           pure export config + summary helpers
src/Drush/Commands/                                   drush contentful:export (stage a space export)
migrations/examples/                                 8 worked migration YAMLs + README
tests/                                               unit + kernel coverage
```

## Upgrading

Behavior changes between releases are cataloged here (and in each release's
notes on drupal.org); none break an API. Per-space migration YAML you authored
is yours — upgrades never rewrite it.

- **1.0.0-beta1** — inline `asset-hyperlink` nodes emit a real `<a href>` to
  the migrated file when the `media` + `file` modules are enabled (previously:
  always plain text — see
  [Inline hyperlinks](#inline-hyperlinks)). A body **re-imported** after
  upgrading gains the file links its earlier import dropped; the plain-text
  degrade remains the modules-absent behavior, so nothing silently loses
  content.
- **1.0.0-beta1** — the example migrations' `body/format` default is now
  `contentful_embed` (was `full_html`), pointing fresh migrations at the
  recipe-shipped format that actually renders their tokens. **New** migrations
  only: per-space YAML you authored on alpha releases keeps `full_html` until
  you adopt the recipe and edit your Pass-B format (a documented two-step in
  [`recipes/contentful_embed/README.md`](recipes/contentful_embed/README.md)).

## Roadmap

- **Presentation-mode profiles** — decoupled (JSON:API / GraphQL / Next.js),
  recoupled (view modes + field formatters against a provided theme), and
  semi-decoupled. First slice: an embed-rendering text-format recipe (see
  [Rendering the embed tokens](#rendering-the-embed-tokens)) and a
  Contentful-editor-interface → Drupal-widget map.
- **Author → user mapping** — an opt-in `contentful:export` step staging the
  space's users for a `migration_lookup`/`static_map` `uid` mapping (the export
  alone carries only opaque author ids; timestamps already migrate — see
  [Not in scope](#not-in-scope)).

Import and rollback aren't wrapped by design: once a space's migrations exist,
`drush migrate:import --execute-dependencies` and `drush migrate:rollback` are
already the right tools.

## Not in scope

Each exclusion below is a deliberate decision, not an omission — with the
evidence it rests on (211 real space exports profiled):

- **Live/bidirectional sync.** One-way migration only.
  [Repeatable / delta imports](#repeatable--delta-imports) cover the re-export →
  re-import case; a live two-way bridge is a different product.
- **Full edit/revision history.** Exports carry only version *counters* — none
  of the 211 profiled exports contain revision snapshots (recovering history
  needs per-entry Management-API calls). Authorship **metadata** is migratable:
  `sys.createdAt`/`updatedAt` map to `created`/`changed` with two core process
  plugins (see `migrations/examples/contentful_blog_post.yml`); author→user
  mapping is on the [roadmap](#roadmap) as an opt-in export step.
- **Roles/permissions.** Role definitions appear in most real exports (154/211)
  — the exclusion is not data availability. Contentful's policy rules do not
  map onto Drupal's permission model, and auto-generating roles risks granting
  more than intended. Model roles deliberately in Drupal.
- **Webhooks, UI extensions, SSO.** Contentful platform config with no safe
  Drupal equivalent: webhooks target Contentful's event model, UI extensions
  are app-framework artifacts, SSO is organization-level configuration.
- **Theme/design-system generation.** Recoupled presentation consumes a
  provided theme; this module ships content, not design.

## Maintainers

- Alex Urevick-Ackelsberg (alex ua)

The current maintainer list is on the
[project page](https://www.drupal.org/project/contentful_migration).
