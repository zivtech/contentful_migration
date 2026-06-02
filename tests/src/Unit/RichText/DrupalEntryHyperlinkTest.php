<?php

declare(strict_types=1);

namespace Drupal\Tests\contentful_migration\Unit\RichText;

use Contentful\Core\Resource\EntryInterface;
use Contentful\RichText\Node\EntryHyperlink;
use Contentful\RichText\RendererInterface;
use Drupal\contentful_migration\RichText\ContentfulEmbedResolverInterface;
use Drupal\contentful_migration\RichText\DrupalEntryHyperlink;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Url;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 * @coversDefaultClass \Drupal\contentful_migration\RichText\DrupalEntryHyperlink
 * @group contentful_migration
 */
class DrupalEntryHyperlinkTest extends UnitTestCase {

  /**
   * A mock entry-hyperlink node carrying a target sys.id and optional title.
   */
  private function node(string $sysId, string $title = ''): EntryHyperlink {
    $entry = $this->createMock(EntryInterface::class);
    $entry->method('getId')->willReturn($sysId);

    $node = $this->createMock(EntryHyperlink::class);
    $node->method('getEntry')->willReturn($entry);
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
   * A resolver returning a canned reference (or NULL) for any sys.id.
   */
  private function resolver(?array $resolved): ContentfulEmbedResolverInterface {
    $resolver = $this->createMock(ContentfulEmbedResolverInterface::class);
    $resolver->method('resolve')->willReturn($resolved);
    return $resolver;
  }

  /**
   * An entity repository returning $entity from loadEntityByUuid().
   */
  private function repository(?EntityInterface $entity): EntityRepositoryInterface {
    $repository = $this->createMock(EntityRepositoryInterface::class);
    $repository->method('loadEntityByUuid')->willReturn($entity);
    return $repository;
  }

  /**
   * An entity whose canonical toUrl() yields the given internal path.
   */
  private function entityWithCanonicalPath(string $internalPath): EntityInterface {
    $url = $this->createMock(Url::class);
    $url->method('getInternalPath')->willReturn($internalPath);
    $entity = $this->createMock(EntityInterface::class);
    $entity->method('toUrl')->willReturn($url);
    return $entity;
  }

  /**
   * A resolved entry with a canonical URL renders a real anchor.
   *
   * The anchor carries the entity-reference attributes, replacing the
   * library's `#Entry-ID` default.
   *
   * @covers ::render
   */
  public function testResolvedEntryRendersAnchor(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->never())->method('warning');

    $renderer = new DrupalEntryHyperlink(
      $this->resolver(['entity_type' => 'node', 'uuid' => 'uuid-post2']),
      $this->repository($this->entityWithCanonicalPath('node/5')),
      $logger,
    );

    $html = $renderer->render($this->renderer('advanced guide'), $this->node('post2'));

    $this->assertSame(
      '<a href="/node/5" data-entity-type="node" data-entity-uuid="uuid-post2">advanced guide</a>',
      $html,
    );
  }

  /**
   * A non-empty Contentful link title is preserved as the anchor title attr.
   *
   * @covers ::render
   */
  public function testTitleAttributePreserved(): void {
    $renderer = new DrupalEntryHyperlink(
      $this->resolver(['entity_type' => 'node', 'uuid' => 'u']),
      $this->repository($this->entityWithCanonicalPath('node/9')),
      $this->createMock(LoggerInterface::class),
    );

    $html = $renderer->render($this->renderer('go'), $this->node('x', 'Read more'));

    $this->assertStringContainsString('title="Read more"', $html);
  }

  /**
   * A resolved entity with no canonical URL keeps the link text, no anchor.
   *
   * This is the blind-spot branch (e.g. a Paragraph target): it must not fatal
   * or emit a broken href, and the node-only kernel fixture cannot reach it.
   *
   * @covers ::render
   */
  public function testNoCanonicalUrlDegradesToText(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('warning');

    // Paragraph-like entity: toUrl('canonical') throws.
    $paragraph = $this->createMock(EntityInterface::class);
    $paragraph->method('toUrl')->willThrowException(new \Exception('no canonical link template'));

    $renderer = new DrupalEntryHyperlink(
      $this->resolver(['entity_type' => 'paragraph', 'uuid' => 'uuid-p']),
      $this->repository($paragraph),
      $logger,
    );

    $html = $renderer->render($this->renderer('advanced guide'), $this->node('callout1'));

    $this->assertSame('advanced guide', $html, 'Link text is kept; the dead anchor is dropped.');
    $this->assertStringNotContainsString('<a', $html);
  }

  /**
   * An unresolved target (not yet migrated) keeps the link text and logs.
   *
   * @covers ::render
   */
  public function testUnresolvedTargetDegradesToText(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('warning');

    $renderer = new DrupalEntryHyperlink(
      $this->resolver(NULL),
      $this->repository(NULL),
      $logger,
    );

    $html = $renderer->render($this->renderer('advanced guide'), $this->node('ghost'));

    $this->assertSame('advanced guide', $html);
    $this->assertStringNotContainsString('<a', $html);
  }

  /**
   * A resolved reference whose entity no longer loads keeps the link text.
   *
   * @covers ::render
   */
  public function testMissingEntityDegradesToText(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('warning');

    $renderer = new DrupalEntryHyperlink(
      $this->resolver(['entity_type' => 'node', 'uuid' => 'gone']),
      $this->repository(NULL),
      $logger,
    );

    $html = $renderer->render($this->renderer('advanced guide'), $this->node('deleted'));

    $this->assertSame('advanced guide', $html);
  }

}
