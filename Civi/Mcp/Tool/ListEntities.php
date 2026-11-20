<?php

namespace Civi\Mcp\Tool;

use Civi\Mcp\Safety\EntityPolicy;

/**
 * list_entities: the APIv4 entities available on this site.
 */
final class ListEntities extends AbstractTool {

  public const NAME = 'list_entities';
  public const TITLE = 'List data entities';

  public function description(): string {
    return 'List the CiviCRM APIv4 entities (data types) available on this site to the current user, e.g. Contact, Membership, Contribution, Event, Participant, Activity, plus any added by extensions or custom field groups. Optionally filter by a search word. Use this before describe_entity or get when you are unsure of an entity name.';
  }

  public function inputSchema(): array {
    return [
      'type' => 'object',
      'properties' => [
        'search' => [
          'type' => 'string',
          'description' => 'Optional word to filter by name, title or description (case-insensitive), e.g. "member" or "event".',
        ],
        'include_custom_groups' => [
          'type' => 'boolean',
          'default' => FALSE,
          'description' => 'Also list multi-record custom field groups (entities named Custom_*). Default false.',
        ],
      ],
      'additionalProperties' => FALSE,
    ];
  }

  /**
   * @return array<string, mixed>
   */
  public function __invoke(string $search = '', bool $include_custom_groups = FALSE): array {
    $args = ['search' => $search, 'include_custom_groups' => $include_custom_groups];

    return $this->execute($args, function () use ($search, $include_custom_groups): array {
      $needle = mb_strtolower(trim($search));
      $items = [];
      foreach (EntityPolicy::entities() as $name => $entity) {
        $isCustomGroup = in_array('CustomValue', (array) ($entity['type'] ?? []), TRUE);
        if ($isCustomGroup && !$include_custom_groups) {
          continue;
        }
        $haystack = mb_strtolower($name . ' ' . ($entity['title'] ?? '') . ' ' . ($entity['description'] ?? ''));
        if ($needle !== '' && !str_contains($haystack, $needle)) {
          continue;
        }
        $items[] = [
          'name' => $name,
          'title' => $entity['title_plural'] ?? $entity['title'] ?? $name,
          'description' => self::shorten((string) ($entity['description'] ?? '')),
        ];
      }

      return [
        'search' => $search,
        'total' => count($items),
        'entities' => $items,
      ];
    });
  }

  private static function shorten(string $text, int $max = 160): string {
    $text = trim(preg_replace('/\s+/', ' ', strip_tags($text)) ?? '');
    return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1) . '…' : $text;
  }

}
