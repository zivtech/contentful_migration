# Semi-decoupled mode

Drupal renders the page shell (routing, SEO surface, editorial preview);
JavaScript "islands" hydrate inside it, consuming JSON:API for the interactive
parts.

**This is an architecture pattern, not a config artifact** — and this README
is deliberately a pointer, not a deliverable. Concretely, semi-decoupled is
the union of pieces documented elsewhere, plus a frontend choice this module
has no opinion on:

1. The [decoupled enable step](../decoupled/README.md) — `drush en jsonapi`,
   read-only, same CORS discipline (the islands fetch from it).
2. The [recoupled display config](../recoupled/README.md) — the shell's
   rendered surfaces are ordinary view displays against your theme.
3. Your island framework and mount points — a theme/frontend decision
   (React/Vue/web components in Twig templates, or a library that formalizes
   the pattern). Nothing here constrains it.

The embed-token contract applies unchanged: shell-rendered bodies use the
[`contentful_embed` format](../../../recipes/contentful_embed/); islands that
fetch raw `body.value` parse tokens per the
[decoupled notes](../decoupled/README.md).

If running a real space through this mode surfaces a concrete, generic,
shippable artifact, it gets promoted into a worked example here — the same
upgrade-trigger discipline the asset-hyperlink renderer followed. Until then,
a third mode shipped "for completeness" would be scope without substance.
