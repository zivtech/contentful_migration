<?php

declare(strict_types=1);

namespace Drupal\contentful_migration\Plugin\migrate\process;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\file\FileRepositoryInterface;
use Drupal\migrate\MigrateException;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\MigrateSkipRowException;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Stages a Contentful asset binary into a Drupal managed file.
 *
 * Returns the file entity id, intended for a media source field target_id
 * (`field_media_image/target_id`) with an `entity:media` destination. The
 * `entity:media` destination wraps the file; this plugin is only responsible
 * for *acquiring the bytes* and producing a deduplicated managed file.
 *
 * NOT migrate_file_to_media (deliberate, 2026-05-30): that module is a
 * Drupal-to-Drupal tool — its source plugins iterate existing Drupal file
 * fields and its hash-dedupe needs a pre-run drush pass over file entities that
 * do not exist when ingesting from a Contentful export. Its only reusable piece
 * (`media_file_copy`) is a thin core `file_copy` subclass. So this plugin owns
 * the one concern no stock plugin covers for Contentful: choosing the asset
 * source (local-staged disk vs CDN URL) and deduplicating by content hash.
 *
 * Behaviour:
 *  - Source: if `local_source_dir` is configured and the file is present there
 *    (assets pre-downloaded by `contentful-export --download-assets`), the
 *    local bytes are used — air-gappable, immune to CDN URL rot mid-run.
 *    Otherwise the (protocol-relative) CDN URL is fetched over HTTP.
 *  - Dedupe by SHA-256 of the bytes: identical content uploaded under different
 *    Contentful asset ids yields a single file entity (the hash->fid map is
 *    kept in key/value; a hit is re-validated against storage so a rollback
 *    that removed the file forces a clean re-create).
 *  - An empty URL skips the row (an image media item with no file is invalid).
 *
 * @code
 * field_media_image/target_id:
 *   plugin: contentful_asset_to_media
 *   source: file/url
 *   destination: 'public://contentful'        # optional, dir for staged files
 *   local_source_dir: 'private://contentful/assets'  # optional, pre-download dir
 * @endcode
 */
#[\Drupal\migrate\Attribute\MigrateProcess('contentful_asset_to_media')]
class ContentfulAssetToMedia extends ProcessPluginBase implements ContainerFactoryPluginInterface {

  /**
   * Key/value collection holding the content-hash -> file id dedupe map.
   */
  private const HASH_COLLECTION = 'contentful_migration.asset_hash';

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly FileSystemInterface $fileSystem,
    private readonly FileRepositoryInterface $fileRepository,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly KeyValueFactoryInterface $keyValueFactory,
    private readonly ClientInterface $httpClient,
    private readonly LoggerInterface $logger,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('file_system'),
      $container->get('file.repository'),
      $container->get('entity_type.manager'),
      $container->get('keyvalue'),
      $container->get('http_client'),
      $container->get('logger.factory')->get('contentful_migration'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property): int {
    $url = is_string($value) ? trim($value) : '';
    if ($url === '') {
      throw new MigrateSkipRowException('Contentful asset has no file URL; row skipped.');
    }

    $data = $this->readAssetBytes($url);
    $hash = hash('sha256', $data);

    $store = $this->keyValueFactory->get(self::HASH_COLLECTION);
    $existingFid = $store->get($hash);
    if ($existingFid !== NULL) {
      // Dedupe hit — reuse only if the file still exists (rollback-safe).
      $existing = $this->entityTypeManager->getStorage('file')->load($existingFid);
      if ($existing !== NULL) {
        return (int) $existingFid;
      }
      // Stale entry (file was rolled back/deleted); drop it and re-create.
      $store->delete($hash);
    }

    $file = $this->writeManagedFile($data, $url);
    $fid = (int) $file->id();
    $store->set($hash, $fid);
    return $fid;
  }

  /**
   * Reads the asset bytes from local disk if staged, else fetches the CDN URL.
   */
  private function readAssetBytes(string $url): string {
    $localDir = $this->configuration['local_source_dir'] ?? NULL;
    if (is_string($localDir) && $localDir !== '') {
      $localUri = rtrim($localDir, '/') . '/' . $this->basename($url);
      if (file_exists($localUri)) {
        $data = @file_get_contents($localUri);
        if ($data !== FALSE) {
          return $data;
        }
        $this->logger->warning('Local asset @uri is present but unreadable; falling back to remote fetch.', ['@uri' => $localUri]);
      }
    }
    return $this->fetchRemote($url);
  }

  /**
   * Fetches a (possibly protocol-relative) Contentful CDN URL over HTTP.
   */
  private function fetchRemote(string $url): string {
    // Contentful asset URLs are protocol-relative ("//images.ctfassets.net/…").
    $normalized = str_starts_with($url, '//') ? 'https:' . $url : $url;
    try {
      return (string) $this->httpClient->get($normalized)->getBody();
    }
    catch (GuzzleException $e) {
      throw new MigrateException(sprintf('Failed to fetch Contentful asset "%s": %s', $normalized, $e->getMessage()));
    }
  }

  /**
   * Writes the bytes to the destination dir as a managed file entity.
   */
  private function writeManagedFile(string $data, string $url) {
    $destDir = rtrim($this->configuration['destination'] ?? 'public://contentful', '/');
    if (!$this->fileSystem->prepareDirectory($destDir, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
      throw new MigrateException(sprintf('Could not prepare asset destination directory "%s".', $destDir));
    }
    $destination = $destDir . '/' . $this->basename($url);
    // FileExists::Rename: a name collision with *different* content (a true
    // hash match was already returned above) gets a distinct filename.
    return $this->fileRepository->writeData($data, $destination, FileExists::Rename);
  }

  /**
   * Extracts a filename from a URL's path, falling back to the raw string.
   */
  private function basename(string $url): string {
    $path = parse_url($url, PHP_URL_PATH);
    return basename(is_string($path) && $path !== '' ? $path : $url);
  }

}
