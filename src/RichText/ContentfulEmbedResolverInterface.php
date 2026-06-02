<?php

declare(strict_types=1);

namespace Drupal\contentful_migration\RichText;

/**
 * Resolves a Contentful sys.id to a migrated Drupal entity reference.
 *
 * Production implementation is backed by the Migrate map (MigrateLookup);
 * tests inject a stub. Decoupling the renderers from MigrateLookup keeps the
 * Rich Text transform unit-testable.
 */
interface ContentfulEmbedResolverInterface {

  /**
   * Resolves a Contentful sys.id to a migrated Drupal entity reference.
   *
   * @param string $sysId
   *   The Contentful entry/asset sys.id from the Rich Text AST.
   * @param string $linkType
   *   Either 'Entry' or 'Asset'.
   *
   * @return array{entity_type: string, uuid: string}|null
   *   The resolved Drupal entity, or NULL if not yet migrated / unresolvable.
   */
  public function resolve(string $sysId, string $linkType): ?array;

}
