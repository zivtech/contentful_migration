<?php

declare(strict_types=1);

namespace Drupal\Tests\contentful_migration\Kernel;

use Drupal\media\Entity\Media;
use Drupal\media\Entity\MediaType;
use Drupal\Tests\migrate\Kernel\MigrateTestBase;

/**
 * End-to-end: a real migrate run stages Contentful assets into image media.
 *
 * Exercises both new process plugins through genuine plugin discovery + the
 * `entity:media` destination:
 *  - `contentful_media_bundle` resolves each asset's MIME to the `image` bundle.
 *  - `contentful_asset_to_media` reads the pre-staged local bytes, creates a
 *    managed file, and deduplicates by content hash.
 *
 * The dedupe claim is the load-bearing assertion the unit tests can't make: two
 * assets (`img1`, `img2`) carry byte-identical files under different names, so
 * the run must produce three media entities but only two managed files, with
 * the two duplicates sharing one file id.
 *
 * Truth boundary: scoped to image assets (one source field). MIME families that
 * map to other bundles (pdf -> document, video, audio) are covered by
 * ContentfulMediaBundleTest, not here, to avoid the cross-bundle source-field
 * problem in a single migration.
 *
 * @group contentful_migration
 */
class ContentfulAssetMigrationTest extends MigrateTestBase {

  protected static $modules = [
    'system',
    'user',
    'field',
    'file',
    'image',
    'media',
    'migrate',
    'contentful_migration',
    'contentful_migration_test',
  ];

  /**
   * The image media type's source field name (created in setUp).
   */
  private string $sourceField;

  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installEntitySchema('media');
    $this->installSchema('file', ['file_usage']);
    $this->installConfig(['field', 'system', 'file', 'image', 'media']);

    // An image media type with its standard source field (field_media_image).
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
    // The migration YAML targets field_media_image explicitly; assert the
    // standard name held so the run lines up with config.
    $this->assertSame('field_media_image', $this->sourceField);

    // Stage the export JSON and the asset binaries where the migration reads
    // them. diagram.png and diagram-copy.png are byte-identical (the dedupe
    // case); logo.png is distinct.
    $fileSystem = $this->container->get('file_system');
    foreach (['public://', 'public://cf-assets'] as $dir) {
      $fileSystem->prepareDirectory($dir, $fileSystem::CREATE_DIRECTORY);
    }
    $pngA = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR4nGNgAAIAAAUAAen63NgAAAAASUVORK5CYII=');
    $pngB = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    file_put_contents('public://cf-assets/diagram.png', $pngA);
    file_put_contents('public://cf-assets/diagram-copy.png', $pngA);
    file_put_contents('public://cf-assets/logo.png', $pngB);
    file_put_contents(
      'public://cf-media-export.json',
      file_get_contents(__DIR__ . '/../../fixtures/synthetic-media-export.json'),
    );
  }

  /**
   * The migration runs, creates image media, and dedupes identical files.
   */
  public function testAssetsMigrateToDedupedImageMedia(): void {
    $this->executeMigration('cf_media');

    $lookup = $this->container->get('migrate.lookup');
    $mediaStorage = $this->container->get('entity_type.manager')->getStorage('media');
    $fileStorage = $this->container->get('entity_type.manager')->getStorage('file');

    // Every asset became a media entity (3 rows -> 3 media).
    $this->assertCount(3, $mediaStorage->loadMultiple(), 'Each Contentful asset produced one media entity.');

    // Each resolves through the id map and carries the image bundle + a file.
    $media = [];
    $fids = [];
    foreach (['img1', 'img2', 'img3'] as $sysId) {
      $result = $lookup->lookup('cf_media', [$sysId]);
      $this->assertNotEmpty($result, "Asset {$sysId} migrated to a media entity.");
      $first = reset($result);
      $entity = $mediaStorage->load(reset($first));
      $this->assertInstanceOf(Media::class, $entity);
      $this->assertSame('image', $entity->bundle(), "Asset {$sysId} resolved to the image bundle.");
      $this->assertFalse($entity->get($this->sourceField)->isEmpty(), "Asset {$sysId} media references a file.");
      $media[$sysId] = $entity;
      $fids[$sysId] = (int) $entity->get($this->sourceField)->target_id;
    }

    // Dedupe by content hash: img1 and img2 are byte-identical -> ONE file,
    // shared; img3 differs -> its own file. So two managed files total.
    $this->assertSame($fids['img1'], $fids['img2'], 'Byte-identical assets share one deduplicated file entity.');
    $this->assertNotSame($fids['img1'], $fids['img3'], 'A distinct asset gets its own file entity.');
    $this->assertCount(2, $fileStorage->loadMultiple(), 'Three assets, two distinct files (one shared via hash dedupe).');

    // Alt text is per-media (from each row's title), even when the file is shared.
    $this->assertSame('Architecture diagram', $media['img1']->get($this->sourceField)->alt);
  }

}
