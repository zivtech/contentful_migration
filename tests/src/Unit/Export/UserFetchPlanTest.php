<?php

declare(strict_types=1);

namespace Drupal\Tests\contentful_migration\Unit\Export;

use Drupal\contentful_migration\Export\UserFetchPlan;
use Drupal\contentful_migration\Source\ContentfulEntryFlattener;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

// phpcs:disable DrupalPractice.General.LanguageNone.Und -- 'und' here is the
// users.json file-format locale key this test pins (the staged file is read
// at locale: und), not a field-API value access; the class under test is
// deliberately Drupal-free.
/**
 * Unit coverage for the pure users-fetch plan behind --include-users.
 *
 * The Drush command does the HTTP and writes users.json; everything decidable
 * without a network lives here (the ExportConfig/ExportSummary pure/thin
 * split): CMA request URL assembly, skip/limit/total pagination advancement,
 * and the CMA-user-item -> entry-shaped-row mapping the existing flattener
 * consumes untouched.
 *
 * Truth boundary: this pins the request shape and mapping against the CMA
 * contract as documented (users collection envelope {sys,total,skip,limit,
 * items}; firstName/lastName/email top-level on the item — verified against
 * the official contentful-management.js UserProps type). The live HTTP
 * behaviour stays first-real-run territory: no network in the harness.
 */
#[Group('contentful_migration')]
class UserFetchPlanTest extends UnitTestCase {

  /**
   * The request URL embeds the space id and pages by skip/limit.
   */
  public function testRequestUrlAssembly(): void {
    $plan = new UserFetchPlan('space1');
    $this->assertSame(
      'https://api.contentful.com/spaces/space1/users?skip=0&limit=100',
      $plan->requestUrl(0),
    );
    $this->assertSame(
      'https://api.contentful.com/spaces/space1/users?skip=200&limit=100',
      $plan->requestUrl(200),
    );
  }

  /**
   * A blank space id is rejected before any request could be built.
   */
  public function testSpaceIdIsRequired(): void {
    $this->expectException(\InvalidArgumentException::class);
    new UserFetchPlan('  ');
  }

  /**
   * A non-positive page size is rejected.
   */
  public function testLimitMustBePositive(): void {
    $this->expectException(\InvalidArgumentException::class);
    new UserFetchPlan('space1', 0);
  }

  /**
   * Pagination advances by the items actually returned, then stops.
   *
   * Advancing by count(items) rather than the requested limit keeps the loop
   * correct when the server clamps the page size.
   */
  public function testPaginationAdvancesByReturnedItemsAndStops(): void {
    $plan = new UserFetchPlan('space1', 2);

    $pageOne = ['total' => 5, 'skip' => 0, 'limit' => 2, 'items' => [['a'], ['b']]];
    $this->assertSame(2, $plan->nextSkip($pageOne));

    $pageTwo = ['total' => 5, 'skip' => 2, 'limit' => 2, 'items' => [['c'], ['d']]];
    $this->assertSame(4, $plan->nextSkip($pageTwo));

    $lastPage = ['total' => 5, 'skip' => 4, 'limit' => 2, 'items' => [['e']]];
    $this->assertNull($plan->nextSkip($lastPage), 'All items collected — pagination is complete.');
  }

  /**
   * An empty page terminates pagination even when total claims more.
   *
   * Guards against an infinite request loop should the reported total ever
   * disagree with what the endpoint actually returns.
   */
  public function testPaginationStopsOnEmptyPageDespiteLyingTotal(): void {
    $plan = new UserFetchPlan('space1', 2);
    $emptyPage = ['total' => 99, 'skip' => 4, 'limit' => 2, 'items' => []];
    $this->assertNull($plan->nextSkip($emptyPage));
  }

  /**
   * A response without an items array is rejected loudly, not as "no users".
   */
  public function testItemsOfRejectsMalformedEnvelope(): void {
    $plan = new UserFetchPlan('space1');
    $this->expectException(\RuntimeException::class);
    $plan->itemsOf(['sys' => ['type' => 'Array'], 'total' => 1]);
  }

