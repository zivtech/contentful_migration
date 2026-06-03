<?php

declare(strict_types=1);

namespace Drupal\Tests\contentful_migration\Kernel;

use Drupal\Core\File\FileSystemInterface;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\Tests\migrate\Kernel\MigrateTestBase;

/**
 * Delta re-import: track_changes re-imports changed rows, skips unchanged.
 *
 * The P0 question this answers by execution: does core `track_changes: true`
 * work as-is on the `contentful_export` source (which extends
 * SourcePluginBase without overriding the hash machinery)? Two scenarios:
 * with the flag, a mutated export row re-imports IN PLACE (same nid, new
 * title) while an untouched row keeps its state; without the flag (the
 * negative control), the same mutation is skipped on re-import and the stale
 * title persists — proving track_changes is the flag doing the work, not
 * accidental re-import.
 *
 * Truth boundary: this proves update-in-place + skip semantics for re-runs
 * of a full export. It does NOT prove deletion handling (a full export has
 * no tombstones and migrate never deletes — the documented drift gap), nor
 * `high_water_property` (needs the `sys_updated_at` source field, a separate
 * change).
 *
 * @group contentful_migration
 */
class ContentfulDeltaImportTest extends MigrateTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'node',
    'migrate',
    'contentful_migration',
    'contentful_migration_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    // node_access is a plain schema table (not entity schema); the in-place
    // node update on re-import writes grants to it, so it must exist.
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['field', 'node', 'filter']);

    NodeType::create(['type' => 'blog_post', 'name' => 'Blog Post'])->save();

    // Stage one mutable export copy per migration (separate id-maps must not
    // share a file, or one scenario's mutation contaminates the other).
    $fileSystem = $this->container->get('file_system');
    $publicDir = 'public://';
    $fileSystem->prepareDirectory($publicDir, FileSystemInterface::CREATE_DIRECTORY);
    $fixture = file_get_contents(__DIR__ . '/../../fixtures/synthetic-contentful-export.json');
    file_put_contents('public://cf-delta-export.json', $fixture);
    file_put_contents('public://cf-delta-off-export.json', $fixture);
  }

  /**
   * With track_changes, a changed row re-imports in place; others skip.
   */
  public function testTrackChangesReimportsChangedRows(): void {
    $this->executeMigrations(['cf_delta']);

    $post1 = $this->loadNodeBySourceId('cf_delta', 'post1');
    $post2 = $this->loadNodeBySourceId('cf_delta', 'post2');
    $this->assertSame('Getting Started with Decoupled Drupal', $post1->getTitle());
    $this->assertSame('Advanced Decoupled Patterns', $post2->getTitle());
    $post1Nid = $post1->id();
    $post2Nid = $post2->id();

    $this->mutatePost1Title('public://cf-delta-export.json', 'Getting Started, Second Edition');
    $this->executeMigrations(['cf_delta']);

    $post1Updated = $this->loadNodeBySourceId('cf_delta', 'post1');
    // In-place update: same nid (the id-map row was reused), new title (the
    // changed hash forced a re-import).
    $this->assertSame($post1Nid, $post1Updated->id(), 'Changed row updated the SAME node — no duplicate.');
    $this->assertSame('Getting Started, Second Edition', $post1Updated->getTitle(), 'Changed row re-imported in place.');

    $post2After = $this->loadNodeBySourceId('cf_delta', 'post2');
    $this->assertSame($post2Nid, $post2After->id());
    $this->assertSame('Advanced Decoupled Patterns', $post2After->getTitle(), 'Unchanged row kept its state.');
  }

  /**
   * Without track_changes, re-import skips already-imported rows entirely.
   *
   * The negative control: the same mutation that re-imported above is
   * ignored here, so the stale title persists. This is why the delta-import
   * documentation must say `track_changes: true`, not just "re-run import".
   */
  public function testWithoutTrackChangesChangedRowsAreSkipped(): void {
    $this->executeMigrations(['cf_delta_off']);

    $post1 = $this->loadNodeBySourceId('cf_delta_off', 'post1');
    $this->assertSame('Getting Started with Decoupled Drupal', $post1->getTitle());
    $post1Nid = $post1->id();

    $this->mutatePost1Title('public://cf-delta-off-export.json', 'Getting Started, Second Edition');
    $this->executeMigrations(['cf_delta_off']);

    $post1After = $this->loadNodeBySourceId('cf_delta_off', 'post1');
    $this->assertSame($post1Nid, $post1After->id());
    $this->assertSame(
      'Getting Started with Decoupled Drupal',
      $post1After->getTitle(),
      'Without track_changes the changed row is skipped — the stale title persists.',
    );
  }

  /**
   * Loads the destination node for a Contentful sys.id via the id-map.
   */
  private function loadNodeBySourceId(string $migrationId, string $sysId): NodeInterface {
    $result = $this->container->get('migrate.lookup')->lookup($migrationId, [$sysId]);
    $this->assertNotEmpty($result, sprintf('%s migrated a node for %s.', $migrationId, $sysId));
    $first = reset($result);
    $node = $this->container->get('entity_type.manager')->getStorage('node')->load(reset($first));
    $this->assertInstanceOf(NodeInterface::class, $node);
    // Re-imports update the stored entity; never assert against a stale copy.
    return $this->container->get('entity_type.manager')->getStorage('node')->loadUnchanged($node->id());
  }

  /**
   * Rewrites post1's en-US title inside a staged export copy.
   */
  private function mutatePost1Title(string $uri, string $newTitle): void {
    $data = json_decode((string) file_get_contents($uri), TRUE);
    foreach ($data['entries'] as &$entry) {
      if (($entry['sys']['id'] ?? '') === 'post1') {
        $entry['fields']['title']['en-US'] = $newTitle;
      }
    }
    file_put_contents($uri, json_encode($data));
  }

}
