<?php

declare(strict_types=1);

namespace Drupal\contentful_migration\RichText;

use Contentful\RichText\Node\EmbeddedEntryInline as EmbeddedEntryInlineNode;
use Contentful\RichText\Node\NodeInterface;
use Contentful\RichText\NodeRenderer\NodeRendererInterface;
use Contentful\RichText\RendererInterface;
use Psr\Log\LoggerInterface;

/**
 * Renders a Contentful embedded-entry-inline as a Drupal entity embed token.
 *
 * The inline companion to DrupalEmbeddedEntryBlock. The library default renders
 * an inline embedded entry as a useless `<span>Entry#ID</span>` placeholder
 * carrying the raw Contentful sys.id; worse, `embedded-entry-inline` is in the
 * plugin's KNOWN_NODE_TYPES list, so the degradation is not even logged. This
 * renderer resolves the entry's sys.id to a migrated Drupal entity via the
 * injected resolver and emits the same `drupal-entity-embed` token the block
 * renderer does; it logs and emits nothing when the target is not (yet)
 * resolvable, so loss is visible rather than silent.
 */
final class DrupalEmbeddedEntryInline implements NodeRendererInterface {

  public function __construct(
    private readonly ContentfulEmbedResolverInterface $resolver,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function supports(NodeInterface $node): bool {
    return $node instanceof EmbeddedEntryInlineNode;
  }

  /**
   * {@inheritdoc}
   */
  public function render(RendererInterface $renderer, NodeInterface $node, array $context = []): string {
    assert($node instanceof EmbeddedEntryInlineNode);
    $sysId = $node->getEntry()->getId();
    $resolved = $this->resolver->resolve($sysId, 'Entry');
    if ($resolved === NULL) {
      $this->logger->warning('Unresolved inline embedded entry @id in Rich Text; emitted nothing.', ['@id' => $sysId]);
      return '';
    }
    return sprintf(
      '<drupal-entity-embed data-entity-type="%s" data-entity-uuid="%s"></drupal-entity-embed>',
      htmlspecialchars($resolved['entity_type'], ENT_QUOTES),
      htmlspecialchars($resolved['uuid'], ENT_QUOTES),
    );
  }

}
