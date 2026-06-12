<?php

declare(strict_types=1);

namespace Drupal\Tests\contentful_migration\Kernel;

use Drupal\contentful_migration\Plugin\migrate\process\ContentfulRichText;
use Drupal\Core\File\FileSystemInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\filter\Entity\FilterFormat;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\migrate\Kernel\MigrateTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * End-to-end: a real migrate run resolves a Rich Text embed via migrate map.
 *
 * This is the test the unit tests can't be: it exercises plugin discovery
 * (`#[MigrateSource]` / `#[MigrateProcess]`), `ContentfulRichText::create()`
 * wiring through the real container (`migrate.lookup` + `entity_type.manager`),
 * and a genuine two-pass migration where Pass B resolves an `embedded-entry`
 * against the id-map populated by Pass A.
 *
 * Truth boundary: the embed target is migrated to a *node* here, as a stand-in
 * for the entity-type-agnostic resolver path (the resolver returns whatever
 * entity_type the matched candidate names). Paragraph / Media integration is
 * NOT proven by this test — node keeps the harness to core modules.
 */
#[Group('contentful_migration')]
#[RunTestsInSeparateProcesses]
class ContentfulMigrationTest extends MigrateTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
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
    // node_access is a plain schema table (not entity schema); node save/update
    // writes grants to it, so it must exist or the Pass-B update errors.
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['field', 'node', 'filter']);

    // The Pass-B body stores format `contentful_embed` (the recipe-shipped
    // default new migrations point at). A bare format satisfies storage in
    // this node-only harness; rendering through the real recipe config is
    // ContentfulEmbedRecipeTest's job.
    FilterFormat::create(['format' => 'contentful_embed', 'name' => 'Contentful embed'])->save();

    // Destination bundles + the standard body field on blog_post.
    NodeType::create(['type' => 'card', 'name' => 'Card'])->save();
    NodeType::create(['type' => 'blog_post', 'name' => 'Blog Post'])->save();

    // Standard body field on blog_post (node_add_body_field() is deprecated in
    // 11.3, so create the storage + instance explicitly).
    FieldStorageConfig::create([
      'field_name' => 'body',
      'entity_type' => 'node',
      'type' => 'text_with_summary',
    ])->save();
    FieldConfig::create([
      'field_name' => 'body',
      'entity_type' => 'node',
      'bundle' => 'blog_post',
      'label' => 'Body',
    ])->save();

    // Stage the export where the migrations' `public://` source path resolves.
    $fileSystem = $this->container->get('file_system');
    $publicDir = 'public://';
    $fileSystem->prepareDirectory($publicDir, FileSystemInterface::CREATE_DIRECTORY);
    file_put_contents(
      'public://cf-export.json',
      file_get_contents(__DIR__ . '/../../fixtures/synthetic-contentful-export.json'),
    );
  }

  /**
   * Cheap insurance: the process plugin builds through the real container.
   *
   * If this fails, the bug is in create()/DI wiring, not the migrate run.
   */
  public function testProcessPluginBuildsViaContainer(): void {
    $plugin = $this->container->get('plugin.manager.migrate.process')->createInstance(
      'contentful_rich_text',
      ['embed_migrations' => ['Entry' => [['migration' => 'cf_card', 'entity_type' => 'node']]]],
    );
    $this->assertInstanceOf(ContentfulRichText::class, $plugin);
  }

  /**
   * A real migration completes and the embed resolves to the migrated node.
   */
  public function testEndToEndEmbedResolves(): void {
    $this->executeMigrations(['cf_card', 'cf_blog', 'cf_blog_body']);

    $lookup = $this->container->get('migrate.lookup');
    $nodeStorage = $this->container->get('entity_type.manager')->getStorage('node');

    // Embed target: calloutCard `callout1` -> a card node. Derive its UUID via
    // the same lookup() contract the resolver uses (a second proof of it).
    $cardResult = $lookup->lookup('cf_card', ['callout1']);
    $this->assertNotEmpty($cardResult, 'calloutCard migrated to a node.');
    $cardFirst = reset($cardResult);
    $cardNode = $nodeStorage->load(reset($cardFirst));
    $this->assertNotNull($cardNode, 'The migrated card node loads.');
    $cardUuid = $cardNode->uuid();

    // Host: blogPost `post1` -> a blog_post node, body set in Pass B.
    $blogResult = $lookup->lookup('cf_blog', ['post1']);
    $this->assertNotEmpty($blogResult, 'blogPost migrated to a node.');
    $blogFirst = reset($blogResult);
    $blogNode = $nodeStorage->load(reset($blogFirst));
    $this->assertNotNull($blogNode, 'The migrated blog node loads.');

    $body = (string) $blogNode->get('body')->value;
    $this->assertStringContainsString('data-entity-type="node"', $body, 'Embed resolved to a node entity.');
    $this->assertStringContainsString(
      'data-entity-uuid="' . $cardUuid . '"',
      $body,
      'Embed token references the migrated card node by its real UUID.',
    );
    // The library default Contentful-id placeholder must be gone.
    $this->assertStringNotContainsString('Entry#', $body);
  }

  /**
   * Authorship timestamps land: sys dates become created/changed via core.
   *
   * Proves the documented chain (skip_on_empty -> callback:strtotime) through
   * a real migrate run: the node's created/changed equal the fixture's
   * sys.createdAt/updatedAt, not import time.
   *
   * Truth boundary: the dated path only. The dateless degrade (skip_on_empty
   * leaves the property unset -> Drupal defaults to import time) composes two
   * core plugins on a NULL the flattener unit test proves it produces — it is
   * not re-proven here (the shared fixture has no dateless entry, and adding
   * one would ripple through the Pass-B body run).
   */
  public function testAuthorshipTimestampsMigrate(): void {
    $this->executeMigrations(['cf_blog']);

    $result = $this->container->get('migrate.lookup')->lookup('cf_blog', ['post1']);
    $this->assertNotEmpty($result, 'post1 migrated to a node.');
    $first = reset($result);
    $node = $this->container->get('entity_type.manager')->getStorage('node')->load(reset($first));
    $this->assertNotNull($node, 'The migrated post1 node loads.');

    // Fixture sys dates: created 2026-02-01T12:00:00.000Z, updated
    // 2026-04-02T14:30:00.000Z.
    $this->assertSame(strtotime('2026-02-01T12:00:00.000Z'), (int) $node->getCreatedTime(), 'created carries the Contentful creation date.');
    $this->assertSame(strtotime('2026-04-02T14:30:00.000Z'), (int) $node->getChangedTime(), 'changed carries the Contentful update date.');
  }

  /**
   * The inline entry-hyperlink (post1 -> post2) resolves to a real anchor.
   *
   * It links the migrated target node's canonical path and carries its real
   * UUID, with the library's `#Entry-` placeholder gone and the link text
   * preserved.
   *
   * Truth boundary: like the embed test, the target is a *node* (has a
   * canonical URL). The no-canonical-URL degradation (e.g. a Paragraph) and the
   * unresolved-target degradation are covered by the DrupalEntryHyperlink unit
   * test, without pulling the Paragraphs module into this harness.
   */
  public function testEndToEndEntryHyperlinkResolves(): void {
    $this->executeMigrations(['cf_card', 'cf_blog', 'cf_blog_body']);

    $lookup = $this->container->get('migrate.lookup');
    $nodeStorage = $this->container->get('entity_type.manager')->getStorage('node');

    // Hyperlink target: post2 -> a blog_post node, resolved via the cf_blog
    // candidate. Its id + UUID come from the same lookup() the renderer uses.
    $post2Result = $lookup->lookup('cf_blog', ['post2']);
    $this->assertNotEmpty($post2Result, 'post2 migrated to a node.');
    $post2First = reset($post2Result);
    $post2Node = $nodeStorage->load(reset($post2First));
    $this->assertNotNull($post2Node, 'The migrated post2 node loads.');
    $uuid = $post2Node->uuid();
    $nid = $post2Node->id();

    // Host: post1 -> a blog_post node whose body holds the inline hyperlink.
    $post1Result = $lookup->lookup('cf_blog', ['post1']);
    $this->assertNotEmpty($post1Result, 'post1 migrated to a node.');
    $post1First = reset($post1Result);
    $post1Node = $nodeStorage->load(reset($post1First));
    $this->assertNotNull($post1Node, 'The migrated post1 node loads.');

    $body = (string) $post1Node->get('body')->value;
    // Anchor links the target node's canonical path and carries its real UUID
    // (for Linkit alias-safe rewriting); the link text survives.
    $this->assertStringContainsString('href="/node/' . $nid . '"', $body);
    $this->assertStringContainsString('data-entity-uuid="' . $uuid . '"', $body);
    $this->assertStringContainsString('>advanced guide</a>', $body);
    // The library default `#Entry-ID` placeholder href must be gone.
    $this->assertStringNotContainsString('#Entry-', $body);
  }

}
