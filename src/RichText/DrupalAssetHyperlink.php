<?php

declare(strict_types=1);

namespace Drupal\contentful_migration\RichText;

use Contentful\RichText\Node\AssetHyperlink as AssetHyperlinkNode;
use Contentful\RichText\Node\NodeInterface;
use Contentful\RichText\NodeRenderer\NodeRendererInterface;
use Contentful\RichText\RendererInterface;
use Psr\Log\LoggerInterface;

/**
 * Renders an inline Contentful asset-hyperlink as a link to the migrated file.
 *
 * A Contentful `asset-hyperlink` links body text to an asset (a file: PDF,
 * download, etc.). The faithful Drupal target is the migrated *file's* URL —
 * not a Media entity's canonical page, which is a Drupal-ism the author never
 * authored and which is often access-restricted.
 *
 * The file resolution is a gated upgrade. When the media and file modules are
 * installed, ContentfulRichText::create() injects a
 * ContentfulAssetUrlResolver and this renderer emits a real anchor to the
 * migrated file (media -> source field -> file -> URL; see the resolver for
 * the chain). When they are absent the resolver is NULL and the renderer
 * keeps the visible link text (overriding the library's useless
 * `<a href="#Asset-ID">` default), logging each dropped link so the loss is
 * visible rather than silent. The same degrade covers an unresolvable asset:
 * not migrated, media deleted, or a source with no local file (oEmbed).
 *
 * This split keeps the renderer itself free of any file/media coupling — the
 * module's only hard dependency remains core Migrate — while sites that
 * migrate assets to media get working file links with zero configuration.
 */
final class DrupalAssetHyperlink implements NodeRendererInterface {

  public function __construct(
    private readonly LoggerInterface $logger,
    private readonly ?ContentfulAssetUrlResolverInterface $assetUrlResolver = NULL,
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

    // The link label (with its inline marks), also the degrade output: text
    // kept, dead anchor dropped.
    $label = $renderer->renderCollection($node->getContent(), $context);
    $sysId = $node->getAsset()->getId();

    $href = $this->assetUrlResolver?->resolveFileUrl($sysId);
    if ($href !== NULL) {
      $markup = sprintf('<a href="%s"', htmlspecialchars($href, ENT_QUOTES));
      $title = $node->getTitle();
      if ($title !== '') {
        $markup .= sprintf(' title="%s"', htmlspecialchars($title, ENT_QUOTES));
      }
      return $markup . '>' . $label . '</a>';
    }

    $this->logger->warning('Asset hyperlink @id rendered as plain text (no file link).', [
      '@id' => $sysId,
    ]);
    return $label;
  }

}
