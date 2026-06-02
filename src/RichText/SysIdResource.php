<?php

declare(strict_types=1);

namespace Drupal\contentful_migration\RichText;

use Contentful\Core\Api\Link;
use Contentful\Core\Resource\AssetInterface;
use Contentful\Core\Resource\EntryInterface;
use Contentful\Core\Resource\ResourceInterface;

/**
 * A lightweight stand-in for a resolved Contentful resource.
 *
 * The contentful/rich-text Parser strictly requires the LinkResolver to return
 * an EntryInterface / AssetInterface (it throws otherwise). Those are empty
 * marker interfaces over ResourceInterface, so this thin class — carrying only
 * the sys.id, which is all the migration needs — satisfies the Parser without
 * hydrating a full Contentful SDK resource or hitting the CDA. Verified in the
 * Phase-1 spike.
 */
final class SysIdResource implements ResourceInterface, EntryInterface, AssetInterface {

  public function __construct(
    private readonly string $id,
    private readonly string $type,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getId(): string {
    return $this->id;
  }

  /**
   * {@inheritdoc}
   */
  public function getType(): string {
    return $this->type;
  }

  /**
   * Returns NULL; this stand-in carries only the sys.id.
   *
   * @return null
   *   This stand-in carries only the sys.id; it has no system properties.
   */
  public function getSystemProperties() {
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function asLink(): Link {
    return new Link($this->id, $this->type);
  }

  /**
   * {@inheritdoc}
   */
  public function jsonSerialize(): mixed {
    return ['sys' => ['id' => $this->id, 'type' => $this->type]];
  }

}
