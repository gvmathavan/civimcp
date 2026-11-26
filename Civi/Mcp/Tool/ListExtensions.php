<?php

namespace Civi\Mcp\Tool;

use Mcp\Exception\ToolCallException;

/**
 * list_extensions: installed extensions, versions and status.
 */
final class ListExtensions extends AbstractTool {

  public const NAME = 'list_extensions';
  public const TITLE = 'List extensions';

  private const STATUSES = ['enabled' => 'installed', 'disabled' => 'disabled', 'uninstalled' => 'uninstalled', 'missing' => 'missing', 'all' => NULL];

  public function description(): string {
    return 'List CiviCRM extensions on this site with their key, name, version, status (enabled, disabled, uninstalled, missing) and development stage. Defaults to enabled extensions. Use this to answer "what is installed?", check versions, or spot extensions whose files are missing. Requires the "administer CiviCRM" permission.';
  }

  public function inputSchema(): array {
    return [
      'type' => 'object',
      'properties' => [
        'status' => [
          'type' => 'string',
          'enum' => array_keys(self::STATUSES),
          'default' => 'enabled',
          'description' => 'Which extensions to list. "missing" means enabled or disabled but the files are gone. Default "enabled".',
        ],
        'search' => [
          'type' => 'string',
          'description' => 'Optional word to filter by key, name or description, e.g. "mailchimp".',
        ],
      ],
      'additionalProperties' => FALSE,
    ];
  }

  /**
   * @return array<string, mixed>
   */
  public function __invoke(string $status = 'enabled', string $search = ''): array {
    $args = ['status' => $status, 'search' => $search];

    return $this->execute($args, function () use ($status, $search): array {
      if (!array_key_exists($status, self::STATUSES)) {
        throw new ToolCallException(sprintf('Unknown status "%s". Use one of: %s.', $status, implode(', ', array_keys(self::STATUSES))));
      }

      $records = \Civi\Api4\Extension::get(TRUE)
        ->addSelect('key', 'label', 'version', 'status', 'status:label', 'develStage', 'description')
        ->execute();

      $needle = mb_strtolower(trim($search));
      $items = [];
      $counts = [];
      foreach ($records as $ext) {
        $state = (string) ($ext['status'] ?? '');
        $counts[$state] = ($counts[$state] ?? 0) + 1;
        if ($status === 'missing' ? !str_ends_with($state, '-missing') : (self::STATUSES[$status] !== NULL && $state !== self::STATUSES[$status])) {
          continue;
        }
        if ($needle !== '' && !str_contains(mb_strtolower($ext['key'] . ' ' . ($ext['label'] ?? '') . ' ' . ($ext['description'] ?? '')), $needle)) {
          continue;
        }
        $items[] = [
          'key' => $ext['key'],
          'name' => $ext['label'] ?? $ext['key'],
          'version' => $ext['version'] ?? NULL,
          'status' => $ext['status:label'] ?? $state,
          'stage' => $ext['develStage'] ?? NULL,
        ];
      }
      usort($items, fn($a, $b) => strcmp($a['key'], $b['key']));

      return [
        'status_filter' => $status,
        'counts' => $counts,
        'total' => count($items),
        'extensions' => $items,
      ];
    });
  }

}
