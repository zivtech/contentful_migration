<?php

declare(strict_types=1);

namespace Drupal\Tests\contentful_migration\Unit\JsonApiMap;

use Drupal\contentful_migration\JsonApiMap\MigrationMapExtractor;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Unit coverage for the pure migration-map extractor.
 *
 * Each test feeds a handwritten definition array (matching real example YAMLs)
 * into MigrationMapExtractor::extract() and asserts the output fields, kind,
 * via, and unmapped entries. No Drupal bootstrap required.
 *
 * Classification rules tested here, one case per rule:
 *  - Plain string → scalar
 *  - Nested-source migration_lookup → reference, contentful id stripped
 *  - sub_process + nested migration_lookup → reference_multiple
 *  - contentful_rich_text pipeline → richtext_html
 *  - field_-prefixed key with sys_id source → identity_field, not in fields
 *  - path/alias scalar → non_field_destination
 *  - path/alias pipeline → non_field_destination (key-name-first rule)
 *  - sys_created_at pipeline → sys_metadata in fields
 *  - Unknown shape → kind: manual with raw preserved
 *  - Bundle from process.type.default_value
 *  - Bundle from destination.default_bundle
 *  - Assets migration (no content_type) → contentful_type: null + warning
 *  - Composite field key field_media_image/target_id + /alt → grouped subkeys
 *  - bundle key with custom plugin → sys_metadata
 */
#[Group('contentful_migration')]
class MigrationMapExtractorTest extends UnitTestCase {

