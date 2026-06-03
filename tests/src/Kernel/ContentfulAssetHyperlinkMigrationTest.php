<?php

declare(strict_types=1);

namespace Drupal\Tests\contentful_migration\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\FileInterface;
use Drupal\filter\Entity\FilterFormat;
use Drupal\media\Entity\MediaType;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\migrate\Kernel\MigrateTestBase;

/**
 * End-to-end: an inline asset-hyperlink resolves to the migrated file's URL.
 *
 * The kernel proof of the B2 gated upgrade: with media + file installed,
 * ContentfulRichText::create() builds the ContentfulAssetUrlResolver, and a
 * real migrate run turns the body's `asset-hyperlink` node into an anchor to
 * the migrated file — exercising the full chain (embed resolver -> media by
 * UUID -> media-source field value -> file entity -> URL generator) plus the
 * create() gate itself.
 *
 * Truth boundaries: the modules-absent path (NULL resolver -> plain-text
 * degrade) cannot be proven here — media/file can't be cleanly uninstalled
 * mid-kernel-test — and is covered by the DrupalAssetHyperlinkTest unit case
 * that constructs the renderer the way create() does without the modules.
 * Private-scheme URLs are asserted at URL-shape level only (the fixture
 * stages public files); /system/files routing is core file's contract.
 *
 * @group contentful_migration
 */
class ContentfulAssetHyperlinkMigrationTest extends MigrateTestBase {

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
    'image',
    'media',
    'node',
    'migrate',
    'contentful_migration',
    'contentful_migration_test',
  ];

  /**
   * The image media type's source field name (created in setUp).
   */
  private string $sourceField;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installEntitySchema('media');
    $this->installEntitySchema('node');
    $this->installSchema('file', ['file_usage']);
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['field', 'system', 'file', 'image', 'media', 'node', 'filter']);

    // An image media type with its standard source field, as in
    // ContentfulAssetMigrationTest.
    $mediaType = MediaType::create([
      'id' => 'image',
      'label' => 'Image',
      'source' => 'image',
    ]);
    $mediaType->save();
    $source = $mediaType->getSource();
    $sourceField = $source->createSourceField($mediaType);
    $sourceField->getFieldStorageDefinition()->save();
    $sourceField->save();
    $this->sourceField = $sourceField->getName();
    $mediaType->set('source_configuration', ['source_field' => $this->sourceField])->save();
    $this->assertSame('field_media_image', $this->sourceField);

    // Destination bundle + body field for the assetPost node.
    NodeType::create(['type' => 'asset_post', 'name' => 'Asset Post'])->save();
    FieldStorageConfig::create([
      'field_name' => 'body',
      'entity_type' => 'node',
      'type' => 'text_with_summary',
    ])->save();
    FieldConfig::create([
      'field_name' => 'body',
      'entity_type' => 'node',
      'bundle' => 'asset_post',
      'label' => 'Body',
    ])->save();

    // The migration stores body/format contentful_embed; a bare format
    // satisfies storage. Rendering through the real recipe-shipped format is
    // ContentfulEmbedRecipeTest's job, not this test's.
    FilterFormat::create(['format' => 'contentful_embed', 'name' => 'Contentful embed'])->save();

    // Stage the export JSON and the asset binaries under the nested layout
    // contentful-export --download-assets writes (mirroring each asset URL).
    $fileSystem = $this->container->get('file_system');
    $pngA = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR4nGNgAAIAAAUAAen63NgAAAAASUVORK5CYII=');
    $pngB = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    $nested = [
      'public://cf-asset-files/images.ctfassets.net/spacexyz/imgA/aaa/diagram.png' => $pngA,
      'public://cf-asset-files/images.ctfassets.net/spacexyz/fileB/bbb/brochure.png' => $pngB,
    ];
    foreach ($nested as $uri => $bytes) {
      $dir = dirname($uri);
      $fileSystem->prepareDirectory($dir, $fileSystem::CREATE_DIRECTORY);
      file_put_contents($uri, $bytes);
    }
    file_put_contents(
      'public://cf-asset-body-export.json',
      file_get_contents(__DIR__ . '/../../fixtures/synthetic-asset-body-export.json'),
    );
  }

  /**
   * The inline asset-hyperlink becomes a real anchor to the migrated file.
   */
  public function testAssetHyperlinkResolvesToMigratedFileUrl(): void {
    $this->executeMigrations(['cf_asset_media', 'cf_asset_post']);

    $lookup = $this->container->get('migrate.lookup');
    $entityTypeManager = $this->container->get('entity_type.manager');

    // Hyperlink target: fileB -> media -> file. The expected href is computed
    // through the same chain + URL generator the resolver uses, so the
    // assertion holds wherever the kernel test's files directory lands.
    $fileBResult = $lookup->lookup('cf_asset_media', ['fileB']);
    $this->assertNotEmpty($fileBResult, 'fileB migrated to a media entity.');
    $first = reset($fileBResult);
    $fileBMedia = $entityTypeManager->getStorage('media')->load(reset($first));
    $this->assertNotNull($fileBMedia, 'The migrated fileB media loads.');
    $fid = (int) $fileBMedia->get($this->sourceField)->target_id;
    $file = $entityTypeManager->getStorage('file')->load($fid);
    $this->assertInstanceOf(FileInterface::class, $file);
    $expectedHref = $this->container->get('file_url_generator')->generateString($file->getFileUri());
    $this->assertStringContainsString('brochure', $expectedHref, 'The expected href points at the staged brochure file.');

    // Host node: apost1, body resolved in the same run.
    $postResult = $lookup->lookup('cf_asset_post', ['apost1']);
    $this->assertNotEmpty($postResult, 'assetPost migrated to a node.');
    $postFirst = reset($postResult);
    $node = $entityTypeManager->getStorage('node')->load(reset($postFirst));
    $this->assertNotNull($node, 'The migrated assetPost node loads.');

    $body = (string) $node->get('body')->value;

    // The full anchor, in place in its sentence: href to the migrated file,
    // the AST's title attribute, the link text preserved.
    $this->assertStringContainsString(
      'Download <a href="' . $expectedHref . '" title="Brochure">the brochure</a> today.',
      $body,
      'Asset hyperlink resolved to the migrated file URL inside its sentence.',
    );
    // The library's `#Asset-ID` placeholder must be gone.
    $this->assertStringNotContainsString('#Asset-', $body);

    // Same run, same resolver map: the embedded-asset-block emitted a
    // media-typed <drupal-media> token (the first media-typed proof — the
    // entry-embed kernel test stands in with nodes).
    $imgAResult = $lookup->lookup('cf_asset_media', ['imgA']);
    $imgAFirst = reset($imgAResult);
    $imgAMedia = $entityTypeManager->getStorage('media')->load(reset($imgAFirst));
    $this->assertNotNull($imgAMedia, 'The migrated imgA media loads.');
    $this->assertStringContainsString(
      '<drupal-media data-entity-type="media" data-entity-uuid="' . $imgAMedia->uuid() . '">',
      $body,
      'Embedded asset block resolved to a media-typed drupal-media token.',
    );
  }

}
