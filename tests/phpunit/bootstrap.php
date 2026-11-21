<?php
/**
 * Unit test bootstrap.
 *
 * The unit tests check CiviMCP's own logic (safety rules, tool definitions,
 * parameter handling) against small stand-ins for CiviCRM, so they run in
 * seconds without a database. The smoke test in tests/smoke checks the real
 * server against a real test site.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

if (class_exists('Civi', FALSE) || function_exists('civicrm_api4')) {
  throw new RuntimeException('Run the unit tests without CiviCRM loaded: vendor/bin/phpunit');
}
require __DIR__ . '/Stubs/civicrm.php';
