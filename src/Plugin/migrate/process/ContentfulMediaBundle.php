<?php

declare(strict_types=1);

namespace Drupal\contentful_migration\Plugin\migrate\process;

use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;

/**
 * Maps a Contentful asset MIME type to a Drupal media bundle.
 *
 * A Contentful asset carries no bundle concept; the only signal for which media
 * type it should become is its file MIME (`fields.file.<locale>.contentType`,
 * exposed by `contentful_export` as the nested source `file/contentType`). This
 * plugin turns that MIME into a media bundle machine name so the `entity:media`
 * destination knows what to create.
 *
 * Matching order: an exact MIME match wins first, then a prefix match (any map
 * key ending in `/`, e.g. `image/`), then the configured fallback. Defaults map
 * the common Contentful asset families; override `map`/`default` per space.
 *
 * @code
 * bundle:
 *   plugin: contentful_media_bundle
 *   source: file/contentType
 *   # optional overrides:
 *   map:
 *     'image/': image
 *     'application/pdf': document
 *   default: document
 * @endcode
 */
#[\Drupal\migrate\Attribute\MigrateProcess('contentful_media_bundle')]
class ContentfulMediaBundle extends ProcessPluginBase {

  /**
   * Default MIME -> bundle map. Keys ending in `/` are prefix matches.
   */
  private const DEFAULT_MAP = [
    'application/pdf' => 'document',
    'image/' => 'image',
    'video/' => 'video',
    'audio/' => 'audio',
  ];

  /**
   * Bundle used when no map entry matches.
   */
  private const DEFAULT_FALLBACK = 'document';

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property): string {
    $mime = is_string($value) ? strtolower(trim($value)) : '';
    $map = $this->configuration['map'] ?? self::DEFAULT_MAP;
    $fallback = $this->configuration['default'] ?? self::DEFAULT_FALLBACK;

    // Exact MIME match wins (e.g. 'application/pdf').
    if ($mime !== '' && isset($map[$mime])) {
      return $map[$mime];
    }

    // Prefix match: any map key ending in '/' (e.g. 'image/' matches
    // 'image/jpeg'). Iterate in declared order so callers control precedence.
    if ($mime !== '') {
      foreach ($map as $key => $bundle) {
        if (str_ends_with((string) $key, '/') && str_starts_with($mime, (string) $key)) {
          return $bundle;
        }
      }
    }

    return $fallback;
  }

}
