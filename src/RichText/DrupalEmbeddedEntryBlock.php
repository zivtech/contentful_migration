<?php

declare(strict_types=1);

namespace Drupal\contentful_migration\RichText;

use Contentful\RichText\Node\EmbeddedEntryBlock as EmbeddedEntryBlockNode;
use Contentful\RichText\Node\NodeInterface;
use Contentful\RichText\NodeRenderer\NodeRendererInterface;
use Contentful\RichText\RendererInterface;
use Psr\Log\LoggerInterface;

/**
 * Renders a Contentful embedded-entry-block as a Drupal entity embed token.
 *
 * Overrides the library default (which emits a useless `<div>Entry#ID</div>`
 * placeholder). Resolves the entry's sys.id to a migrated Drupal entity via the
 * injected resolver; logs and emits nothing when the target is not (yet)
 * resolvable, so loss is visible rather than silent.
 */
final class DrupalEmbeddedEntryBlock implements NodeRendererInterface {

  public function __construct(
    private readonly ContentfulEmbedResolverInterface $resolver,
    private readonly LoggerInterface $logger,
  ) {}

  public function supports(NodeInterface $node): bool {
    return $node instanceof EmbeddedEntryBlockNode;
  }

  public function render(RendererInterface $renderer, NodeInterface $node, array $context = []): string {
    assert($node instanceof EmbeddedEntryBlockNode);
    $sysId = $node->getEntry()->getId();
    $resolved = $this->resolver->resolve($sysId, 'Entry');
    if ($resolved === NULL) {
      $this->logger->warning('Unresolved embedded entry @id in Rich Text; emitted nothing.', ['@id' => $sysId]);
      return '';
    }
    return sprintf(
      '<drupal-entity-embed data-entity-type="%s" data-entity-uuid="%s"></drupal-entity-embed>',
      htmlspecialchars($resolved['entity_type'], ENT_QUOTES),
      htmlspecialchars($resolved['uuid'], ENT_QUOTES),
    );
  }

}
