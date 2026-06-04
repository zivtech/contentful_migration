<?php

declare(strict_types=1);

namespace Drupal\contentful_migration\Drush\Commands;

use Drupal\contentful_migration\Export\ExportConfig;
use Drupal\contentful_migration\Export\ExportSummary;
use Drupal\contentful_migration\Export\UserFetchPlan;
use Drupal\Core\File\FileSystemInterface;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

/**
 * Drush command: stage a Contentful space export for migration.
 *
 * Wraps the `contentful-export` CLI (with `--download-assets`) and stages its
 * JSON dump + asset binaries at a Drupal stream-wrapper location the migration
 * source plugin and ContentfulAssetToMedia read. The value over running
 * `contentful-export` by hand is the Drupal glue: resolving a `private://`
 * destination to a real path the Node tool can write to, plus a post-export
 * summary of the staged content.
 *
 * Secret handling: `contentful-export` exposes no environment variable for the
 * management token, so the token is written only to a 0600 temp `--config` file
 * (deleted immediately after the run) and is never placed in the process argv,
 * where `ps` would expose it. The optional `--include-users` fetch sends the
 * same token only in the Authorization header of its CMA requests. Prefer the
 * CONTENTFUL_MANAGEMENT_TOKEN env variable over `--management-token`, whose
 * value is visible in your own shell history and process list.
 *
 * Import and rollback are intentionally NOT wrapped: this module ships no fixed
 * migration set (migrations are generated per space), so once a space's
 * migrations exist, `drush migrate:import --execute-dependencies` /
 * `drush migrate:rollback` are already the right tools.
 */
class ContentfulMigrateDrushCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    #[Autowire(service: 'file_system')]
    private readonly FileSystemInterface $fileSystem,
    #[Autowire(service: 'http_client')]
    private readonly ClientInterface $httpClient,
  ) {
    parent::__construct();
  }

  /**
   * Exports a Contentful space (with assets) to a migration-ready location.
   */
  #[CLI\Command(name: 'contentful:export', aliases: ['cf-export'])]
  #[CLI\Option(name: 'space-id', description: 'Contentful space ID to export. Required.')]
  #[CLI\Option(name: 'environment-id', description: 'Contentful environment ID.')]
  #[CLI\Option(name: 'management-token', description: 'Contentful Management API token. PREFER the CONTENTFUL_MANAGEMENT_TOKEN environment variable: a value passed here is visible in your shell history and process list.')]
  #[CLI\Option(name: 'export-dir', description: 'Where to stage the JSON + assets: a Drupal stream wrapper (default private://contentful) or a real path. The migration source plugin reads <export-dir>/export.json.')]
  #[CLI\Option(name: 'bin', description: 'Path to the contentful-export executable.')]
  #[CLI\Option(name: 'include-users', description: 'Also fetch the space members (a separate Content Management API call — contentful-export itself never includes users) and stage them as entry-shaped users.json for blocked-stub author attribution. Off by default: no network beyond the export unless you opt in. Member emails are admin-token-only and land in users.json, which therefore stays in the (private by default) export dir.')]
  #[CLI\Usage(name: 'CONTENTFUL_MANAGEMENT_TOKEN=cfpat-… drush contentful:export --space-id=abc123', description: 'Export space abc123 (token from the environment) into private://contentful.')]
  #[CLI\Usage(name: 'CONTENTFUL_MANAGEMENT_TOKEN=cfpat-… drush contentful:export --space-id=abc123 --include-users', description: 'Same, plus users.json for the contentful_user example migration (author -> uid).')]
  public function export(
    array $options = [
      'space-id' => NULL,
      'environment-id' => 'master',
      'management-token' => NULL,
      'export-dir' => 'private://contentful',
      'bin' => 'contentful-export',
      'include-users' => FALSE,
    ],
  ): void {
    $token = (string) ($options['management-token'] ?? '');
    if ($token === '') {
      $token = (string) (getenv('CONTENTFUL_MANAGEMENT_TOKEN') ?: '');
    }

    $exportDirOption = (string) $options['export-dir'];

    // Pure: assemble + validate the run config (throws on a missing
    // space/token) BEFORE the filesystem, so a missing --space-id fails fast.
    $config = ExportConfig::build(
      spaceId: (string) ($options['space-id'] ?? ''),
      environmentId: (string) ($options['environment-id'] ?? 'master'),
      managementToken: $token,
      exportDir: $exportDirOption,
    );
    // Resolve the staging dir to the real path the Node tool must write to.
    $config['exportDir'] = $this->resolveExportDir($exportDirOption);

    $jsonPath = $this->runExport((string) $options['bin'], $config);
    // Users are fetched before the summary so a fetch failure is loud and
    // the summary never reports a half-staged state.
    $memberCount = empty($options['include-users'])
      ? NULL
      : $this->fetchUsers($config['spaceId'], $token, $config['exportDir']);
    $this->reportSummary($jsonPath, $exportDirOption);
    if ($memberCount !== NULL) {
      $this->io()->writeln(sprintf('  members:       %d (users.json — pair with migrations/examples/contentful_user.yml)', $memberCount));
    }
  }

  /**
   * Resolves a stream wrapper (or plain path) to a writable real directory.
   */
  private function resolveExportDir(string $exportDir): string {
    $dir = $exportDir;
    if (str_contains($dir, '://')) {
      if (!$this->fileSystem->prepareDirectory($dir, FileSystemInterface::CREATE_DIRECTORY)) {
        throw new \RuntimeException(sprintf('Export directory "%s" could not be created or is not writable.', $exportDir));
      }
      $real = $this->fileSystem->realpath($dir);
      if ($real === FALSE) {
        throw new \RuntimeException(sprintf('Could not resolve "%s" to a real filesystem path for contentful-export.', $exportDir));
      }
      return $real;
    }
    return rtrim($dir, '/');
  }

  /**
   * Runs contentful-export, returning the path to the written JSON.
   *
   * The token goes only in a 0600 temp --config file (never argv); the tool's
   * output is streamed live.
   */
  private function runExport(string $bin, array $config): string {
    $configFile = $this->fileSystem->tempnam('temporary://', 'cf_export_');
    $configReal = $this->fileSystem->realpath($configFile);
    if ($configReal === FALSE) {
      throw new \RuntimeException('Could not create a temporary config file for the export.');
    }
    // Restrict permissions before the secret is written.
    @chmod($configReal, 0600);
    file_put_contents($configReal, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    try {
      $this->io()->writeln(sprintf('<info>Running %s (downloading assets)…</info>', $bin));
      // Arguments array (never a shell string) — and only --config on argv, so
      // the token is not exposed in the process list.
      $process = new Process([$bin, '--config', $configReal]);
      $process->setTimeout(NULL);
      $process->run(function (string $type, string $buffer): void {
        $this->io()->write($buffer);
      });

      if (!$process->isSuccessful()) {
        throw new \RuntimeException(sprintf(
          'contentful-export failed (exit %d). Check that "%s" is installed and on PATH (e.g. npm i -g contentful-cli, or set --bin), then see the output above.',
          (int) $process->getExitCode(),
          $bin,
        ));
      }
    }
    finally {
      // Remove the token-bearing file regardless of success/failure.
      @unlink($configReal);
    }

    return rtrim($config['exportDir'], '/') . '/' . $config['contentFile'];
  }

  /**
   * Fetches the space members and stages them as entry-shaped users.json.
   *
   * A separate paginated CMA call (contentful-export never includes users);
   * the request plan, pagination math, and the CMA-item -> entry-shaped-row
   * mapping live in the pure UserFetchPlan. The token travels only in the
   * Authorization header — never argv, never the staged file.
   *
   * @return int
   *   The number of members staged.
   *
   * @throws \RuntimeException
   *   When a CMA request fails or returns an unusable payload. The export
   *   itself has already staged by then; the message says so.
   */
  private function fetchUsers(string $spaceId, string $token, string $exportDir): int {
    $plan = new UserFetchPlan($spaceId);
    $items = [];
    $skip = 0;
    $this->io()->writeln('<info>Fetching space members from the Content Management API…</info>');
    try {
      while ($skip !== NULL) {
        $response = $this->httpClient->request('GET', $plan->requestUrl($skip), [
          'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);
        $page = json_decode((string) $response->getBody(), TRUE);
        if (!is_array($page)) {
          throw new \RuntimeException('The CMA users response was not valid JSON.');
        }
        $items = array_merge($items, $plan->itemsOf($page));
        $skip = $plan->nextSkip($page);
      }
    }
    catch (GuzzleException $e) {
      throw new \RuntimeException(sprintf(
        'Fetching space members failed: %s — the export itself already staged successfully. Check the token can read the space\'s users (then re-run), or run without --include-users.',
        $e->getMessage(),
      ), 0, $e);
    }

    $file = $plan->buildFile($items);
    $usersPath = rtrim($exportDir, '/') . '/users.json';
    file_put_contents($usersPath, json_encode($file, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    return count($file['items']);
  }

  /**
   * Prints a structural summary of the staged export.
   */
  private function reportSummary(string $jsonPath, string $exportDirOption): void {
    if (!is_file($jsonPath)) {
      $this->logger()?->warning('Export finished but no JSON was found at {path}.', ['path' => $jsonPath]);
      return;
    }
    $data = json_decode((string) file_get_contents($jsonPath), TRUE);
    if (!is_array($data)) {
      $this->logger()?->warning('Export JSON at {path} is not readable.', ['path' => $jsonPath]);
      return;
    }

    $summary = ExportSummary::fromExport($data);
    $this->io()->success(sprintf('Staged Contentful export to %s', $jsonPath));
    $this->io()->writeln(sprintf('  content types: %d (%s)', $summary['content_types'], implode(', ', $summary['content_type_ids']) ?: '—'));
    $this->io()->writeln(sprintf('  entries:       %d', $summary['entries']));
    $this->io()->writeln(sprintf('  assets:        %d (%s)', $summary['assets'], $this->formatFamilies($summary['asset_mime_families'])));
    $this->io()->writeln(sprintf('  locales:       %s', implode(', ', $summary['locales']) ?: '—'));
    $this->io()->note(sprintf('Point your migration source plugin at %s/export.json. Downloaded assets are mirrored under %s/<url-host>/… (e.g. images.ctfassets.net/<space>/<id>/<hash>/<file>); set contentful_asset_to_media local_source_dir to the export dir itself.', rtrim($exportDirOption, '/'), rtrim($exportDirOption, '/')));
  }

  /**
   * Formats the asset MIME-family counts as a compact string.
   *
   * @param array<string, int> $families
   *   MIME family (e.g. "image") => count.
   */
  private function formatFamilies(array $families): string {
    $parts = [];
    foreach ($families as $family => $count) {
      $parts[] = sprintf('%s×%d', $family, $count);
    }
    return $parts !== [] ? implode(', ', $parts) : '—';
  }

}
