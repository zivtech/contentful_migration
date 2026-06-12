<?php

declare(strict_types=1);

namespace Drupal\contentful_migration\JsonApiMap;

/**
 * Extracts a CDA→Drupal field mapping from a single migration definition array.
 *
 * Pure: no Drupal classes, no container. Accepts the raw definition array that
 * plugin.manager.migration provides (keys: source, process, destination) and
 * returns a structured result array suitable for JSON serialization.
 *
 * The returned array shape is manifest schema v1 (the `manifest_version` key
 * in the command's output envelope). Every process key that cannot be
 * classified by the documented rules is emitted as `kind: "manual"` with the
 * raw value preserved — the contract is honesty, not completeness.
 *
 * Composite destination keys (`field_media_image/target_id`) are grouped under
 * their parent field key with a `subkeys` map, so a manifest consumer sees one
 * `field_media_image` entry rather than two disconnected rows.
 */
final class MigrationMapExtractor {

  /**
   * Destination keys kept as sys_metadata in `fields`.
   *
   * `created` and `changed` are kept in `fields` as `sys_metadata`; the
   * others go to `unmapped` with reason `non_field_destination`.
   */
  private const SYS_TIMESTAMP_KEYS = ['created', 'changed'];

  /**
   * Destination keys unconditionally classified as non_field_destination.
   */
  private const NON_FIELD_KEYS = [
    'type',
    'uid',
    'langcode',
    'nid',
    'status',
    'name',
    'mail',
  ];

  /**
   * Plugin names that determine field kind, checked in priority order.
   */
  private const PLUGIN_KIND_MAP = [
    'contentful_rich_text' => 'richtext_html',
    'contentful_asset_to_media' => 'media_reference',
    'contentful_internal_link' => 'link',
  ];

  /**
   * Extracts the manifest entry for one migration definition.
   *
   * @param string $migration_id
   *   The migration plugin id.
   * @param array<string, mixed> $definition
   *   The raw migration definition array (source, process, destination keys).
   *
   * @return array<string, mixed>
   *   A manifest migration entry: migration_id, contentful_type,
   *   source_locale, drupal, identity_field, fields, unmapped, warnings.
   */
  public function extract(string $migration_id, array $definition): array {
    $warnings = [];

    $contentful_type = $definition['source']['content_type'] ?? NULL;
    if ($contentful_type === NULL) {
      $warnings[] = sprintf(
        'Migration %s has no source.content_type (assets migration?); contentful_type set to null.',
        $migration_id,
      );
    }

    $entity_type = $this->resolveEntityType($definition);
    $bundle = $this->resolveBundle($definition);
    if ($bundle === NULL) {
      $warnings[] = sprintf(
        'Migration %s: bundle could not be resolved from destination.default_bundle or process.type.default_value.',
        $migration_id,
      );
    }

    $process = $definition['process'] ?? [];
    $classified = $this->classifyProcess($process);

    return [
      'migration_id' => $migration_id,
      'contentful_type' => $contentful_type,
      'source_locale' => $definition['source']['locale'] ?? NULL,
      'drupal' => [
        'entity_type' => $entity_type,
        'bundle' => $bundle,
      ],
      'identity_field' => $classified['identity_field'],
      'fields' => $classified['fields'],
      'unmapped' => $classified['unmapped'],
      'warnings' => array_merge($warnings, $classified['warnings']),
    ];
  }

  /**
   * Resolves the entity type id from the destination plugin name.
   *
   * @param array<string, mixed> $definition
   *   The raw migration definition array.
   *
   * @return string|null
   *   The entity type id, or NULL when the destination plugin is absent.
   */
  private function resolveEntityType(array $definition): ?string {
    $plugin = $definition['destination']['plugin'] ?? NULL;
    if ($plugin === NULL) {
      return NULL;
    }
    foreach (['entity:', 'entity_reference_revisions:'] as $prefix) {
      if (str_starts_with((string) $plugin, $prefix)) {
        return substr((string) $plugin, strlen($prefix));
      }
    }
    return (string) $plugin;
  }

  /**
   * Resolves the bundle from destination.default_bundle or process.type.
   *
   * @param array<string, mixed> $definition
   *   The raw migration definition array.
   *
   * @return string|null
   *   The bundle id, or NULL when it cannot be resolved.
   */
  private function resolveBundle(array $definition): ?string {
    if (isset($definition['destination']['default_bundle'])) {
      return (string) $definition['destination']['default_bundle'];
    }
    $type_process = $definition['process']['type'] ?? NULL;
    if ($type_process === NULL) {
      return NULL;
    }
    // Single plugin config: {plugin: default_value, default_value: X}.
    if (is_array($type_process) && isset($type_process['plugin'], $type_process['default_value'])) {
      return (string) $type_process['default_value'];
    }
    // Pipeline list: [{plugin: default_value, default_value: X}, …].
    if (is_array($type_process) && isset($type_process[0])) {
      foreach ($type_process as $step) {
        if (is_array($step) && ($step['plugin'] ?? '') === 'default_value' && isset($step['default_value'])) {
          return (string) $step['default_value'];
        }
      }
    }
    return NULL;
  }

