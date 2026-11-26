<?php

namespace Civi\Mcp\Safety;

use Civi\Mcp\SiteContext;

/**
 * Records every tool call.
 *
 * Writes one JSON line per call to its own file in the ConfigAndLog folder,
 * CiviCRM.<domain>.civimcp.<hash>.log, using CiviCRM's log-file prefix
 * mechanism (Civi::log('civimcp') would silently fall back to the main log).
 * read_log with channel "civimcp" shows it. A database table and an admin
 * screen come later.
 */
final class AuditLog {

  /**
   * @param array<string, mixed> $args
   */
  public static function record(string $tool, array $args, string $outcome, float $startedAt, ?string $error = NULL): void {
    try {
      $entry = [
        'tool' => $tool,
        'outcome' => $outcome,
        'contact_id' => \CRM_Core_Session::getLoggedInContactID(),
        'environment' => SiteContext::environment(),
        'args' => Redactor::redact($args),
        'ms' => (int) round((microtime(TRUE) - $startedAt) * 1000),
      ];
      if ($error !== NULL) {
        $entry['error'] = $error;
      }
      \CRM_Core_Error::debug_log_message(
        'civimcp tool call ' . json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        FALSE,
        'civimcp',
        defined('PEAR_LOG_INFO') ? PEAR_LOG_INFO : 6
      );
    }
    catch (\Throwable $e) {
      // Logging must never break a tool call.
    }
  }

}
