<?php

namespace Civi\Mcp\Safety;

use Civi\Mcp\SiteContext;

/**
 * Records every tool call.
 *
 * v0.1 writes to CiviCRM's own log channel "civimcp", which lands in the
 * ConfigAndLog folder (CiviCRM.civimcp.*.log). A database table and an admin
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
      \Civi::log('civimcp')->info('civimcp tool call', $entry);
    }
    catch (\Throwable $e) {
      // Logging must never break a tool call.
    }
  }

}
