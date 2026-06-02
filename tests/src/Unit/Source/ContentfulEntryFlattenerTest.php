<?php

declare(strict_types=1);

namespace Drupal\Tests\contentful_migration\Unit\Source;

use Drupal\contentful_migration\Source\ContentfulEntryFlattener;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\contentful_migration\Source\ContentfulEntryFlattener
 * @group contentful_migration
 */
class ContentfulEntryFlattenerTest extends UnitTestCase {

  /**
   * The blogPost entry (post1) from the synthetic fixture.
   */
  private function blogPostEntry(): array {
    $export = json_decode(
      file_get_contents(__DIR__ . '/../../../fixtures/synthetic-contentful-export.json'),
      TRUE,
    );
    // entries[0] is post1 (blogPost) — the row exercising every field shape.
    return $export['entries'][0];
  }

  /**
   * The hero1 entry (heroSection) — used for the content-type filter test.
   */
  private function heroEntry(): array {
    $export = json_decode(
      file_get_contents(__DIR__ . '/../../../fixtures/synthetic-contentful-export.json'),
      TRUE,
    );
    return $export['entries'][2];
  }

  /**
   * sys.id becomes the scalar migrate source key.
   *
   * @covers ::flatten
   */
  public function testExposesSysId(): void {
    $row = (new ContentfulEntryFlattener('en-US', 'blogPost'))->flatten($this->blogPostEntry());
    $this->assertSame('post1', $row['sys_id']);
    $this->assertSame('blogPost', $row['content_type']);
  }

  /**
   * A localized field resolves to the value at the requested locale.
   *
   * @covers ::flatten
   */
  public function testLocalizedFieldResolvesPerLocale(): void {
    $en = (new ContentfulEntryFlattener('en-US', 'blogPost'))->flatten($this->blogPostEntry());
    $es = (new ContentfulEntryFlattener('es-MX', 'blogPost'))->flatten($this->blogPostEntry());
    $this->assertSame('Getting Started with Decoupled Drupal', $en['title']);
    $this->assertSame('Primeros pasos con Drupal desacoplado', $es['title']);
  }

  /**
   * No-fallback contract: a non-localized field is NULL on a non-default
   * locale pass — never the default-locale value. The two-pass translation
   * migrations depend on this.
   *
   * @covers ::flatten
   */
  public function testNoFallbackForNonLocalizedField(): void {
    $en = (new ContentfulEntryFlattener('en-US', 'blogPost'))->flatten($this->blogPostEntry());
    $es = (new ContentfulEntryFlattener('es-MX', 'blogPost'))->flatten($this->blogPostEntry());
    $this->assertSame('getting-started-decoupled-drupal', $en['slug']);
    $this->assertNull($es['slug'], 'A field with no es-MX value must be NULL, not the en-US fallback.');
  }

  /**
   * A single Link passes through raw (resolved by migration_lookup on
   * `heroImage/sys/id` in the YAML, not flattened to a scalar here).
   *
   * @covers ::flatten
   */
  public function testSingleLinkLeftRaw(): void {
    $row = (new ContentfulEntryFlattener('en-US', 'blogPost'))->flatten($this->blogPostEntry());
    $this->assertIsArray($row['heroImage']);
    $this->assertSame('img1', $row['heroImage']['sys']['id']);
    $this->assertSame('Asset', $row['heroImage']['sys']['linkType']);
  }

  /**
   * An array of Links passes through raw (resolved by sub_process +
   * migration_lookup + extract over `sys/id` in the YAML). No `_prepared_*`.
   *
   * @covers ::flatten
   */
  public function testLinkArrayLeftRaw(): void {
    $row = (new ContentfulEntryFlattener('en-US', 'blogPost'))->flatten($this->blogPostEntry());
    $this->assertCount(2, $row['sections']);
    $this->assertSame('hero1', $row['sections'][0]['sys']['id']);
    $this->assertSame('callout1', $row['sections'][1]['sys']['id']);
    $this->assertArrayNotHasKey('_prepared_sections', $row, 'No source-side pre-computation: links stay raw.');
  }

  /**
   * A RichText field passes through as the document AST, untouched.
   *
   * @covers ::flatten
   */
  public function testRichTextLeftAsAst(): void {
    $row = (new ContentfulEntryFlattener('en-US', 'blogPost'))->flatten($this->blogPostEntry());
    $this->assertSame('document', $row['body']['nodeType']);
    $this->assertNotEmpty($row['body']['content']);
  }

  /**
   * A content-type mismatch skips the row (NULL).
   *
   * @covers ::flatten
   */
  public function testContentTypeFilterSkipsNonMatchingRow(): void {
    // hero1 is a heroSection; a blogPost-scoped flattener must skip it.
    $row = (new ContentfulEntryFlattener('en-US', 'blogPost'))->flatten($this->heroEntry());
    $this->assertNull($row, 'A non-matching content type must be skipped.');

    // …and a heroSection-scoped flattener keeps it.
    $kept = (new ContentfulEntryFlattener('en-US', 'heroSection'))->flatten($this->heroEntry());
    $this->assertSame('hero1', $kept['sys_id']);
  }

}
