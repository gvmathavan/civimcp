<?php

namespace Civi\Mcp\Tool;

use Civi\Mcp\Safety\Redactor;
use Mcp\Exception\ToolCallException;

/**
 * get_setting: look up CiviCRM settings by name or keyword, with secrets redacted.
 */
final class GetSetting extends AbstractTool {

  public const NAME = 'get_setting';
  public const TITLE = 'Look up settings';

  private const MAX_SETTINGS = 30;

  public function description(): string {
    return 'Look up CiviCRM settings (the values behind the Administer screens) either by exact name, e.g. ["environment", "enable_components"], or by a keyword that matches setting names, titles and descriptions, e.g. "renewal" or "smtp". Returns name, title, description and current value. Passwords, API keys and other secrets are always redacted. Requires the "administer CiviCRM" permission.';
  }

  public function inputSchema(): array {
    return [
      'type' => 'object',
      'properties' => [
        'names' => [
          'type' => 'array',
          'items' => ['type' => 'string'],
          'description' => 'Exact setting names, e.g. ["environment", "dateformatDatetime"].',
        ],
        'search' => [
          'type' => 'string',
          'description' => 'Keyword to find settings by name, title or description, e.g. "membership" or "mail".',
        ],
      ],
      'additionalProperties' => FALSE,
    ];
  }

  /**
   * @param string[] $names
   *
   * @return array<string, mixed>
   */
  public function __invoke(array $names = [], string $search = ''): array {
    $args = ['names' => $names, 'search' => $search];

    return $this->execute($args, function () use ($names, $search): array {
      $names = array_values(array_filter(array_map('trim', $names), fn($n) => $n !== ''));
      $needle = mb_strtolower(trim($search));
      if (!$names && $needle === '') {
        throw new ToolCallException('Give either names (exact setting names) or search (a keyword).');
      }

      $meta = [];
      foreach (\Civi\Api4\Setting::getFields(TRUE)->addSelect('name', 'title', 'description')->execute() as $field) {
        $meta[$field['name']] = $field;
      }

      if ($names) {
        $unknown = array_values(array_diff($names, array_keys($meta)));
        $wanted = array_values(array_intersect($names, array_keys($meta)));
      }
      else {
        $unknown = [];
        $wanted = [];
        foreach ($meta as $name => $field) {
          $haystack = mb_strtolower($name . ' ' . ($field['title'] ?? '') . ' ' . strip_tags((string) ($field['description'] ?? '')));
          if (str_contains($haystack, $needle)) {
            $wanted[] = $name;
          }
        }
      }
      $matched = count($wanted);
      $wanted = array_slice($wanted, 0, self::MAX_SETTINGS);

      $values = [];
      if ($wanted) {
        $get = \Civi\Api4\Setting::get(TRUE);
        $get->addSelect(...$wanted);
        foreach ($get->execute() as $row) {
          $values[$row['name']] = $row['value'] ?? NULL;
        }
      }

      $settings = [];
      foreach ($wanted as $name) {
        $value = $values[$name] ?? NULL;
        $settings[] = [
          'name' => $name,
          'title' => $meta[$name]['title'] ?? NULL,
          'description' => isset($meta[$name]['description']) ? trim(strip_tags((string) $meta[$name]['description'])) : NULL,
          'value' => Redactor::isSecretKey($name) && $value !== NULL && $value !== '' ? Redactor::REDACTED : $value,
        ];
      }

      $out = [
        'matched' => $matched,
        'returned' => count($settings),
        'settings' => $settings,
      ];
      if ($matched > self::MAX_SETTINGS) {
        $out['note'] = sprintf('Showing the first %d of %d matching settings. Use a more specific keyword or exact names.', self::MAX_SETTINGS, $matched);
      }
      if ($unknown) {
        $out['unknown_names'] = $unknown;
      }
      return $out;
    });
  }

}
