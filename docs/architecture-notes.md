# Rich Text pipeline — design rationale (from the 2026-05 spike)

This is the preserved rationale from an early spike that de-risked the Rich
Text embed-resolution design before the Drupal plugin was written. The
throwaway spike artifacts (the standalone script, its captured output, and the
real-world AST excerpt it ran against) have been removed from the repository;
the concrete third-party identifiers from that export are replaced with
`<entry-id>` / `<asset-id>` placeholders below.

Ran `contentful/rich-text` 4.0.3 standalone (no Drupal) against a real
embedded-entry AST excerpted from a real-world Contentful export. Goal:
de-risk embed resolution before writing the Drupal plugin. PHP 8.x, library
installed clean.

## Proven (the core thesis holds)

```
DEFAULT render:  <div>Entry#<entry-id></div><p></p>
CUSTOM render:   <drupal-entity-embed data-entity-type="paragraph" data-entity-uuid="uuid-0001-callout"></drupal-entity-embed><p></p>
```

Pushing one custom `NodeRenderer` (front priority) **resolves a real embedded
entry to a real Drupal token** via a (stub) sys.id→entity map. The mechanism
works on real AST.

## Findings that change the plugin design

1. **The default failure mode is NOT "silent empty".** The original research
   claimed embed renderers return empty strings. In 4.0.3 they return
   **Contentful-id placeholders**: `EmbeddedEntryBlock` → `<div>Entry#ID</div>`,
   `EmbeddedAssetBlock` → `<div>Asset#ID</div>`, `EntryHyperlink`/
   `AssetHyperlink` → `<a href="#Entry-ID">`. Useless in Drupal, but
   **visible**, not empty. The genuine **silent-empty** path is the default
   `CatchAll` (unknown node types) → `''`.

   > **Correction (2026-06-13):** the CatchAll-returns-`''` observation is about
   > the *render* stage. Unknown node types never reach it —
   > `Parser::parseLocalized()` (contentful/rich-text 4.0.x) **throws
   > `InvalidArgumentException`** on any unmapped `nodeType` at *parse* time.
   > The shipped plugin pre-logs the type, catches the throw, and degrades the
   > whole body (logged, not silent). So "unknown node types → silent drop" is
   > inaccurate; the accurate split is *standard embeds → visible garbage;
   > unknown node type → parse throw → logged whole-body degrade.*

2. **The Parser strictly requires `EntryInterface`/`AssetInterface` from the
   LinkResolver** — a generic `ResourceInterface` stub is rejected with a
   `RuntimeException` ("This should never happen"). BUT those two interfaces are
   **empty markers** over `ResourceInterface`. So a thin id-carrying resource
   that `implements ResourceInterface, EntryInterface, AssetInterface` satisfies
   the check. **No NodeMapper surgery, no full SDK hydration needed** — the
   plugin's resolver just returns a lightweight resource carrying the sys.id.
   (Methods to implement: `getId`, `getType`, `getSystemProperties`, `asLink`,
   `jsonSerialize` — `asLink()` was the one easy-to-miss requirement.)

3. **The custom renderer reads `$node->getEntry()->getId()` = the Contentful
   sys.id** — exactly the migrate-map key. So the Drupal `ContentfulRichText`
   plugin: parse with a sys.id-only LinkResolver, then a custom
   `EmbeddedEntryBlock`/`AssetBlock`/`*Hyperlink` renderer looks `getId()` up in
   the MigrateLookup map and emits `<drupal-media>`/`<drupal-entity-embed>`/
   internal-path.

4. **Renderer ordering gotcha.** `pushNodeRenderer` = front (highest) priority —
   correct for overriding the default embed renderers. A **logging catch-all
   cannot be added by push (too greedy — it intercepts Document/paragraph/text)
   nor by append (the default greedy `CatchAll` precedes it and wins).** To make
   unknown nodes *log instead of silently empty*, the plugin must construct
   `Renderer` with a **fully-owned, explicitly-ordered renderer list** ending in
   the logging catch-all — not push/append onto the defaults.

## Plugin design (locked by the spike)

`ContentfulRichText` process plugin:
- Construct a sys.id-only `LinkResolver` (returns the thin marker-implementing
  resource).
- Construct `Renderer` with an explicit list: custom embed/hyperlink renderers
  (resolve via injected MigrateLookup) → all standard block/inline/mark
  renderers → **logging** catch-all last.
- `transform()`: `parseLocalized($ast, $locale)` → `render()` → return HTML for
  `body/value`.
- Unresolved embeds (sys.id not yet in the map) and unknown node types both
  **log a warning** (visible), never silently vanish.

## Doc corrections this triggered
- Plan §6, module README, analyzer skill: "embeds return empty by default" →
  corrected to the split failure mode above.
