<?php

declare(strict_types=1);

namespace Drupal\Tests\contentful_migration\Unit\RichText;

use Drupal\contentful_migration\Plugin\migrate\process\ContentfulRichText;
use Drupal\contentful_migration\RichText\ContentfulEmbedResolverInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\Row;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\AbstractLogger;

/**
 * Unit coverage for ContentfulRichText process plugin.
 */
#[CoversClass(ContentfulRichText::class)]
#[Group('contentful_migration')]
class ContentfulRichTextTest extends UnitTestCase {

  /**
   * Real embedded-entry sys.id present in the 059 fixture AST.
   */
  public const REAL_ENTRY_SYS_ID = '45mD46Irkt50j4i2IqcSa2';

  /**
   * Real embedded-entry-inline sys.id present in the 039 fixture AST.
   *
   * The inline-embedded target is a `button` entry inside a blockCopy body.
   */
  public const REAL_INLINE_ENTRY_SYS_ID = '1Tv28k2hEXIfgASOY9XyIP';

  /**
   * Builds a capturing logger whose records are assertable in tests.
   */
  private function makeLogger(): AbstractLogger {
    return new class() extends AbstractLogger {
      /**
       * @var string[]
       */
      public array $records = [];

      /**
       * {@inheritdoc}
       */
      public function log($level, string|\Stringable $message, array $context = []): void {
        // Interpolate the @placeholders for easy assertion.
        $msg = (string) $message;
        foreach ($context as $k => $v) {
          $msg = str_replace($k, (string) $v, $msg);
        }
        $this->records[] = $level . ': ' . $msg;
      }

    };
  }

  /**
   * Builds a ContentfulRichText plugin with the given resolver and logger.
   */
  private function makePlugin(ContentfulEmbedResolverInterface $resolver, $logger): ContentfulRichText {
    // The entity repository is only exercised by the inline entry-hyperlink
    // renderer; the ASTs in this unit suite carry no entry-hyperlink, so a bare
    // mock (never invoked) keeps these tests focused on embed/unknown-node
    // behaviour. End-to-end hyperlink resolution is covered by the kernel test.
    return new ContentfulRichText(
      [],
      'contentful_rich_text',
      [],
      $resolver,
      $this->createMock(EntityRepositoryInterface::class),
      $logger,
    );
  }

  /**
   * Runs the plugin's transform() method against the given AST array.
   */
  private function transform(ContentfulRichText $plugin, array $ast): string {
    return $plugin->transform(
      $ast,
      $this->createMock(MigrateExecutableInterface::class),
      $this->createMock(Row::class),
      'body/value',
    );
  }

  /**
   * The real embedded entry from corpus 059 resolves to a Drupal embed token.
   *
   * The library's default `<div>Entry#ID</div>` placeholder is gone.
   */
  public function testResolvesEmbeddedEntryFromRealAst(): void {
    $ast = json_decode(file_get_contents(__DIR__ . '/../../../fixtures/real-ast-059.json'), TRUE);
    $resolver = new class() implements ContentfulEmbedResolverInterface {

      /**
       * {@inheritdoc}
       */
      public function resolve(string $sysId, string $linkType): ?array {
        return $sysId === ContentfulRichTextTest::REAL_ENTRY_SYS_ID
          ? ['entity_type' => 'paragraph', 'uuid' => 'uuid-callout-1']
          : NULL;
      }

    };

    $html = $this->transform($this->makePlugin($resolver, $this->makeLogger()), $ast);

    $this->assertStringContainsString(
      '<drupal-entity-embed data-entity-type="paragraph" data-entity-uuid="uuid-callout-1">',
      $html,
      'Embedded entry should resolve to a Drupal entity embed token.',
    );
    $this->assertStringNotContainsString('<div>Entry#', $html, 'The library default placeholder must be overridden.');
  }

