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

Early development (`1.0.x-dev`). The migration runtime is built and covered by
unit and kernel tests — including a real two-pass Migrate run that resolves
embeds and stages assets to Media. Drush commands and presentation-mode profiles
are on the [roadmap](#roadmap). This module is not yet covered by Drupal's
security advisory policy — use at your own risk.

## Table of contents

- [Requirements](#requirements)
- [How it works](#how-it-works-hybrid-approach)
- [Embedded entry/asset resolution](#embedded-entryasset-resolution-the-central-problem)
- [Installation](#installation)
- [Configuration](#configuration)
- [What's included](#whats-included)
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

1. **Extract.** Produce a `contentful-export` JSON dump (and, with
   `--download-assets`, local asset files). A Drush wrapper is on the roadmap;
   today you run `contentful-export` yourself.
2. **Ingest + track.** The `contentful_export` Migrate **source plugin** reads
   that JSON, filters by content type, and flattens per-locale fields
   (no-fallback). Migrate's map tables give you idempotent re-runs and
   `drush migrate:rollback`.
3. **Transform.** Custom **process plugins** handle what YAML can't:
   - `contentful_rich_text` — Rich Text AST → HTML, resolving embedded
     entries/assets to `<drupal-entity-embed>` / `<drupal-media>` tokens via the
     migrate map. Two-pass: entities first, bodies second.
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
migrations/examples/                                 8 worked migration YAMLs + README
tests/                                               unit + kernel coverage
```

## Roadmap

- **`ContentfulMigrateCommands` Drush layer** — `contentful:export` / `:analyze`
  / `:import` / `:rollback`, wrapping `contentful-export --download-assets`.
- **Presentation-mode profiles** — decoupled (JSON:API / GraphQL / Next.js),
  recoupled (view modes + field formatters against a provided theme), and
  semi-decoupled.
- **Inline Rich Text hyperlinks** — `entry-hyperlink` / `asset-hyperlink` nodes
  inside a body; the `contentful_internal_link` process plugin covers the
  link-field case today.
- **Kernel coverage** for `contentful_internal_link`.

## Not in scope

One-way migration only (not live bidirectional sync). Does not generate a design
system (recoupled mode consumes a provided theme). Does not preserve Contentful
authorship/edit history (a source limitation) or migrate Contentful functional
config (webhooks, roles, UI extensions, SSO).

## Maintainers

- Alex Urevick-Ackelsberg (alex ua)

The current maintainer list is on the
[project page](https://www.drupal.org/project/contentful_migration).
