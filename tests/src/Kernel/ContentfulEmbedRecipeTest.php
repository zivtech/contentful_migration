<?php

declare(strict_types=1);

namespace Drupal\Tests\contentful_migration\Kernel;

use Drupal\Core\Recipe\Recipe;
use Drupal\Core\Recipe\RecipeRunner;
use Drupal\Core\Render\RenderContext;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\filter\Entity\FilterFormat;
use Drupal\media\Entity\MediaType;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\migrate\Kernel\MigrateTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;

/**
 * End-to-end: the shipped recipe renders a freshly migrated body.
 *
 * Closes the BASE embed-rendering gap with a real assertion instead of a
 * docs claim: RecipeRunner applies recipes/contentful_embed/ exactly as a
 * site would, the asset-body fixture migrates with body/format
 * contentful_embed (the new default), and check_markup through the created
 * format must consume the <drupal-media> token into a rendered <img> while
 * the module's own anchors survive filter_html with their attributes intact
 * — the allow-list is verified against the markup the module actually emits,
 * not hand-checked.
 *
 * Truth boundaries: the <drupal-entity-embed> branch renders only with
 * contrib entity_embed, which this harness cannot install — its token is
 * asserted allow-listed (survives, not stripped) and its rendering stays a
 * documented contract. The recipe's module-install step is a no-op here
 * (media is a test dependency); core's RecipeRunner tests own that behavior.
 *
 * @group contentful_migration
 */
class ContentfulEmbedRecipeTest extends MigrateTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'file',
    'image',
    'media',
    'node',
    'migrate',
    'contentful_migration',
    'contentful_migration_test',
  ];

  /**
   * The image media type's source field name (created in setUp).
   */
  private string $sourceField;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installEntitySchema('media');
    $this->installEntitySchema('node');
    $this->installSchema('file', ['file_usage']);
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['field', 'system', 'file', 'image', 'media', 'node', 'filter']);

    // media_embed renders with entity access checks: a user that can view
    // media must be current. The first created user is skipped — uid 1
    // bypasses access and would prove nothing.
    $this->createUser([]);
    $user = $this->createUser(['access content', 'view media']);
    $this->container->get('current_user')->setAccount($user);

    // An image media type with its standard source field.
    $mediaType = MediaType::create([
      'id' => 'image',
      'label' => 'Image',
      'source' => 'image',
    ]);
    $mediaType->save();
    $source = $mediaType->getSource();
    $sourceField = $source->createSourceField($mediaType);
    $sourceField->getFieldStorageDefinition()->save();
    $sourceField->save();
    $this->sourceField = $sourceField->getName();
    $mediaType->set('source_configuration', ['source_field' => $this->sourceField])->save();

    // Programmatic MediaType creation skips the form-time display wiring, so
    // the default view display would render no image at all. Wire the source
    // field the way the media UI does.
    $display = $this->container->get('entity_display.repository')->getViewDisplay('media', 'image');
    $source->prepareViewDisplay($mediaType, $display);
    $display->save();

    // Destination bundle + body field for the assetPost node.
    NodeType::create(['type' => 'asset_post', 'name' => 'Asset Post'])->save();
    FieldStorageConfig::create([
      'field_name' => 'body',
      'entity_type' => 'node',
      'type' => 'text_with_summary',
    ])->save();
    FieldConfig::create([
      'field_name' => 'body',
      'entity_type' => 'node',
      'bundle' => 'asset_post',
      'label' => 'Body',
    ])->save();

    // No FilterFormat here — creating contentful_embed is the recipe's job.
    // Stage the export + nested-layout assets, as in the hyperlink test.
    $fileSystem = $this->container->get('file_system');
    $pngA = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR4nGNgAAIAAAUAAen63NgAAAAASUVORK5CYII=');
    $pngB = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    $nested = [
      'public://cf-asset-files/images.ctfassets.net/spacexyz/imgA/aaa/diagram.png' => $pngA,
      'public://cf-asset-files/images.ctfassets.net/spacexyz/fileB/bbb/brochure.png' => $pngB,
    ];
    foreach ($nested as $uri => $bytes) {
      $dir = dirname($uri);
      $fileSystem->prepareDirectory($dir, $fileSystem::CREATE_DIRECTORY);
      file_put_contents($uri, $bytes);
    }
    file_put_contents(
      'public://cf-asset-body-export.json',
      file_get_contents(__DIR__ . '/../../fixtures/synthetic-asset-body-export.json'),
    );
  }

  /**
   * The recipe applies, and the format it creates renders a migrated body.
   */
  public function testRecipeRendersMigratedEmbeds(): void {
    // Apply the recipe from the path shipped in the module, exactly as
    // `drush recipe` would.
    $recipePath = $this->container->get('extension.list.module')->getPath('contentful_migration') . '/recipes/contentful_embed';
    RecipeRunner::processRecipe(Recipe::createFromDirectory($recipePath));

    // The format exists, renders media, and allow-lists both token kinds.
    $format = FilterFormat::load('contentful_embed');
    $this->assertNotNull($format, 'The recipe created the contentful_embed format.');
    $this->assertTrue((bool) $format->filters('media_embed')->getConfiguration()['status'], 'media_embed is enabled.');
    $allowed = $format->filters('filter_html')->getConfiguration()['settings']['allowed_html'];
    $this->assertStringContainsString('<drupal-media', $allowed);
    $this->assertStringContainsString('<drupal-entity-embed', $allowed);

    // Migrate the asset-body fixture; the body stores the new format default.
    $this->executeMigrations(['cf_asset_media', 'cf_asset_post']);
    $lookup = $this->container->get('migrate.lookup');
    $postResult = $lookup->lookup('cf_asset_post', ['apost1']);
    $this->assertNotEmpty($postResult, 'assetPost migrated to a node.');
    $postFirst = reset($postResult);
    $node = $this->container->get('entity_type.manager')->getStorage('node')->load(reset($postFirst));
    $this->assertSame('contentful_embed', $node->get('body')->format, 'Migrated body stores the recipe-shipped format.');

    $body = (string) $node->get('body')->value;
    $this->assertStringContainsString('<drupal-media', $body, 'The stored body carries the media token.');

    // Render through the real filter pipeline, in a render context as the
    // filter system expects.
    $rendered = (string) $this->container->get('renderer')->executeInRenderContext(
      new RenderContext(),
      fn () => (string) check_markup($body, 'contentful_embed'),
    );

    // Round trip, token side: media_embed consumed the token into a real
    // image render of the embedded asset.
    $this->assertStringNotContainsString('<drupal-media', $rendered, 'The media token was consumed, not passed through.');
    $this->assertStringContainsString('<img', $rendered, 'The embedded asset rendered as an image.');
    $this->assertStringContainsString('diagram', $rendered, 'The rendered image is the migrated diagram file.');

    // Round trip, allow-list side: the module's own emitted anchor survived
    // filter_html with href and title intact — the allow-list is proven
    // against real module output. The explicit `<a href=` check matters:
    // filter_html strips disallowed *attributes* leaving a bare <a>, so a
    // text-only assertion could pass with the link destroyed.
    $this->assertStringContainsString(' title="Brochure">the brochure</a>', $rendered, 'The asset-hyperlink anchor survived the restrictive format.');
    $this->assertStringContainsString('<a href=', $rendered, 'The anchor kept its href attribute.');
    $this->assertStringContainsString('Download', $rendered, 'Surrounding text survived.');
  }

}
