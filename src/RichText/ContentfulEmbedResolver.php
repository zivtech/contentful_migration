<?php

declare(strict_types=1);

namespace Drupal\contentful_migration\RichText;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\migrate\MigrateLookupInterface;

/**
 * Resolves a Contentful sys.id to a migrated Drupal entity via the migrate map.
 *
 * Backed by core's MigrateLookup: for the given `linkType` it walks the
 * configured candidate migrations in order, looks each `sys.id` up in that
 * migration's id-map, and on the first hit loads the destination entity to read
 * its UUID (the Rich Text embed tokens reference entities by UUID, not id).
 *
 * Config (`embed_migrations`, supplied via the ContentfulRichText process YAML)
 * maps each linkType to an ordered candidate list:
 * @code
 * embed_migrations:
 *   Entry:
 *     - { migration: contentful_callout_card, entity_type: paragraph }
 *     - { migration: contentful_blog_post,    entity_type: node }
 *   Asset:
 *     - { migration: contentful_media,         entity_type: media }
 * @endcode
 *
 * Not a shared service: it depends on per-migration YAML config, so
 * ContentfulRichText::create() constructs it with `migrate.lookup` +
 * `entity_type.manager` from the container plus `embed_migrations`.
 */
final class ContentfulEmbedResolver implements ContentfulEmbedResolverInterface {

  /**
   * Constructs a ContentfulEmbedResolver.
   *
   * @param \Drupal\migrate\MigrateLookupInterface $migrateLookup
   *   Resolves a source id to destination ids via a migration's id-map.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   Loads the resolved destination entity to read its UUID.
   * @param array<string,array<int,array{migration:string,entity_type:string}>> $embedMigrations
   *   linkType => ordered list of {migration, entity_type} candidates.
   */
  public function __construct(
    private readonly MigrateLookupInterface $migrateLookup,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly array $embedMigrations,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function resolve(string $sysId, string $linkType): ?array {
    foreach ($this->embedMigrations[$linkType] ?? [] as $candidate) {
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

      $entity = $this->entityTypeManager->getStorage($entityType)->load($entityId);
      if ($entity !== NULL) {
        return ['entity_type' => $entityType, 'uuid' => $entity->uuid()];
      }
    }

    // Not yet migrated / unresolvable — the renderer logs and omits.
    return NULL;
  }

}
