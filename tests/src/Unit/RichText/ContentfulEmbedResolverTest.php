<?php

declare(strict_types=1);

namespace Drupal\Tests\contentful_migration\Unit\RichText;

use Drupal\contentful_migration\RichText\ContentfulEmbedResolver;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\migrate\MigrateLookupInterface;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\contentful_migration\RichText\ContentfulEmbedResolver
 * @group contentful_migration
 */
class ContentfulEmbedResolverTest extends UnitTestCase {

  private const EMBED_MIGRATIONS = [
    'Entry' => [
      ['migration' => 'contentful_callout_card', 'entity_type' => 'paragraph'],
      ['migration' => 'contentful_blog_post', 'entity_type' => 'node'],
    ],
    'Asset' => [
      ['migration' => 'contentful_media', 'entity_type' => 'media'],
    ],
  ];

  /**
   * A MigrateLookup that returns canned destination ids per (migration, sysId).
   *
   * $map: [ "migration|sysId" => [[destId, …]], … ]. Anything not in $map is a
   * miss ([]).
   */
  private function makeLookup(array $map): MigrateLookupInterface {
    $lookup = $this->createMock(MigrateLookupInterface::class);
    $lookup->method('lookup')->willReturnCallback(
      function ($migrationId, array $sourceIds) use ($map): array {
        $key = $migrationId . '|' . reset($sourceIds);
        return $map[$key] ?? [];
      },
    );
    return $lookup;
  }

  /**
   * An EntityTypeManager whose storages return entities with canned UUIDs.
   *
   * $uuids: [ "entity_type|id" => 'uuid', … ]. Missing => load() returns NULL.
   */
  private function makeEtm(array $uuids): EntityTypeManagerInterface {
    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $etm->method('getStorage')->willReturnCallback(
      function (string $entityType) use ($uuids): EntityStorageInterface {
        $storage = $this->createMock(EntityStorageInterface::class);
        $storage->method('load')->willReturnCallback(
          function ($id) use ($entityType, $uuids): ?EntityInterface {
            $uuid = $uuids[$entityType . '|' . $id] ?? NULL;
            if ($uuid === NULL) {
              return NULL;
            }
            $entity = $this->createMock(EntityInterface::class);
            $entity->method('uuid')->willReturn($uuid);
            return $entity;
          },
        );
        return $storage;
      },
    );
    return $etm;
  }

  /**
   * Builds a ContentfulEmbedResolver with the standard EMBED_MIGRATIONS config.
   */
  private function resolver(MigrateLookupInterface $lookup, EntityTypeManagerInterface $etm): ContentfulEmbedResolver {
    return new ContentfulEmbedResolver($lookup, $etm, self::EMBED_MIGRATIONS);
  }

  /**
   * First candidate hits: returns its entity_type + the loaded entity's UUID.
   *
   * Paragraph destination ids are [id, revision_id]; the id is taken.
   *
   * @covers ::resolve
   */
  public function testResolvesViaFirstCandidate(): void {
    $lookup = $this->makeLookup(['contentful_callout_card|callout1' => [['id' => 7, 'revision_id' => 9]]]);
    $etm = $this->makeEtm(['paragraph|7' => 'uuid-callout']);

    $this->assertSame(
      ['entity_type' => 'paragraph', 'uuid' => 'uuid-callout'],
      $this->resolver($lookup, $etm)->resolve('callout1', 'Entry'),
    );
  }

  /**
   * First candidate misses, second hits: falls through in configured order.
   *
   * @covers ::resolve
   */
  public function testFallsThroughToNextCandidate(): void {
    // callout_card misses post2; blog_post (a node) hits.
    $lookup = $this->makeLookup(['contentful_blog_post|post2' => [['nid' => 5]]]);
    $etm = $this->makeEtm(['node|5' => 'uuid-post2']);

    $this->assertSame(
      ['entity_type' => 'node', 'uuid' => 'uuid-post2'],
      $this->resolver($lookup, $etm)->resolve('post2', 'Entry'),
    );
  }

  /**
   * LinkType dispatch: an Asset uses the Asset candidate list, not Entry's.
   *
   * @covers ::resolve
   */
  public function testDispatchesByLinkType(): void {
    $lookup = $this->makeLookup(['contentful_media|img1' => [['mid' => 3]]]);
    $etm = $this->makeEtm(['media|3' => 'uuid-img1']);

    $this->assertSame(
      ['entity_type' => 'media', 'uuid' => 'uuid-img1'],
      $this->resolver($lookup, $etm)->resolve('img1', 'Asset'),
    );
  }

  /**
   * No candidate matches (not yet migrated) -> NULL, so the renderer omits.
   *
   * @covers ::resolve
   */
  public function testReturnsNullWhenNoCandidateMatches(): void {
    $resolver = $this->resolver($this->makeLookup([]), $this->makeEtm([]));
    $this->assertNull($resolver->resolve('missingAuthor', 'Entry'));
  }

  /**
   * An unconfigured linkType resolves to NULL without touching MigrateLookup.
   *
   * @covers ::resolve
   */
  public function testReturnsNullForUnconfiguredLinkType(): void {
    $lookup = $this->createMock(MigrateLookupInterface::class);
    $lookup->expects($this->never())->method('lookup');
    $resolver = new ContentfulEmbedResolver($lookup, $this->makeEtm([]), []);
    $this->assertNull($resolver->resolve('x', 'Entry'));
  }

  /**
   * A lookup hit whose entity fails to load (deleted post-migration) -> NULL.
   *
   * Not a fatal error; the caller degrades gracefully.
   *
   * @covers ::resolve
   */
  public function testReturnsNullWhenEntityMissing(): void {
    $lookup = $this->makeLookup(['contentful_callout_card|callout1' => [['id' => 7, 'revision_id' => 9]]]);
    // load() returns NULL for paragraph|7.
    $etm = $this->makeEtm([]);
    $this->assertNull($this->resolver($lookup, $etm)->resolve('callout1', 'Entry'));
  }

}
