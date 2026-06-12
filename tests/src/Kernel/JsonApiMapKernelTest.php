<?php

declare(strict_types=1);

namespace Drupal\Tests\contentful_migration\Kernel;

use Drupal\contentful_migration\JsonApiMap\MigrationMapExtractor;
use Drupal\contentful_migration\JsonApiMap\ResourceNameResolver;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\migrate\Kernel\MigrateTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Kernel coverage for migration-map extraction and JSON:API name resolution.
 *
 * Three concerns this test covers that unit tests cannot:
 *  1. Plugin discovery — that the test module's migrations (including
 *     cf_jsonapi_map_fixture) are discoverable via plugin.manager.migration
 *     after Drupal's migration system bootstraps.
 *  2. Extraction against real discovered definitions — that the extractor
 *     produces correct output for the actual YAMLs in the test module, not
 *     just handwritten arrays.
 *  3. JSON:API name resolution — that ResourceNameResolver correctly calls
 *     the live jsonapi.resource_type.repository when jsonapi is installed.
 *
 * Truth boundary: the jsonapi resolution test creates a minimal NodeType only;
 * no field storage or instances are created (field public names fall back to
 * the drupal name when the field is unknown to jsonapi, which is expected for
 * dynamically-created bundles not backed by real field config).
 */
#[Group('contentful_migration')]
#[RunTestsInSeparateProcesses]
class JsonApiMapKernelTest extends MigrateTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'file',
    'node',
    'migrate',
    'serialization',
    'jsonapi',
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
    $this->installEntitySchema('file');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['field', 'node', 'filter']);

    NodeType::create(['type' => 'blog_post', 'name' => 'Blog Post'])->save();
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    NodeType::create(['type' => 'card', 'name' => 'Card'])->save();
  }

  /**
   * The test module's migrations are discoverable via plugin.manager.migration.
   *
   * Verifies that both cf_blog (the original test migration) and
   * cf_jsonapi_map_fixture (added for this plan) are found after filtering
   * to contentful_export source plugin.
   */
  public function testContentfulMigrationsAreDiscoverable(): void {
    $definitions = $this->container->get('plugin.manager.migration')->getDefinitions();

    $contentfulIds = array_keys(array_filter(
      $definitions,
      static fn (array $def): bool => ($def['source']['plugin'] ?? '') === 'contentful_export',
    ));

    $this->assertContains('cf_blog', $contentfulIds, 'cf_blog must be discoverable via contentful_export filter.');
    $this->assertContains('cf_jsonapi_map_fixture', $contentfulIds, 'cf_jsonapi_map_fixture must be discoverable via contentful_export filter.');
  }

  /**
   * Extractor produces correct output for cf_blog (type via default_value).
   *
   * Cf_blog uses process.type.default_value (not destination.default_bundle),
   * and its timestamps are sys_metadata.
   */
  public function testExtractCfBlog(): void {
    $definitions = $this->container->get('plugin.manager.migration')->getDefinitions();
    $this->assertArrayHasKey('cf_blog', $definitions, 'cf_blog must be in definitions.');

    $extractor = new MigrationMapExtractor();
    $result = $extractor->extract('cf_blog', $definitions['cf_blog']);

    $this->assertSame('blogPost', $result['contentful_type']);
    $this->assertSame('blog_post', $result['drupal']['bundle'], 'Bundle must come from process.type.default_value.');
    $this->assertSame('node', $result['drupal']['entity_type']);

    $this->assertArrayHasKey('title', $result['fields']);
    $this->assertSame('scalar', $result['fields']['title']['kind']);

    $this->assertArrayHasKey('created', $result['fields']);
    $this->assertSame('sys_metadata', $result['fields']['created']['kind']);

    $this->assertArrayHasKey('changed', $result['fields']);
    $this->assertSame('sys_metadata', $result['fields']['changed']['kind']);
  }

  /**
   * Extractor classifies cf_jsonapi_map_fixture correctly.
   *
   * Covers: destination.default_bundle, migration_lookup → reference,
   * sub_process + nested lookup → reference_multiple, richtext pipeline,
   * identity field detection, and path/alias pipeline → unmapped.
   */
  public function testExtractFixtureMigration(): void {
    $definitions = $this->container->get('plugin.manager.migration')->getDefinitions();
    $this->assertArrayHasKey('cf_jsonapi_map_fixture', $definitions, 'cf_jsonapi_map_fixture must be in definitions.');

    $extractor = new MigrationMapExtractor();
    $result = $extractor->extract('cf_jsonapi_map_fixture', $definitions['cf_jsonapi_map_fixture']);

    $this->assertSame('article', $result['contentful_type']);
    $this->assertSame('article', $result['drupal']['bundle'], 'Bundle must come from destination.default_bundle.');

    $this->assertSame('field_contentful_id', $result['identity_field'], 'sys_id mapping must register as identity_field.');
    $this->assertArrayNotHasKey('field_contentful_id', $result['fields'], 'Identity field must not appear in fields.');

    $this->assertArrayHasKey('field_hero_image', $result['fields']);
    $this->assertSame('reference', $result['fields']['field_hero_image']['kind']);

    $this->assertArrayHasKey('field_sections', $result['fields']);
    $this->assertSame('reference_multiple', $result['fields']['field_sections']['kind']);

    // body/value and path/alias must be in unmapped (key-name-first rule).
    $this->assertArrayHasKey('body/value', $result['unmapped']);
    $this->assertSame('non_field_destination', $result['unmapped']['body/value']['reason']);

    $this->assertArrayHasKey('path/alias', $result['unmapped']);
    $this->assertSame('non_field_destination', $result['unmapped']['path/alias']['reason']);
  }

  /**
   * ResourceNameResolver returns jsonapi-sourced names when module is active.
   *
   * With jsonapi installed and the blog_post NodeType created in setUp,
   * the resolver must report resolved_from: jsonapi and the standard
   * node--blog_post resource type name.
   */
  public function testResolverUsesJsonApiWhenInstalled(): void {
    $resolver = new ResourceNameResolver($this->container);
    $result = $resolver->resolve('node', 'blog_post', ['title']);

    $this->assertSame('jsonapi', $result['resolved_from'], 'Must use jsonapi when the module is installed.');
    $this->assertSame('node--blog_post', $result['resource_type']);
    $this->assertSame('/jsonapi/node/blog_post', $result['endpoint']);
    $this->assertNull($result['warning'], 'No warning expected when jsonapi is installed.');
  }

}
