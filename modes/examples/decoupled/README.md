# Decoupled mode

Drupal as a headless content store; your existing frontend (often the one that
already consumed Contentful) reads JSON. The migration side is unchanged —
this mode is almost entirely *what you enable*, not *what you build*.

## The one enable step

```sh
drush en jsonapi
```

Core JSON:API exposes every migrated entity at `/jsonapi/node/{bundle}`,
`/jsonapi/media/{bundle}`, etc., with no per-space configuration: resource
shapes follow your content model automatically.

**JSON:API ships read-only by default** (`read_only: true` in
`jsonapi.settings`) — treat that as the floor. Don't enable write mode without
an authentication layer in front of it; a migration content store has no
reason to accept writes.

## The embed-token contract (read this if bodies look wrong)

Migrated Rich Text bodies **store** `<drupal-media>` / `<drupal-entity-embed>`
tokens. JSON:API serves two body properties, and the difference is the filter
pipeline:

- `body.processed` — rendered through the body's text format. Apply
  [`recipes/contentful_embed/`](../../../recipes/contentful_embed/) and
  embedded assets arrive as rendered HTML, ready to display.
- `body.value` — the raw stored markup, tokens included. If your SPA renders
  this, it must parse the tokens itself: `data-entity-uuid` identifies the
  target, `/jsonapi/media/{bundle}?filter[id]={uuid}` fetches it.

Pick one deliberately. Serving `processed` with the recipe applied is the
zero-custom-code path; parsing `value` client-side trades that for full
rendering control.

## CORS — environment config, never exported

Browsers enforce CORS when your frontend origin differs from Drupal's. This is
**environment-specific** (`sites/*/services.yml`) and deliberately not shipped
or exported as config — the same discipline as the management token:

```yaml
# sites/default/services.yml — adjust per environment.
parameters:
  cors.config:
    enabled: true
    allowedOrigins:
      - 'https://your-frontend.example.com'
    allowedMethods: ['GET', 'OPTIONS']
    allowedHeaders: ['Content-Type', 'Accept']
```

Scope `allowedOrigins` to your real frontend origins; resist `*`.

## Contrib, when core JSON:API isn't enough

All optional, all already in the module's `composer.json` suggests, none
configured by this module:

- [`jsonapi_extras`](https://www.drupal.org/project/jsonapi_extras) — alias
  resource names/fields (e.g. keep paths your existing frontend expects).
- [`decoupled_router`](https://www.drupal.org/project/decoupled_router) —
  resolve path aliases to entities for SPA routing.
- [`graphql_compose`](https://www.drupal.org/project/graphql_compose) —
  schema-first GraphQL; the closest DX to Contentful's GraphQL API.
- [`next`](https://www.drupal.org/project/next) — Next.js for Drupal, when
  that's your frontend.
