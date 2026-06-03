<?php

declare(strict_types=1);

namespace Drupal\contentful_migration\Source;

/**
 * Flattens one raw contentful-export entry/asset into a migrate source row.
 *
 * Pure, stateless, Drupal-free — so it is unit-testable without a migration
 * bootstrap. ContentfulExport (the migrate source plugin) wraps it; the logic
 * lives here so the load-bearing behaviours can be asserted directly against a
 * fixture row.
 *
 * Scope is deliberately narrow (idiomatic-Migrate decision, 2026-05-30): the
 * flattener does locale resolution and exposes `sys_id` / `content_type`. It
 * does NOT rewrite reference values — single Links and arrays of Links pass
 * through in their raw `{sys:{id:…}}` shape so the migration YAML can resolve
 * them with stock `migration_lookup` + `extract` inside a `sub_process`
 * (using nested source keys like `heroImage/sys/id` or `sys/id`). This keeps
 * reference resolution in declarative config rather than custom source-side
 * pre-computation.
 *
 * Behaviours:
 *  - Locale flatten, NO fallback: a field absent at the requested locale yields
 *    NULL — never the default-locale value. The two-pass translation
 *    migrations depend on this (an es-MX pass must leave untranslated fields
 *    untouched rather than re-import the en-US value).
 *  - RichText (document AST), Link(s), Object fields, and scalars all pass
 *    through unchanged at the resolved locale.
 *  - A `content_type` filter mismatch yields NULL, signalling "skip this row".
 *  - Sys metadata passes through as `sys_created_at` / `sys_updated_at`
 *    (ISO 8601 strings, for `created`/`changed` mapping and
 *    `high_water_property`) and `sys_created_by` (the raw
 *    `{sys:{linkType:User,id}}` link, for author->uid mapping via the nested
 *    source key `sys_created_by/sys/id`). Set after the fields loop, so the
 *    sys value wins over an identically-named Contentful field.
 */
final class ContentfulEntryFlattener {

  /**
   * Constructs a ContentfulEntryFlattener.
   *
   * @param string $locale
   *   The locale to resolve field values at (e.g. 'en-US').
   * @param string|null $contentType
   *   If set, only entries of this Contentful content type are kept; others
   *   flatten to NULL. NULL keeps every row (used for assets / single-type
   *   exports).
   */
  public function __construct(
    private readonly string $locale,
    private readonly ?string $contentType = NULL,
  ) {}

  /**
   * Flattens a single raw export item.
   *
   * @param array $entry
   *   A raw element from the export's `entries` or `assets` array.
   *
   * @return array<string,mixed>|null
   *   The flattened source row keyed by field id (plus `sys_id` and
   *   `content_type`), or NULL to skip the row.
   */
  public function flatten(array $entry): ?array {
    $sys = $entry['sys'] ?? [];
    $entryType = $sys['contentType']['sys']['id'] ?? NULL;

    // Content-type filter: skip rows that do not match.
    if ($this->contentType !== NULL && $entryType !== $this->contentType) {
      return NULL;
    }

    $row = [
      'sys_id' => $sys['id'] ?? NULL,
      'content_type' => $entryType,
    ];

    foreach ($entry['fields'] ?? [] as $fieldId => $localeValues) {
      // No-fallback locale resolution: missing at this locale -> NULL.
      $row[$fieldId] = (is_array($localeValues) && array_key_exists($this->locale, $localeValues))
        ? $localeValues[$this->locale]
        : NULL;
    }

    // Sys metadata, set AFTER the fields loop so a Contentful field that
    // happens to share one of these names cannot shadow the real sys value
    // (the sys value wins — documented in ContentfulExport::fields()).
    $row['sys_created_at'] = $sys['createdAt'] ?? NULL;
    $row['sys_updated_at'] = $sys['updatedAt'] ?? NULL;
    $row['sys_created_by'] = $sys['createdBy'] ?? NULL;

    return $row;
  }

}