  /**
   * A full CMA user item maps to the entry-shaped row, names concatenated.
   */
  public function testMapUserConcatenatesNamesAndKeepsEmail(): void {
    $plan = new UserFetchPlan('space1');
    $row = $plan->mapUser([
      'sys' => ['id' => 'user1', 'type' => 'User'],
      'firstName' => ' Ada ',
      'lastName' => ' Lovelace ',
      'email' => 'ada@example.com',
      'avatarUrl' => 'https://example.com/a.png',
      'activated' => TRUE,
    ]);
    $this->assertSame([
      'sys' => ['id' => 'user1'],
      'fields' => [
        'name' => ['und' => 'Ada Lovelace'],
        'mail' => ['und' => 'ada@example.com'],
      ],
    ], $row);
  }

  /**
   * Without an email (non-admin token), the mail field is omitted entirely.
   *
   * The example migration's skip_on_empty then leaves the stub mail-less
   * rather than importing an empty string.
   */
  public function testMapUserOmitsMailWhenEmailAbsentOrBlank(): void {
    $plan = new UserFetchPlan('space1');

    $nonAdminShape = $plan->mapUser([
      'sys' => ['id' => 'user2'],
      'firstName' => 'Grace',
      'lastName' => 'Hopper',
      'avatarUrl' => 'https://example.com/g.png',
    ]);
    $this->assertSame('Grace Hopper', $nonAdminShape['fields']['name']['und']);
    $this->assertArrayNotHasKey('mail', $nonAdminShape['fields']);

    $blankEmail = $plan->mapUser([
      'sys' => ['id' => 'user3'],
      'firstName' => 'Joan',
      'lastName' => 'Clarke',
      'email' => '   ',
    ]);
    $this->assertArrayNotHasKey('mail', $blankEmail['fields']);
  }

  /**
   * Partial or missing names degrade deterministically, never to blank.
   *
   * A blank name would be rejected (or collide) at user save; the sys id is
   * the stable fallback.
   */
  public function testMapUserNameFallsBackThroughPartialNamesToSysId(): void {
    $plan = new UserFetchPlan('space1');

    $firstOnly = $plan->mapUser(['sys' => ['id' => 'u4'], 'firstName' => 'Ada']);
    $this->assertSame('Ada', $firstOnly['fields']['name']['und']);

    $lastOnly = $plan->mapUser(['sys' => ['id' => 'u5'], 'lastName' => 'Lovelace']);
    $this->assertSame('Lovelace', $lastOnly['fields']['name']['und']);

    $nameless = $plan->mapUser(['sys' => ['id' => 'u6'], 'email' => 'u6@example.com']);
    $this->assertSame('u6', $nameless['fields']['name']['und']);
  }

  /**
   * Names longer than Drupal's 60-character limit are clipped.
   *
   * The name column is varchar(60) under strict SQL mode (proven by kernel
   * execution: SQLSTATE 22001 "Data too long for column 'name'" hard-failed
   * the row) — an over-length CMA display name must be clipped before it
   * reaches the database.
   */
  public function testMapUserClipsOverLongNamesToSixtyCharacters(): void {
    $plan = new UserFetchPlan('space1');
    $row = $plan->mapUser([
      'sys' => ['id' => 'user5'],
      'firstName' => 'Maximiliana Bartholomewson-Featherstonehaugh',
      'lastName' => 'von Hohenzollern-Sigmaringen',
    ]);
    $this->assertSame(
      'Maximiliana Bartholomewson-Featherstonehaugh von Hohenzoller',
      $row['fields']['name']['und'],
    );
    $this->assertSame(60, mb_strlen($row['fields']['name']['und']));
  }

  /**
   * The disambiguation suffix never pushes a clipped name past the limit.
   *
   * Clipping can itself CREATE duplicates (two long names sharing a 60-char
   * prefix), so the base is re-clipped to make room for the sys-id suffix.
   */
  public function testMapUsersKeepsDisambiguationSuffixWithinTheLimit(): void {
    $plan = new UserFetchPlan('space1');
    $rows = $plan->mapUsers([
      [
        'sys' => ['id' => 'user5'],
        'firstName' => 'Maximiliana Bartholomewson-Featherstonehaugh',
        'lastName' => 'von Hohenzollern-Sigmaringen',
      ],
      [
        'sys' => ['id' => 'user9'],
        'firstName' => 'Maximiliana Bartholomewson-Featherstonehaugh',
        'lastName' => 'von Hohenzollern-Sigmaringen',
      ],
    ]);
    $names = array_map(static fn (array $row): string => $row['fields']['name']['und'], $rows);
    $this->assertSame([
      'Maximiliana Bartholomewson-Featherstonehaugh von Hohenzoller',
      'Maximiliana Bartholomewson-Featherstonehaugh von Hoh (user9)',
    ], $names);
    $this->assertLessThanOrEqual(60, max(array_map('mb_strlen', $names)));
  }

