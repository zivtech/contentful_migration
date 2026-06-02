<?php

declare(strict_types=1);

namespace Drupal\Tests\contentful_migration\Unit\RichText;

use Contentful\Core\Resource\AssetInterface;
use Contentful\RichText\Node\AssetHyperlink;
use Contentful\RichText\RendererInterface;
use Drupal\contentful_migration\RichText\DrupalAssetHyperlink;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 * @coversDefaultClass \Drupal\contentful_migration\RichText\DrupalAssetHyperlink
 * @group contentful_migration
 */
class DrupalAssetHyperlinkTest extends UnitTestCase {

  /**
   * The asset hyperlink degrades to its link text and logs the loss.
   *
   * This overrides the library's useless `#Asset-ID` default.
   *
   * @covers ::render
   */
  public function testRendersLinkTextAndLogs(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('warning');

    $asset = $this->createMock(AssetInterface::class);
    $asset->method('getId')->willReturn('doc1');

    $node = $this->createMock(AssetHyperlink::class);
    $node->method('getAsset')->willReturn($asset);
    $node->method('getContent')->willReturn([]);

    $renderer = $this->createMock(RendererInterface::class);
    $renderer->method('renderCollection')->willReturn('the brochure');

    $html = (new DrupalAssetHyperlink($logger))->render($renderer, $node);

    $this->assertSame('the brochure', $html, 'Asset hyperlink degrades to its link text.');
    $this->assertStringNotContainsString('<a', $html);
  }

}