  /**
   * The extractor under test.
   */
  private MigrationMapExtractor $extractor;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->extractor = new MigrationMapExtractor();
  }

  /**
   * A plain string process value yields kind: scalar.
   */
  public function testScalarMapping(): void {
    $result = $this->extractor->extract('test_migration', [
      'source' => ['plugin' => 'contentful_export', 'content_type' => 'blogPost', 'locale' => 'en-US'],
      'process' => ['title' => 'title'],
      'destination' => ['plugin' => 'entity:node', 'default_bundle' => 'blog_post'],
    ]);

    $this->assertArrayHasKey('title', $result['fields']);
    $this->assertSame('scalar', $result['fields']['title']['kind']);
    $this->assertSame('title', $result['fields']['title']['contentful_field']);
  }

  /**
   * Nested-source migration_lookup yields reference + stripped contentful id.
   *
   * HeroImage/sys/id → contentful_field: heroImage, kind: reference.
   */
  public function testNestedSourceLookup(): void {
    $result = $this->extractor->extract('test_migration', [
      'source' => ['plugin' => 'contentful_export', 'content_type' => 'blogPost', 'locale' => 'en-US'],
      'process' => [
        'field_hero_image' => [
          'plugin' => 'migration_lookup',
          'migration' => 'contentful_media',
          'source' => 'heroImage/sys/id',
        ],
      ],
      'destination' => ['plugin' => 'entity:node', 'default_bundle' => 'blog_post'],
    ]);

    $this->assertArrayHasKey('field_hero_image', $result['fields']);
    $this->assertSame('reference', $result['fields']['field_hero_image']['kind']);
    $this->assertSame('heroImage', $result['fields']['field_hero_image']['contentful_field']);
    $this->assertContains('migration_lookup', $result['fields']['field_hero_image']['via']);
  }

  /**
   * Sub_process with nested migration_lookup yields reference_multiple.
   */
  public function testSubProcessWithNestedLookup(): void {
    $result = $this->extractor->extract('test_migration', [
      'source' => ['plugin' => 'contentful_export', 'content_type' => 'page', 'locale' => 'en-US'],
      'process' => [
        'field_sections' => [
          'plugin' => 'sub_process',
          'source' => 'sections',
          'process' => [
            'ids' => ['plugin' => 'migration_lookup', 'migration' => ['m1'], 'source' => 'sys/id'],
            'target_id' => ['plugin' => 'extract', 'source' => '@ids', 'index' => [0]],
          ],
        ],
      ],
      'destination' => ['plugin' => 'entity:node', 'default_bundle' => 'page'],
    ]);

    $this->assertArrayHasKey('field_sections', $result['fields']);
    $this->assertSame('reference_multiple', $result['fields']['field_sections']['kind']);
    $this->assertContains('sub_process', $result['fields']['field_sections']['via']);
    $this->assertContains('migration_lookup', $result['fields']['field_sections']['via']);
  }

  /**
   * Contentful_rich_text plugin in a pipeline yields richtext_html.
   */
  public function testRichTextPipeline(): void {
    $result = $this->extractor->extract('test_migration', [
      'source' => ['plugin' => 'contentful_export', 'content_type' => 'blogPost', 'locale' => 'en-US'],
      'process' => [
        'field_body' => [
          'plugin' => 'contentful_rich_text',
          'source' => 'body',
          'embed_migrations' => [],
        ],
      ],
      'destination' => ['plugin' => 'entity:node', 'default_bundle' => 'blog_post'],
    ]);

    $this->assertArrayHasKey('field_body', $result['fields']);
    $this->assertSame('richtext_html', $result['fields']['field_body']['kind']);
    $this->assertContains('contentful_rich_text', $result['fields']['field_body']['via']);
  }

  /**
   * A field_-prefixed key mapping sys_id is the identity field, not in fields.
   *
   * Any field_-prefixed name is accepted, not only field_contentful_id.
   */
  public function testIdentityFieldDetection(): void {
    $result = $this->extractor->extract('test_migration', [
      'source' => ['plugin' => 'contentful_export', 'content_type' => 'article', 'locale' => 'en-US'],
      'process' => [
        'field_contentful_id' => 'sys_id',
        'title' => 'title',
      ],
      'destination' => ['plugin' => 'entity:node', 'default_bundle' => 'article'],
    ]);

    $this->assertSame('field_contentful_id', $result['identity_field']);
    $this->assertArrayNotHasKey('field_contentful_id', $result['fields'], 'Identity field must not appear in fields map.');
  }

  /**
   * Any field_-prefixed name for sys_id is accepted as identity_field.
   */
  public function testIdentityFieldAcceptsAnyFieldPrefixedName(): void {
    $result = $this->extractor->extract('test_migration', [
      'source' => ['plugin' => 'contentful_export', 'content_type' => 'article', 'locale' => 'en-US'],
      'process' => ['field_cf_id' => 'sys_id'],
      'destination' => ['plugin' => 'entity:node', 'default_bundle' => 'article'],
    ]);

    $this->assertSame('field_cf_id', $result['identity_field']);
  }

  /**
   * Path/alias scalar form goes to unmapped as non_field_destination.
   */
  public function testPathAliasScalarIsUnmapped(): void {
    $result = $this->extractor->extract('test_migration', [
      'source' => ['plugin' => 'contentful_export', 'content_type' => 'page', 'locale' => 'en-US'],
      'process' => ['path/alias' => 'slug'],
      'destination' => ['plugin' => 'entity:node', 'default_bundle' => 'page'],
    ]);

    $this->assertArrayHasKey('path/alias', $result['unmapped']);
    $this->assertSame('non_field_destination', $result['unmapped']['path/alias']['reason']);
    $this->assertArrayNotHasKey('path/alias', $result['fields']);
  }

  /**
   * Path/alias pipeline form is still non_field_destination (key-name-first).
   *
   * Mirrors migrations/examples/contentful_page.yml:17 — the canonical form
   * uses a skip_on_empty pipeline, not a plain string.
   */
  public function testPathAliasPipelineFormIsUnmapped(): void {
    $result = $this->extractor->extract('test_migration', [
      'source' => ['plugin' => 'contentful_export', 'content_type' => 'page', 'locale' => 'en-US'],
      'process' => [
        'path/alias' => [
          'plugin' => 'skip_on_empty',
          'method' => 'process',
          'source' => 'slug',
        ],
      ],
      'destination' => ['plugin' => 'entity:node', 'default_bundle' => 'page'],
    ]);

    $this->assertArrayHasKey('path/alias', $result['unmapped']);
    $this->assertSame('non_field_destination', $result['unmapped']['path/alias']['reason']);
    $this->assertArrayNotHasKey('path/alias', $result['fields'], 'Key-name-first: pipeline shape must not override the / classification.');
  }

  /**
   * Sys_created_at / sys_updated_at pipeline sources are sys_metadata.
   *
   * Mirrors cf_blog.yml: created/changed via skip_on_empty +
   * callback:strtotime.
   */
  public function testTimestampPipelineIsSysMetadata(): void {
    $result = $this->extractor->extract('test_migration', [
      'source' => ['plugin' => 'contentful_export', 'content_type' => 'blogPost', 'locale' => 'en-US'],
      'process' => [
        'created' => [
          ['plugin' => 'skip_on_empty', 'method' => 'process', 'source' => 'sys_created_at'],
          ['plugin' => 'callback', 'callable' => 'strtotime'],
        ],
        'changed' => [
          ['plugin' => 'skip_on_empty', 'method' => 'process', 'source' => 'sys_updated_at'],
          ['plugin' => 'callback', 'callable' => 'strtotime'],
        ],
      ],
      'destination' => ['plugin' => 'entity:node'],
    ]);

    $this->assertArrayHasKey('created', $result['fields']);
    $this->assertSame('sys_metadata', $result['fields']['created']['kind']);
    $this->assertArrayHasKey('changed', $result['fields']);
    $this->assertSame('sys_metadata', $result['fields']['changed']['kind']);
  }

  /**
   * An unrecognised shape yields kind: manual with the raw value preserved.
   */
  public function testUnknownShapeYieldsManual(): void {
    $weirdValue = ['deeply' => ['nested' => 'unknown_plugin']];
    $result = $this->extractor->extract('test_migration', [
      'source' => ['plugin' => 'contentful_export', 'content_type' => 'thing', 'locale' => 'en-US'],
      'process' => ['field_weird' => $weirdValue],
      'destination' => ['plugin' => 'entity:node', 'default_bundle' => 'thing'],
    ]);

    $this->assertArrayHasKey('field_weird', $result['fields']);
    $this->assertSame('manual', $result['fields']['field_weird']['kind']);
    $this->assertArrayHasKey('raw', $result['fields']['field_weird']);
  }

  /**
   * Bundle resolves from process.type.default_value (cf_blog style).
   */
  public function testBundleFromProcessTypeDefaultValue(): void {
    $result = $this->extractor->extract('cf_blog', [
      'source' => ['plugin' => 'contentful_export', 'content_type' => 'blogPost', 'locale' => 'en-US'],
      'process' => [
        'type' => ['plugin' => 'default_value', 'default_value' => 'blog_post'],
      ],
      'destination' => ['plugin' => 'entity:node'],
    ]);

    $this->assertSame('blog_post', $result['drupal']['bundle']);
  }

  /**
   * Bundle resolves from destination.default_bundle.
   */
  public function testBundleFromDestinationDefaultBundle(): void {
    $result = $this->extractor->extract('test_migration', [
      'source' => ['plugin' => 'contentful_export', 'content_type' => 'page', 'locale' => 'en-US'],
      'process' => [],
      'destination' => ['plugin' => 'entity:node', 'default_bundle' => 'page'],
    ]);

    $this->assertSame('page', $result['drupal']['bundle']);
    $this->assertSame('node', $result['drupal']['entity_type']);
  }

  /**
   * An assets migration with no content_type yields null and a warning.
   */
  public function testAssetsMigrationNoContentType(): void {
    $result = $this->extractor->extract('contentful_media', [
      'source' => ['plugin' => 'contentful_export', 'selector' => 'assets', 'locale' => 'en-US'],
      'process' => ['name' => 'file/fileName'],
      'destination' => ['plugin' => 'entity:media'],
    ]);

    $this->assertNull($result['contentful_type'], 'Assets migration must yield null contentful_type.');
    $this->assertNotEmpty($result['warnings'], 'A warning must be emitted when content_type is absent.');
  }

  /**
   * Composite keys field_media_image/target_id + /alt merge into one entry.
   *
   * Mirrors cf_media.yml and contentful_media.yml patterns.
   */
  public function testCompositeFieldSubkeysAreGrouped(): void {
    $result = $this->extractor->extract('cf_media', [
      'source' => ['plugin' => 'contentful_export', 'selector' => 'assets', 'locale' => 'en-US'],
      'process' => [
        'field_media_image/target_id' => [
          'plugin' => 'contentful_asset_to_media',
          'source' => 'file/url',
        ],
        'field_media_image/alt' => 'title',
      ],
      'destination' => ['plugin' => 'entity:media'],
    ]);

    $this->assertArrayHasKey('field_media_image', $result['fields'], 'Composite keys must be grouped under parent.');
    $this->assertArrayNotHasKey('field_media_image/target_id', $result['fields'], 'Sub-key must not appear at top level.');
    $this->assertArrayNotHasKey('field_media_image/alt', $result['fields'], 'Sub-key must not appear at top level.');

    $entry = $result['fields']['field_media_image'];
    $this->assertArrayHasKey('subkeys', $entry);
    $this->assertArrayHasKey('target_id', $entry['subkeys']);
    $this->assertArrayHasKey('alt', $entry['subkeys']);
    // Target_id is media_reference via contentful_asset_to_media.
    $this->assertSame('media_reference', $entry['subkeys']['target_id']['kind']);
    // Alt is plain scalar.
    $this->assertSame('scalar', $entry['subkeys']['alt']['kind']);
    // Dominant kind is media_reference (non-scalar wins).
    $this->assertSame('media_reference', $entry['kind']);
  }

  /**
   * A bundle key using a custom plugin is classified as sys_metadata.
   *
   * Mirrors cf_media.yml: bundle: {plugin: contentful_media_bundle, source: …}.
   */
  public function testBundleKeyWithCustomPluginIsSysMetadata(): void {
    $result = $this->extractor->extract('cf_media', [
      'source' => ['plugin' => 'contentful_export', 'selector' => 'assets', 'locale' => 'en-US'],
      'process' => [
        'bundle' => ['plugin' => 'contentful_media_bundle', 'source' => 'file/contentType'],
      ],
      'destination' => ['plugin' => 'entity:media'],
    ]);

    $this->assertArrayHasKey('bundle', $result['fields']);
    $this->assertSame('sys_metadata', $result['fields']['bundle']['kind']);
  }

}
