<?php

declare(strict_types=1);

namespace Drupal\contentful_migration\Export;

/**
 * Pure structural digest of a contentful-export JSON payload.
 *
 * Deliberately shallow: a quick eyeball of what a space export contains (types,
 * counts, locales, asset MIME families), printed after `contentful:export`. The
 * deep content-model analysis — node-vs-Paragraph decisions, reference depth,
 * cycles — is a separate concern (the contentful-content-model-analyzer skill),
 * not this.
 */
final class ExportSummary {

  /**
   * Summarises a decoded contentful-export payload.
   *
   * @param array<string, mixed> $export
   *   A decoded contentful-export payload.
   *
   * @return array{content_types: int, content_type_ids: list<string>, entries: int, assets: int, locales: list<string>, asset_mime_families: array<string, int>}
   *   Counts and identifiers digested from the export.
   */
  public static function fromExport(array $export): array {
    $contentTypes = is_array($export['contentTypes'] ?? NULL) ? $export['contentTypes'] : [];
    $entries = is_array($export['entries'] ?? NULL) ? $export['entries'] : [];
    $assets = is_array($export['assets'] ?? NULL) ? $export['assets'] : [];
    $locales = is_array($export['locales'] ?? NULL) ? $export['locales'] : [];

    $families = [];
    foreach ($assets as $asset) {
      $files = is_array($asset) ? ($asset['fields']['file'] ?? []) : [];
      if (!is_array($files)) {
        continue;
      }
      foreach ($files as $file) {
        $mime = is_array($file) ? ($file['contentType'] ?? '') : '';
        if (is_string($mime) && $mime !== '') {
          $family = explode('/', $mime)[0];
          $families[$family] = ($families[$family] ?? 0) + 1;
        }
      }
    }
    ksort($families);

    return [
      'content_types' => count($contentTypes),
      'content_type_ids' => self::pluck($contentTypes, ['sys', 'id'], 'name'),
      'entries' => count($entries),
      'assets' => count($assets),
      'locales' => self::pluck($locales, ['code']),
      'asset_mime_families' => $families,
    ];
  }

  /**
   * Pulls a string identifier from each item, with an optional fallback key.
   *
   * @param list<mixed> $items
   *   The list to read.
   * @param list<string> $path
   *   Nested key path to the preferred identifier (e.g. ['sys', 'id']).
   * @param string|null $fallbackKey
   *   A top-level key to use when the path yields nothing.
   *
   * @return list<string>
   *   The non-empty string identifiers found.
   */
  private static function pluck(array $items, array $path, ?string $fallbackKey = NULL): array {
    $out = [];
    foreach ($items as $item) {
      if (!is_array($item)) {
        continue;
      }
      $value = $item;
      foreach ($path as $key) {
        $value = is_array($value) ? ($value[$key] ?? NULL) : NULL;
      }
      if ((!is_string($value) || $value === '') && $fallbackKey !== NULL) {
        $value = $item[$fallbackKey] ?? NULL;
      }
      if (is_string($value) && $value !== '') {
        $out[] = $value;
      }
    }
    return $out;
  }

}
