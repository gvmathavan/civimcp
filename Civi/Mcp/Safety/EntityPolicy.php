<?php

namespace Civi\Mcp\Safety;

use Mcp\Exception\ToolCallException;

/**
 * Which APIv4 entities CiviMCP may touch.
 *
 * Some entities hold credentials or system internals even for admins
 * (payment processor passwords, OAuth tokens, SMTP settings). They are never
 * readable through the generic tools; dedicated tools with their own
 * redaction can expose safe parts later.
 */
final class EntityPolicy {

  private const DENIED = [
    'Setting',
    'PaymentProcessor',
    'PaymentToken',
    'OAuthClient',
    'OAuthSysToken',
    'OAuthContactToken',
    'OAuthSessionToken',
    'MailSettings',
    'System',
    'Log',
    'Session',
  ];

  /**
   * @var array<string, array<string, mixed>>|null
   */
  private static ?array $entities = NULL;

  /**
   * All APIv4 entities the current user may see, minus denied ones, keyed by name.
   *
   * @return array<string, array<string, mixed>>
   */
  public static function entities(): array {
    if (self::$entities === NULL) {
      self::$entities = [];
      $records = \Civi\Api4\Entity::get(TRUE)
        ->addSelect('name', 'title', 'title_plural', 'description', 'type', 'primary_key')
        ->execute();
      foreach ($records as $entity) {
        if (!in_array($entity['name'], self::DENIED, TRUE)) {
          self::$entities[$entity['name']] = $entity;
        }
      }
      ksort(self::$entities);
    }
    return self::$entities;
  }

  /**
   * Forget the cached entity list (used by tests).
   *
   * @internal
   */
  public static function resetCache(): void {
    self::$entities = NULL;
  }

  /**
   * Throw a clear error unless $entity is a known, allowed entity.
   */
  public static function assertReadable(string $entity): void {
    if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $entity)) {
      throw new ToolCallException(sprintf('"%s" is not a valid entity name.', $entity));
    }
    if (in_array($entity, self::DENIED, TRUE)) {
      throw new ToolCallException(sprintf('The %s entity is not available through CiviMCP because it can hold credentials or system internals.', $entity));
    }
    if (!isset(self::entities()[$entity])) {
      throw new ToolCallException(sprintf('Unknown entity "%s". Call list_entities to see the entities available on this site.', $entity));
    }
  }

}
