<?php

declare(strict_types=1);

namespace Drupal\Tests\contentful_migration\Kernel;

use Drupal\contentful_migration\Plugin\migrate\process\ContentfulInternalLink;
use Drupal\Core\File\FileSystemInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\link\LinkItemInterface;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\migrate\Kernel\MigrateTestBase;

/**
 * End-to-end coverage: a real migrate run resolves a reference to a link URI.
 *
 * This is the test the unit test cannot be. ContentfulInternalLink declares
 * `handle_multiples: TRUE` because a single Contentful reference is a
 * `{sys: {...}}` associative array; without the flag, migrate shreds that array
 * element-by-element and never passes the whole reference to transform(). A
 * direct-call unit test bypasses that pipeline entirely, so only a real migrate
 * run proves the flag is doing its job. This test also exercises plugin
 * discovery of the `#[MigrateProcess]` attribute and the real `migrate.lookup`
 * resolution path through create()/DI.
 *
 * Shape: Pass A (`cf_link_post`) migrates both posts to nodes; Pass B
 * (`cf_link`) feeds the RAW `relatedPost` reference (`{sys: {...}}`) to the
 * plugin, which resolves post_b through cf_link_post's id-map and writes
 * `entity:node/{nid}` to a core link field. `entity:` URIs are intentional —
 * alias-safe (resolved at render), unlike a baked-in `/node/N` path.
 *
 * Truth boundary: the reference target is a node here (core-only harness). The
 * resolver is entity-type-agnostic — it emits whatever entity_type the matched
 * candidate names — so Media/Paragraph targets follow the same path but are not
 * separately proven here.
 *
 * @group contentful_migration
 */
class ContentfulInternalLinkMigrationTest extends MigrateTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'node',
    'link',
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
    // node_access is a plain schema table (not entity schema); node save writes
    // grants to it, so it must exist or the Pass-B update errors.
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['field', 'node']);

    NodeType::create(['type' => 'blog_post', 'name' => 'Blog Post'])->save();

    // A core link field on blog_post to receive the resolved entity: URI.
    FieldStorageConfig::create([
      'field_name' => 'field_related',
      'entity_type' => 'node',
      'type' => 'link',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_related',
      'entity_type' => 'node',
      'bundle' => 'blog_post',
      'label' => 'Related',
      'settings' => [
        'link_type' => LinkItemInterface::LINK_GENERIC,
        'title' => DRUPAL_DISABLED,
      ],
    ])->save();

    // Stage the export where the migrations' `public://` source path resolves.
    $fileSystem = $this->container->get('file_system');
    $publicDir = 'public://';
    $fileSystem->prepareDirectory($publicDir, FileSystemInterface::CREATE_DIRECTORY);
    file_put_contents(
      'public://cf-link-export.json',
      file_get_contents(__DIR__ . '/../../fixtures/synthetic-link-export.json'),
    );
  }

  /**
   * Cheap insurance: the process plugin builds through the real container.
   *
   * If this fails, the bug is in create()/DI wiring, not the migrate run.
   */
  public function testProcessPluginBuildsViaContainer(): void {
    $plugin = $this->container->get('plugin.manager.migrate.process')->createInstance(
      'contentful_internal_link',
      ['link_migrations' => ['Entry' => [['migration' => 'cf_link_post', 'entity_type' => 'node']]]],
    );
    $this->assertInstanceOf(ContentfulInternalLink::class, $plugin);
  }

  /**
   * A real two-pass migration resolves the reference to an entity: link URI.
   */
  public function testInternalLinkResolvesToEntityUri(): void {
    $this->executeMigrations(['cf_link_post', 'cf_link']);

    $lookup = $this->container->get('migrate.lookup');
    $nodeStorage = $this->container->get('entity_type.manager')->getStorage('node');

    // The reference target: post_b -> a blog_post node. Derive its nid via the
    // same lookup() contract the plugin uses (a second proof of resolution).
    $targetResult = $lookup->lookup('cf_link_post', ['post_b']);
    $this->assertNotEmpty($targetResult, 'post_b migrated to a node.');
    $targetFirst = reset($targetResult);
    $targetNid = reset($targetFirst);

    // The host: post_a -> a blog_post node carrying the resolved link.
    $hostResult = $lookup->lookup('cf_link_post', ['post_a']);
    $this->assertNotEmpty($hostResult, 'post_a migrated to a node.');
    $hostFirst = reset($hostResult);
    $hostNode = $nodeStorage->load(reset($hostFirst));
    $this->assertNotNull($hostNode, 'The migrated host node loads.');

    // handle_multiples proof: the raw {sys: {...}} reference survived the
    // pipeline whole and resolved to the migrated target's entity: URI.
    $this->assertFalse($hostNode->get('field_related')->isEmpty(), 'The link field was populated.');
    $this->assertSame(
      'entity:node/' . $targetNid,
      $hostNode->get('field_related')->uri,
      'relatedPost resolved to an alias-safe entity:node/{nid} URI for the migrated target.',
    );
  }

}
