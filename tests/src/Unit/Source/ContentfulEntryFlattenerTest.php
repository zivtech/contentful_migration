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
   * Sys.id becomes the scalar migrate source key.
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
   * No-fallback: a non-localized field is NULL on a non-default locale pass.
   *
   * Never the default-locale value. The two-pass translation migrations depend
   * on this.
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
   * A single Link passes through raw, not flattened to a scalar here.
   *
   * Resolved by migration_lookup on `heroImage/sys/id` in the YAML.
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
   * An array of Links passes through raw; no `_prepared_*` keys are added.
   *
   * Resolved by sub_process + migration_lookup + extract over `sys/id` in the
   * YAML.
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

  /**
   * Sys timestamps pass through as ISO 8601 strings; author link stays raw.
   *
   * @covers ::flatten
   */
  public function testExposesSysMetadata(): void {
    $row = (new ContentfulEntryFlattener('en-US', 'blogPost'))->flatten($this->blogPostEntry());
    $this->assertSame('2026-02-01T12:00:00.000Z', $row['sys_created_at']);
    $this->assertSame('2026-04-02T14:30:00.000Z', $row['sys_updated_at']);

    // The fixture entry carries no createdBy; an entry that does passes the
    // raw User link through (resolved via `sys_created_by/sys/id` in YAML).
    $this->assertNull($row['sys_created_by']);
    $entry = $this->blogPostEntry();
    $entry['sys']['createdBy'] = ['sys' => ['type' => 'Link', 'linkType' => 'User', 'id' => 'user-abc']];
    $withAuthor = (new ContentfulEntryFlattener('en-US', 'blogPost'))->flatten($entry);
    $this->assertSame('user-abc', $withAuthor['sys_created_by']['sys']['id']);
  }

  /**
   * Sys metadata is NULL when the export lacks it (2/211 corpus exports do).
   *
   * NULL feeds the documented `skip_on_empty` front-stop, which leaves
   * `created`/`changed` unset so Drupal defaults them to import time.
   *
   * @covers ::flatten
   */
  public function testSysMetadataNullWhenAbsent(): void {
    $entry = [
      'sys' => [
        'id' => 'bare1',
        'contentType' => ['sys' => ['id' => 'blogPost']],
      ],
      'fields' => ['title' => ['en-US' => 'Dateless entry']],
    ];
    $row = (new ContentfulEntryFlattener('en-US', 'blogPost'))->flatten($entry);
    $this->assertNull($row['sys_created_at']);
    $this->assertNull($row['sys_updated_at']);
    $this->assertNull($row['sys_created_by']);
  }

  /**
   * The real sys value wins over an identically-named Contentful field.
   *
   * The sys_* keys are set after the fields loop, so a space field literally
   * named `sys_created_at` is shadowed in the flattened row — the documented
   * precedence (ContentfulExport::fields()).
   *
   * @covers ::flatten
   */
  public function testSysMetadataWinsOverSameNamedField(): void {
    $entry = [
      'sys' => [
        'id' => 'clash1',
        'contentType' => ['sys' => ['id' => 'blogPost']],
        'createdAt' => '2026-05-01T00:00:00.000Z',
      ],
      'fields' => ['sys_created_at' => ['en-US' => 'field-value-not-a-date']],
    ];
    $row = (new ContentfulEntryFlattener('en-US', 'blogPost'))->flatten($entry);
    $this->assertSame('2026-05-01T00:00:00.000Z', $row['sys_created_at']);
  }

}
