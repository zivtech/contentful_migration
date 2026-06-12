# ADR: Contentful CDA emulation layer

Date: 2026-06-12
Origin: maintainer question (P. Wolanin) — "do you have a way to either
mimic the existing JSON output or quick tools for converting the front end
to use jsonapi output?"

## Status

Accepted

Default outcome is **no-build**; this decision was reached retroactively
after the spike and is gated — see the reopen conditions in Decision. If
those gates pass in the future, update this Status line in place (do not
fork a new file).

## Context

Peter Wolanin asked whether the module could emulate the Contentful Content
Delivery API (CDA) — serving migrated content at the same
`/spaces/{s}/environments/{e}/entries` paths the original Contentful
space used — so that a decoupled front end could keep working against
Drupal unchanged.

The module already answers the decoupled case through a different path:
Drupal JSON:API is the post-migration read surface, the
[decoupled presentation-mode templates](../../modes/examples/decoupled/) cover
the adapter layer, and a three-part conversion chain — numbered 001–003 after
the internal plans that produced it — provides the mechanical scaffolding:

- **001 — identity:** durable per-entity `field_contentful_id` (worked
  pattern in `migrations/examples/contentful_blog_post.yml`)
- **002 — conversion guide:** CDA → JSON:API front-end translation
  (`modes/examples/decoupled/CONVERSION.md`)
- **003 — mapping manifest:** the `contentful:jsonapi-map` generator

This ADR records why a CDA emulation layer is gated at no-build and what a
future go-decision would require.

## Decision

**Chosen path: C — conversion-first** (plans 001–003). No CDA emulation
layer is built now.

Options A and B remain on the table, but only if **all three** of the
following gates pass:

**Gate 1 — Real adopter demand, confirmed in writing.**
Three or more distinct module users must each post a reply on the module's
tracking issue at
`https://www.drupal.org/project/contentful_migration/issues` explicitly
stating one of:
- "My space has zero RichText fields and I accept HTML in asset URLs
  rather than Images-API transforms," or
- "I have reviewed the HTML-not-AST and Images-API caveats in
  `spike/cda-emulation/DECISION.md` and accept them for my use case."

A bare +1 or a me-too reply without that statement does not satisfy this
gate.

**Gate 2 — Plans 001–003 shipped and demonstrably insufficient.**
The conversion path is available to a named, real adopter (not
hypothetical) and has been tried, documented, and found insufficient for
that adopter's specific situation.

**Gate 3 — Maintainer ownership committed.**
A maintainer explicitly commits to owning the CDA-surface compatibility
burden. The CDA is a versioned, evolving API — query operators
(`links_to_entry`, `locale=*`, sync endpoint), response envelope shape,
and asset URL conventions all change. That ownership means tracking
Contentful's API changelog indefinitely, not just shipping the initial
implementation.

**When all gates pass, build B before A** unless the adopter demonstrably
cannot modify their front-end dependency manifest at all — that is the only
case where the Drupal-side option A wins.

## Evidence

### 1. Rich Text is one-way — 14% of spaces carry embedded-entry RichText

