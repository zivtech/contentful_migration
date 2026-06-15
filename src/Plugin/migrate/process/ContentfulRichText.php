<?php

declare(strict_types=1);

namespace Drupal\contentful_migration\Plugin\migrate\process;

use Contentful\RichText\Parser;
use Contentful\RichText\Renderer;
use Drupal\contentful_migration\RichText\ContentfulAssetUrlResolver;
use Drupal\contentful_migration\RichText\ContentfulAssetUrlResolverInterface;
use Drupal\contentful_migration\RichText\ContentfulEmbedResolver;
use Drupal\contentful_migration\RichText\ContentfulEmbedResolverInterface;
use Drupal\contentful_migration\RichText\DrupalAssetHyperlink;
use Drupal\contentful_migration\RichText\DrupalEmbeddedAssetBlock;
use Drupal\contentful_migration\RichText\DrupalEmbeddedAssetInline;
use Drupal\contentful_migration\RichText\DrupalEmbeddedEntryBlock;
use Drupal\contentful_migration\RichText\DrupalEmbeddedEntryInline;
use Drupal\contentful_migration\RichText\DrupalEntryHyperlink;
use Drupal\contentful_migration\RichText\DrupalHyperlink;
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
 *    (DrupalEntryHyperlink) and asset-hyperlink to link the migrated file
 *    when media + file are installed, degrading to plain text when they are
 *    not (DrupalAssetHyperlink).
 *  - an unknown node type would make the contentful/rich-text Parser throw
 *    InvalidArgumentException at parse time (Parser::parseLocalized()), which
 *    would empty the whole field. So transform() pre-sanitizes the AST first
 *    (sanitizeAst()): it drops and logs only the unknown node and its subtree,
 *    leaving every sibling intact — a localized drop, never a silent or
 *    whole-body loss. The library's CatchAll renderer is a separate
 *    render-stage path that unknown node types never reach. Marks are not
 *    sanitized — only node types.
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
   * Anything else makes the Parser throw at parse time; sanitizeAst() drops and
   * logs it before parsing, so one unknown node degrades to a localized drop
   * rather than emptying the whole body.
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
    private readonly ?ContentfulAssetUrlResolverInterface $assetUrlResolver = NULL,
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
    $resolver = new ContentfulEmbedResolver(
      $container->get('migrate.lookup'),
      $container->get('entity_type.manager'),
      $configuration['embed_migrations'] ?? [],
    );

    // Asset-hyperlink file resolution is a gated upgrade: the URL resolver —
    // the single place media/file entity APIs live — is only built when both
    // modules are installed. Absent them, DrupalAssetHyperlink keeps its
    // plain-text degrade and the module's media/file independence holds.
    $assetUrlResolver = NULL;
    $moduleHandler = $container->get('module_handler');
    if ($moduleHandler->moduleExists('media') && $moduleHandler->moduleExists('file')) {
      $assetUrlResolver = new ContentfulAssetUrlResolver(
        $resolver,
        $container->get('entity.repository'),
        $container->get('entity_type.manager'),
        $container->get('file_url_generator'),
      );
    }

    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $resolver,
      $container->get('entity.repository'),
      $container->get('logger.factory')->get('contentful_migration'),
      $assetUrlResolver,
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

    // The Parser throws on any unmapped nodeType, which would empty the whole
    // field. Pre-sanitize first: drop (and log) only the unknown nodes so every
    // sibling survives. The try/catch below stays as a belt-and-suspenders
    // fallback for any parse failure sanitizing doesn't cover (e.g. unknown
    // marks, which are not sanitized here).
    $value = $this->sanitizeAst($value) ?? $value;

    $parser = new Parser(new SysIdLinkResolver());
    try {
      $node = $parser->parse($value);
    }
    catch (\InvalidArgumentException $e) {
      $this->logger->error('Rich Text parse failed (@msg); field left empty. See preceding warnings for the unrecognised node type.', ['@msg' => $e->getMessage()]);
      return '';
    }

    // These renderers take front priority, overriding the library defaults for
    // the embed and hyperlink node types; all other nodes use the library's
    // renderers. DrupalHyperlink scheme-guards plain external links (dropping
    // javascript:/data: hrefs); the inline embed renderers stop the library's
    // visible Entry#/Asset# placeholders from reaching migrated bodies.
    $renderer = new Renderer([
      new DrupalEmbeddedEntryBlock($this->resolver, $this->logger),
      new DrupalEmbeddedEntryInline($this->resolver, $this->logger),
      new DrupalEmbeddedAssetBlock($this->resolver, $this->logger),
      new DrupalEmbeddedAssetInline($this->resolver, $this->logger),
      new DrupalEntryHyperlink(
        $this->resolver,
        $this->entityRepository,
        $this->logger,
      ),
      new DrupalAssetHyperlink($this->logger, $this->assetUrlResolver),
      new DrupalHyperlink($this->logger),
    ]);

    return $renderer->render($node);
  }

  /**
   * Strips nodes the library can't parse so one bad node is a localized drop.
   *
   * The contentful/rich-text Parser throws on any unmapped nodeType, which
   * would empty the whole body. Removing the offending node (and its subtree)
   * and logging it keeps every sibling renderable, so one unknown node degrades
   * to a localized drop instead of throwing out the whole body. Returns the
   * filtered node, or NULL if the node itself is unknown (the caller drops it).
   * Marks are not sanitized — only node types.
   */
  private function sanitizeAst(array $node): ?array {
    $type = $node['nodeType'] ?? NULL;
    if ($type !== NULL && !in_array($type, self::KNOWN_NODE_TYPES, TRUE)) {
      $this->logger->warning('Dropping unknown Rich Text node type "@type"; the rest of the body is preserved.', ['@type' => $type]);
      return NULL;
    }
    if (!empty($node['content']) && is_array($node['content'])) {
      $kept = [];
      foreach ($node['content'] as $child) {
        if (is_array($child) && ($clean = $this->sanitizeAst($child)) !== NULL) {
          $kept[] = $clean;
        }
      }
      $node['content'] = $kept;
    }
    return $node;
  }

}
