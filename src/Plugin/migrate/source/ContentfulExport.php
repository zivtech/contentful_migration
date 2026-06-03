<?php

declare(strict_types=1);

namespace Drupal\contentful_migration\Plugin\migrate\source;

use Drupal\contentful_migration\Source\ContentfulEntryFlattener;
use Drupal\migrate\Plugin\migrate\source\SourcePluginBase;

/**
 * Source plugin for a contentful-export JSON file.
 *
 * Reads one top-level array of the export (`entries` by default, or `assets`),
 * filters to a single Contentful content type, and resolves every field value
 * to one locale with no fallback. Reference values are left raw so the
 * migration YAML resolves them with stock `migration_lookup` + `extract`.
 *
 * The whole file is decoded into memory: real contentful-export outputs in the
 * 218-space corpus top out at ~330KB, so this is safe for v1. For very large
 * production spaces a streaming parser (halaxa/json-machine) would be the
 * future enhancement.
 *
 * @code
 * source:
 *   plugin: contentful_export
 *   path: 'private://contentful/export.json'
 *   selector: entries          # or 'assets'
 *   content_type: blogPost     # omit for assets / single-type exports
 *   locale: en-US
 *   track_changes: true        # optional: re-import rows whose content changed
 *   ids:
 *     sys_id:
 *       type: string
 * @endcode
 *
 * `track_changes` is a stock SourcePluginBase feature (this plugin does not
 * override the hash machinery), verified by ContentfulDeltaImportTest.
 * `high_water_property` is deliberately undocumented: rows yield in
 * export-file order, not date order, and the high-water interaction with an
 * unordered iterator is unverified. See the README "Repeatable / delta
 * imports".
 */
#[\Drupal\migrate\Attribute\MigrateSource('contentful_export')]
class ContentfulExport extends SourcePluginBase {

  /**
   * {@inheritdoc}
   */
  public function __toString(): string {
    return (string) ($this->configuration['path'] ?? 'contentful_export');
  }

  /**
   * {@inheritdoc}
   */
  public function getIds(): array {
    return ['sys_id' => ['type' => 'string']];
  }

  /**
   * {@inheritdoc}
   */
  public function fields(): array {
    return [
      'sys_id' => $this->t('Contentful sys.id (the migrate source key).'),
      'content_type' => $this->t('The Contentful content type id.'),
      'sys_created_at' => $this->t('Entry sys.createdAt (ISO 8601) — map to `created` via skip_on_empty + callback:strtotime.'),
      'sys_updated_at' => $this->t('Entry sys.updatedAt (ISO 8601) — map to `changed` via skip_on_empty + callback:strtotime.'),
      'sys_created_by' => $this->t('Entry sys.createdBy (raw User link) — author id at `sys_created_by/sys/id` for static_map / migration_lookup.'),
      // Per-field source properties are dynamic per space; the migration YAML
      // references them by field id directly. Precedence: the sys_* keys above
      // are set after field flattening, so they WIN over an identically-named
      // Contentful field (which would be shadowed in the flattened row).
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function initializeIterator(): \Iterator {
    $path = $this->configuration['path'] ?? '';
    $selector = $this->configuration['selector'] ?? 'entries';
    $locale = $this->configuration['locale'] ?? 'en-US';
    $contentType = $this->configuration['content_type'] ?? NULL;

    $raw = $path !== '' ? file_get_contents($path) : FALSE;
    if ($raw === FALSE) {
      throw new \RuntimeException(sprintf('Contentful export not readable at "%s".', $path));
    }
    $data = json_decode($raw, TRUE);
    if (!is_array($data)) {
      throw new \RuntimeException(sprintf('Contentful export at "%s" is not valid JSON.', $path));
    }

    $items = $data[$selector] ?? [];
    $flattener = new ContentfulEntryFlattener($locale, $contentType);

    foreach ($items as $item) {
      if (!is_array($item)) {
        continue;
      }
      $row = $flattener->flatten($item);
      if ($row !== NULL) {
        yield $row;
      }
    }
  }

}
