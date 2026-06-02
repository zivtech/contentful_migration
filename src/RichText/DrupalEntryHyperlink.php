<?php

declare(strict_types=1);

namespace Drupal\contentful_migration\RichText;

use Contentful\RichText\Node\EntryHyperlink as EntryHyperlinkNode;
use Contentful\RichText\Node\NodeInterface;
use Contentful\RichText\NodeRenderer\NodeRendererInterface;
use Contentful\RichText\RendererInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Renders an inline Contentful entry-hyperlink as a link to a Drupal entity.
 *
 * A Contentful `entry-hyperlink` is an inline link, inside a Rich Text body,
 * that points at another entry by `sys.id`. The library default emits useless
 * `<a href="#Entry-ID">` garbage. This renderer resolves the `sys.id` to a
 * migrated Drupal entity (via the same `embed_migrations` config and resolver
 * the embedded-entry renderers use) and emits a real anchor to the entity's
 * canonical page, carrying `data-entity-type`/`data-entity-uuid` so the link
 * upgrades to alias-safe if the contrib Linkit filter is enabled on the
 * destination text format. The bare `/node/N`-style href always resolves on
 * its own, so the link works regardless.
 *
 * Unlike the embed renderers (which omit unresolvable content), a hyperlink
 * carries visible sentence text. When the target is unresolvable — not yet
 * migrated, since deleted, or an entity type with no canonical URL (e.g. a
 * Paragraph) — this keeps the rendered link text and drops only the dead
 * anchor, logging the loss so it is visible rather than silent.
 *
 * Scope: this is the inline-*body* case. The single stored link-*field* case
 * is the `contentful_internal_link` process plugin, which emits an `entity:`
 * URI a Drupal link field stores natively (a path Drupal core rewrites through
 * the alias system — not available to raw body HTML, hence the Linkit-style
 * markup here instead).
 */
final class DrupalEntryHyperlink implements NodeRendererInterface {

  public function __construct(
    private readonly ContentfulEmbedResolverInterface $resolver,
    private readonly EntityRepositoryInterface $entityRepository,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function supports(NodeInterface $node): bool {
    return $node instanceof EntryHyperlinkNode;
  }

  /**
   * {@inheritdoc}
   */
  public function render(RendererInterface $renderer, NodeInterface $node, array $context = []): string {
    assert($node instanceof EntryHyperlinkNode);

    // The link label (child nodes), rendered through the full renderer set so
    // inline marks (bold/italic/…) survive. Reused as the graceful-degradation
    // output: text kept, dead anchor dropped.
    $label = $renderer->renderCollection($node->getContent(), $context);
    $sysId = $node->getEntry()->getId();

    $resolved = $this->resolver->resolve($sysId, 'Entry');
    if ($resolved === NULL) {
      $this->logger->warning('Unresolved entry hyperlink @id.', [
        '@id' => $sysId,
      ]);
      return $label;
    }

    $entityType = $resolved['entity_type'];
    $entity = $this->entityRepository->loadEntityByUuid($entityType, $resolved['uuid']);
    if ($entity === NULL) {
      $this->logger->warning('Entry hyperlink @id (@type) no longer loads.', [
        '@id' => $sysId,
        '@type' => $entityType,
      ]);
      return $label;
    }

    try {
      $href = '/' . $entity->toUrl('canonical')->getInternalPath();
    }
    catch (\Exception $e) {
      // The resolved entity has no canonical URL (e.g. a Paragraph). A body
      // hyperlink needs a real href, so keep the visible text and drop the
      // dead anchor rather than emit a broken link.
      $this->logger->warning('Entry hyperlink @id (@type): no canonical URL.', [
        '@id' => $sysId,
        '@type' => $entityType,
      ]);
      return $label;
    }

    $markup = sprintf(
      '<a href="%s" data-entity-type="%s" data-entity-uuid="%s"',
      htmlspecialchars($href, ENT_QUOTES),
      htmlspecialchars($entityType, ENT_QUOTES),
      htmlspecialchars($resolved['uuid'], ENT_QUOTES),
    );
    $title = $node->getTitle();
    if ($title !== '') {
      $markup .= sprintf(' title="%s"', htmlspecialchars($title, ENT_QUOTES));
    }
    return $markup . '>' . $label . '</a>';
  }

}
