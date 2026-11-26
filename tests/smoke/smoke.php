<?php
/**
 * CiviMCP smoke test: runs the real stdio server against a real TEST site,
 * once as an admin and once as a limited user, and checks the answers.
 *
 * Run from the extension folder:
 *   php tests/smoke/smoke.php --admin=admin --limited=mcp-tester --url=http://wpcrm6.loc
 *
 * The limited user should have "access CiviCRM" but NOT "access CiviContribute"
 * or "administer CiviCRM".
 */

$opts = getopt('', ['admin:', 'limited:', 'url:']);
$admin = $opts['admin'] ?? 'admin';
$limited = $opts['limited'] ?? 'mcp-tester';
$url = $opts['url'] ?? 'http://localhost';
$server = getenv('CIVIMCP_SMOKE_SERVER') ?: dirname(__DIR__, 2) . '/bin/mcp-stdio.php';

$failures = 0;
$passes = 0;

function check(bool $ok, string $label, string $detail = ''): void {
  global $failures, $passes;
  if ($ok) {
    $passes++;
    echo "  \033[32m✔\033[0m $label\n";
  }
  else {
    $failures++;
    echo "  \033[31m✘ $label\033[0m" . ($detail !== '' ? "\n      $detail" : '') . "\n";
  }
}

/**
 * Start the server as $user, send tool calls, return responses keyed by id.
 *
 * @param array<int, array{0: string, 1: array}> $calls id => [tool, arguments]
 */
function run_server(string $server, string $user, string $url, array $calls): array {
  $messages = [
    ['jsonrpc' => '2.0', 'id' => 0, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => new stdClass(), 'clientInfo' => ['name' => 'civimcp-smoke', 'version' => '1']]],
    ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
    ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'],
  ];
  foreach ($calls as $id => [$tool, $arguments]) {
    $messages[] = ['jsonrpc' => '2.0', 'id' => $id, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => (object) $arguments]];
  }
  $input = implode("\n", array_map(fn($m) => json_encode($m), $messages)) . "\n";

  $cmd = [PHP_BINARY, $server, '--user=' . $user, '--url=' . $url];
  $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
  if (!is_resource($proc)) {
    throw new RuntimeException('Could not start the server');
  }
  fwrite($pipes[0], $input);
  fclose($pipes[0]);
  $stdout = stream_get_contents($pipes[1]);
  $stderr = stream_get_contents($pipes[2]);
  fclose($pipes[1]);
  fclose($pipes[2]);
  proc_close($proc);

  $responses = ['_stderr' => $stderr, '_stdout_ok' => TRUE];
  foreach (explode("\n", trim($stdout)) as $line) {
    if ($line === '') {
      continue;
    }
    $msg = json_decode($line, TRUE);
    if (!is_array($msg)) {
      $responses['_stdout_ok'] = FALSE;
      continue;
    }
    if (isset($msg['id'])) {
      $responses[$msg['id']] = $msg;
    }
  }
  return $responses;
}

function tool_ok(array $r, int $id): bool {
  return isset($r[$id]['result']) && empty($r[$id]['result']['isError']);
}

function tool_error(array $r, int $id): string {
  if (isset($r[$id]['result']['isError']) && $r[$id]['result']['isError']) {
    return $r[$id]['result']['content'][0]['text'] ?? 'error';
  }
  return $r[$id]['error']['message'] ?? '';
}

function data(array $r, int $id): array {
  return $r[$id]['result']['structuredContent'] ?? json_decode($r[$id]['result']['content'][0]['text'] ?? '{}', TRUE) ?? [];
}

// ---------------------------------------------------------------------------
echo "\nCiviMCP smoke test on $url\n";
echo "\nAs admin user \"$admin\":\n";
$r = run_server($server, $admin, $url, [
  10 => ['site_info', []],
  11 => ['list_entities', ['search' => 'member']],
  12 => ['describe_entity', ['entity' => 'Membership', 'search' => 'status']],
  13 => ['get', ['entity' => 'Membership', 'select' => ['status_id:label', 'COUNT(id) AS total'], 'groupBy' => ['status_id']]],
  14 => ['get', ['entity' => 'Contact', 'select' => ['id', 'display_name'], 'limit' => 3]],
  15 => ['search_contacts', ['query' => 'smith', 'limit' => 3]],
  16 => ['system_check', []],
  17 => ['get', ['entity' => 'Setting']],
  18 => ['get', ['entity' => 'PaymentProcessor']],
  19 => ['get', ['entity' => 'Contact', 'limit' => 500]],
  30 => ['list_extensions', []],
  31 => ['scheduled_jobs', []],
  32 => ['job_log', ['limit' => 3]],
  33 => ['read_log', ['channel' => 'civimcp', 'lines' => 5]],
  34 => ['get_setting', ['names' => ['environment']]],
  35 => ['get_setting', ['search' => 'smtp']],
  36 => ['list_saved_searches', ['limit' => 5]],
  37 => ['read_log', ['channel' => '../../etc']],
]);

check($r['_stdout_ok'], 'stdout carries only valid JSON-RPC', trim($r['_stderr']));
check(isset($r[0]['result']['serverInfo']['name']) && $r[0]['result']['serverInfo']['name'] === 'civimcp', 'server starts and says hello', trim($r['_stderr']));
$tools = array_column($r[1]['result']['tools'] ?? [], 'name');
check(count($tools) === 13, '13 tools listed', implode(', ', $tools));
$allReadOnly = array_reduce($r[1]['result']['tools'] ?? [], fn($c, $t) => $c && ($t['annotations']['readOnlyHint'] ?? FALSE), TRUE);
check($allReadOnly, 'every tool is marked read-only');

