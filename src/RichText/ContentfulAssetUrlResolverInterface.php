<?php

declare(strict_types=1);

namespace Drupal\contentful_migration\RichText;

/**
 * Resolves a Contentful asset sys.id to the migrated file's URL.
 *
 * Collaborator seam for DrupalAssetHyperlink: present (constructed in
 * ContentfulRichText::create() when the media + file modules are installed),
 * it upgrades inline asset-hyperlinks to real anchors; absent (NULL), the
 * renderer keeps its plain-text degrade and the module stays free of any
 * media/file coupling.
 *
 * @internal
 *   Not yet stable public API while the module is in alpha/beta. Graduates at
 *   1.0.0, or earlier if an external consumer proves the need.
 */
interface ContentfulAssetUrlResolverInterface {

  /**
   * Resolves an asset sys.id to a root-relative URL for the migrated file.
   *
   * @param string $sysId
   *   The Contentful asset sys.id.
   *
   * @return string|null
   *   The migrated file's URL, or NULL when any link in the chain is missing
   *   (asset not migrated, media gone, no file-backed source field).
   */
  public function resolveFileUrl(string $sysId): ?string;

}
