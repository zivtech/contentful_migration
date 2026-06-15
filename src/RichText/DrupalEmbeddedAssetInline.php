<?php

declare(strict_types=1);

namespace Drupal\contentful_migration\RichText;

use Contentful\RichText\Node\EmbeddedAssetInline as EmbeddedAssetInlineNode;
use Contentful\RichText\Node\NodeInterface;
use Contentful\RichText\NodeRenderer\NodeRendererInterface;
use Contentful\RichText\RendererInterface;
use Psr\Log\LoggerInterface;

/**
 * Renders a Contentful embedded-asset-inline as a Drupal media embed token.
 *
 * The inline companion to DrupalEmbeddedAssetBlock. The library default renders
 * an inline embedded asset as a useless `<span>Asset#ID</span>` placeholder
 * carrying the raw Contentful sys.id; worse, `embedded-asset-inline` is in the
 * plugin's KNOWN_NODE_TYPES list, so the degradation is not even logged. This
 * renderer resolves the asset's sys.id to a migrated Drupal media entity via
 * the injected resolver and emits the same `drupal-media` token the block
 * renderer does; it logs and emits nothing when the target is not (yet)
 * resolvable, so loss is visible rather than silent.
 */
final class DrupalEmbeddedAssetInline implements NodeRendererInterface {

  public function __construct(
    private readonly ContentfulEmbedResolverInterface $resolver,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function supports(NodeInterface $node): bool {
    return $node instanceof EmbeddedAssetInlineNode;
  }

  /**
   * {@inheritdoc}
   */
  public function render(RendererInterface $renderer, NodeInterface $node, array $context = []): string {
    assert($node instanceof EmbeddedAssetInlineNode);
    $sysId = $node->getAsset()->getId();
    $resolved = $this->resolver->resolve($sysId, 'Asset');
    if ($resolved === NULL) {
      $this->logger->warning('Unresolved inline embedded asset @id in Rich Text; emitted nothing.', ['@id' => $sysId]);
      return '';
    }
    return sprintf(
      '<drupal-media data-entity-type="%s" data-entity-uuid="%s"></drupal-media>',
      htmlspecialchars($resolved['entity_type'], ENT_QUOTES),
      htmlspecialchars($resolved['uuid'], ENT_QUOTES),
    );
  }

}
