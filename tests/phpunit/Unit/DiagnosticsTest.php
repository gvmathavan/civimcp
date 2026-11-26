<?php

namespace Civi\Mcp\Tests\Unit;

use Civi\Mcp\Safety\Redactor;
use Civi\Mcp\Tool\GetSetting;
use Civi\Mcp\Tool\JobLog;
use Civi\Mcp\Tool\ListExtensions;
use Civi\Mcp\Tool\ListSavedSearches;
use Civi\Mcp\Tool\ReadLog;
use Civi\Mcp\Tool\RunSavedSearch;
use Civi\Mcp\Tool\ScheduledJobs;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\TestCase;

/**
 * v0.2 diagnostic tools.
 */
final class DiagnosticsTest extends TestCase {

  private ?string $tmpLog = NULL;

  protected function setUp(): void {
    \CiviStub::reset();
    \CiviStub::$permissions = ['access CiviCRM', 'administer CiviCRM'];
  }

  protected function tearDown(): void {
    if ($this->tmpLog && is_file($this->tmpLog)) {
      unlink($this->tmpLog);
    }
  }

  // --- list_extensions -----------------------------------------------------

  private function extensions(): void {
    \CiviStub::$rows['Extension'] = [
      ['key' => 'org.civicrm.search_kit', 'label' => 'SearchKit', 'version' => '6.3.1', 'status' => 'installed', 'status:label' => 'Enabled', 'develStage' => 'stable', 'description' => 'Search builder'],
      ['key' => 'civimcp', 'label' => 'CiviMCP', 'version' => '0.2.0-dev', 'status' => 'installed', 'status:label' => 'Enabled', 'develStage' => 'alpha', 'description' => 'MCP for CiviCRM'],
      ['key' => 'old.ext', 'label' => 'Old', 'version' => '1.0', 'status' => 'disabled', 'status:label' => 'Disabled', 'develStage' => 'stable', 'description' => ''],
      ['key' => 'gone.ext', 'label' => 'Gone', 'version' => '1.0', 'status' => 'installed-missing', 'status:label' => 'Enabled (Missing)', 'develStage' => 'stable', 'description' => ''],
    ];
  }

  public function testListExtensionsDefaultsToEnabledAndSortsByKey(): void {
    $this->extensions();
    $result = (new ListExtensions())();
    $this->assertSame(['civimcp', 'org.civicrm.search_kit'], array_column($result['extensions'], 'key'));
    $this->assertSame(['installed' => 2, 'disabled' => 1, 'installed-missing' => 1], $result['counts']);
  }

  public function testListExtensionsFindsMissingAndSearches(): void {
    $this->extensions();
    $this->assertSame(['gone.ext'], array_column((new ListExtensions())('missing')['extensions'], 'key'));
    $this->assertSame(['org.civicrm.search_kit'], array_column((new ListExtensions())('all', 'search')['extensions'], 'key'));
  }

  public function testListExtensionsNeedsAdmin(): void {
    \CiviStub::$permissions = ['access CiviCRM'];
    $this->extensions();
    $this->expectException(ToolCallException::class);
    $this->expectExceptionMessage('Permission denied');
    (new ListExtensions())();
  }

  // --- scheduled_jobs ------------------------------------------------------

  public function testScheduledJobsFlagsNeverRunOverdueAndUnfinished(): void {
    \CiviStub::$rows['Job'] = [
      ['id' => 1, 'name' => 'Update membership statuses', 'api_entity' => 'Job', 'api_action' => 'process_membership', 'run_frequency' => 'Daily', 'is_active' => TRUE, 'last_run' => date('Y-m-d H:i:s', strtotime('-1 hour')), 'last_run_end' => date('Y-m-d H:i:s', strtotime('-59 minutes')), 'scheduled_run_date' => NULL, 'parameters' => ''],
      ['id' => 2, 'name' => 'Send scheduled mailings', 'api_entity' => 'Job', 'api_action' => 'process_mailing', 'run_frequency' => 'Always', 'is_active' => TRUE, 'last_run' => date('Y-m-d H:i:s', strtotime('-3 days')), 'last_run_end' => date('Y-m-d H:i:s', strtotime('-3 days')), 'scheduled_run_date' => NULL, 'parameters' => ''],
      ['id' => 3, 'name' => 'Fetch bounces', 'api_entity' => 'Job', 'api_action' => 'fetch_bounces', 'run_frequency' => 'Hourly', 'is_active' => TRUE, 'last_run' => NULL, 'last_run_end' => NULL, 'scheduled_run_date' => NULL, 'parameters' => 'password=hunter2'],
      ['id' => 4, 'name' => 'Geocode', 'api_entity' => 'Job', 'api_action' => 'geocode', 'run_frequency' => 'Daily', 'is_active' => TRUE, 'last_run' => date('Y-m-d H:i:s', strtotime('-2 hours')), 'last_run_end' => date('Y-m-d H:i:s', strtotime('-1 day')), 'scheduled_run_date' => NULL, 'parameters' => ''],
    ];
    $result = (new ScheduledJobs())();
    $flags = array_column($result['jobs'], 'flags', 'id');
    $this->assertSame([], $flags[1]);
    $this->assertSame(['overdue'], $flags[2]);
    $this->assertSame(['never run'], $flags[3]);
    $this->assertSame(['last run did not finish'], $flags[4]);
    $this->assertSame(3, $result['jobs_with_problems']);
    $this->assertSame('Job.process_membership', $result['jobs'][0]['api_call']);
    $this->assertSame('password=[redacted]', $result['jobs'][2]['parameters']);
    $this->assertContains(['is_active', '=', TRUE], \CiviStub::$lastQuery['where']);
  }

