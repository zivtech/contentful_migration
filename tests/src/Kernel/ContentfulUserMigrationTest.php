<?php

declare(strict_types=1);

namespace Drupal\Tests\contentful_migration\Kernel;

use Drupal\Core\File\FileSystemInterface;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\Tests\migrate\Kernel\MigrateTestBase;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * P2 --include-users: blocked user stubs + author->uid entry attribution.
 *
 * Runs the entry-shaped users.json a real `--include-users` run stages
 * through the EXACT example shapes (cf_user mirrors contentful_user.yml,
 * cf_authored mirrors the blog example's uid chain) and asserts the COMPLETE
 * outcome — exact user count, every name/mail, blocked status, role set,
 * every node's owner — so a silently failed row (migrate records a message
 * and keeps going) cannot pass green.
 *
 * The fixture carries the contested cases as data, so the database settles
 * them by execution rather than recalled internals:
 * - user3/user4 shared the display name "Jordan Smith" in the CMA (names
 *   are not unique; user__name IS a unique key — a verbatim duplicate
 *   hard-failed this very test with SQLSTATE 23000). The staged file
 *   carries what the mapper actually emits: user4 disambiguated with its
 *   sys id. Proven at unit level; proven importable here.
 * - user5's CMA display name was 73 characters; USERNAME_MAX_LENGTH is 60
 *   and strict SQL mode hard-failed the verbatim insert (SQLSTATE 22001,
 *   proven by execution here). The staged file carries the mapper's
 *   60-character clip.
 * - art2's author id is a DEPARTED member (absent from users.json) — no_stub
 *   must mint no user, and the owner falls back to anonymous.
 * - art3 has no sys.createdBy at all — the lookup chain must degrade without
 *   failing the row.
 *
 * Truth boundary: green here proves the staged-file -> stubs -> attribution
 * chain in this harness's MySQL; the live CMA HTTP contract stays
 * first-real-run territory (no network), and a permissive harness collation
 * makes duplicate/over-length greens necessary-but-not-sufficient.
 */
