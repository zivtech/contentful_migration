<?php

declare(strict_types=1);

namespace Drupal\Tests\contentful_migration\Kernel;

use Drupal\Core\File\FileSystemInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\migrate\Kernel\MigrateTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Kernel test: sys_id maps to field_contentful_id on the migrated entity.
 *
 * Proves the identity-preservation pattern documented in
 * migrations/examples/contentful_blog_post.yml: the migrate map records
 * sys.id → entity, but map tables are migration infrastructure (JSON:API
 * never exposes them, migrate:reset erases them). Mapping sys_id to a plain
 * field makes the identity durable content, queryable by any decoupled
 * consumer. Two fixture entries are asserted to prove the mapping is per-row,
 * not coincidence.
 */
#[Group('contentful_migration')]
#[RunTestsInSeparateProcesses]
class ContentfulIdentityFieldTest extends MigrateTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'node',
    'migrate',
    'contentful_migration',
    'contentful_migration_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    // node_access is a plain schema table (not entity schema); node save
    // writes grants to it, so it must exist.
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['field', 'node']);

    NodeType::create(['type' => 'blog_post', 'name' => 'Blog Post'])->save();

    FieldStorageConfig::create([
      'field_name' => 'field_contentful_id',
      'entity_type' => 'node',
      'type' => 'string',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_contentful_id',
      'entity_type' => 'node',
      'bundle' => 'blog_post',
      'label' => 'Contentful ID',
    ])->save();

    $fileSystem = $this->container->get('file_system');
    $publicDir = 'public://';
    $fileSystem->prepareDirectory($publicDir, FileSystemInterface::CREATE_DIRECTORY);
    file_put_contents(
      'public://cf-export.json',
      file_get_contents(__DIR__ . '/../../fixtures/synthetic-contentful-export.json'),
    );
  }

  /**
   * The source sys_id lands on field_contentful_id for each migrated entry.
   *
   * Two fixture entries are asserted to prove the mapping is per-row.
   * The UUID guard at the end documents why the field is necessary: Drupal
   * UUIDs are not Contentful sys.ids.
   */
  public function testContentfulIdIsPreserved(): void {
    $this->executeMigrations(['cf_identity']);

    $lookup = $this->container->get('migrate.lookup');
    $nodeStorage = $this->container->get('entity_type.manager')->getStorage('node');

    // post1: field_contentful_id must equal the source sys_id.
    $result1 = $lookup->lookup('cf_identity', ['post1']);
    $this->assertNotEmpty($result1, 'post1 migrated to a node.');
    $first1 = reset($result1);
    $node1 = $nodeStorage->load(reset($first1));
    $this->assertNotNull($node1, 'The migrated post1 node loads.');
    $this->assertSame('post1', $node1->get('field_contentful_id')->value, 'post1: field_contentful_id equals the Contentful sys.id.');

    // post2: proves the mapping is per-row, not coincidence.
    $result2 = $lookup->lookup('cf_identity', ['post2']);
    $this->assertNotEmpty($result2, 'post2 migrated to a node.');
    $first2 = reset($result2);
    $node2 = $nodeStorage->load(reset($first2));
    $this->assertNotNull($node2, 'The migrated post2 node loads.');
    $this->assertSame('post2', $node2->get('field_contentful_id')->value, 'post2: field_contentful_id equals the Contentful sys.id.');

    // UUID guard: Drupal UUIDs are not Contentful sys.ids — without the field,
    // a consumer has no durable way to resolve old Contentful-id-based links.
    $this->assertNotSame('post1', $node1->uuid(), 'The Drupal UUID is not the Contentful sys.id — the field is required for id-based lookups.');
  }

}
