<?php

declare(strict_types=1);

namespace Drupal\Tests\contentful_migration\Unit\RichText;

use Contentful\RichText\Node\Hyperlink;
use Contentful\RichText\RendererInterface;
use Drupal\contentful_migration\RichText\DrupalHyperlink;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;

/**
 * Unit coverage for DrupalHyperlink (the plain-hyperlink scheme guard).
 */
#[CoversClass(DrupalHyperlink::class)]
#[Group('contentful_migration')]
class DrupalHyperlinkTest extends UnitTestCase {

  /**
   * A mock plain-hyperlink node carrying a target URI and optional title.
   */
  private function node(string $uri, string $title = ''): Hyperlink {
    $node = $this->createMock(Hyperlink::class);
    $node->method('getUri')->willReturn($uri);
    $node->method('getTitle')->willReturn($title);
    // The label is produced by the renderer mock's renderCollection(), so the
    // child content itself is never iterated here.
    $node->method('getContent')->willReturn([]);

    return $node;
  }

  /**
   * A renderer whose renderCollection() returns the canned link label.
   */
  private function renderer(string $label): RendererInterface {
    $renderer = $this->createMock(RendererInterface::class);
    $renderer->method('renderCollection')->willReturn($label);
    return $renderer;
  }

  /**
   * An https URI renders a real anchor carrying that href.
   */
  public function testHttpsUriRendersAnchor(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->never())->method('warning');

    $renderer = new DrupalHyperlink($logger);
    $html = $renderer->render($this->renderer('docs'), $this->node('https://example.org/x'));

    $this->assertSame('<a href="https://example.org/x">docs</a>', $html);
  }

  /**
   * A javascript: URI is rejected: label kept, no anchor, no scheme leaked.
   *
   * This is the XSS regression: the library default would emit the raw href.
   */
  public function testJavascriptUriRejected(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('warning');

    $renderer = new DrupalHyperlink($logger);
    $html = $renderer->render($this->renderer('click me'), $this->node('javascript:alert(1)'));

    $this->assertSame('click me', $html, 'Link text is kept; the dangerous anchor is dropped.');
    $this->assertStringNotContainsString('<a', $html);
    $this->assertStringNotContainsString('javascript:', $html);
  }

  /**
   * A data: URI is rejected the same way (label kept, anchor dropped).
   */
  public function testDataUriRejected(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('warning');

    $renderer = new DrupalHyperlink($logger);
    $html = $renderer->render($this->renderer('img'), $this->node('data:text/html,<script>'));

    $this->assertSame('img', $html);
    $this->assertStringNotContainsString('<a', $html);
    $this->assertStringNotContainsString('data:', $html);
  }

  /**
   * A whitespace/control-prefixed dangerous scheme is still rejected.
   *
   * Leading whitespace or control characters make parse_url() report a NULL
   * scheme — the allowlist would otherwise pass it through and render a live
   * anchor. The renderer trims such characters before parsing, so each of these
   * bypass attempts must degrade to plain text, not a link.
   */
  #[DataProvider('schemeBypassUris')]
  public function testWhitespacePrefixedSchemeRejected(string $uri): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('warning');

    $renderer = new DrupalHyperlink($logger);
    $html = $renderer->render($this->renderer('gotcha'), $this->node($uri));

    $this->assertSame('gotcha', $html, 'A trimmed dangerous scheme must not render an anchor.');
    $this->assertStringNotContainsString('<a', $html);
    $this->assertStringNotContainsString('javascript:', $html);
  }

  /**
   * Provides whitespace/control-char scheme bypass attempts.
   *
   * @return array<string, array{string}>
   *   Each case is a single URI whose dangerous scheme is hidden behind a
   *   leading, trailing, or embedded whitespace or control character.
   */
  public static function schemeBypassUris(): array {
    return [
      'tab-prefixed javascript' => ["\tjavascript:alert(1)"],
      'space-prefixed javascript' => [' javascript:alert(1)'],
      'newline-prefixed javascript' => ["\njavascript:alert(1)"],
      // Embedded control chars: browsers strip tab/CR/LF from an href before
      // evaluating the scheme, so these reform "javascript:" on click.
      'tab-embedded javascript' => ["ja\tvascript:alert(1)"],
      'newline-embedded javascript' => ["java\nscript:alert(1)"],
      'cr-embedded javascript' => ["java\rscript:alert(1)"],
      'control-before-colon javascript' => ["javascript\t:alert(1)"],
    ];
  }

  /**
   * A relative URI (no scheme) renders an anchor: relative/anchor links are OK.
   */
  public function testRelativeUriRendersAnchor(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->never())->method('warning');

    $renderer = new DrupalHyperlink($logger);
    $html = $renderer->render($this->renderer('about'), $this->node('/about'));

    $this->assertSame('<a href="/about">about</a>', $html);
  }

  /**
   * The mailto: and tel: schemes are allowed (common authored link schemes).
   */
  public function testMailtoAndTelAllowed(): void {
    $renderer = new DrupalHyperlink($this->createMock(LoggerInterface::class));

    $this->assertStringContainsString(
      'href="mailto:a@b.test"',
      $renderer->render($this->renderer('email'), $this->node('mailto:a@b.test')),
    );
    $this->assertStringContainsString(
      'href="tel:+15551234"',
      $renderer->render($this->renderer('call'), $this->node('tel:+15551234')),
    );
  }

  /**
   * A non-empty title is preserved as the anchor title attribute.
   */
  public function testTitleAttributePreserved(): void {
    $renderer = new DrupalHyperlink($this->createMock(LoggerInterface::class));
    $html = $renderer->render($this->renderer('go'), $this->node('https://example.org', 'Read more'));

    $this->assertStringContainsString('title="Read more"', $html);
  }

}
