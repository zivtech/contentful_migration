# Presentation-mode reference patterns

The content migration is identical across all presentation modes — these
directories document the **presentation layer on top**, one per mode:

| Mode | What it is | What's here |
|---|---|---|
| [`decoupled/`](decoupled/) | Drupal as a headless content store | The one core enable step, the embed-token contract for SPA consumers, CORS notes, contrib pointers |
| [`recoupled/`](recoupled/) | Drupal renders the content | The `editorInterfaces` → Drupal widget map + four worked display templates |
| [`semi-decoupled/`](semi-decoupled/) | Drupal shell + JS-hydrated islands | An honest pointer: it's an architecture pattern, not a config artifact |

## The boundary rule

**This directory never gains applied config.** It is reference patterns
forever — the same contract as
[`migrations/examples/`](../../migrations/examples/): templates you read and
adapt, with placeholder bundle/field names that cannot import as-is, and no
backwards-compatibility obligation.

The module ships exactly **one** piece of standing presentation config, and it
lives outside this directory:
[`recipes/contentful_embed/`](../../recipes/contentful_embed/) — earned by a
present correctness need (migrated bodies emit embed tokens that core-alone
does not render) and genuinely space-independent.

Everything else per mode is either *generic and documented here*, or
*per-space and generated* — real view/form displays name real bundles and
fields, which don't exist until your migration defines them. Per-space display
config is the job of companion display-planning tooling (fed by the export's
`editorInterfaces` + `contentTypes` + the
[widget map](recoupled/WIDGET-MAP.md) — the same division of labor as
migration YAML), or of hand-adapting these templates.

If a future request sounds like "just ship my space's display config in the
module," it routes to that tooling — by this written rule, not by habit.
