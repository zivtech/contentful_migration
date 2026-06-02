<?php

declare(strict_types=1);

namespace Drupal\contentful_migration\Plugin\migrate\process;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\MigrateLookupInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Rewrites a Contentful entry/asset reference to a Drupal link-field URI.
 *
 * A Contentful link field stores a reference as `{sys: {linkType, id}}`. This
 * plugin resolves that `sys.id` through the relevant entity migration's id-map
 * (core MigrateLookup) and returns an `entity:{entity_type}/{id}` URI — the
 * value a Drupal `link` field natively stores. `entity:` URIs are resolved
 * through the alias system at render time, so a migrated link survives later
 * path/alias changes (unlike a baked-in `/node/N` path). No entity load is
 * needed: the id-map hit already proves the row migrated, and the URI is built
 * from the id directly, so the only dependency is `migrate.lookup`.
 *
 * Like the Rich Text embed resolver, `link_migrations` maps each linkType to an
 * ordered list of candidate migrations, tried in turn (a reference may resolve
 * to one of several bundles). An unresolved reference is logged and returns
 * NULL, leaving the link field empty rather than failing the row.
 *
 * Scope: this is the *field* case — a single stored reference. Inline
 * entry/asset hyperlinks *inside a Rich Text body* (`entry-hyperlink` /
 * `asset-hyperlink` AST nodes) belong in the Rich Text renderer path
 * (a `DrupalEntryHyperlink` renderer mirroring DrupalEmbeddedEntryBlock), not
 * here. Multi-value link fields use the idiomatic `sub_process` +
 * `migration_lookup` shape, not this plugin.
 *
 * @code
 * field_related_page/uri:
 *   plugin: contentful_internal_link
 *   source: field_related_page          # raw {sys: {...}} link, or a bare sys.id
 *   link_type: Entry                     # default linkType for a bare-id source
 *   link_migrations:
 *     Entry:
 *       - { migration: contentful_blog_post, entity_type: node }
 *     Asset:
 *       - { migration: contentful_media, entity_type: media }
 * @endcode
 */
// handle_multiples: TRUE — a single Contentful reference is a `{sys: {...}}`
// associative array. Without this, migrate's pipeline treats the array as a
// multi-value and calls transform() on each sub-value instead of passing the
// whole reference. A bare scalar sys.id passes through unchanged either way.
// (Verified pattern: ContentfulRichText needed the same flag; a direct-call
// unit test cannot catch its absence — only a real migrate run does.)
#[\Drupal\migrate\Attribute\MigrateProcess('contentful_internal_link', handle_multiples: TRUE)]
class ContentfulInternalLink extends ProcessPluginBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly MigrateLookupInterface $migrateLookup,
    private readonly LoggerInterface $logger,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('migrate.lookup'),
      $container->get('logger.factory')->get('contentful_migration'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property): ?string {
    [$sysId, $linkType] = $this->extractReference($value);
    if ($sysId === NULL) {
      return NULL;
    }

    foreach ($this->configuration['link_migrations'][$linkType] ?? [] as $candidate) {
      $migration = $candidate['migration'] ?? NULL;
      $entityType = $candidate['entity_type'] ?? NULL;
      if ($migration === NULL || $entityType === NULL) {
        continue;
      }

      // MigrateLookup returns a list of destination-id arrays (e.g.
      // [['nid' => 5]] or [['id' => 7, 'revision_id' => 9]]), or [] on miss.
      $results = $this->migrateLookup->lookup($migration, [$sysId]);
      if (!$results) {
        continue;
      }
      $destinationIds = reset($results);
      $entityId = is_array($destinationIds) ? reset($destinationIds) : $destinationIds;
      if ($entityId === FALSE || $entityId === NULL) {
        continue;
      }

      return sprintf('entity:%s/%s', $entityType, $entityId);
    }

    // Not yet migrated / unresolvable: leave the link empty, but make the loss
    // visible rather than silently dropping the reference.
    $this->logger->warning('Contentful internal link "@id" (@type) did not resolve to a migrated entity; link left empty.', [
      '@id' => $sysId,
      '@type' => $linkType,
    ]);
    return NULL;
  }

  /**
   * Extracts (sys.id, linkType) from a raw Contentful link or a bare sys.id.
   *
   * @param mixed $value
   *   Either a raw Contentful link object
   *   (`['sys' => ['id' => …, 'linkType' => 'Entry'|'Asset']]`) or a bare sys.id
   *   string (when the migration already `extract`ed `sys/id`).
   *
   * @return array{0: string|null, 1: string}
   *   [sysId, linkType]; sysId is NULL when the value carries no usable id.
   */
  private function extractReference($value): array {
    $defaultLinkType = $this->configuration['link_type'] ?? 'Entry';

    if (is_array($value)) {
      $sys = $value['sys'] ?? NULL;
      if (is_array($sys) && isset($sys['id']) && is_string($sys['id']) && $sys['id'] !== '') {
        $linkType = (isset($sys['linkType']) && is_string($sys['linkType']) && $sys['linkType'] !== '')
          ? $sys['linkType']
          : $defaultLinkType;
        return [$sys['id'], $linkType];
      }
      return [NULL, $defaultLinkType];
    }

    if (is_string($value) && trim($value) !== '') {
      return [trim($value), $defaultLinkType];
    }

    return [NULL, $defaultLinkType];
  }

}