  /**
   * Classifies all process entries into fields, unmapped, identity_field.
   *
   * @param array<string, mixed> $process
   *   The raw process map from the migration definition.
   *
   * @return array<string, mixed>
   *   Array with keys: identity_field (string|null), fields (array), unmapped
   *   (array), and warnings (list of strings).
   */
  private function classifyProcess(array $process): array {
    $identity_field = NULL;
    $fields = [];
    $unmapped = [];
    $warnings = [];
    // Accumulate composite sub-key entries before merging.
    $subkey_accumulator = [];

    foreach ($process as $dest_key => $process_value) {
      $dest_key = (string) $dest_key;

      // Key-name-first: composite field sub-key (field_foo/subkey).
      if (preg_match('/^(field_[^\/]+)\/([^\/]+)$/', $dest_key, $m)) {
        $parent_key = $m[1];
        $sub_key = $m[2];
        $classified = $this->classifyValue($process_value);
        $subkey_accumulator[$parent_key][$sub_key] = $classified;
        continue;
      }

      // Composite non-field key containing / (e.g. path/alias, body/value).
      if (str_contains($dest_key, '/')) {
        $unmapped[$dest_key] = ['reason' => 'non_field_destination'];
        continue;
      }

      // Bundle key — bundle resolver, not a content field.
      if ($dest_key === 'bundle') {
        $fields[$dest_key] = [
          'drupal_field' => $dest_key,
          'jsonapi_name' => $dest_key,
          'kind' => 'sys_metadata',
          'note' => 'bundle resolver',
        ];
        continue;
      }

      if (in_array($dest_key, self::SYS_TIMESTAMP_KEYS, TRUE)) {
        $source_key = $this->extractSourceKey($process_value);
        $fields[$dest_key] = [
          'drupal_field' => $dest_key,
          'jsonapi_name' => $dest_key,
          'kind' => 'sys_metadata',
          'contentful_field' => $source_key,
        ];
        continue;
      }

      if (in_array($dest_key, self::NON_FIELD_KEYS, TRUE)) {
        $unmapped[$dest_key] = ['reason' => 'non_field_destination'];
        continue;
      }

      // field_-prefixed keys: check for identity field first.
      if (str_starts_with($dest_key, 'field_')) {
        $source_key = $this->extractSourceKey($process_value);
        if ($source_key === 'sys_id') {
          $identity_field = $dest_key;
          continue;
        }
        $classified = $this->classifyValue($process_value);
        $contentful_id = $this->stripSysPath($source_key);
        $fields[$dest_key] = [
          'drupal_field' => $dest_key,
          'jsonapi_name' => $dest_key,
          'contentful_field' => $contentful_id,
        ] + $classified;
        continue;
      }

      // Non-prefixed, non-system keys (e.g. title, plain non-field fields).
      $source_key = $this->extractSourceKey($process_value);
      if (
        str_starts_with($source_key ?? '', 'sys_created')
        || str_starts_with($source_key ?? '', 'sys_updated')
      ) {
        $fields[$dest_key] = [
          'drupal_field' => $dest_key,
          'jsonapi_name' => $dest_key,
          'kind' => 'sys_metadata',
          'contentful_field' => $source_key,
        ];
        continue;
      }

      $classified = $this->classifyValue($process_value);
      $contentful_id = $this->stripSysPath($source_key);
      $fields[$dest_key] = [
        'drupal_field' => $dest_key,
        'jsonapi_name' => $dest_key,
        'contentful_field' => $contentful_id,
      ] + $classified;
    }

    // Merge composite sub-key groups into the fields map.
    foreach ($subkey_accumulator as $parent_key => $subkeys) {
      // Dominant kind: first non-scalar sub-key wins.
      $dominant_kind = 'scalar';
      $dominant_via = [];
      foreach ($subkeys as $classified) {
        if (($classified['kind'] ?? 'scalar') !== 'scalar') {
          $dominant_kind = $classified['kind'];
          $dominant_via = $classified['via'] ?? [];
          break;
        }
      }
      $fields[$parent_key] = [
        'drupal_field' => $parent_key,
        'jsonapi_name' => $parent_key,
        'kind' => $dominant_kind,
        'via' => $dominant_via,
        'subkeys' => $subkeys,
      ];
    }

    return [
      'identity_field' => $identity_field,
      'fields' => $fields,
      'unmapped' => $unmapped,
      'warnings' => $warnings,
    ];
  }

  /**
   * Classifies a single process value into {kind, via?, raw?}.
   *
   * @param mixed $value
   *   The process value for one destination key.
   *
   * @return array<string, mixed>
   *   Array with at minimum a 'kind' key; may include 'via' or 'raw'.
   */
  private function classifyValue(mixed $value): array {
    // Plain string scalar.
    if (is_string($value)) {
      return ['kind' => 'scalar'];
    }

    if (!is_array($value)) {
      return ['kind' => 'manual', 'raw' => $value];
    }

    // Pipeline: numerically-indexed list of plugin configs.
    if (isset($value[0]) && is_array($value[0])) {
      return $this->classifyPipeline($value);
    }

    // Single plugin config: {plugin: …, …}.
    if (isset($value['plugin'])) {
      return $this->classifySinglePlugin($value);
    }

    // Unknown structure.
    return ['kind' => 'manual', 'raw' => $value];
  }

