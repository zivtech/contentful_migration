<?php

declare(strict_types=1);

namespace Drupal\contentful_migration\RichText;

use Contentful\RichText\Node\EmbeddedAssetBlock as EmbeddedAssetBlockNode;
use Contentful\RichText\Node\NodeInterface;
use Contentful\RichText\NodeRenderer\NodeRendererInterface;
use Contentful\RichText\RendererInterface;
use Psr\Log\LoggerInterface;

/**
 * Renders a Contentful embedded-asset-block as a Drupal media embed token.
 *
 * Overrides the library default (`<div>Asset#ID</div>`). Resolves the asset's
 * sys.id to a migrated Drupal media entity via the injected resolver.
 */
final class DrupalEmbeddedAssetBlock implements NodeRendererInterface {

  public function __construct(
    private readonly ContentfulEmbedResolverInterface $resolver,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function supports(NodeInterface $node): bool {
    return $node instanceof EmbeddedAssetBlockNode;
  }

  /**
   * {@inheritdoc}
   */
  public function render(RendererInterface $renderer, NodeInterface $node, array $context = []): string {
    assert($node instanceof EmbeddedAssetBlockNode);
    $sysId = $node->getAsset()->getId();
    $resolved = $this->resolver->resolve($sysId, 'Asset');
    if ($resolved === NULL) {
      $this->logger->warning('Unresolved embedded asset @id in Rich Text; emitted nothing.', ['@id' => $sysId]);
      return '';
    }
    return sprintf(
      '<drupal-media data-entity-type="%s" data-entity-uuid="%s"></drupal-media>',
      htmlspecialchars($resolved['entity_type'], ENT_QUOTES),
      htmlspecialchars($resolved['uuid'], ENT_QUOTES),
    );
  }

}
