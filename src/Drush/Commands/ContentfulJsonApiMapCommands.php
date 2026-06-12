<?php

declare(strict_types=1);

namespace Drupal\contentful_migration\Drush\Commands;

use Drupal\contentful_migration\JsonApiMap\MigrationMapExtractor;
use Drupal\contentful_migration\JsonApiMap\ResourceNameResolver;
use Drupal\migrate\Plugin\MigrationPluginManagerInterface;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drush command: emit a machine-readable CDA→JSON:API mapping manifest.
 *
 * Reads every `contentful_export`-sourced migration definition (or a filtered
 * group) and produces a JSON manifest of Contentful type → JSON:API resource
 * + Contentful field id → JSON:API field name. The value over doing this by
 * hand is automation: hand the manifest — together with the conversion guide
 * at modes/examples/decoupled/CONVERSION.md — to a codemod or an LLM
 * rewriting front-end getEntries() calls. Fields the extractor cannot classify
 * are marked `"kind": "manual"` rather than guessed.
 *
 * JSON:API name resolution is authoritative (including jsonapi_extras aliases)
 * when the jsonapi module is installed, and convention-derived otherwise; the
 * manifest records which path was taken in `resolved_from`.
 */
class ContentfulJsonApiMapCommands extends DrushCommands {

  use AutowireTrait;

  /**
   * Source plugin name that identifies Contentful migrations.
   */
  private const CONTENTFUL_SOURCE_PLUGIN = 'contentful_export';

  public function __construct(
    #[Autowire(service: 'plugin.manager.migration')]
    private readonly MigrationPluginManagerInterface $migrationPluginManager,
    #[Autowire(service: 'service_container')]
    private readonly ContainerInterface $container,
  ) {
    parent::__construct();
  }

  /**
   * Emits a JSON manifest of Contentful type → JSON:API resource/field maps.
   */
  #[CLI\Command(name: 'contentful:jsonapi-map', aliases: ['cf-jsonapi-map'])]
  #[CLI\Option(name: 'output', description: 'Path to write the JSON manifest file. Prints to stdout when omitted.')]
  #[CLI\Option(name: 'migration-group', description: 'Filter to migrations in this group. Default: all contentful_export migrations.')]
  #[CLI\Usage(name: 'drush contentful:jsonapi-map', description: 'Print the manifest for all contentful_export migrations.')]
  #[CLI\Usage(name: 'drush contentful:jsonapi-map --output=/tmp/manifest.json', description: 'Write the manifest to a file.')]
  #[CLI\Usage(name: 'drush contentful:jsonapi-map --migration-group=contentful', description: 'Limit to the contentful migration group.')]
  public function jsonapiMap(
    array $options = [
      'output' => NULL,
      'migration-group' => NULL,
    ],
  ): void {
    $definitions = $this->migrationPluginManager->getDefinitions();
    $group = $options['migration-group'] ?? NULL;

    $extractor = new MigrationMapExtractor();
    $resolver = new ResourceNameResolver($this->container);

    $migrations = [];
    $manifestWarnings = [];

    foreach ($definitions as $migrationId => $definition) {
      $sourcePlugin = $definition['source']['plugin'] ?? '';
      if ($sourcePlugin !== self::CONTENTFUL_SOURCE_PLUGIN) {
        continue;
      }
      if ($group !== NULL && ($definition['migration_group'] ?? '') !== $group) {
        continue;
      }

      $entry = $extractor->extract((string) $migrationId, $definition);

      // Resolve JSON:API names.
      $entityType = $entry['drupal']['entity_type'] ?? '';
      $bundle = $entry['drupal']['bundle'];
      $drupalFields = array_keys($entry['fields']);
      if ($entityType !== '' && $bundle !== NULL) {
        $resolved = $resolver->resolve($entityType, $bundle, $drupalFields);
        $entry['jsonapi'] = [
          'resource_type' => $resolved['resource_type'],
          'endpoint' => $resolved['endpoint'],
          'resolved_from' => $resolved['resolved_from'],
        ];
        if ($resolved['warning'] !== NULL && !in_array($resolved['warning'], $manifestWarnings, TRUE)) {
          $manifestWarnings[] = $resolved['warning'];
        }
        // Apply public field names.
        foreach ($resolved['field_names'] as $drupalField => $jsonapiName) {
          if (isset($entry['fields'][$drupalField])) {
            $entry['fields'][$drupalField]['jsonapi_name'] = $jsonapiName;
          }
        }
      }
      else {
        $entry['jsonapi'] = NULL;
      }

      $migrations[] = $entry;
    }

    $manifest = [
      'manifest_version' => 1,
      'module' => 'contentful_migration',
      'migrations' => $migrations,
      'warnings' => $manifestWarnings,
    ];

    $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === FALSE) {
      throw new \RuntimeException('Failed to JSON-encode the manifest.');
    }

    $outputPath = $options['output'] ?? NULL;
    if ($outputPath !== NULL) {
      file_put_contents((string) $outputPath, $json);
      $this->io()->success(sprintf('Manifest written to %s (%d migration(s)).', $outputPath, count($migrations)));
    }
    else {
      $this->io()->writeln($json);
    }
  }

}
