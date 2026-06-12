# Converting a Contentful front end to JSON:API

A query-by-query Rosetta Stone. The migration moves your content into Drupal;
this guide moves your front end from `contentful.js` to Drupal's built-in
JSON:API. The machine-readable companion is `drush contentful:jsonapi-map`,
which emits a JSON manifest mapping every Contentful type/field to its
JSON:API resource/field — fields that cannot be classified are marked
`"kind": "manual"` and named in the output for manual attention.

## What stays, what changes

**Stays unchanged**: your framework, your components, your routing logic, and
the content itself — the same entries, the same reference graph, the same
locales are in Drupal after migration.

**Changes mechanically** — table-by-table in this guide: the endpoint base URL,
query parameter syntax, and the response envelope shape. These are repetitive,
predictable rewrites. Hand this guide plus your migration YAML to a
code-assistant and the mechanical layer becomes a transformation task rather
than a research task.

**Changes structurally** — no emulation is possible here, and none is
attempted:

- *Rich Text arrives as HTML, not AST.* The module renders the AST at
  migration time (see
  [Embedded entry/asset resolution](../../../README.md#embedded-entryasset-resolution-the-central-problem)).
  A front end using `@contentful/rich-text-react-renderer` or
  `rich-text-html-renderer` must replace that code path with sanitized-HTML
  rendering. In React that is typically a sanitizer library plus
  `dangerouslySetInnerHTML`; equivalent patterns apply elsewhere.
- *Asset URLs move off `ctfassets.net`.* Files are re-staged into Drupal at
  migration. `uri.url` on the file entity is a root-relative path (see
  [Assets and images](#assets-and-images) below).
- *Contentful Images API transforms have no core equivalent.* Query params
  (`?w=&h=&fm=&q=`) are not reproduced; define Drupal image styles per
  rendition instead.

## Identity map

The migration YAML is the authoritative old-name → new-name record. For every
field, look up its source key in your `migrations/examples/` YAML `process`
block to find the destination field name. For content types:

| Contentful | Drupal / JSON:API |
|---|---|
| `sys.id` | `field_contentful_id` if you mapped it (see [Preserving Contentful identity](../../../README.md#preserving-contentful-identity)) — Drupal's `id`/UUID are **not** `sys.id`s |
| `sys.contentType.sys.id` (e.g. `blogPost`) | resource type (e.g. `node--blog_post`), per your migration's `destination` |
| field id (e.g. `heroImage`) | your migration YAML's `process` key (e.g. `field_hero_image`) |
| `sys.createdAt` / `sys.updatedAt` | `created` / `changed` (migrated by the authorship-timestamps pattern) |
| `sys.locale` | the request's language path prefix (`/de/jsonapi/…`) |

## Query translation

Examples below assume `contentful.js`'s `getEntries(query)` on the CDA side,
and bundle `blog_post` on the JSON:API side. Substitute your actual bundle name
from the migration YAML `destination.plugin` value.

| Contentful CDA | JSON:API |
|---|---|
| `GET /spaces/{s}/environments/{e}/entries?content_type=blogPost` | `GET /jsonapi/node/blog_post` (bundle is in the path — no `content_type` param) |
| `GET …/entries/{sys.id}` | `GET /jsonapi/node/blog_post?filter[field_contentful_id]={sys.id}` → one-element collection; Drupal-native alternative: `/jsonapi/node/blog_post/{uuid}` |
| `fields.slug=my-post` (requires `content_type`) | `filter[field_slug]=my-post` |
| `fields.rating[gte]=3` | `filter[r][condition][path]=field_rating&filter[r][condition][operator]=>=&filter[r][condition][value]=3` (raw query string percent-encodes `>=` as `%3E%3D`; `URLSearchParams` / `fetch` handles this automatically) |
| `fields.tag[in]=a,b` | `filter[t][condition][path]=field_tag&filter[t][condition][operator]=IN&filter[t][condition][value][]=a&filter[t][condition][value][]=b` |
| `order=-sys.createdAt,fields.title` | `sort=-created,title` |
| `limit=20&skip=40` | `page[limit]=20&page[offset]=40` (`OffsetPage::SIZE_MAX = 50` is a hardcoded constant in core — not configurable via `jsonapi.settings`) |
| `include=2` (numeric depth) | `include=field_hero_image.field_media_image,field_sections` (named relationship paths — list what you need; no depth cap in core — `FieldResolver` validates path validity, not depth) |
| `select=fields.title,sys.id` | `fields[node--blog_post]=title,field_contentful_id` (sparse fieldsets) |
| `locale=de-DE` | request `/de/jsonapi/node/blog_post` (language path prefix) |
| `locale=*` (all locales at once) | no equivalent — one request per language |
| `query=full text` | no core equivalent — Search API plus a JSON:API-exposed index is the Drupal answer |
| `links_to_entry={id}` (reverse lookup across all fields) | no direct equivalent — filter on the specific referencing field: `filter[field_related.field_contentful_id]={id}` |

## Response envelope

**CDA**:

```json
{
  "total": 1, "skip": 0, "limit": 100,
  "items": [
    {
      "sys": { "id": "post1", "contentType": { "sys": { "id": "blogPost" } } },
      "fields": {
        "title": "My post",
        "heroImage": { "sys": { "id": "asset1" } }
      }
    }
  ],
  "includes": { "Asset": [ { "sys": { "id": "asset1" } } ] }
}
```

**JSON:API**:

```json
{
  "data": [
    {
      "type": "node--blog_post",
      "id": "<uuid>",
      "attributes": {
        "title": "My post",
        "field_contentful_id": "post1"
      },
      "relationships": {
        "field_hero_image": {
          "data": { "type": "media--image", "id": "<uuid>" }
        }
      }
    }
  ],
  "included": [ ],
  "links": { "next": { "href": "…" } }
}
```

Key differences:

- References live under `relationships` and resolve through `included` (request
  them via `include=field_hero_image.field_media_image`).
- `meta.count` is absent by default — `ResourceType::includeCount()` returns
  `false` unless overridden (e.g. via `jsonapi_extras`). Paginate by following
  `links.next` until it is absent.
- Errors arrive as an `errors` array, not a `sys.type: Error` object.

## Rich text

See the [embed-token contract](README.md) section in this directory's README
for the full picture. The short version: serve `body.processed` with the
[`contentful_embed` recipe](../../../recipes/contentful_embed/) applied —
that is the zero-custom-code path; embedded assets arrive as rendered HTML,
ready to display. Alternatively, parse `body.value` tokens client-side —
`data-entity-uuid` identifies the target,
`/jsonapi/media/{bundle}?filter[id]={uuid}` fetches it.

The hard statement: delete AST-renderer dependencies
(`@contentful/rich-text-react-renderer`, `rich-text-html-renderer`). The
Contentful Rich Text AST does not exist in Drupal — the module renders it to
HTML at migration time. There is no AST to traverse in the JSON:API response;
only HTML strings.

## Assets and images

Asset binaries were re-staged into Drupal at migration (SHA-256 deduped).
The file URL lives on the file entity referenced by the media entity:
request `include=field_hero_image.field_media_image`, then read
`included[file].attributes.uri.url`.

`uri.url` is **root-relative** (e.g. `/sites/default/files/2024-01/image.jpg`);
prefix your Drupal origin to form an absolute URL. This is the value produced
by `ComputedFileUrl::getValue()` via `FileUrlGenerator::generateString()`,
which calls `doGenerateString($uri, true)` — the `true` flag requests a
relative path.

Contentful Images API params (`w`, `h`, `fit`, `fm`, `q`) have no core
equivalent. Define Drupal image styles per rendition and point at the
[`consumer_image_styles`](https://www.drupal.org/project/consumer_image_styles)
contrib module to expose derivative URLs in JSON:API responses.

## Shrinking the diff with jsonapi_extras

Because source rows keep original Contentful field ids and your migration YAML
records the mapping (e.g. `field_hero_image: heroImage/sys/id`), you can
invert that record with
[`jsonapi_extras`](https://www.drupal.org/project/jsonapi_extras): rename the
resource (`node--blog_post` → `blogPost`) and alias fields
(`field_hero_image` → `heroImage`) so JSON:API attribute keys match what the
front end already destructures.

This is per-space configuration you create on your site — the module never
ships it. `drush contentful:jsonapi-map` generates this manifest automatically
from your migration YAML; it is the machine-readable companion to this guide.
The aliases it emits map directly to `jsonapi_extras` resource type and field
override config.

## No equivalent / different model

These Contentful API surfaces have no Drupal equivalent or require a
fundamentally different approach:

- **Sync API (`/sync`)** — no Drupal counterpart; do full re-fetches or
  build-time caching via static site generation.
- **Preview API** — the Drupal answer is content moderation plus a
  decoupled-preview contrib; the
  [`next`](https://www.drupal.org/project/next) module provides this for
  Next.js frontends.
- **GraphQL Content API** — [`graphql_compose`](https://www.drupal.org/project/graphql_compose)
  is the closest DX; a conversion of its own, not covered here.
- **Rate-limit headers (`X-Contentful-RateLimit-*`)** — your infrastructure
  now; Drupal does not impose or advertise per-client rate limits in the same
  way.

## Worked example: blog post by slug with hero image

**Contentful**:

```js
const res = await client.getEntries({
  content_type: 'blogPost',
  'fields.slug': slug,
  include: 1,
});
const post = res.items[0];
const heroUrl = res.includes.Asset.find(
  a => a.sys.id === post.fields.heroImage.sys.id
).fields.file.url;
```

**JSON:API**:

```js
const url =
  `/jsonapi/node/blog_post` +
  `?filter[field_slug]=${slug}` +
  `&include=field_hero_image.field_media_image`;

const res = await fetch(url).then(r => r.json());
const post = res.data[0];

// Resolve an included resource by its relationship reference.
const findIncluded = (type, id) =>
  res.included.find(r => r.type === type && r.id === id);

const mediaRel = post.relationships.field_hero_image.data;
const media = findIncluded(mediaRel.type, mediaRel.id);
const fileRel = media.relationships.field_media_image.data;
const file = findIncluded(fileRel.type, fileRel.id);
// uri.url is root-relative — prefix your Drupal origin.
const heroUrl = `${DRUPAL_BASE_URL}${file.attributes.uri.url}`;
```

The pattern is the same for every relationship: walk `relationships` to the
reference, resolve through `included`, repeat for nested relationships. Hand
this guide plus your migration YAML to a code-assistant and the rewrite is
mechanical.
