<?php

declare(strict_types=1);

namespace Drupal\contentful_migration\Export;

/**
 * Pure request plan + row mapper for the opt-in CMA users fetch.
 *
 * Backs `contentful:export --include-users`. Everything decidable without a
 * network lives here — CMA request URL assembly, skip/limit/total pagination
 * advancement, and the mapping from a raw CMA user item to an entry-shaped
 * row — so it is unit-testable with no Drush bootstrap (the same pure/thin
 * split as ExportConfig/ExportSummary). The Drush command does the HTTP and
 * writes the file; the management token never enters this class (it travels
 * only in the Authorization header the command sets).
 *
 * Why entry-shaped: a raw CMA user object is top-level ({sys, firstName,
 * lastName, email…}, no fields/locale wrappers), so fed raw through the
 * contentful_export source plugin every field would resolve NULL — blank
 * stubs. Reshaping each user to {sys:{id}, fields:{name:{und}, mail:{und}}}
 * lets the existing flattener consume users.json untouched (selector: items,
 * locale: und).
 *
 * Email is an admin-token-only CMA attribute: a non-admin token yields items
 * without it, in which case `mail` is omitted entirely so the example
 * migration's skip_on_empty leaves the stub mail-less rather than importing
 * an empty string.
 *
 * @internal
 *   Not yet stable public API while the module is in alpha/beta. Graduates at
 *   1.0.0, or earlier if an external consumer proves the need.
 */
final class UserFetchPlan {

  /**
   * The Content Management API base URL (users are space-, not env-scoped).
   */
  private const API_BASE = 'https://api.contentful.com';

  /**
   * Locale key for the single un-localised value of each user field.
   *
   * Matches LanguageInterface::LANGCODE_NOT_SPECIFIED without coupling this
   * Drupal-free class to core; the example migration reads `locale: und`.
   */
  private const LOCALE = 'und';

  /**
   * Maximum length of a Drupal user name.
   *
   * Mirrors UserInterface::USERNAME_MAX_LENGTH without coupling this
   * Drupal-free class to core. The column is varchar(60) and strict SQL mode
   * hard-fails an over-length insert, so names are clipped here, before they
   * can reach the database.
   */
  private const NAME_MAX = 60;

  /**
   * Constructs a UserFetchPlan.
   *
   * @param string $spaceId
   *   The Contentful space whose members to fetch.
   * @param int $limit
   *   Page size for the paginated collection (CMA default-compatible 100).
   *
   * @throws \InvalidArgumentException
   *   When the space id is blank or the page size is not positive.
   */
  public function __construct(
    private readonly string $spaceId,
    private readonly int $limit = 100,
  ) {
    if (trim($spaceId) === '') {
      throw new \InvalidArgumentException('A Contentful space id is required to fetch users.');
    }
    if ($limit < 1) {
      throw new \InvalidArgumentException('The users page size must be at least 1.');
    }
  }

  /**
   * Builds the collection request URL for one page.
   *
   * @param int $skip
   *   The pagination offset (0 for the first request).
   *
   * @return string
   *   The GET /spaces/{space_id}/users URL for that page.
   */
  public function requestUrl(int $skip): string {
    return sprintf('%s/spaces/%s/users?skip=%d&limit=%d', self::API_BASE, $this->spaceId, $skip, $this->limit);
  }

  /**
   * Extracts the items of a decoded collection response, loudly.
   *
   * @param array<string, mixed> $response
   *   The decoded CMA collection response.
   *
   * @return list<mixed>
   *   The page's items.
   *
   * @throws \RuntimeException
   *   When the envelope carries no items array — a malformed or error
   *   response must not read as "this space has no users".
   */
  public function itemsOf(array $response): array {
    $items = $response['items'] ?? NULL;
    if (!is_array($items)) {
      throw new \RuntimeException('Unexpected CMA users response: no "items" array in the collection envelope.');
    }
    return array_values($items);
  }

  /**
   * Computes the next pagination offset, or NULL when the fetch is complete.
   *
   * Advances by the number of items actually returned (not the requested
   * limit), so a server-clamped page size cannot skip users; an empty page
   * terminates even when the reported total claims more, so a disagreeing
   * total cannot loop forever.
   *
   * @param array<string, mixed> $response
   *   The decoded CMA collection response for the page just fetched.
   *
   * @return int|null
   *   The skip value for the next request, or NULL when done.
   */
  public function nextSkip(array $response): ?int {
    $items = $this->itemsOf($response);
    if ($items === []) {
      return NULL;
    }
    $next = (int) ($response['skip'] ?? 0) + count($items);
    return $next < (int) ($response['total'] ?? 0) ? $next : NULL;
  }

