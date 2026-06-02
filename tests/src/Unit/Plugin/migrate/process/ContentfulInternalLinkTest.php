<?php

declare(strict_types=1);

namespace Drupal\Tests\contentful_migration\Unit\Plugin\migrate\process;

use Drupal\contentful_migration\Plugin\migrate\process\ContentfulInternalLink;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\MigrateLookupInterface;
use Drupal\migrate\Row;
use Drupal\Tests\UnitTestCase;
use Psr\Log\NullLogger;

/**
 * @coversDefaultClass \Drupal\contentful_migration\Plugin\migrate\process\ContentfulInternalLink
 * @group contentful_migration
 */
class ContentfulInternalLinkTest extends UnitTestCase {

  private const LINK_MIGRATIONS = [
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
   * Runs transform() with the given value, lookup, and (defaulted) config.
   */
  private function transform($value, MigrateLookupInterface $lookup, array $configuration = []): ?string {
    $configuration += ['link_migrations' => self::LINK_MIGRATIONS];
    $plugin = new ContentfulInternalLink($configuration, 'contentful_internal_link', [], $lookup, new NullLogger());
    return $plugin->transform(
      $value,
      $this->createMock(MigrateExecutableInterface::class),
      $this->createMock(Row::class),
      'uri',
    );
  }

  /**
   * A bare sys.id (Entry by default) resolves via the first candidate hit.
   *
   * The first candidate (paragraph) misses; the node candidate hits.
   *
   * @covers ::transform
   * @covers ::extractReference
   */
  public function testResolvesBareSysId(): void {
    $lookup = $this->makeLookup(['contentful_blog_post|post1' => [['nid' => 5]]]);
    $this->assertSame('entity:node/5', $this->transform('post1', $lookup));
  }

  /**
   * A raw Contentful link object resolves the same as a bare id.
   *
   * Reads the linkType from the object.
   *
   * @covers ::transform
   * @covers ::extractReference
   */
  public function testResolvesRawLinkObject(): void {
    $lookup = $this->makeLookup(['contentful_blog_post|post1' => [['nid' => 5]]]);
    $value = ['sys' => ['type' => 'Link', 'linkType' => 'Entry', 'id' => 'post1']];
    $this->assertSame('entity:node/5', $this->transform($value, $lookup));
  }

  /**
   * Candidates are tried in configured order; the first hit wins.
   *
   * A composite destination id ([id, revision_id], e.g. a paragraph) takes
   * the first id.
   *
   * @covers ::transform
   */
  public function testResolvesViaFirstCandidateWithCompositeId(): void {
    $lookup = $this->makeLookup(['contentful_callout_card|callout1' => [['id' => 7, 'revision_id' => 9]]]);
    $this->assertSame('entity:paragraph/7', $this->transform('callout1', $lookup));
  }

  /**
   * LinkType dispatch: an Asset uses the Asset candidate list, not Entry's.
   *
   * @covers ::transform
   * @covers ::extractReference
   */
  public function testDispatchesByLinkType(): void {
    $lookup = $this->makeLookup(['contentful_media|img1' => [['mid' => 3]]]);
    $value = ['sys' => ['linkType' => 'Asset', 'id' => 'img1']];
    $this->assertSame('entity:media/3', $this->transform($value, $lookup));
  }

  /**
   * The object's linkType overrides the configured `link_type` default.
   *
   * An Asset object resolves against the Asset list even when the default is
   * Entry.
   *
   * @covers ::extractReference
   */
  public function testObjectLinkTypeOverridesConfiguredDefault(): void {
    $lookup = $this->makeLookup(['contentful_media|img1' => [['mid' => 3]]]);
    $value = ['sys' => ['linkType' => 'Asset', 'id' => 'img1']];
    $this->assertSame('entity:media/3', $this->transform($value, $lookup, ['link_type' => 'Entry']));
  }

  /**
   * A bare id uses the configured `link_type` to pick the candidate list.
   *
   * @covers ::extractReference
   */
  public function testConfiguredLinkTypeForBareId(): void {
    $lookup = $this->makeLookup(['contentful_media|img1' => [['mid' => 3]]]);
    $this->assertSame('entity:media/3', $this->transform('img1', $lookup, ['link_type' => 'Asset']));
  }

  /**
   * No candidate resolves (not yet migrated) -> NULL, leaving the link empty.
   *
   * @covers ::transform
   */
  public function testReturnsNullWhenNoCandidateMatches(): void {
    $this->assertNull($this->transform('missing1', $this->makeLookup([])));
  }

  /**
   * An unconfigured linkType resolves to NULL without consulting MigrateLookup.
   *
   * @covers ::transform
   */
  public function testReturnsNullForUnconfiguredLinkType(): void {
    $lookup = $this->createMock(MigrateLookupInterface::class);
    $lookup->expects($this->never())->method('lookup');
    $value = ['sys' => ['linkType' => 'Tag', 'id' => 'x']];
    $this->assertNull($this->transform($value, $lookup));
  }

  /**
   * Empty / malformed input -> NULL without consulting MigrateLookup.
   *
   * @dataProvider emptyInputCases
   * @covers ::transform
   * @covers ::extractReference
   */
  public function testReturnsNullForEmptyInput($value): void {
    $lookup = $this->createMock(MigrateLookupInterface::class);
    $lookup->expects($this->never())->method('lookup');
    $this->assertNull($this->transform($value, $lookup));
  }

  /**
   * Data provider for testReturnsNullForEmptyInput.
   */
  public static function emptyInputCases(): array {
    return [
      'empty string' => [''],
      'whitespace string' => ['   '],
      'null' => [NULL],
      'array without sys' => [['fields' => []]],
      'sys without id' => [['sys' => ['linkType' => 'Entry']]],
      'sys id empty' => [['sys' => ['id' => '', 'linkType' => 'Entry']]],
    ];
  }

}
