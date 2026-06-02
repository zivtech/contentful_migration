<?php

declare(strict_types=1);

namespace Drupal\contentful_migration\RichText;

use Contentful\Core\Api\Link;
use Contentful\Core\Api\LinkResolverInterface;
use Contentful\Core\Resource\ResourceInterface;

/**
 * LinkResolver that never calls the Contentful API.
 *
 * Returns a SysIdResource carrying the link's sys.id, so the Rich Text Parser
 * can build embed nodes without hydrating real Contentful entries. The actual
 * sys.id → Drupal entity resolution happens later, in the custom node
 * renderers, via the Migrate map.
 */
final class SysIdLinkResolver implements LinkResolverInterface {

  /**
   * {@inheritdoc}
   */
  public function resolveLink(Link $link, array $parameters = []): ResourceInterface {
    return new SysIdResource($link->getId(), $link->getLinkType());
  }

  /**
   * {@inheritdoc}
   */
  public function resolveLinkCollection(array $links, array $parameters = []): array {
    return array_map(fn(Link $link) => $this->resolveLink($link, $parameters), $links);
  }

}
