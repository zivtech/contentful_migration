<?php

declare(strict_types=1);

namespace Drupal\Tests\contentful_migration\Unit\Export;

use Drupal\contentful_migration\Export\ExportConfig;
use Drupal\contentful_migration\Export\ExportSummary;
use Drupal\Tests\UnitTestCase;

/**
 * Unit coverage for the pure export helpers behind contentful:export.
 *
 * The Drush command itself is a thin Process wrapper, left untested; the
 * substance (config assembly/validation and the export summary) lives here.
 *
 * @group contentful_migration
 */
class ContentfulExportHelpersTest extends UnitTestCase {

  /**
   * The config is assembled and an empty environment falls back to master.
   */
  public function testExportConfigAssemblesAndDefaultsEnvironment(): void {
    $config = ExportConfig::build(
      spaceId: 'space1',
      environmentId: '',
      managementToken: 'cfpat-secret',
      exportDir: '/real/dir',
    );
    $this->assertSame('space1', $config['spaceId']);
    $this->assertSame('master', $config['environmentId']);
    $this->assertSame('cfpat-secret', $config['managementToken']);
    $this->assertTrue($config['downloadAssets']);
    $this->assertSame('/real/dir', $config['exportDir']);
    $this->assertSame('export.json', $config['contentFile']);
  }

  /**
   * A missing space id is rejected.
   */
  public function testExportConfigRequiresSpaceId(): void {
    $this->expectException(\InvalidArgumentException::class);
    ExportConfig::build(
      spaceId: '',
      environmentId: 'master',
      managementToken: 'cfpat-secret',
      exportDir: '/real/dir',
    );
  }

  /**
   * A blank management token is rejected.
   */
  public function testExportConfigRequiresManagementToken(): void {
    $this->expectException(\InvalidArgumentException::class);
    ExportConfig::build(
      spaceId: 'space1',
      environmentId: 'master',
      managementToken: '   ',
      exportDir: '/real/dir',
    );
  }

  /**
   * The summary counts types, entries, assets, locales and MIME families.
   */
  public function testSummaryCountsTypesEntriesAssetsLocalesAndMimeFamilies(): void {
    $data = json_decode(
      (string) file_get_contents(__DIR__ . '/../../../fixtures/synthetic-contentful-export.json'),
      TRUE,
    );
    $summary = ExportSummary::fromExport($data);

    $this->assertSame(3, $summary['content_types']);
    $this->assertSame(['blogPost', 'heroSection', 'calloutCard'], $summary['content_type_ids']);
    $this->assertSame(4, $summary['entries']);
    $this->assertSame(2, $summary['assets']);
    $this->assertSame(['en-US', 'es-MX'], $summary['locales']);
    // img1 is image/jpeg, doc1 is application/pdf — one family each, ksorted.
    $this->assertSame(['application' => 1, 'image' => 1], $summary['asset_mime_families']);
  }

  /**
   * An empty payload summarises to zeroes rather than erroring.
   */
  public function testSummaryHandlesEmptyExport(): void {
    $summary = ExportSummary::fromExport([]);
    $this->assertSame(0, $summary['content_types']);
    $this->assertSame([], $summary['content_type_ids']);
    $this->assertSame(0, $summary['entries']);
    $this->assertSame(0, $summary['assets']);
    $this->assertSame([], $summary['locales']);
    $this->assertSame([], $summary['asset_mime_families']);
  }

}
