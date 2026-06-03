<?php

declare(strict_types=1);

namespace Drupal\Tests\contentful_migration\Kernel;

use Drupal\media\Entity\Media;
use Drupal\media\Entity\MediaType;
use Drupal\migrate\Plugin\MigrateIdMapInterface;
use Drupal\Tests\migrate\Kernel\MigrateTestBase;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

/**
 * End-to-end: a real migrate run stages Contentful assets into image media.
 *
 * Exercises both new process plugins through genuine plugin discovery + the
 * `entity:media` destination:
 *  - `contentful_media_bundle` resolves each asset's MIME to the `image`
 *    bundle.
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

  /**
   * {@inheritdoc}
   */
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

  /**
   * {@inheritdoc}
   */
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

    // Alt text is per-media (from each row's title), even when the file is
    // shared.
    $this->assertSame('Architecture diagram', $media['img1']->get($this->sourceField)->alt);
  }

  /**
   * The nested layout contentful-export actually writes is read locally.
   *
   * `--download-assets` mirrors each asset's URL on disk
   * (`<dir>/<host>/<space>/<id>/<hash>/<file>`), NOT a flat directory. The
   * plugin derives that path from the asset's own URL. A throwing http_client
   * guarantees the test fails loudly if any asset misses the local read and
   * falls through to the CDN — the local-read claim is hermetic, not
   * incidental.
   *
   * Truth boundary: the fixture URLs are all images.ctfassets.net; the
   * non-image hosts (assets./downloads.ctfassets.net for PDF/video) follow
   * the same per-asset-URL derivation but their real on-disk layout is
   * unverified until a real --download-assets run (CDN fallback guards it).
   */
  public function testNestedStagingLayoutIsReadLocally(): void {
    // Mirror the fixture URLs under the nested root, as contentful-export
    // lays them out: //images.ctfassets.net/spacexyz/<id>/<hash>/<file>.
    $pngA = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR4nGNgAAIAAAUAAen63NgAAAAASUVORK5CYII=');
    $pngB = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    $fileSystem = $this->container->get('file_system');
    $nested = [
      'public://cf-assets-nested/images.ctfassets.net/spacexyz/img1/abc/diagram.png' => $pngA,
      'public://cf-assets-nested/images.ctfassets.net/spacexyz/img2/def/diagram-copy.png' => $pngA,
      'public://cf-assets-nested/images.ctfassets.net/spacexyz/img3/ghi/logo.png' => $pngB,
    ];
    foreach ($nested as $uri => $bytes) {
      $dir = dirname($uri);
      $fileSystem->prepareDirectory($dir, $fileSystem::CREATE_DIRECTORY);
      file_put_contents($uri, $bytes);
    }

    // Any HTTP attempt fails the row (and so the assertions below): the only
    // way this test passes is the nested local read working for every asset.
    $deny = new MockHandler(array_fill(0, 3, new RequestException(
      'Network disabled: the nested local read must satisfy every asset.',
      new Request('GET', 'https://images.ctfassets.net/denied'),
    )));
    $this->container->set('http_client', new Client(['handler' => HandlerStack::create($deny)]));

    $this->executeMigration('cf_media_nested');

    $lookup = $this->container->get('migrate.lookup');
    $mediaStorage = $this->container->get('entity_type.manager')->getStorage('media');
    $fids = [];
    foreach (['img1', 'img2', 'img3'] as $sysId) {
      $result = $lookup->lookup('cf_media_nested', [$sysId]);
      $this->assertNotEmpty($result, "Asset {$sysId} migrated from the nested layout.");
      $first = reset($result);
      $entity = $mediaStorage->load(reset($first));
      $this->assertInstanceOf(Media::class, $entity);
      $this->assertFalse($entity->get($this->sourceField)->isEmpty(), "Asset {$sysId} media references a file.");
      $fids[$sysId] = (int) $entity->get($this->sourceField)->target_id;
    }
    // Dedupe still holds through the nested read path.
    $this->assertSame($fids['img1'], $fids['img2'], 'Byte-identical nested assets share one file.');
    $this->assertNotSame($fids['img1'], $fids['img3'], 'Distinct nested asset gets its own file.');
  }

  /**
   * With no local staging, assets fetch from the CDN; empty URLs skip rows.
   *
   * Closes the two paths the original hermetic test deliberately left
   * unexercised: fetchRemote()'s protocol-relative `//` -> `https:`
   * normalization (proven against the mock's request history) with the
   * fetched bytes becoming the managed file, and the empty-`file/url` row
   * skipping (IGNORED, not failed) before any HTTP happens — the mock queue
   * holds exactly one response, so a second request would fail the run.
   */
  public function testCdnFetchAndEmptyUrlSkip(): void {
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR4nGNgAAIAAAUAAen63NgAAAAASUVORK5CYII=');
    file_put_contents(
      'public://cf-cdn-export.json',
      file_get_contents(__DIR__ . '/../../fixtures/synthetic-media-cdn-export.json'),
    );

    // One queued response for the one asset with a URL; a request history to
    // assert what was actually fetched.
    $history = [];
    $mock = new MockHandler([new Response(200, [], $png)]);
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($history));
    $this->container->set('http_client', new Client(['handler' => $stack]));

    $this->executeMigration('cf_media_cdn');

    // The fetch was the normalized https URL derived from the
    // protocol-relative fixture value.
    $this->assertCount(1, $history, 'Exactly one HTTP fetch happened (the empty-URL row never hit the network).');
    $this->assertSame(
      'https://images.ctfassets.net/spacexyz/cdn1/xyz/remote.png',
      (string) $history[0]['request']->getUri(),
      'The protocol-relative CDN URL was fetched as https.',
    );

    // The fetched bytes became the managed file behind an image media.
    $lookup = $this->container->get('migrate.lookup');
    $cdnResult = $lookup->lookup('cf_media_cdn', ['cdn1']);
    $this->assertNotEmpty($cdnResult, 'The CDN-fetched asset migrated to a media entity.');
    $first = reset($cdnResult);
    $media = $this->container->get('entity_type.manager')->getStorage('media')->load(reset($first));
    $this->assertInstanceOf(Media::class, $media);
    $fid = (int) $media->get($this->sourceField)->target_id;
    $file = $this->container->get('entity_type.manager')->getStorage('file')->load($fid);
    $this->assertSame($png, file_get_contents($file->getFileUri()), 'The managed file holds the fetched CDN bytes.');

    // The empty-URL asset was skipped — recorded IGNORED in the id-map, no
    // media created. (A skipped row still has a map entry; its destination
    // ids are NULL, so filter rather than expect an absent row.)
    $emptyResult = $lookup->lookup('cf_media_cdn', ['empty1']);
    $this->assertEmpty(array_filter($emptyResult ? reset($emptyResult) : []), 'The empty-URL asset produced no destination id.');
    $this->assertCount(1, $this->container->get('entity_type.manager')->getStorage('media')->loadMultiple(), 'Only the fetchable asset became media.');
    $mapRow = $this->getMigration('cf_media_cdn')->getIdMap()->getRowBySource(['sys_id' => 'empty1']);
    $this->assertSame(MigrateIdMapInterface::STATUS_IGNORED, (int) $mapRow['source_row_status'], 'The empty-URL row was skipped, not failed.');
  }

}
