<?php

declare(strict_types=1);

namespace Drupal\contentful_migration\RichText;

use Contentful\RichText\Node\Hyperlink as HyperlinkNode;
use Contentful\RichText\Node\NodeInterface;
use Contentful\RichText\NodeRenderer\NodeRendererInterface;
use Contentful\RichText\RendererInterface;
use Psr\Log\LoggerInterface;

/**
 * Renders a Contentful plain hyperlink as an HTML anchor with scheme guarding.
 *
 * A Contentful `hyperlink` is an inline link, inside Rich Text, that points at
 * an external URI (as opposed to another entry or asset). The library default
 * emits `<a href="…">` with the AST's `uri` verbatim — no scheme validation —
 * so a malicious Contentful entry can store `javascript:` or `data:` hrefs
 * into Drupal body fields.
 *
 * This renderer allows only: `https`, `http`, `mailto`, `tel`, and NULL scheme
 * (relative paths, fragment anchors). Any other scheme (`javascript`, `data`,
 * `vbscript`, …) is rejected: the visible link text is preserved and the dead
 * anchor dropped, matching the established degrade pattern in
 * DrupalEntryHyperlink. Every rejection is logged so the loss is visible rather
 * than silent.
 */
final class DrupalHyperlink implements NodeRendererInterface {

  /**
   * URI schemes permitted in plain hyperlinks.
   */
  private const ALLOWED_SCHEMES = ['https', 'http', 'mailto', 'tel'];

  public function __construct(
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function supports(NodeInterface $node): bool {
    return $node instanceof HyperlinkNode;
  }

  /**
   * {@inheritdoc}
   */
  public function render(RendererInterface $renderer, NodeInterface $node, array $context = []): string {
    assert($node instanceof HyperlinkNode);

    // Render children for the visible label (inline marks survive).
    // Also the graceful-degradation output: text kept, dead anchor dropped.
    $label = $renderer->renderCollection($node->getContent(), $context);

    // Remove ALL control characters anywhere in the URI, then trim surrounding
    // whitespace. Control characters (tab, CR, LF, NUL, etc.) are never valid
    // in a URI, and browsers strip tab/CR/LF from an href before evaluating the
    // scheme — so an embedded or prefixed one (e.g. "ja\tvascript:" or
    // "\tjavascript:") makes parse_url() return a NULL scheme that would
    // otherwise pass the allowlist and render as a live, clickable anchor.
    $uri = preg_replace('/[\x00-\x1F\x7F]/u', '', $node->getUri()) ?? '';
    $uri = trim($uri);

    $scheme = parse_url($uri, PHP_URL_SCHEME);
    // NULL scheme covers relative paths (/about) and fragment anchors (#top).
    if ($scheme !== NULL && !in_array($scheme, self::ALLOWED_SCHEMES, TRUE)) {
      $this->logger->warning(
        'Hyperlink with scheme "@scheme" rejected for security; rendered as plain text.',
        ['@scheme' => $scheme],
      );
      return $label;
    }

    $markup = sprintf('<a href="%s"', htmlspecialchars($uri, ENT_QUOTES));
    $title = $node->getTitle();
    if ($title !== '') {
      $markup .= sprintf(' title="%s"', htmlspecialchars($title, ENT_QUOTES));
    }
    return $markup . '>' . $label . '</a>';
  }

}