  /**
   * Classifies a pipeline (numerically-indexed list of plugin configs).
   *
   * @param list<array<string, mixed>> $pipeline
   *   A list of plugin config arrays.
   *
   * @return array<string, mixed>
   *   Classification result.
   */
  private function classifyPipeline(array $pipeline): array {
    $plugins = array_filter(
      array_map(
        static fn ($step): ?string => is_array($step) ? ($step['plugin'] ?? NULL) : NULL,
        $pipeline,
      ),
    );
    $plugins = array_values($plugins);
    return $this->kindFromPlugins($plugins);
  }

  /**
   * Classifies a single plugin config array.
   *
   * @param array<string, mixed> $config
   *   A single plugin configuration array with at minimum a 'plugin' key.
   *
   * @return array<string, mixed>
   *   Classification result.
   */
  private function classifySinglePlugin(array $config): array {
    $plugin = (string) ($config['plugin'] ?? '');

    if ($plugin === 'sub_process') {
      // Look for migration_lookup inside the nested process map.
      $nested = $config['process'] ?? [];
      if (is_array($nested)) {
        foreach ($nested as $nested_value) {
          if ($this->containsPlugin($nested_value, 'migration_lookup')) {
            return [
              'kind' => 'reference_multiple',
              'via' => ['sub_process', 'migration_lookup'],
            ];
          }
        }
      }
      return ['kind' => 'reference_multiple', 'via' => ['sub_process']];
    }

    return $this->kindFromPlugins([$plugin]);
  }

  /**
   * Determines kind from a flat list of plugin names.
   *
   * @param list<string> $plugins
   *   Plugin names present in the pipeline.
   *
   * @return array<string, mixed>
   *   Classification result.
   */
  private function kindFromPlugins(array $plugins): array {
    // Check known plugin→kind mappings in priority order.
    foreach (self::PLUGIN_KIND_MAP as $plugin_name => $kind) {
      if (in_array($plugin_name, $plugins, TRUE)) {
        return ['kind' => $kind, 'via' => $plugins];
      }
    }

    if (in_array('sub_process', $plugins, TRUE) && in_array('migration_lookup', $plugins, TRUE)) {
      return ['kind' => 'reference_multiple', 'via' => $plugins];
    }

    if (in_array('migration_lookup', $plugins, TRUE)) {
      return ['kind' => 'reference', 'via' => $plugins];
    }

    if ($plugins !== []) {
      return ['kind' => 'pipeline', 'via' => $plugins];
    }

    return ['kind' => 'manual', 'raw' => NULL];
  }

  /**
   * Checks whether a process value (any shape) contains a given plugin name.
   *
   * @param mixed $value
   *   A process value, potentially nested.
   * @param string $plugin_name
   *   The plugin name to search for.
   *
   * @return bool
   *   TRUE if the plugin name appears anywhere in the value.
   */
  private function containsPlugin(mixed $value, string $plugin_name): bool {
    if (!is_array($value)) {
      return FALSE;
    }
    if (($value['plugin'] ?? '') === $plugin_name) {
      return TRUE;
    }
    foreach ($value as $item) {
      if (is_array($item) && $this->containsPlugin($item, $plugin_name)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Extracts the first `source` key from a process value.
   *
   * Handles: plain string (returns it), single plugin config (returns
   * config['source']), pipeline list (returns first step's source).
   *
   * @param mixed $value
   *   The raw process value.
   *
   * @return string|null
   *   The source key, or NULL when none can be found.
   */
  private function extractSourceKey(mixed $value): ?string {
    if (is_string($value)) {
      return $value;
    }
    if (!is_array($value)) {
      return NULL;
    }
    // Single plugin config.
    if (isset($value['plugin'])) {
      return isset($value['source']) ? (string) $value['source'] : NULL;
    }
    // Pipeline: first step with a source.
    if (isset($value[0]) && is_array($value[0])) {
      foreach ($value as $step) {
        if (is_array($step) && isset($step['source'])) {
          return (string) $step['source'];
        }
      }
    }
    return NULL;
  }

  /**
   * Strips any trailing sys path segment from a source key.
   *
   * E.g. `heroImage/sys/id` → `heroImage`. Returns NULL when input is NULL.
   *
   * @param string|null $source_key
   *   The raw source key from the process config.
   *
   * @return string|null
   *   The base key with any /… suffix removed.
   */
  private function stripSysPath(?string $source_key): ?string {
    if ($source_key === NULL) {
      return NULL;
    }
    $pos = strpos($source_key, '/');
    return $pos !== FALSE ? substr($source_key, 0, $pos) : $source_key;
  }

}