  /**
   * Duplicate display names are disambiguated with the member's sys id.
   *
   * Drupal's user__name key is unique at the database level (proven by
   * kernel execution: Duplicate entry 'Jordan Smith-en' for key
   * 'user__name'), and CMA display names are not unique — two members named
   * Jordan Smith would hard-fail
   * the second stub row. The suffix is the sys id, NOT a counter, so a
   * regenerated users.json always produces the same names: a track_changes
   * re-import sees stable values instead of renaming users on every delta
   * run (the failure mode of counter-based uniquifiers that query site
   * state, e.g. make_unique_entity_field, whose exists() check has no
   * self-exclusion).
   */
  public function testMapUsersDisambiguatesDuplicateDisplayNames(): void {
    $plan = new UserFetchPlan('space1');
    $rows = $plan->mapUsers([
      ['sys' => ['id' => 'user3'], 'firstName' => 'Jordan', 'lastName' => 'Smith'],
      ['sys' => ['id' => 'user4'], 'firstName' => 'Jordan', 'lastName' => 'Smith'],
      // The user__name key is case-insensitive — collide on case too.
      ['sys' => ['id' => 'user7'], 'firstName' => 'JORDAN', 'lastName' => 'SMITH'],
      ['sys' => ['id' => 'user8'], 'firstName' => 'Una', 'lastName' => 'Unique'],
    ]);
    $this->assertSame(
      ['Jordan Smith', 'Jordan Smith (user4)', 'JORDAN SMITH (user7)', 'Una Unique'],
      array_map(static fn (array $row): string => $row['fields']['name']['und'], $rows),
    );
  }

  /**
   * Items without a usable sys.id are skipped, and the list re-indexes.
   */
  public function testMapUsersDropsMalformedItems(): void {
    $plan = new UserFetchPlan('space1');
    $rows = $plan->mapUsers([
      ['sys' => ['id' => 'u1'], 'firstName' => 'Ada', 'lastName' => 'Lovelace'],
      ['firstName' => 'No', 'lastName' => 'SysId'],
      ['sys' => ['id' => 42], 'firstName' => 'Numeric', 'lastName' => 'Id'],
      ['sys' => ['id' => 'u2'], 'firstName' => 'Grace', 'lastName' => 'Hopper'],
    ]);
    $this->assertCount(2, $rows);
    $this->assertSame(['u1', 'u2'], array_column(array_column($rows, 'sys'), 'id'));
    $this->assertSame([0, 1], array_keys($rows), 'Dropped items do not leave holes in the list.');
  }

  /**
   * The file payload is {items: …} and the existing flattener consumes a row.
   *
   * This is the load-bearing claim behind "entry-shaped users.json": the
   * contentful_export source plugin (selector: items, locale: und) must read
   * the file with zero source-plugin changes. Proven here by running the real
   * flattener over a built row.
   */
  public function testBuildFileIsConsumableByTheExistingFlattener(): void {
    $plan = new UserFetchPlan('space1');
    $file = $plan->buildFile([
      ['sys' => ['id' => 'user1'], 'firstName' => 'Ada', 'lastName' => 'Lovelace', 'email' => 'ada@example.com'],
    ]);

    $this->assertSame(['items'], array_keys($file));
    $this->assertCount(1, $file['items']);

    $flattened = (new ContentfulEntryFlattener('und'))->flatten($file['items'][0]);
    $this->assertSame('user1', $flattened['sys_id']);
    $this->assertSame('Ada Lovelace', $flattened['name']);
    $this->assertSame('ada@example.com', $flattened['mail']);
  }

}