#[Group('contentful_migration')]
#[RunTestsInSeparateProcesses]
class ContentfulUserMigrationTest extends MigrateTestBase {

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
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['field', 'node', 'filter']);

    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();

    $fileSystem = $this->container->get('file_system');
    $publicDir = 'public://';
    $fileSystem->prepareDirectory($publicDir, FileSystemInterface::CREATE_DIRECTORY);
    copy(__DIR__ . '/../../fixtures/synthetic-users.json', 'public://cf-users.json');
    copy(__DIR__ . '/../../fixtures/synthetic-authored-export.json', 'public://cf-authored-export.json');
  }

  /**
   * Space members import as blocked, role-less stubs — all five of them.
   */
  public function testMembersImportAsBlockedStubs(): void {
    $before = $this->countUsers();
    $this->executeMigrations(['cf_user']);
    $this->assertArrayNotHasKey('error', (array) $this->migrateMessages, 'No row failed: ' . print_r($this->migrateMessages, TRUE));
    $this->assertSame($before + 5, $this->countUsers(), 'Every staged member became exactly one stub.');

    $expected = [
      'user1' => ['Ada Lovelace', 'ada@example.com'],
      'user2' => ['Grace Hopper', NULL],
      // user3 + user4 shared a display name; the mapper suffixed user4.
      'user3' => ['Jordan Smith', 'jordan.a@example.com'],
      'user4' => ['Jordan Smith (user4)', 'jordan.b@example.com'],
      // Was 73 characters in the CMA; the mapper clipped it to 60.
      'user5' => ['Maximiliana Bartholomewson-Featherstonehaugh von Hohenzoller', 'max@example.com'],
    ];
    foreach ($expected as $sysId => [$name, $mail]) {
      $stub = $this->loadMigratedUser($sysId);
      $this->assertSame($name, $stub->getAccountName(), "$sysId kept its display name.");
      if ($mail === NULL) {
        $this->assertEmpty($stub->getEmail(), "$sysId (fetched without an admin token) has no email.");
      }
      else {
        $this->assertSame($mail, $stub->getEmail(), "$sysId kept its email.");
      }
      $this->assertTrue($stub->isBlocked(), "$sysId is a BLOCKED stub — attribution target, never a login.");
      $this->assertSame(['authenticated'], $stub->getRoles(), "$sysId gained no roles.");
    }
  }

  /**
   * Entries attribute to their author's stub; missing authors degrade to 0.
   */
  public function testEntriesAttributeToAuthorStubs(): void {
    $this->executeMigrations(['cf_user', 'cf_authored']);
    $this->assertArrayNotHasKey('error', (array) $this->migrateMessages, 'No row failed: ' . print_r($this->migrateMessages, TRUE));

    $usersAfterStubs = $this->countUsers();

    // Known member: the node belongs to that member's stub.
    $art1 = $this->loadMigratedNode('art1');
    $this->assertSame(
      $this->loadMigratedUser('user1')->id(),
      $art1->getOwnerId(),
      'An entry by a known member is owned by that member\'s stub.',
    );

    // Departed member (id absent from users.json): anonymous, explicitly —
    // and no_stub means the entry pass minted no user for the dangling id.
    $art2 = $this->loadMigratedNode('art2');
    $this->assertSame('0', (string) $art2->getOwnerId(), 'A departed member\'s entry falls back to anonymous.');
    $this->assertSame($usersAfterStubs, $this->countUsers(), 'No stub was minted for the departed member id.');

    // No createdBy at all: the lookup skips on empty input, uid stays
    // UNSET, and Drupal's owner field default (the current user) fills it —
    // anonymous here and under CLI imports. A UI-triggered import would
    // default these to the logged-in user instead; documented, not hidden.
    $art3 = $this->loadMigratedNode('art3');
    $this->assertSame('0', (string) $art3->getOwnerId(), 'An entry without sys.createdBy is unattributed (anonymous in CLI/kernel context).');

    $nodeCount = (int) $this->container->get('entity_type.manager')->getStorage('node')
      ->getQuery()->accessCheck(FALSE)->count()->execute();
    $this->assertSame(3, $nodeCount, 'Every authored entry imported — no row was lost to the uid chain.');
  }

  /**
   * Counts all user entities (anonymous row included, if present).
   */
  private function countUsers(): int {
    return (int) $this->container->get('entity_type.manager')->getStorage('user')
      ->getQuery()->accessCheck(FALSE)->count()->execute();
  }

  /**
   * Loads the stub a Contentful user id migrated to, asserting it exists.
   */
  private function loadMigratedUser(string $sysId): UserInterface {
    $uid = $this->migratedId('cf_user', $sysId);
    $this->assertNotNull($uid, sprintf('cf_user migrated a stub for %s.', $sysId));
    $user = $this->container->get('entity_type.manager')->getStorage('user')->load($uid);
    $this->assertInstanceOf(UserInterface::class, $user);
    return $user;
  }

  /**
   * Loads the node a Contentful entry id migrated to, asserting it exists.
   */
  private function loadMigratedNode(string $sysId): NodeInterface {
    $nid = $this->migratedId('cf_authored', $sysId);
    $this->assertNotNull($nid, sprintf('cf_authored migrated a node for %s.', $sysId));
    $node = $this->container->get('entity_type.manager')->getStorage('node')->load($nid);
    $this->assertInstanceOf(NodeInterface::class, $node);
    return $node;
  }

  /**
   * Returns the destination id for a source id, NULL when the row failed.
   *
   * The id-map keeps a row with NULL destination ids for failed/ignored
   * rows, so the lookup result must be filtered before use.
   */
  private function migratedId(string $migrationId, string $sysId): ?string {
    $result = $this->container->get('migrate.lookup')->lookup($migrationId, [$sysId]);
    $ids = array_filter(reset($result) ?: []);
    return $ids === [] ? NULL : (string) reset($ids);
  }

}
