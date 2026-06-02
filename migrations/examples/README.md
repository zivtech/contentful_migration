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
| Nested Paragraph + **multi-ref** (`sub_process`, #2890844) | `contentful_column.yml` | 017 |
| Node + multi-ref sections + **seo→metatag in-host** + path alias | `contentful_page.yml` | 017 |
| Self-referential type → **Drupal menu** (cycle resolved natively) | `contentful_navigation.yml` | 017 |
| **Two-pass** body — Pass A (entities, no body) | `contentful_blog_post.yml` | synthetic |
| **Two-pass** body — Pass B (**embed resolution**) | `contentful_blog_post_body.yml` | synthetic |
| **i18n** translation pass (`translations: true`) | `contentful_blog_post_es.yml` | synthetic |

`siteSettings` → `config_singleton`: handled by config import, **not** a content
migration (noted in `contentful_navigation.yml`).

## Run order (`drush migrate:import`), leaf-first

017 subtree: `contentful_media` → `contentful_button` (+ other leaf paragraphs)
→ `contentful_column` → section paragraphs → `contentful_page` →
`contentful_navigation`.

synthetic: `contentful_media` → `contentful_callout_card`/`contentful_hero_section`
→ `contentful_blog_post` (Pass A) → `contentful_blog_post_body` (Pass B) →
`contentful_blog_post_es` (translation).

**Rollback** runs in reverse order (`drush migrate:rollback`). Because every
migration tracks `sys.id` as its source key, rollback and re-import are clean.

## Validation

These patterns are exercised end-to-end by the module's kernel tests — a real
two-pass Migrate run over the test fixtures that asserts embed resolution,
asset → Media staging with hash dedupe, and internal-link rewriting. See
`tests/src/Kernel/`. The example YAMLs above are the structural reference those
discoverable test migrations are modelled on: acyclic dependencies, two-pass
integrity, multi-ref `sub_process` shape (core #2890844), and translation-pass
shape.
