<?php

declare(strict_types=1);

namespace Drupal\contentful_migration\RichText;

use Contentful\RichText\Node\AssetHyperlink as AssetHyperlinkNode;
use Contentful\RichText\Node\NodeInterface;
use Contentful\RichText\NodeRenderer\NodeRendererInterface;
use Contentful\RichText\RendererInterface;
use Psr\Log\LoggerInterface;

/**
 * Renders an inline Contentful asset-hyperlink as plain text (link dropped).
 *
 * A Contentful `asset-hyperlink` links body text to an asset (a file: PDF,
 * download, etc.). The faithful Drupal target is the migrated file's URL, not
 * a Media entity's canonical page (a Drupal-ism the author never authored, and
 * often access-restricted). Resolving to the file URL means traversing
 * Media → source field → File and coupling this always-constructed renderer to
 * the file/media modules — which this module deliberately does NOT depend on
 * (its only hard dependency is core Migrate).
 *
 * Against that cost, asset-hyperlinks are vanishingly rare in practice (a
 * corpus scan of 218 real Contentful exports found a single instance, versus
 * hundreds of plain external hyperlinks the library already handles). So this
 * renderer takes the proportionate, honest path: it keeps the visible link
 * text (overriding the library's useless `<a href="#Asset-ID">` default) and
 * logs the dropped link, making the loss visible rather than silent.
 *
 * The log is the upgrade trigger: if a real space turns out to use
 * asset-hyperlinks heavily, the warnings make that obvious, and this renderer
 * can be upgraded to emit the file URL then — at which point the file/media
 * coupling is justified by real usage rather than paid for speculatively.
 */
final class DrupalAssetHyperlink implements NodeRendererInterface {

  public function __construct(
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function supports(NodeInterface $node): bool {
    return $node instanceof AssetHyperlinkNode;
  }

  /**
   * {@inheritdoc}
   */
  public function render(RendererInterface $renderer, NodeInterface $node, array $context = []): string {
    assert($node instanceof AssetHyperlinkNode);

    // Keep the link label (with its inline marks) and drop only the anchor.
    $label = $renderer->renderCollection($node->getContent(), $context);
    $this->logger->warning('Asset hyperlink @id rendered as plain text (no file link).', [
      '@id' => $node->getAsset()->getId(),
    ]);
    return $label;
  }

}
