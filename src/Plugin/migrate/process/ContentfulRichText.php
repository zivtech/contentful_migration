<?php

declare(strict_types=1);

namespace Drupal\contentful_migration\Plugin\migrate\process;

use Contentful\RichText\Parser;
use Contentful\RichText\Renderer;
use Drupal\contentful_migration\RichText\ContentfulEmbedResolver;
use Drupal\contentful_migration\RichText\ContentfulEmbedResolverInterface;
use Drupal\contentful_migration\RichText\DrupalAssetHyperlink;
use Drupal\contentful_migration\RichText\DrupalEmbeddedAssetBlock;
use Drupal\contentful_migration\RichText\DrupalEmbeddedEntryBlock;
use Drupal\contentful_migration\RichText\DrupalEntryHyperlink;
use Drupal\contentful_migration\RichText\SysIdLinkResolver;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Converts a Contentful Rich Text AST to Drupal-ready HTML.
 *
 * Wraps contentful/rich-text. Two behaviors the library defaults get wrong for
 * Drupal (spike-verified):
 *  - embedded entries/assets render as Contentful-id placeholders; we override
 *    them to resolve sys.id -> migrated Drupal entity and emit drupal-media /
 *    drupal-entity-embed tokens.
 *  - inline entry/asset hyperlinks render with a useless `#Entry-ID` href; we
 *    override entry-hyperlink to link the migrated entity's canonical page
 *    (DrupalEntryHyperlink) and degrade asset-hyperlink to plain text
 *    (DrupalAssetHyperlink).
 *  - unknown node types are silently dropped by the default CatchAll; we walk
 *    the AST and log any node type the library doesn't handle, so loss is
 *    visible.
 *
 * @code
 * body/value:
 *   plugin: contentful_rich_text
 *   source: body
 * @endcode
 *
 * handle_multiples: TRUE — the source value is a single Rich Text document
 * AST (an associative array). Without this, migrate's pipeline iterates the
 * array element-by-element and calls transform() on each sub-value (nodeType,
 * data, content) instead of passing the whole document. (Verified in a real
 * migrate run; the unit test missed it by calling transform() directly.)
 */
#[\Drupal\migrate\Attribute\MigrateProcess('contentful_rich_text', handle_multiples: TRUE)]
class ContentfulRichText extends ProcessPluginBase implements ContainerFactoryPluginInterface {

  /**
   * Node types the contentful/rich-text library renders natively.
   *
   * Anything else is dropped by the default CatchAll, so we log it.
   */
  private const KNOWN_NODE_TYPES = [
    'document', 'paragraph', 'text', 'hr', 'blockquote', 'hyperlink',
    'heading-1', 'heading-2', 'heading-3', 'heading-4', 'heading-5', 'heading-6',
    'ordered-list', 'unordered-list', 'list-item',
    'table', 'table-row', 'table-cell', 'table-header-cell',
    'embedded-entry-block', 'embedded-entry-inline',
    'embedded-asset-block', 'embedded-asset-inline',
    'entry-hyperlink', 'asset-hyperlink',
  ];

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly ContentfulEmbedResolverInterface $resolver,
    private readonly EntityRepositoryInterface $entityRepository,
    private readonly LoggerInterface $logger,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    // The resolver depends on per-migration YAML (`embed_migrations`), so it is
    // built here from container services + this plugin's config rather than
    // being a shared service. `embed_migrations` maps each linkType to an
    // ordered list of {migration, entity_type} candidates.
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      new ContentfulEmbedResolver(
        $container->get('migrate.lookup'),
        $container->get('entity_type.manager'),
        $configuration['embed_migrations'] ?? [],
      ),
      $container->get('entity.repository'),
      $container->get('logger.factory')->get('contentful_migration'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property): string {
    // Empty or non-AST input -> empty body.
    if (!is_array($value) || ($value['nodeType'] ?? NULL) !== 'document') {
      return '';
    }

    // Log any node types the library doesn't recognise. NB: the
    // contentful/rich-text Parser THROWS InvalidArgumentException on an
    // unrecognised node type —
    // it does not silently drop or reach a CatchAll. So we log the offending
    // type for visibility, then degrade gracefully on parse failure rather than
    // letting one unknown node crash the entire migration.
    $this->logUnknownNodeTypes($value);

    $parser = new Parser(new SysIdLinkResolver());
    try {
      $node = $parser->parse($value);
    }
    catch (\InvalidArgumentException $e) {
      $this->logger->error('Rich Text parse failed (@msg); field left empty. See preceding warnings for the unrecognised node type.', ['@msg' => $e->getMessage()]);
      return '';
    }

    // Pushed renderers take front priority, overriding the library defaults for
    // the embed and inline-hyperlink node types; all other nodes use the
    // library's renderers.
    $renderer = new Renderer([
      new DrupalEmbeddedEntryBlock($this->resolver, $this->logger),
      new DrupalEmbeddedAssetBlock($this->resolver, $this->logger),
      new DrupalEntryHyperlink(
        $this->resolver,
        $this->entityRepository,
        $this->logger,
      ),
      new DrupalAssetHyperlink($this->logger),
    ]);

    return $renderer->render($node);
  }

  /**
   * Recursively walks the raw AST, logging node types the library won't render.
   */
  private function logUnknownNodeTypes(array $node): void {
    $type = $node['nodeType'] ?? NULL;
    if ($type !== NULL && !in_array($type, self::KNOWN_NODE_TYPES, TRUE)) {
      $this->logger->warning('Unknown Rich Text node type "@type" encountered; it will not be rendered.', ['@type' => $type]);
    }
    foreach ($node['content'] ?? [] as $child) {
      if (is_array($child)) {
        $this->logUnknownNodeTypes($child);
      }
    }
  }

}