The module migrates RichText AST → HTML at migration time
(`src/Plugin/migrate/process/ContentfulRichText.php`; README "Embedded
entry/asset resolution"). Drupal stores HTML; the original AST is not
retained. An emulated `/entries` response would have to place an HTML
string where CDA clients expect an AST object — this breaks every front end
using `@contentful/rich-text-*` renderers (they throw or render nothing on
a non-AST value).

Source: `tools/CORPUS-FINDINGS.md` §"Headline numbers" — 30/218 (14%) of
profiled exports contain RichText **embedded-entry or embedded-asset**
nodes. The 104/218 (48%) figure for spaces with **any RichText field**
(including plain body fields with no embeds) was measured from the raw
corpus by the profiling script on 2026-06-12; `tools/CORPUS-FINDINGS.md`
tracks the embed subset. Either figure confirms the wall is real: the
emulator cannot reproduce the AST contract for any space that uses RichText.

Confirmed by `spike/SPIKE-FINDINGS.md` §"Proven" — the spike verified that
`contentful/rich-text` 4.0.3 processes the real AST from corpus export 059
and that the resolved HTML output is incompatible with an AST-expecting
renderer downstream.

### 2. Assets — 83% of spaces carry media; Images API has no Drupal equivalent

83% of profiled spaces carry assets (measured 2026-06-12 from the corpus of
218 exports). Contentful front ends typically construct image URLs with
query-parameter transforms (`images.ctfassets.net/…?w=&h=&fm=`). Migrated
assets are Drupal-managed files (`ContentfulAssetToMedia`, SHA-256 dedupe).
Drupal's image-handling model uses named styles, not arbitrary parametric
transforms — emulating the Images API would require an on-the-fly derivative
controller with unbounded derivative generation (an unacceptable security and
DoS surface). Even a limited emulator (fixed dimensions only) would need new
d.o security-policy scope.

Source: the 2026-06-12 corpus measurement (see Data Confidence); `tools/CORPUS-FINDINGS.md`
§"Headline numbers" (asset-related embed types confirm widespread asset use).

### 3. No prior art on any backend

Searched 2026-06-12: no CDA-compatible emulation layer exists for Strapi,
Directus, Webiny, or any other Contentful migration target. Every existing
Contentful exit rewrites front-end API calls to the destination's native
surface. Entering this space means owning the full CDA query-operator surface
alone with no reference implementation.

### 4. What an emulator would have available

For completeness: what is available post-migration that a server-side
emulator could use —

- Per-entity `sys.id` (plan 001's `field_contentful_id`)
- Type/field mapping (plan 003's manifest — invertible to original field ids)
- `created`/`changed` timestamps
- Locales as content translations
- Migrate map as a fallback resolver

What is not available: the original AST (converted to HTML), original Images
API URLs (replaced by Drupal file URLs).

### 5. Client-side shim alternative (out of module scope)

A lighter option exists and does not require changes to this module: a thin
JavaScript adapter — an npm package — implementing the `contentful.js` read
surface (`getEntries`, `getEntry`, `getAssets`) and translating those calls
to Drupal JSON:API requests using the plan 003 manifest. This shim would
live in the consumer's repository, not in Drupal contrib. Trade-offs:

- No new Drupal attack surface; no d.o security-team scope
- Independently testable in JavaScript
- Adoptable per-team without a module version bump
- Subject to the same two structural caveats (HTML-not-AST, Images API)
- Separate npm release train; the maintainer of this module is not
  responsible for it

If Gate 1 passes, a client-side shim is the recommended first step before
evaluating a server-side option.

### 6. Server-side architecture reference (if gates ever pass)

The tagged-collector pattern used by
`https://www.drupal.org/project/geo_starter_jsonld` — tagged normalizers per
bundle, CacheableMetadata bubbling — is the right architectural approach for
projecting Drupal entities into a foreign JSON vocabulary with correct
cacheability. Copy the pattern, not the code: the two modules' policies are
opposite (JSON-LD's render-parity rule vs. an API's serve-everything rule),
and a shared d.o dependency would couple independent release trains for
negative value.

## Data Confidence

All corpus measurements were made on 2026-06-12 against 218 valid public
Contentful export files profiled by `tools/profile-corpus.php`. Full
per-file data is in `tools/corpus-profile.json`. Re-verified at beta3
release prep (2026-06-12): an independent profiling run reproduced 218
valid / 104 any-RichText (48%) / 181 with-assets (83%) / 30 embeds (14%)
exactly.

Note for readers of the packaged module: `tools/` and
`spike/SPIKE-FINDINGS.md` are internal development artifacts kept in the
maintainer's working tree, not shipped in the module package (this decision
record is the deliberate exception). The headline figures those files track
are quoted inline in Evidence above.

The reference graph depth and cycle counts in `tools/CORPUS-FINDINGS.md`
are **lower bounds** — the graph is built from schema-level
`linkContentType` validations; unconstrained `Link/Entry` fields create no
edge. The 14%-embeds and 13%-multilingual figures are exact counts from the
schema-level analysis. The 48%/104-of-218 RichText-field figure counts
content types with at least one `type: RichText` field and is exact at the
schema level.

Use the numbers in `tools/CORPUS-FINDINGS.md` §"Headline numbers" as the
canonical reference; if a future profiling run produces different figures,
update that file and note the re-measurement date here.

## Consequences

**Costs of no-build:**

- A "CDA-compatible" checkbox is absent. Competing migration tooling may
  claim it (with the same silent caveats about RichText and Images).
- Adopters with zero RichText fields and no Images-API dependency get no
  benefit from conversion-first that emulation would not also provide — they
  bear the conversion cost unnecessarily. This is the strongest argument for
  reopening if Gate 1 fills with confirmed zero-RichText adopters.
- The occasional inbound inquiry converts to a bounce rather than a landing.

**Benefits of no-build:**

- No permanent API-parity treadmill bolted to a migration runtime. The CDA
  keeps moving; every query-operator change is a maintenance debt event.
- No new public attack surface on module installs that never needed emulation.
- The conversion path (plans 001–003) is mechanical, testable, and scope-stable.
- If the gates ever pass, the decision record is already here — the next
  maintainer does not re-derive the analysis from scratch.