  /**
   * The real embedded-entry-INLINE from corpus 039 resolves to an embed token.
   *
   * Companion to the block case above: an entry embedded *inline* in copy
   * (039's `button` inside a paragraph) must resolve to the same Drupal entity
   * embed token, not fall through to the library's `Entry#ID` placeholder.
   * `embedded-entry-inline` is in KNOWN_NODE_TYPES (so it is not even logged as
   * unknown), yet no Drupal override renderer handles it — so without a
   * DrupalEmbeddedEntryInline renderer the inline target is silently degraded.
   */
  public function testResolvesInlineEmbeddedEntryFromRealAst(): void {
    $ast = json_decode(file_get_contents(__DIR__ . '/../../../fixtures/real-ast-039-inline.json'), TRUE);
    $resolver = new class() implements ContentfulEmbedResolverInterface {

      /**
       * {@inheritdoc}
       */
      public function resolve(string $sysId, string $linkType): ?array {
        return $sysId === ContentfulRichTextTest::REAL_INLINE_ENTRY_SYS_ID
          ? ['entity_type' => 'node', 'uuid' => 'uuid-inline-button']
          : NULL;
      }

    };

    $html = $this->transform($this->makePlugin($resolver, $this->makeLogger()), $ast);

    $this->assertStringContainsString(
      'data-entity-uuid="uuid-inline-button"',
      $html,
      'An inline-embedded entry must resolve to a Drupal entity embed token.',
    );
    // The library-default placeholder (the raw sys.id) must be gone.
    $this->assertStringNotContainsString(
      ContentfulRichTextTest::REAL_INLINE_ENTRY_SYS_ID,
      $html,
      'The raw Contentful sys.id placeholder must not survive to output.',
    );
  }

  /**
   * An unknown node type is dropped and logged — the rest of the body survives.
   *
   * The Parser throws on any unmapped nodeType, which would empty the whole
   * field. Pre-sanitization removes only the offending node (and its subtree),
   * logs it, and leaves every sibling intact.
   */
  public function testDropsUnknownNodeButPreservesRest(): void {
    $logger = $this->makeLogger();
    $resolver = new class() implements ContentfulEmbedResolverInterface {

      /**
       * {@inheritdoc}
       */
      public function resolve(string $sysId, string $linkType): ?array {
        return NULL;
      }

    };
    $ast = [
      'nodeType' => 'document',
      'data' => [],
      'content' => [
        ['nodeType' => 'mystery-widget', 'data' => [], 'content' => []],
        [
          'nodeType' => 'paragraph',
          'data' => [],
          'content' => [
            ['nodeType' => 'text', 'value' => 'kept text', 'marks' => [], 'data' => []],
          ],
        ],
      ],
    ];

    $html = $this->transform($this->makePlugin($resolver, $logger), $ast);

    $this->assertNotEmpty(
      array_filter($logger->records, fn($r) => str_contains($r, 'mystery-widget')),
      'The unknown node type must be logged.',
    );
    $this->assertStringContainsString('kept text', $html, 'Sibling content must survive an unknown node.');
    $this->assertStringNotContainsString('mystery-widget', $html, 'The unknown node must not render.');
  }

  /**
   * An unresolvable embed (not yet migrated) logs and emits nothing.
   */
  public function testUnresolvedEmbedLogsAndOmits(): void {
    $logger = $this->makeLogger();
    $resolver = new class() implements ContentfulEmbedResolverInterface {

      /**
       * {@inheritdoc}
       */
      public function resolve(string $sysId, string $linkType): ?array {
        return NULL;
      }

    };
    $ast = json_decode(file_get_contents(__DIR__ . '/../../../fixtures/real-ast-059.json'), TRUE);

    $html = $this->transform($this->makePlugin($resolver, $logger), $ast);

    $this->assertStringNotContainsString('drupal-entity-embed', $html, 'Unresolved embed must emit nothing.');
    $this->assertNotEmpty(
      array_filter($logger->records, fn($r) => str_contains($r, 'Unresolved embedded entry')),
      'Unresolved embed must be logged.',
    );
  }

  /**
   * Non-document input degrades to an empty string.
   */
  public function testEmptyOnNonDocument(): void {
    $plugin = $this->makePlugin(
      new class() implements ContentfulEmbedResolverInterface {

        /**
         * {@inheritdoc}
         */
        public function resolve(string $sysId, string $linkType): ?array {
          return NULL;
        }

      },
      $this->makeLogger(),
    );
    $this->assertSame('', $this->transform($plugin, ['nodeType' => 'text', 'value' => 'x']));
  }

}
