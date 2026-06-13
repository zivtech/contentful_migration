# Worked migration examples

Golden output of `contentful-migration-planner`, demonstrating every distinct
pattern the planner emits. **Representative subset, not a full site** — split
across both fixtures because no single export shows everything (`017` has the
hard graph but no embeds/i18n; the synthetic fixture has embeds + 2 locales).

These are reference patterns. The real per-space migration set is skill-generated
from an approved `contentful-mapping.yml`.

## Pattern → file

| Pattern | File | Fixture |
|---|---|---|
| Asset → Media (handoff, MIME→bundle) | `contentful_media.yml` | 017 |
| Leaf Paragraph (`entity_reference_revisions` dest) | `contentful_button.yml` | 017 |
| Nested Paragraph + **multi-ref** (`sub_process`, no-stub lookup, row-skip guard, #2890844) | `contentful_column.yml` | 017 |
| Node + multi-ref sections + **seo→metatag in-host** + path alias | `contentful_page.yml` | 017 |
| Self-referential type → **Drupal menu** Pass A (links, no parent) | `contentful_navigation.yml` | 017 |
| Self-referential type → **Drupal menu** Pass B (parent attachment via `menu_link_parent`) | `contentful_navigation_parent.yml` | 017 |
| **Two-pass** body — Pass A (entities, no body) | `contentful_blog_post.yml` | synthetic |
| **Authorship timestamps** (`created`/`changed` from sys, core plugins) | `contentful_blog_post.yml` | synthetic |
| **Identity preservation** (`sys_id` → `field_contentful_id`, durable JSON:API lookup) | `contentful_blog_post.yml` | synthetic |
| **Author → uid** (opt-in: blocked stubs + `migration_lookup`, or `static_map`) | `contentful_user.yml` + snippet in `contentful_blog_post.yml` | users.json (`--include-users`) |
| **Two-pass** body — Pass B (**embed resolution**) | `contentful_blog_post_body.yml` | synthetic |
| **i18n** translation pass (`translations: true`) | `contentful_blog_post_es.yml` | synthetic |

A Contentful entry has *one* set of sys timestamps across locales, so a
translation pass mapping `created`/`changed` writes the same values as the
base pass — one entry, one history; don't expect per-locale dates.

**Delta re-imports:** every source block here accepts `track_changes: true`
(re-import only changed rows) — a stock core option whose semantics are
kernel-verified on this source plugin (`ContentfulDeltaImportTest`).
`high_water_property` is deliberately omitted: unverified on this unordered
source (see the module README "Repeatable / delta imports", which also covers
the deletion caveat).

`siteSettings` → `config_singleton`: handled by config import, **not** a content
migration (noted in `contentful_navigation.yml`).

## Run order (`drush migrate:import`), leaf-first

017 subtree: `contentful_media` → `contentful_button` (+ other leaf paragraphs)
→ `contentful_column` → section paragraphs → `contentful_page` →
`contentful_navigation` → `contentful_navigation_parent`.

synthetic: `contentful_media` → `contentful_callout_card`/`contentful_hero_section`
→ `contentful_blog_post` (Pass A) → `contentful_blog_post_body` (Pass B) →
`contentful_blog_post_es` (translation).

With author attribution enabled, `contentful_user` runs **before** the entry
migrations that look authors up (it has no dependencies of its own — stubs
first, then everything that attributes to them).

**Rollback** runs in reverse order (`drush migrate:rollback`). Because every
migration tracks `sys.id` as its source key, rollback and re-import are clean.

## Validation

These patterns are exercised end-to-end by the module's kernel tests — a real
two-pass Migrate run over the test fixtures that asserts embed resolution,
asset → Media staging with hash dedupe, internal-link rewriting, and the
author-attribution chain (blocked stubs, departed-member and no-author
degrades). See `tests/src/Kernel/`. The example YAMLs above are the structural
reference those discoverable test migrations are modelled on: acyclic
dependencies, two-pass integrity, multi-ref `sub_process` shape with no-stub
row guards (core #2890844), menu parent attachment via `menu_link_parent`, and
translation-pass shape.