  public function testScheduledJobsCanIncludeInactive(): void {
    (new ScheduledJobs())(TRUE);
    $this->assertSame([], \CiviStub::$lastQuery['where']);
  }

  // --- job_log -------------------------------------------------------------

  public function testJobLogFiltersRedactsAndTruncates(): void {
    \CiviStub::$rows['JobLog'] = [
      ['id' => 9, 'run_time' => '2026-11-25 02:00:00', 'job_id' => 3, 'name' => 'Fetch bounces', 'command' => 'Job.fetch_bounces', 'description' => 'Failed for jane@example.org', 'data' => str_repeat('x', 3000)],
    ];
    $result = (new JobLog())(3, 'bounce', 'fail', '2026-11-01', 500);
    $this->assertSame('Failed for [email]', $result['runs'][0]['description']);
    $this->assertStringEndsWith('…[truncated]', $result['runs'][0]['output']);
    $this->assertSame(100, \CiviStub::$lastQuery['limit']);
    $this->assertContains(['job_id', '=', 3], \CiviStub::$lastQuery['where']);
    $this->assertContains(['name', 'LIKE', '%bounce%'], \CiviStub::$lastQuery['where']);
    $this->assertContains(['run_time', '>=', '2026-11-01 00:00:00'], \CiviStub::$lastQuery['where']);
    $this->assertSame('OR', \CiviStub::$lastQuery['clauses'][0][0]);
  }

  public function testJobLogRejectsUnreadableDates(): void {
    $this->expectException(ToolCallException::class);
    (new JobLog())(NULL, '', '', 'not a date at all');
  }

  // --- read_log ------------------------------------------------------------

  private function writeLog(string $content): void {
    $this->tmpLog = tempnam(sys_get_temp_dir(), 'civilog');
    file_put_contents($this->tmpLog, $content);
    \CiviStub::$logFile = $this->tmpLog;
  }

  public function testReadLogReturnsLastLinesRedacted(): void {
    $lines = [];
    for ($i = 1; $i <= 300; $i++) {
      $lines[] = "Nov 26 10:00:00  [info] line $i";
    }
    $lines[] = 'Nov 26 10:01:00  [error] IPN failed for jane@example.org api_key=abc123';
    $this->writeLog(implode("\n", $lines) . "\n");

    $result = (new ReadLog())('', 5);
    $this->assertSame(5, $result['returned']);
    $this->assertSame('Nov 26 10:01:00  [error] IPN failed for [email] api_key=[redacted]', end($result['lines']));
    $this->assertSame('main', $result['channel']);
  }

  public function testReadLogSearchKeepsOnlyMatchingLines(): void {
    $this->writeLog("a [info] fine\nb [error] Boom\nc [info] fine\nd [ERROR] again\n");
    $result = (new ReadLog())('', 50, 'error');
    $this->assertSame(['b [error] Boom', 'd [ERROR] again'], $result['lines']);
  }

  public function testReadLogNeedsAdminBeforeTouchingFiles(): void {
    \CiviStub::$permissions = ['access CiviCRM'];
    $this->writeLog("secret stuff\n");
    $this->expectException(ToolCallException::class);
    $this->expectExceptionMessage('Permission denied');
    (new ReadLog())();
  }

  public function testReadLogRejectsPathTricks(): void {
    $this->expectException(ToolCallException::class);
    (new ReadLog())('../../etc/passwd');
  }

  public function testReadLogHandlesMissingFile(): void {
    \CiviStub::$logFile = sys_get_temp_dir() . '/does-not-exist-' . uniqid() . '.log';
    $result = (new ReadLog())('civimcp');
    $this->assertFalse($result['exists']);
  }

