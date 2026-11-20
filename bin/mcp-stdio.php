#!/usr/bin/env php
<?php
/**
 * CiviMCP: serve the MCP tools over stdio, for MCP Inspector, Claude Desktop
 * and Claude Code.
 *
 * Usage:
 *   php bin/mcp-stdio.php --user=<WordPress login or email> [--wp-root=/path/to/wordpress] [--url=http://site.loc]
 *
 * The server always runs as a real CiviCRM user, so CiviCRM permissions and
 * ACLs apply to every tool call. It never runs anonymously.
 *
 * stdout carries the MCP protocol and nothing else: PHP errors and anything
 * WordPress or CiviCRM print are redirected to stderr.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
  fwrite(STDERR, "civimcp: bin/mcp-stdio.php must be run from the command line.\n");
  exit(1);
}

// Keep stdout clean for the protocol.
ini_set('display_errors', 'stderr');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
ob_start(static function (string $buffer): string {
  if ($buffer !== '') {
    fwrite(STDERR, $buffer);
  }
  return '';
}, 1);

/**
 * Write a message to stderr and stop.
 */
function civimcp_fail(string $message, int $code = 1): never {
  fwrite(STDERR, 'civimcp: ' . $message . "\n");
  exit($code);
}

/**
 * Walk up from the extension folder until we find wp-load.php.
 */
function civimcp_find_wp_root(string $start): ?string {
  $dir = $start;
  for ($i = 0; $i < 10; $i++) {
    if (is_file($dir . '/wp-load.php')) {
      return $dir;
    }
    $parent = dirname($dir);
    if ($parent === $dir) {
      break;
    }
    $dir = $parent;
  }
  return NULL;
}

$civimcpOpts = getopt('', ['user:', 'wp-root:', 'url:']);
$civimcpUser = $civimcpOpts['user'] ?? (getenv('CIVIMCP_USER') ?: NULL);
if (!$civimcpUser) {
  civimcp_fail('--user=<WordPress login or email> is required. CiviMCP never runs anonymously or as a default admin.', 2);
}

$civimcpExtDir = dirname(__DIR__);
$civimcpWpRoot = $civimcpOpts['wp-root'] ?? (getenv('CIVIMCP_WP_ROOT') ?: civimcp_find_wp_root($civimcpExtDir));
if (!$civimcpWpRoot || !is_file($civimcpWpRoot . '/wp-load.php')) {
  civimcp_fail('Could not find WordPress (wp-load.php). Pass --wp-root=/path/to/wordpress.');
}

// WordPress expects a few web-request values even on the command line.
$civimcpUrl = $civimcpOpts['url'] ?? (getenv('CIVIMCP_URL') ?: NULL);
$civimcpHost = $civimcpUrl ? (parse_url($civimcpUrl, PHP_URL_HOST) ?: 'localhost') : 'localhost';
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? $civimcpHost;
$_SERVER['SERVER_NAME'] = $_SERVER['SERVER_NAME'] ?? $civimcpHost;
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';
$_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Boot WordPress and CiviCRM. This must run in global scope.
try {
  define('WP_USE_THEMES', FALSE);
  require_once $civimcpWpRoot . '/wp-load.php';

  if (!function_exists('civicrm_initialize') || !civicrm_initialize()) {
    throw new RuntimeException('The CiviCRM plugin is not active on this WordPress site.');
  }

  $civimcpWpUser = get_user_by('login', $civimcpUser) ?: get_user_by('email', $civimcpUser);
  if (!$civimcpWpUser) {
    throw new RuntimeException(sprintf('WordPress user "%s" not found.', $civimcpUser));
  }
  wp_set_current_user($civimcpWpUser->ID);
  CRM_Core_BAO_UFMatch::synchronize($civimcpWpUser, FALSE, 'WordPress', 'Individual');

  if (!CRM_Core_Session::getLoggedInContactID()) {
    throw new RuntimeException(sprintf('WordPress user "%s" has no CiviCRM contact.', $civimcpUser));
  }
  if (!CRM_Core_Permission::check('access CiviCRM')) {
    throw new RuntimeException(sprintf('User "%s" does not have the "access CiviCRM" permission.', $civimcpUser));
  }
}
catch (Throwable $e) {
  civimcp_fail('Bootstrap failed: ' . $e->getMessage());
}

// Load the MCP SDK after CiviCRM, so CiviCRM's own copies of shared libraries
// (psr/log, psr/container and others) are the ones in use.
if (!is_file($civimcpExtDir . '/vendor/autoload.php')) {
  civimcp_fail('Dependencies missing. Run "composer install" in ' . $civimcpExtDir);
}
require_once $civimcpExtDir . '/vendor/autoload.php';

fwrite(STDERR, sprintf(
  "civimcp: serving over stdio as %s (contact %d) on %s\n",
  $civimcpUser,
  CRM_Core_Session::getLoggedInContactID(),
  \Civi\Mcp\SiteContext::label()
));

$civimcpServer = \Civi\Mcp\ServerFactory::create();
$civimcpResult = $civimcpServer->run(new \Mcp\Server\Transport\StdioTransport());
exit(is_int($civimcpResult) ? $civimcpResult : 0);
