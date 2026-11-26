<?php

namespace Civi\Mcp\Tool;

use Civi\Mcp\Safety\Redactor;
use Mcp\Exception\ToolCallException;

/**
 * read_log: the end of a CiviCRM log file in ConfigAndLog, redacted.
 *
 * This reads files directly, so CiviCRM's API permissions do not apply:
 * the tool checks "administer CiviCRM" itself before reading anything.
 */
final class ReadLog extends AbstractTool {

  public const NAME = 'read_log';
  public const TITLE = 'Read CiviCRM log';

  public const MAX_LINES = 200;

  /**
   * Read at most this many bytes from the end of the file.
   */
  private const MAX_BYTES = 1048576;

  public function description(): string {
    return 'Read the most recent lines of a CiviCRM log file from the ConfigAndLog folder, newest last. Use it to find errors behind a failed job, payment, email or page. The default channel is the main CiviCRM log; other channels write their own files (e.g. "civimcp" for this server\'s audit log). Optionally keep only lines containing a word such as "error". Email addresses, keys, passwords and checksums are redacted. Requires the "administer CiviCRM" permission.';
  }

  public function inputSchema(): array {
    return [
      'type' => 'object',
      'properties' => [
        'channel' => [
          'type' => 'string',
          'pattern' => '^[a-z0-9_]*$',
          'default' => '',
          'description' => 'Log channel. Empty (default) for the main CiviCRM log, or e.g. "civimcp", "ipn".',
        ],
        'lines' => [
          'type' => 'integer',
          'minimum' => 1,
          'maximum' => self::MAX_LINES,
          'default' => 50,
          'description' => 'How many of the most recent matching lines to return (1-200). Default 50.',
        ],
        'search' => [
          'type' => 'string',
          'description' => 'Only lines containing this text (case-insensitive), e.g. "error", "IPN", "Fatal".',
        ],
      ],
      'additionalProperties' => FALSE,
    ];
  }

  /**
   * @return array<string, mixed>
   */
  public function __invoke(string $channel = '', int $lines = 50, string $search = ''): array {
    $args = ['channel' => $channel, 'lines' => $lines, 'search' => $search];

    return $this->execute($args, function () use ($channel, $lines, $search): array {
      if (!\CRM_Core_Permission::check('administer CiviCRM')) {
        throw new \Civi\API\Exception\UnauthorizedException('Reading log files requires the "administer CiviCRM" permission.');
      }
      if (!preg_match('/^[a-z0-9_]*$/', $channel)) {
        throw new ToolCallException('channel may only contain lowercase letters, digits and underscores.');
      }
      $lines = max(1, min(self::MAX_LINES, $lines));

      $file = \CRM_Core_Error::generateLogFileName($channel);
      if (!is_file($file) || !is_readable($file)) {
        return [
          'channel' => $channel === '' ? 'main' : $channel,
          'file' => basename($file),
          'exists' => FALSE,
          'lines' => [],
          'note' => 'No log file for this channel yet (nothing has been logged since the last monthly rotation).',
        ];
      }

      $size = filesize($file) ?: 0;
      $handle = fopen($file, 'rb');
      if ($handle === FALSE) {
        throw new ToolCallException('Could not open the log file.');
      }
      $start = max(0, $size - self::MAX_BYTES);
      fseek($handle, $start);
      $chunk = (string) stream_get_contents($handle);
      fclose($handle);

      $all = preg_split('/\r?\n/', $chunk) ?: [];
      if ($start > 0) {
        // First line is probably partial.
        array_shift($all);
      }
      $all = array_values(array_filter($all, fn($l) => trim($l) !== ''));
      if (trim($search) !== '') {
        $needle = mb_strtolower(trim($search));
        $all = array_values(array_filter($all, fn($l) => str_contains(mb_strtolower($l), $needle)));
      }
      $selected = array_slice($all, -$lines);
      $selected = array_map(fn($l) => Redactor::redactText(mb_strlen($l) > 2000 ? mb_substr($l, 0, 2000) . ' …[truncated]' : $l), $selected);

      return [
        'channel' => $channel === '' ? 'main' : $channel,
        'file' => basename($file),
        'file_size_kb' => (int) round($size / 1024),
        'modified' => date('Y-m-d H:i:s', filemtime($file) ?: time()),
        'matching_lines_scanned' => count($all),
        'returned' => count($selected),
        'lines' => $selected,
      ];
    });
  }

}
