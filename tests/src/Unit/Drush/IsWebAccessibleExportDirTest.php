<?php

declare(strict_types=1);

namespace Drupal\Tests\contentful_migration\Unit\Drush;

use Drupal\contentful_migration\Drush\Commands\ContentfulMigrateDrushCommands;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Unit coverage for the web-accessible export-dir predicate.
 *
 * The export command's --include-users warning hinges on this pure predicate;
 * the IO glue around it is left untested. The Drush command is a thin Process
 * wrapper, so the predicate is what can regress.
 */
#[Group('contentful_migration')]
class IsWebAccessibleExportDirTest extends UnitTestCase {

  /**
   * The public:// scheme is web-served; every other value is not.
   */
  #[DataProvider('exportDirOptions')]
  public function testDetectsWebAccessibleExportDir(string $option, bool $expected): void {
    $this->assertSame(
      $expected,
      ContentfulMigrateDrushCommands::isWebAccessibleExportDir($option),
    );
  }

  /**
   * Provides export-dir option strings with their expected web-accessibility.
   *
   * @return array<string, array{string, bool}>
   *   [option string, expected web-accessible].
   */
  public static function exportDirOptions(): array {
    return [
      'public scheme with path' => ['public://contentful', TRUE],
      'bare public scheme' => ['public://', TRUE],
      'private scheme' => ['private://contentful', FALSE],
      'temporary scheme' => ['temporary://x', FALSE],
      'plain server path' => ['/var/exports', FALSE],
    ];
  }

}
