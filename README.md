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
assets to Media. All three presentation modes are documented with worked
templates, plus a shipped embed-rendering recipe (see
[Presentation modes](#presentation-modes)) and opt-in
[author attribution](#author-attribution-opt-in). This module is not yet
covered by Drupal's security advisory policy — use at your own risk.

## Table of contents

- [Requirements](#requirements)
- [How it works](#how-it-works-hybrid-approach)
- [Embedded entry/asset resolution](#embedded-entryasset-resolution-the-central-problem)
- [Installation](#installation)
- [Configuration](#configuration)
- [Presentation modes](#presentation-modes)
- [Repeatable / delta imports](#repeatable--delta-imports)
- [Author attribution (opt-in)](#author-attribution-opt-in)
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
   (You can also run `contentful-export` by hand.) Add `--include-users` to
   also stage the space's members for
   [author attribution](#author-attribution-opt-in).
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
     (`sub_process` + no-stub `migration_lookup` + `skip_on_empty` row guard
     + `extract`) — no custom plugin needed, and unresolved refs never become
     `entity_reference_revisions` NULL writes.

The migration targets standard Drupal entities (nodes, Paragraphs, Media,
menus). How you present them — decoupled, recoupled, or semi-decoupled — is a
separate layer (see [Presentation modes](#presentation-modes)); the content
migration is identical across all three.

## Embedded entry/asset resolution (the central problem)

A naive Rich Text migration loses content silently. `contentful/rich-text`'s
default embed renderers emit Contentful-id placeholders (`<div>Entry#ID</div>`) —
visible garbage in Drupal, not empty. And an unrecognised node type makes the
Parser throw at parse time (`InvalidArgumentException`) — which, uncaught, fails
the whole migration row.

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
- a self-referential type resolved to a Drupal menu with a parent-attachment pass

Rich Text embed resolution and internal-link rewriting are configured per
migration via an `embed_migrations` / `link_migrations` map — each keys a
Contentful `linkType` to an ordered list of candidate migrations to resolve
against. See `contentful_blog_post_body.yml` (Pass B) for the embed map.

### Reference safety

Reference lookups in the examples disable Migrate stubs (`no_stub: true`).
That is intentional. A Contentful reference that did not migrate should be
empty, skipped, or logged by the mapping, not materialized as a half-empty
Drupal entity. Multi-value Paragraph references add a `skip_on_empty` row
guard inside `sub_process`, so a single unresolved child is dropped before
`extract` reads `target_id` / `target_revision_id`.

Self-referential structures use the same rule. The navigation example creates
menu links in Pass A and attaches parents in Pass B with core
`menu_link_parent`; it does not rely on same-migration row order. That keeps
recursive source graphs out of Drupal default-content `_meta.depends` cycle
territory and avoids the `entity_reference_revisions` NULL fatal class exposed
by upstream issue-queue testing.

### Preserving Contentful identity

Map `sys_id` to a plain text field (the worked example uses
`field_contentful_id`) on every destination bundle whose entries a consumer
may need to look up by their original Contentful id — see the PATTERN comment
in `contentful_blog_post.yml`. The migrate map already records sys.id →
entity, but map tables are migration infrastructure: JSON:API never exposes
them and a `migrate:reset` erases them. A field makes the identity durable
content, queryable by any decoupled consumer
(`/jsonapi/node/blog_post?filter[field_contentful_id]=<sys.id>`) — see
[Converting a Contentful front end](modes/examples/decoupled/README.md).

## Presentation modes

The content migration is identical across all three modes — presentation is a
layer on top, and the module ships exactly its generic slice: the
[`contentful_embed` recipe](recipes/contentful_embed/) (the one piece of
standing config, because migrated bodies depend on it — kernel-tested against
a freshly migrated body) and [`modes/examples/`](modes/examples/) (worked
reference templates, the same adapt-these contract as `migrations/examples/`).

- **Decoupled** — Drupal as a headless content store. One core enable step
  (`drush en jsonapi`, read-only by default), the embed-token contract for SPA
  consumers, CORS-as-environment-config notes, a [front-end conversion guide](modes/examples/decoupled/CONVERSION.md),
  and contrib pointers:
  [`modes/examples/decoupled/`](modes/examples/decoupled/).
- **Recoupled** — Drupal renders, against a theme you provide. The
  [`editorInterfaces` → widget map](modes/examples/recoupled/WIDGET-MAP.md)
  derives field/widget/formatter choices from data 211 of 218 profiled exports
  already carry, with four worked display templates:
  [`modes/examples/recoupled/`](modes/examples/recoupled/).
- **Semi-decoupled** — Drupal shell + JS-hydrated islands. An architecture
  pattern, honestly documented as a pointer (the union of the other two plus
  a frontend choice): [`modes/examples/semi-decoupled/`](modes/examples/semi-decoupled/).

Per-space display config (real bundle/field names) is **generated, not
shipped**: derive it from your export's `editorInterfaces` + the widget map —
by hand from the templates, or via companion display-planning tooling, the
same division of labor as migration YAML. The boundary is written down in
[`modes/examples/README.md`](modes/examples/README.md): that directory stays
reference patterns forever, and the module never ships applied per-space
config.

### Mapping manifest for front-end conversion

`drush contentful:jsonapi-map` emits a JSON manifest of every
`contentful_export` migration: Contentful type → JSON:API resource, field id →
JSON:API field name (authoritative when `jsonapi` is installed;
convention-derived otherwise). Hand it to whoever — or whatever — is rewriting
front-end queries; it is the machine-readable companion to the
[front-end conversion guide](modes/examples/decoupled/CONVERSION.md).
Pipelines the extractor cannot classify are marked `"kind": "manual"`.

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

## Author attribution (opt-in)

Entry timestamps migrate out of the box (`sys.createdAt`/`updatedAt` →
`created`/`changed`, two core process plugins). Authors need one more step:
the export JSON carries only **opaque author ids** — bare
`{sys:{linkType:User,id}}` links, present in 204 of 218 profiled exports, with
zero user objects in any of them. Two supported paths, smallest first:

- **`static_map` (no fetch).** Map the handful of author ids you care about to
  existing Drupal accounts on the `sys_created_by/sys/id` nested source key.
  Zero network, zero new users — right for spaces with few authors, or when
  editors already have real Drupal accounts.
- **`--include-users` (CMA fetch).** `drush contentful:export --space-id=…
  --include-users` makes a separate paginated Content Management API call
  (`contentful-export` itself never includes users) and stages each member
  entry-shaped in `users.json`. The
  [`contentful_user` example](migrations/examples/contentful_user.yml) then
  imports them as **blocked stubs** — `status: 0`, no roles, no password:
  attribution targets, never logins — and entry migrations resolve `uid` via
  `migration_lookup` (worked snippet in `contentful_blog_post.yml`).

Handled for you, deterministically, at the export step: duplicate display
names are disambiguated with the member's sys id, and names are clipped to
Drupal's 60-character limit — both hard per-row failures otherwise (user
names are a database-level unique key). Because the staged names depend only
on the CMA data, a regenerated `users.json` is stable and `track_changes`
re-imports never rename members. Stated edges: member **email is an
admin-token-only** CMA attribute (with a non-admin token, stubs import
mail-less — `users.json` holds names and emails, which is why it stays in the
private-by-default export dir); a member whose name collides with an
**existing site user** fails that row loudly (rename the account or
`static_map` that member). Entries whose author **left the space** (id
absent from users.json) fall back to anonymous explicitly; entries with **no
author link at all** (rare — 7 of 211 profiled exports lack `createdBy`)
leave `uid` unset, which Drupal fills with the importing user: anonymous
under a standard `drush migrate:import`, but a logged-in admin running
imports through a UI (e.g. migrate_tools) would own them. Neither path is
ever silently attributed to uid 1. The staged-file → stubs → attribution
chain, including both degrades, is kernel-tested.

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
src/Export/                                           pure export config/summary/users-fetch helpers
src/JsonApiMap/                                       migration → JSON:API manifest extractor (pure)
src/Drush/Commands/                                   drush contentful:export, contentful:jsonapi-map
migrations/examples/                                 9 worked migration YAMLs + README
recipes/contentful_embed/                            text format rendering the embed tokens
modes/examples/                                      presentation-mode templates + WIDGET-MAP
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
- **1.0.0-beta2** — `contentful:export` gains `--include-users`
  ([author attribution](#author-attribution-opt-in)). **Off by default**:
  without the flag, nothing changes — no extra network call, no users.json,
  no new users.
- **1.0.0-beta3** — additive only: new read-only `contentful:jsonapi-map`
  command ([mapping manifest](#mapping-manifest-for-front-end-conversion)) and
  the `field_contentful_id` identity-preservation pattern in the worked
  examples ([preserving Contentful identity](#preserving-contentful-identity)).
  No existing migration, command, or rendering behavior changes.

## Roadmap

Empty by graduation: presentation modes and asset hyperlinks shipped in beta1,
opt-in author → user mapping in beta2, the decoupled conversion toolkit
(identity pattern, conversion guide, `contentful:jsonapi-map`) in beta3.
Nothing further is planned before 1.0.0 — candidates beyond it (e.g.
`high_water_property` support once it has a kernel test of its own) are
tracked in the issue queue, not promised here.

Import and rollback aren't wrapped by design: once a space's migrations exist,
`drush migrate:import --execute-dependencies` and `drush migrate:rollback` are
already the right tools.

## Not in scope

Each exclusion below is a deliberate decision, not an omission — with the
evidence it rests on (218 real space exports profiled):

- **Live/bidirectional sync.** One-way migration only.
  [Repeatable / delta imports](#repeatable--delta-imports) cover the re-export →
  re-import case; a live two-way bridge is a different product.
- **Full edit/revision history.** Exports carry only version *counters* — none
  of the 218 profiled exports contain revision snapshots (recovering history
  needs per-entry Management-API calls). Authorship **metadata** is migratable:
  `sys.createdAt`/`updatedAt` map to `created`/`changed` with two core process
  plugins (see `migrations/examples/contentful_blog_post.yml`), and
  [author attribution](#author-attribution-opt-in) is supported as an opt-in
  export step.
- **Roles/permissions.** Role definitions appear in most real exports (155/218)
  — the exclusion is not data availability. Contentful's policy rules do not
  map onto Drupal's permission model, and auto-generating roles risks granting
  more than intended. Model roles deliberately in Drupal.
- **Webhooks, UI extensions, SSO.** Contentful platform config with no safe
  Drupal equivalent: webhooks target Contentful's event model, UI extensions
  are app-framework artifacts, SSO is organization-level configuration.
- **Theme/design-system generation.** Recoupled presentation consumes a
  provided theme; this module ships content, not design.
- **Contentful CDA emulation.** Evaluated and gated, not forgotten: the
  envelope (`sys` + `fields` + `includes`) is reproducible, but the contract
  (RichText AST, Images API parametric transforms) is not — at least 14% of
  profiled spaces carry embedded-entry RichText that would break unchanged
  front ends. Decision record and reopen-gates:
  [`spike/cda-emulation/DECISION.md`](spike/cda-emulation/DECISION.md).
  Conversion is the supported path (see
  [Presentation modes](#presentation-modes)).

## Maintainers

- Alex Urevick-Ackelsberg (alex ua)

The current maintainer list is on the
[project page](https://www.drupal.org/project/contentful_migration).
