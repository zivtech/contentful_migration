<?php

declare(strict_types=1);

namespace Drupal\contentful_migration\Export;

/**
 * Pure builder + validator for a `contentful-export` run configuration.
 *
 * Kept separate from the Drush command (a thin Process wrapper) so the
 * validation + option assembly is unit-testable without a Drush bootstrap — the
 * same pure/thin split as ContentfulEntryFlattener vs. the ContentfulExport
 * source plugin.
 *
 * The returned array is the JSON object `contentful-export` consumes via its
 * `--config` flag (keys match the tool's config object: spaceId, environmentId,
 * managementToken, downloadAssets, exportDir, contentFile).
 *
 * The token lives only in that 0600 temp file, never the process argv —
 * `contentful-export` exposes no environment variable for it.
 */
final class ExportConfig {

  /**
   * Assembles and validates the export configuration.
   *
   * @return array<string, mixed>
   *   The config object for `contentful-export --config`.
   *
   * @throws \InvalidArgumentException
   *   When a required value (space id, management token) is missing.
   */
  public static function build(
    string $spaceId,
    string $environmentId,
    string $managementToken,
    string $exportDir,
    bool $downloadAssets = TRUE,
    string $contentFile = 'export.json',
  ): array {
    $missing = [];
    if (trim($spaceId) === '') {
      $missing[] = '--space-id';
    }
    if (trim($managementToken) === '') {
      $missing[] = 'a management token (--management-token or the CONTENTFUL_MANAGEMENT_TOKEN environment variable)';
    }
    if ($missing !== []) {
      throw new \InvalidArgumentException('Missing required: ' . implode('; ', $missing) . '.');
    }

    return [
      'spaceId' => $spaceId,
      'environmentId' => trim($environmentId) !== '' ? $environmentId : 'master',
      'managementToken' => $managementToken,
      'downloadAssets' => $downloadAssets,
      'exportDir' => $exportDir,
      'contentFile' => $contentFile,
    ];
  }

}
