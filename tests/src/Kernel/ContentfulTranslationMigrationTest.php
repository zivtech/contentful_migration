<?php

declare(strict_types=1);

namespace Drupal\Tests\contentful_migration\Kernel;

use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\migrate\Kernel\MigrateTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * End-to-end: a translation pass attaches es-MX values to the en node.
 *
 * The executable proof of the contentful_blog_post_es.yml example shape,
 * which was previously only statically validated: a second migration over the
 * same export with `locale: es-MX`, `migration_lookup` back to the base
 * migration, and `translations: true` must attach a Spanish translation to
 * the SAME node (no new nid), carrying the es-MX field values.
 *
 * Also proves the timestamps interplay documented in the example: Contentful
 * sys timestamps are entry-wide, not per-locale, so the translation pass maps
 * the same created/changed values as the base pass — one entry, one history.
 *
 * Truth boundary: the es-MX *body* Pass B (embed resolution inside a
 * translation) is not executed here — it composes the translation mechanics
 * proven here with the embed mechanics proven in ContentfulMigrationTest, and
 * the fixture's es-MX body carries no embeds to resolve.
 */
#[Group('contentful_migration')]
#[RunTestsInSeparateProcesses]
class ContentfulTranslationMigrationTest extends MigrateTestBase {

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
    'language',
    'content_translation',
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
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['field', 'node', 'filter']);

    ConfigurableLanguage::createFromLangcode('en')->save();
    ConfigurableLanguage::createFromLangcode('es')->save();

    NodeType::create(['type' => 'blog_post', 'name' => 'Blog Post'])->save();
    // The example's content_translation_source line needs the bundle enabled
    // for content translation (which adds the metadata fields).
    $this->container->get('content_translation.manager')->setEnabled('node', 'blog_post', TRUE);

    file_put_contents(
      'public://cf-export.json',
      file_get_contents(__DIR__ . '/../../fixtures/synthetic-contentful-export.json'),
    );
  }

  /**
   * The es-MX pass translates the existing nodes in place.
   */
  public function testTranslationAttachesToSameNode(): void {
    $this->executeMigrations(['cf_blog', 'cf_blog_es']);

    $lookup = $this->container->get('migrate.lookup');
    $nodeStorage = $this->container->get('entity_type.manager')->getStorage('node');

    // Base node: en, original title.
    $baseResult = $lookup->lookup('cf_blog', ['post1']);
    $this->assertNotEmpty($baseResult, 'post1 migrated to a node.');
    $baseFirst = reset($baseResult);
    $node = $nodeStorage->load(reset($baseFirst));
    $this->assertNotNull($node, 'The migrated post1 node loads.');
    $this->assertSame('en', $node->language()->getId());
    $this->assertSame('Getting Started with Decoupled Drupal', $node->getTitle());

    // The translation pass mapped onto the SAME nid: its destination ids are
    // [nid, langcode], and the nid is the base node's.
    $esResult = $lookup->lookup('cf_blog_es', ['post1']);
    $this->assertNotEmpty($esResult, 'post1 es-MX pass produced a destination.');
    $esFirst = reset($esResult);
    $this->assertSame((int) $node->id(), (int) reset($esFirst), 'The translation attached to the existing nid — no new node.');

    // The es translation carries the es-MX title and its declared source.
    $this->assertTrue($node->hasTranslation('es'), 'post1 gained a Spanish translation.');
    $es = $node->getTranslation('es');
    $this->assertSame('Primeros pasos con Drupal desacoplado', $es->getTitle());
    $this->assertSame('en', $es->get('content_translation_source')->value, 'The translation records its source language.');

    // One entry, one history: the translation carries the same entry-wide
    // sys dates as the base pass (fixture: created 2026-02-01, updated
    // 2026-04-02), not import time and not per-locale values.
    $this->assertSame(strtotime('2026-02-01T12:00:00.000Z'), (int) $es->getCreatedTime(), 'Translation created mirrors the entry-wide sys date.');
    $this->assertSame(strtotime('2026-04-02T14:30:00.000Z'), (int) $es->getChangedTime(), 'Translation changed mirrors the entry-wide sys date.');

    // Both fixture posts carry es-MX titles; the second row translated too.
    $post2Result = $lookup->lookup('cf_blog', ['post2']);
    $post2First = reset($post2Result);
    $post2 = $nodeStorage->load(reset($post2First));
    $this->assertTrue($post2->hasTranslation('es'), 'post2 gained a Spanish translation.');
    $this->assertSame('Patrones desacoplados avanzados', $post2->getTranslation('es')->getTitle());
  }

}
