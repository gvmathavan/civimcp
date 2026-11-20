<?php

namespace Civi\Mcp\Tool;

use Mcp\Exception\ToolCallException;

/**
 * system_check: CiviCRM's System Status messages (Administer > System Status).
 */
final class SystemCheck extends AbstractTool {

  public const NAME = 'system_check';
  public const TITLE = 'System status check';

  /**
   * CiviCRM severity names, lowest to highest (matches CRM_Utils_Check).
   */
  private const SEVERITIES = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];

  public function description(): string {
    return 'Run CiviCRM\'s system status checks (the same as Administer > Administration Console > System Status) and return the messages at or above a minimum severity. Use this to find configuration problems, missing upgrades, failing cron or outdated extensions. Requires the "administer CiviCRM" permission.';
  }

  public function inputSchema(): array {
    return [
      'type' => 'object',
      'properties' => [
        'min_severity' => [
          'type' => 'string',
          'enum' => self::SEVERITIES,
          'default' => 'warning',
          'description' => 'Only return messages at this severity or higher. Default "warning".',
        ],
        'include_hidden' => [
          'type' => 'boolean',
          'default' => FALSE,
          'description' => 'Also include messages an admin has hidden (snoozed). Default false.',
        ],
      ],
      'additionalProperties' => FALSE,
    ];
  }

  /**
   * @return array<string, mixed>
   */
  public function __invoke(string $min_severity = 'warning', bool $include_hidden = FALSE): array {
    $args = ['min_severity' => $min_severity, 'include_hidden' => $include_hidden];

    return $this->execute($args, function () use ($min_severity, $include_hidden): array {
      $minLevel = array_search(strtolower($min_severity), self::SEVERITIES, TRUE);
      if ($minLevel === FALSE) {
        throw new ToolCallException(sprintf('Unknown min_severity "%s". Use one of: %s.', $min_severity, implode(', ', self::SEVERITIES)));
      }

      $messages = \Civi\Api4\System::check()
        ->setIncludeDisabled($include_hidden)
        ->addSelect('name', 'title', 'message', 'severity_id', 'is_visible')
        ->addWhere('severity_id', '>=', $minLevel)
        ->addOrderBy('severity_id', 'DESC')
        ->execute();

      $items = [];
      $counts = [];
      foreach ($messages as $message) {
        $severity = self::SEVERITIES[(int) $message['severity_id']] ?? (string) $message['severity_id'];
        $counts[$severity] = ($counts[$severity] ?? 0) + 1;
        $items[] = [
          'severity' => $severity,
          'name' => $message['name'] ?? '',
          'title' => $message['title'] ?? '',
          'message' => self::plainText((string) ($message['message'] ?? '')),
          'hidden' => empty($message['is_visible']),
        ];
      }

      return [
        'min_severity' => $min_severity,
        'total' => count($items),
        'counts' => $counts,
        'messages' => $items,
      ];
    });
  }

  /**
   * System check messages are HTML; Claude reads them better as plain text.
   */
  private static function plainText(string $html): string {
    $text = preg_replace('/<(br|\/p|\/li)\s*\/?>/i', "\n", $html) ?? $html;
    $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace("/[ \t]+/", ' ', preg_replace("/\n{3,}/", "\n\n", $text) ?? $text) ?? $text);
  }

}
