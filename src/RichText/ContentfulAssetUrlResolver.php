<?php

declare(strict_types=1);

namespace Drupal\contentful_migration\RichText;

use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;

/**
 * Resolves asset sys.ids to migrated file URLs via the media source field.
 *
 * The single place in the module where media/file entity APIs are touched.
 * Chain: embed resolver (sys.id -> migrated media UUID via the migrate
 * id-map, the same `embed_migrations.Asset` candidates the embed renderers
 * use) -> media entity -> source field value (the media-source API, so
 * non-standard source field names work) -> file entity -> URI ->
 * root-relative URL. Any missing link returns NULL and the caller keeps its
 * plain-text degrade.
 *
 * The URL targets the *file*, not the media canonical page: a Contentful
 * asset-hyperlink is an author-intended link to a file (PDF, download), and a
 * Media entity's canonical page is a Drupal-ism the author never authored —
 * often access-restricted on top. A private:// URI yields /system/files/…,
 * which routes through file_download access control: correct by default.
 *
 * Construction is gated in ContentfulRichText::create() behind the media and
 * file modules being installed — which is what keeps the `use` statements
 * here safe: the class is never instantiated when they are absent.
 *
 * @internal
 *   Not yet stable public API while the module is in alpha/beta. Graduates at
 *   1.0.0, or earlier if an external consumer proves the need.
 */
final class ContentfulAssetUrlResolver implements ContentfulAssetUrlResolverInterface {

  public function __construct(
    private readonly ContentfulEmbedResolverInterface $resolver,
    private readonly EntityRepositoryInterface $entityRepository,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function resolveFileUrl(string $sysId): ?string {
    $resolved = $this->resolver->resolve($sysId, 'Asset');
    if ($resolved === NULL) {
      return NULL;
    }
    $media = $this->entityRepository->loadEntityByUuid($resolved['entity_type'], $resolved['uuid']);
    if (!$media instanceof MediaInterface) {
      return NULL;
    }
    // Media-source API: file-backed sources (image, file, video_file, …)
    // yield the file id; URL-backed sources (oEmbed remote video) yield a
    // string URL and fall out here — a remote video has no local file to
    // link, so the renderer's degrade is the honest output.
    $value = $media->getSource()->getSourceFieldValue($media);
    if (!is_numeric($value)) {
      return NULL;
    }
    $file = $this->entityTypeManager->getStorage('file')->load((int) $value);
    if (!$file instanceof FileInterface) {
      return NULL;
    }
    return $this->fileUrlGenerator->generateString($file->getFileUri());
  }

}
