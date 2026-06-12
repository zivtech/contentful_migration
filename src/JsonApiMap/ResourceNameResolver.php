<?php

declare(strict_types=1);

namespace Drupal\contentful_migration\JsonApiMap;

use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Resolves JSON:API resource type names and field public names.
 *
 * Uses `jsonapi.resource_type.repository` when the JSON:API module is
 * installed, falling back to `entity_type--bundle` convention when it is not.
 * The JSON:API module must remain optional (never a hard dependency).
 *
 * Rationale for ContainerInterface: this module already imports
 * Symfony\Component\DependencyInjection\ContainerInterface (see
 * ContentfulRichText::create()) — using the same interface avoids a new
 * dependency and keeps the container contract consistent with the module's
 * existing conventions.
 */
final class ResourceNameResolver {

  /**
   * Warning added to the manifest when JSON:API is not installed.
   */
  public const WARNING_NO_JSONAPI = 'jsonapi not installed; names are conventions, enable jsonapi for authoritative names.';

  /**
   * Constructs a ResourceNameResolver.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The service container.
   */
  public function __construct(
    private readonly ContainerInterface $container,
  ) {}

  /**
   * Resolves JSON:API resource info for an entity type + bundle.
   *
   * @param string $entity_type
   *   The Drupal entity type id (e.g. "node").
   * @param string|null $bundle
   *   The entity bundle (e.g. "blog_post"). When NULL, falls back to entity
   *   type id as bundle.
   * @param list<string> $drupal_fields
   *   Drupal field names to resolve to JSON:API public names.
   *
   * @return array<string, mixed>
   *   Array with keys: resource_type, endpoint, resolved_from
   *   ("jsonapi"|"convention"), field_names (drupal_field => jsonapi_name),
   *   and warning (string|null).
   */
  public function resolve(string $entity_type, ?string $bundle, array $drupal_fields = []): array {
    $bundle = $bundle ?? $entity_type;

    if ($this->container->has('jsonapi.resource_type.repository')) {
      return $this->resolveViaJsonApi($entity_type, $bundle, $drupal_fields);
    }

    return $this->resolveByConvention($entity_type, $bundle, $drupal_fields);
  }

  /**
   * Resolves using the live JSON:API resource type repository.
   *
   * @param string $entity_type
   *   The Drupal entity type id.
   * @param string $bundle
   *   The entity bundle.
   * @param list<string> $drupal_fields
   *   Drupal field names to resolve to public names.
   *
   * @return array<string, mixed>
   *   Resolved resource info array.
   */
  private function resolveViaJsonApi(
    string $entity_type,
    string $bundle,
    array $drupal_fields,
  ): array {
    /** @var object $repo */
    $repo = $this->container->get('jsonapi.resource_type.repository');
    $resource_type = $repo->get($entity_type, $bundle);

    if ($resource_type === NULL) {
      // JSON:API installed but no resource type for this entity/bundle.
      return $this->resolveByConvention($entity_type, $bundle, $drupal_fields);
    }

    $type_name = $resource_type->getTypeName();
    $endpoint = '/jsonapi/' . str_replace('--', '/', $type_name);

    $field_names = [];
    foreach ($drupal_fields as $drupal_field) {
      $field = $resource_type->getFieldByInternalName($drupal_field);
      $field_names[$drupal_field] = $field !== NULL
        ? $field->getPublicName()
        : $drupal_field;
    }

    return [
      'resource_type' => $type_name,
      'endpoint' => $endpoint,
      'resolved_from' => 'jsonapi',
      'field_names' => $field_names,
      'warning' => NULL,
    ];
  }

  /**
   * Resolves using naming convention only (no JSON:API module required).
   *
   * @param string $entity_type
   *   The Drupal entity type id.
   * @param string $bundle
   *   The entity bundle.
   * @param list<string> $drupal_fields
   *   Drupal field names (returned unchanged as the public name).
   *
   * @return array<string, mixed>
   *   Convention-derived resource info array.
   */
  private function resolveByConvention(
    string $entity_type,
    string $bundle,
    array $drupal_fields,
  ): array {
    $type_name = $entity_type . '--' . $bundle;
    $endpoint = '/jsonapi/' . $entity_type . '/' . $bundle;

    $field_names = [];
    foreach ($drupal_fields as $drupal_field) {
      $field_names[$drupal_field] = $drupal_field;
    }

    return [
      'resource_type' => $type_name,
      'endpoint' => $endpoint,
      'resolved_from' => 'convention',
      'field_names' => $field_names,
      'warning' => self::WARNING_NO_JSONAPI,
    ];
  }

}