$info = data($r, 10);
check(tool_ok($r, 10) && ($info['current_user']['permissions']['administer CiviCRM'] ?? FALSE), 'site_info: admin has "administer CiviCRM"');
check(($info['context']['environment'] ?? '') !== 'Production', 'site_info: site is not labelled Production', 'environment = ' . ($info['context']['environment'] ?? '?'));
check(tool_ok($r, 11) && in_array('Membership', array_column(data($r, 11)['entities'] ?? [], 'name'), TRUE), 'list_entities finds Membership');
check(tool_ok($r, 12) && (data($r, 12)['total_fields'] ?? 0) > 0, 'describe_entity returns Membership fields');
check(tool_ok($r, 13) && isset(data($r, 13)['values'][0]['total']), 'get with groupBy returns totals', tool_error($r, 13));
check(tool_ok($r, 14) && count(data($r, 14)['values'] ?? []) <= 3, 'get Contact respects limit', tool_error($r, 14));
check(tool_ok($r, 15), 'search_contacts runs', tool_error($r, 15));
check(tool_ok($r, 16), 'system_check runs for admin', tool_error($r, 16));
check(str_contains(tool_error($r, 17), 'not available through CiviMCP'), 'Setting entity is refused');
check(str_contains(tool_error($r, 18), 'not available through CiviMCP'), 'PaymentProcessor entity is refused');
check(tool_error($r, 19) !== '', 'limit above 100 is rejected');

echo "\n  v0.2 diagnostics:\n";
check(tool_ok($r, 30) && in_array('civimcp', array_column(data($r, 30)['extensions'] ?? [], 'key'), TRUE), 'list_extensions shows civimcp as enabled', tool_error($r, 30));
check(tool_ok($r, 31) && isset(data($r, 31)['jobs']), 'scheduled_jobs lists jobs (' . (data($r, 31)['total'] ?? '?') . ' active, ' . (data($r, 31)['jobs_with_problems'] ?? '?') . ' flagged)', tool_error($r, 31));
check(tool_ok($r, 32), 'job_log runs', tool_error($r, 32));
$log = data($r, 33);
check(tool_ok($r, 33) && !empty($log['exists'] ?? TRUE), 'read_log reads the civimcp audit log (' . ($log['returned'] ?? 0) . ' lines)', tool_error($r, 33));
$leaks = preg_grep('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', $log['lines'] ?? []);
check(!$leaks, 'read_log output contains no email addresses', implode(' | ', (array) $leaks));
$env = array_column(data($r, 34)['settings'] ?? [], 'value', 'name')['environment'] ?? NULL;
check(tool_ok($r, 34) && $env !== NULL, 'get_setting reads environment (' . json_encode($env) . ')', tool_error($r, 34));
$smtp = json_encode(data($r, 35)['settings'] ?? []);
check(tool_ok($r, 35) && !preg_match('/"smtpPassword":"(?!\[redacted\])[^"]+"/', $smtp), 'get_setting never shows an SMTP password', tool_error($r, 35));
check(tool_ok($r, 36), 'list_saved_searches runs (' . (data($r, 36)['total_matched'] ?? '?') . ' saved searches)', tool_error($r, 36));
check(tool_error($r, 37) !== '', 'read_log refuses path tricks');
$first = data($r, 36)['searches'][0]['name'] ?? NULL;
if ($first) {
  $rs = run_server($server, $admin, $url, [40 => ['run_saved_search', ['name' => $first, 'limit' => 3]]]);
  $ok = tool_ok($rs, 40);
  $err = tool_error($rs, 40);
  // Some core saved searches use entities CiviMCP blocks; a clear refusal is also correct.
  check($ok || str_contains($err, 'not available through CiviMCP'), "run_saved_search \"$first\" runs (or is cleanly refused)", $err);
}

// ---------------------------------------------------------------------------
echo "\nAs limited user \"$limited\":\n";
$r = run_server($server, $limited, $url, [
  20 => ['site_info', []],
  21 => ['get', ['entity' => 'Membership', 'select' => ['id'], 'limit' => 1]],
  22 => ['get', ['entity' => 'Contribution', 'limit' => 1]],
  23 => ['system_check', []],
  24 => ['list_extensions', []],
  25 => ['read_log', []],
  26 => ['get_setting', ['names' => ['environment']]],
]);
check(isset($r[0]['result']), 'server starts as the limited user', trim($r['_stderr']));
$perms = data($r, 20)['current_user']['permissions'] ?? [];
check(($perms['access CiviCRM'] ?? FALSE) && !($perms['administer CiviCRM'] ?? TRUE) && !($perms['access CiviContribute'] ?? TRUE), 'site_info: limited permissions reported', json_encode($perms));
check(tool_ok($r, 21), 'can read memberships', tool_error($r, 21));
check(str_starts_with(tool_error($r, 22), 'Permission denied'), 'cannot read contributions', tool_error($r, 22));
check(str_starts_with(tool_error($r, 23), 'Permission denied'), 'cannot run system_check', tool_error($r, 23));
check(str_starts_with(tool_error($r, 24), 'Permission denied'), 'cannot list extensions', tool_error($r, 24));
check(str_starts_with(tool_error($r, 25), 'Permission denied'), 'cannot read log files', tool_error($r, 25));
check(str_starts_with(tool_error($r, 26), 'Permission denied'), 'cannot read settings', tool_error($r, 26));

// ---------------------------------------------------------------------------
echo "\n" . ($failures ? "\033[31m$failures failed\033[0m, " : '') . "$passes passed\n\n";
exit($failures ? 1 : 0);