  /**
   * Maps one raw CMA user item to an entry-shaped users.json row.
   *
   * @param array<string, mixed> $item
   *   A raw CMA user item ({sys:{id}, firstName, lastName, email?, …}).
   *
   * @return array{sys: array{id: string}, fields: array<string, array<string, string>>}|null
   *   The entry-shaped row, or NULL for an item without a usable sys.id.
   */
  public function mapUser(array $item): ?array {
    $id = $item['sys']['id'] ?? NULL;
    if (!is_string($id) || trim($id) === '') {
      return NULL;
    }

    // Display name: "First Last", degrading through either half to the sys
    // id — a blank name would be rejected (or collide) at user save. Clipped
    // to Drupal's limit; the trailing rtrim covers a clip landing on a space.
    $name = trim(trim((string) ($item['firstName'] ?? '')) . ' ' . trim((string) ($item['lastName'] ?? '')));
    if ($name === '') {
      $name = $id;
    }
    $name = rtrim(mb_substr($name, 0, self::NAME_MAX));

    $row = [
      'sys' => ['id' => $id],
      'fields' => ['name' => [self::LOCALE => $name]],
    ];
    $email = trim((string) ($item['email'] ?? ''));
    if ($email !== '') {
      $row['fields']['mail'] = [self::LOCALE => $email];
    }
    return $row;
  }

  /**
   * Maps a batch of raw CMA user items, dropping malformed ones.
   *
   * Duplicate display names within the batch are disambiguated with the
   * member's sys id ("Jordan Smith (user4)"), case-insensitively: Drupal's
   * user name is a database-level unique key, and CMA display names carry no
   * uniqueness guarantee. The sys id — not a counter against site state —
   * keeps a regenerated users.json byte-stable, so a track_changes re-import
   * never renames an unchanged member. A staged name colliding with a
   * pre-existing site user remains a loud per-row failure at import (the
   * same posture as core's own user migrations); see the example
   * migration's notes for remedies.
   *
   * @param list<mixed> $items
   *   Raw CMA user items, e.g. the concatenated pages of the collection.
   *
   * @return list<array<string, mixed>>
   *   The entry-shaped rows, re-indexed.
   */
  public function mapUsers(array $items): array {
    $rows = [];
    $seen = [];
    foreach ($items as $item) {
      $row = is_array($item) ? $this->mapUser($item) : NULL;
      if ($row === NULL) {
        continue;
      }
      $name = $row['fields']['name'][self::LOCALE];
      if (isset($seen[mb_strtolower($name)])) {
        $name = $this->disambiguate($name, $row['sys']['id']);
        $row['fields']['name'][self::LOCALE] = $name;
      }
      $seen[mb_strtolower($name)] = TRUE;
      $rows[] = $row;
    }
    return $rows;
  }

  /**
   * Appends the sys-id suffix, re-clipping the base so the result fits.
   *
   * Clipping in mapUser() can itself create duplicates (two long names
   * sharing a 60-character prefix), so the base is shortened to make room
   * rather than letting the suffix push past the limit. Should the suffix
   * alone ever exceed the limit (no realistic sys id does), the bare sys id
   * is the deterministic last resort.
   */
  private function disambiguate(string $name, string $id): string {
    $suffix = sprintf(' (%s)', $id);
    $base = rtrim(mb_substr($name, 0, max(0, self::NAME_MAX - mb_strlen($suffix))));
    return $base !== ''
      ? $base . $suffix
      : rtrim(mb_substr($id, 0, self::NAME_MAX));
  }

  /**
   * Builds the full users.json payload from raw CMA user items.
   *
   * @param list<mixed> $items
   *   Raw CMA user items.
   *
   * @return array{items: list<array<string, mixed>>}
   *   The payload to write: consumable by the contentful_export source
   *   plugin with `selector: items` and `locale: und`.
   */
  public function buildFile(array $items): array {
    return ['items' => $this->mapUsers($items)];
  }

}
