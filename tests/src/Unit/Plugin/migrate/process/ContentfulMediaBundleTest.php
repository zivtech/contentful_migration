<?php

declare(strict_types=1);

namespace Drupal\Tests\contentful_migration\Unit\Plugin\migrate\process;

use Drupal\contentful_migration\Plugin\migrate\process\ContentfulMediaBundle;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\Row;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\contentful_migration\Plugin\migrate\process\ContentfulMediaBundle
 * @group contentful_migration
 */
class ContentfulMediaBundleTest extends UnitTestCase {

  /**
   * Runs the plugin's transform() method with the given MIME and configuration.
   */
  private function transform($value, array $configuration = []): string {
    $plugin = new ContentfulMediaBundle($configuration, 'contentful_media_bundle', []);
    return $plugin->transform(
      $value,
      $this->createMock(MigrateExecutableInterface::class),
      $this->createMock(Row::class),
      'bundle',
    );
  }

  /**
   * Default map: prefix and exact matches resolve to the right bundle.
   *
   * @dataProvider defaultMapCases
   * @covers ::transform
   */
  public function testDefaultMap(string $mime, string $expected): void {
    $this->assertSame($expected, $this->transform($mime));
  }

  /**
   * Data provider for testDefaultMap.
   */
  public static function defaultMapCases(): array {
    return [
      'jpeg image (prefix)' => ['image/jpeg', 'image'],
      'png image (prefix)' => ['image/png', 'image'],
      'mp4 video (prefix)' => ['video/mp4', 'video'],
      'mpeg audio (prefix)' => ['audio/mpeg', 'audio'],
      'pdf (exact, not prefix)' => ['application/pdf', 'document'],
      // Mixed case normalises down.
      'uppercase mime' => ['IMAGE/JPEG', 'image'],
    ];
  }

  /**
   * Unknown / empty MIME falls back to the default bundle.
   *
   * @covers ::transform
   */
  public function testFallback(): void {
    $this->assertSame('document', $this->transform('application/zip'), 'Unmapped MIME -> default fallback.');
    $this->assertSame('document', $this->transform(''), 'Empty MIME -> default fallback.');
    $this->assertSame('document', $this->transform(NULL), 'Non-string MIME -> default fallback.');
  }

  /**
   * A configured map and default override the built-ins; exact beats prefix.
   *
   * @covers ::transform
   */
  public function testConfiguredOverrides(): void {
    $config = [
      'map' => [
        'image/svg+xml' => 'vector',
        'image/' => 'picture',
      ],
      'default' => 'binary',
    ];
    $this->assertSame('vector', $this->transform('image/svg+xml', $config), 'Exact match wins over the image/ prefix.');
    $this->assertSame('picture', $this->transform('image/png', $config), 'Prefix match applies to other images.');
    $this->assertSame('binary', $this->transform('video/mp4', $config), 'Custom default replaces the built-in fallback.');
  }

}
