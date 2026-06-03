<?php

declare(strict_types=1);

namespace Drupal\Tests\contentful_migration\Unit\RichText;

use Contentful\Core\Resource\AssetInterface;
use Contentful\RichText\Node\AssetHyperlink;
use Contentful\RichText\RendererInterface;
use Drupal\contentful_migration\RichText\ContentfulAssetUrlResolverInterface;
use Drupal\contentful_migration\RichText\DrupalAssetHyperlink;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 * @coversDefaultClass \Drupal\contentful_migration\RichText\DrupalAssetHyperlink
 * @group contentful_migration
 */
class DrupalAssetHyperlinkTest extends UnitTestCase {

  /**
   * Without a URL resolver, the hyperlink degrades to text and logs the loss.
   *
   * This is the modules-absent proof: ContentfulRichText::create() injects no
   * resolver when media/file are not installed, and this construction — the
   * logger alone — is exactly what that produces. It also overrides the
   * library's useless `#Asset-ID` default.
   *
   * @covers ::render
   */
  public function testRendersLinkTextAndLogs(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('warning');

    $node = $this->mockNode('doc1');
    $renderer = $this->mockRenderer('the brochure');

    $html = (new DrupalAssetHyperlink($logger))->render($renderer, $node);

    $this->assertSame('the brochure', $html, 'Asset hyperlink degrades to its link text.');
    $this->assertStringNotContainsString('<a', $html);
  }

  /**
   * With a resolver hit, the hyperlink emits a real, escaped file anchor.
   *
   * @covers ::render
   */
  public function testEmitsFileAnchorWhenResolved(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->never())->method('warning');

    $node = $this->mockNode('doc1', 'Q3 "Results" & more');
    $renderer = $this->mockRenderer('the brochure');

    $resolver = $this->createMock(ContentfulAssetUrlResolverInterface::class);
    $resolver->expects($this->once())
      ->method('resolveFileUrl')
      ->with('doc1')
      ->willReturn('/files/contentful/q3 "report".pdf');

    $html = (new DrupalAssetHyperlink($logger, $resolver))->render($renderer, $node);

    $this->assertSame(
      '<a href="/files/contentful/q3 &quot;report&quot;.pdf" title="Q3 &quot;Results&quot; &amp; more">the brochure</a>',
      $html,
      'Anchor carries the escaped file URL and title around the link text.',
    );
  }

  /**
   * A resolver miss (asset unresolvable) degrades exactly like no resolver.
   *
   * @covers ::render
   */
  public function testDegradesWhenResolverReturnsNull(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('warning');

    $node = $this->mockNode('doc1');
    $renderer = $this->mockRenderer('the brochure');

    $resolver = $this->createMock(ContentfulAssetUrlResolverInterface::class);
    $resolver->method('resolveFileUrl')->willReturn(NULL);

    $html = (new DrupalAssetHyperlink($logger, $resolver))->render($renderer, $node);

    $this->assertSame('the brochure', $html, 'Unresolvable asset keeps the link text, drops the anchor.');
    $this->assertStringNotContainsString('<a', $html);
  }

  /**
   * Builds an asset-hyperlink node mock for the given asset id and title.
   */
  private function mockNode(string $assetId, string $title = ''): AssetHyperlink {
    $asset = $this->createMock(AssetInterface::class);
    $asset->method('getId')->willReturn($assetId);

    $node = $this->createMock(AssetHyperlink::class);
    $node->method('getAsset')->willReturn($asset);
    $node->method('getContent')->willReturn([]);
    $node->method('getTitle')->willReturn($title);
    return $node;
  }

  /**
   * Builds a renderer mock whose collection render returns the given label.
   */
  private function mockRenderer(string $label): RendererInterface {
    $renderer = $this->createMock(RendererInterface::class);
    $renderer->method('renderCollection')->willReturn($label);
    return $renderer;
  }

}