  // --- get_setting ---------------------------------------------------------

  private function settings(): void {
    \CiviStub::$rows['SettingFields'] = [
      ['name' => 'environment', 'title' => 'Environment', 'description' => 'Production, Staging or Development'],
      ['name' => 'mailing_backend', 'title' => 'Mailer Settings', 'description' => 'Outbound <b>mail</b> settings'],
      ['name' => 'recaptchaPrivateKey', 'title' => 'Recaptcha Private Key', 'description' => ''],
      ['name' => 'smtp_note', 'title' => 'Note', 'description' => 'about SMTP'],
    ];
    \CiviStub::$rows['Setting'] = [
      'environment' => 'Development',
      'mailing_backend' => ['outBound_option' => 0, 'smtpServer' => 'smtp.example.org', 'smtpPassword' => 'hunter2'],
      'recaptchaPrivateKey' => 'abcdef',
      'smtp_note' => 'hello',
    ];
  }

  public function testGetSettingByNameRedactsSecrets(): void {
    $this->settings();
    $result = (new GetSetting())(['environment', 'recaptchaPrivateKey', 'mailing_backend', 'nope']);
    $values = array_column($result['settings'], 'value', 'name');
    $this->assertSame('Development', $values['environment']);
    $this->assertSame(Redactor::REDACTED, $values['recaptchaPrivateKey']);
    $this->assertSame(Redactor::REDACTED, $values['mailing_backend']['smtpPassword']);
    $this->assertSame('smtp.example.org', $values['mailing_backend']['smtpServer']);
    $this->assertSame(['nope'], $result['unknown_names']);
  }

  public function testGetSettingBySearchMatchesTitleAndDescription(): void {
    $this->settings();
    $result = (new GetSetting())([], 'smtp');
    $this->assertSame(['smtp_note'], array_column($result['settings'], 'name'));
    $this->assertSame(['mailing_backend'], array_column((new GetSetting())([], 'mail')['settings'], 'name'));
  }

  public function testGetSettingNeedsNamesOrSearch(): void {
    $this->expectException(ToolCallException::class);
    (new GetSetting())();
  }

  // --- saved searches ------------------------------------------------------

  private function savedSearches(): void {
    \CiviStub::$rows['SavedSearch'] = [
      ['id' => 1, 'name' => 'Lapsed_members', 'label' => 'Lapsed members', 'api_entity' => 'Membership', 'description' => 'Expired last year', 'modified_date' => '2026-11-01',
        'api_params' => ['version' => 4, 'select' => ['id', 'contact_id.display_name'], 'where' => [['status_id:name', '=', 'Expired']], 'limit' => 9999, 'checkPermissions' => FALSE]],
      ['id' => 2, 'name' => 'Processors', 'label' => 'Processors', 'api_entity' => 'PaymentProcessor', 'description' => '', 'modified_date' => NULL, 'api_params' => ['select' => ['*']]],
    ];
    \CiviStub::$apiHandler = fn() => new \StubResult([['id' => 1, 'contact_id.display_name' => 'Jane', 'row_count' => 1]], 1);
  }

  public function testListSavedSearches(): void {
    $this->savedSearches();
    $result = (new ListSavedSearches())('lapsed');
    $this->assertSame('Lapsed_members', $result['searches'][0]['name']);
    $this->assertSame('OR', \CiviStub::$lastQuery['clauses'][0][0]);
  }

  public function testRunSavedSearchUsesItsQueryButForcesSafePaging(): void {
    $this->savedSearches();
    $result = (new RunSavedSearch())('Lapsed_members', 500);
    [$entity, $action, $params] = end(\CiviStub::$apiCalls);
    $this->assertSame(['Membership', 'get'], [$entity, $action]);
    $this->assertSame([['status_id:name', '=', 'Expired']], $params['where']);
    $this->assertSame(100, $params['limit'], 'The search\'s own limit must not override the cap');
    $this->assertTrue($params['checkPermissions'], 'The search must not switch off permission checks');
    $this->assertArrayNotHasKey('version', $params);
    $this->assertSame('Jane', $result['values'][0]['contact_id.display_name']);
  }

  public function testRunSavedSearchRefusesBlockedEntities(): void {
    $this->savedSearches();
    $this->expectException(ToolCallException::class);
    $this->expectExceptionMessage('not available through CiviMCP');
    (new RunSavedSearch())('Processors');
  }

  public function testRunSavedSearchUnknownName(): void {
    $this->savedSearches();
    $this->expectException(ToolCallException::class);
    $this->expectExceptionMessage('list_saved_searches');
    (new RunSavedSearch())('Nope');
  }

}
