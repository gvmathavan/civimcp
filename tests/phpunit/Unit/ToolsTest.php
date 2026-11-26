<?php

namespace Civi\Mcp\Tests\Unit;

use Civi\Mcp\ServerFactory;
use Civi\Mcp\Tool\AbstractTool;
use Civi\Mcp\Tool\DescribeEntity;
use Civi\Mcp\Tool\ListEntities;
use Civi\Mcp\Tool\SearchContacts;
use Civi\Mcp\Tool\SiteInfo;
use Civi\Mcp\Tool\SystemCheck;
use Civi\Mcp\ToolRegistry;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\TestCase;

/**
 * Tool definitions and the behaviour of the smaller tools.
 */
final class ToolsTest extends TestCase {

  protected function setUp(): void {
    \CiviStub::reset();
  }

  public function testEveryToolIsWellDefinedAndReadOnly(): void {
    $names = [];
    foreach (ToolRegistry::tools() as $tool) {
      $this->assertInstanceOf(AbstractTool::class, $tool);
      $this->assertMatchesRegularExpression('/^[a-z][a-z_]+$/', $tool::NAME);
      $this->assertNotSame('', $tool::TITLE, $tool::NAME . ' needs a title');
      $this->assertGreaterThan(40, strlen($tool->description()), $tool::NAME . ' needs a useful description');
      $this->assertSame('object', $tool->inputSchema()['type'], $tool::NAME . ' schema must be an object');

      $annotations = $tool->annotations();
      $this->assertSame($tool::TITLE, $annotations->title);
      $this->assertTrue($annotations->readOnlyHint, $tool::NAME . ' must be marked read-only in v0.1');
      $this->assertFalse($annotations->destructiveHint, $tool::NAME . ' must not be marked destructive');
      $names[] = $tool::NAME;
    }
    $this->assertSame(array_unique($names), $names, 'Tool names must be unique');
    $this->assertEqualsCanonicalizing([
      'site_info', 'system_check', 'list_entities', 'describe_entity', 'get', 'search_contacts',
      'list_extensions', 'scheduled_jobs', 'job_log', 'read_log', 'get_setting', 'list_saved_searches', 'run_saved_search',
    ], $names);
  }

  public function testServerBuildsWithAllTools(): void {
    $this->assertInstanceOf(\Mcp\Server::class, ServerFactory::create());
  }

  public function testSiteInfoReportsPermissions(): void {
    $info = (new SiteInfo())();
    $this->assertSame('6.3.1', $info['civicrm_version']);
    $this->assertSame(2, $info['enabled_extensions']);
    $this->assertTrue($info['current_user']['permissions']['access CiviCRM']);
    $this->assertFalse($info['current_user']['permissions']['administer CiviCRM']);
    $this->assertSame(['site' => 'Test Site', 'environment' => 'Development', 'url' => 'http://test.loc', 'user' => 'Test User'], $info['context']);
  }

  public function testEnvironmentDefaultsToProductionWhenUnset(): void {
    \CiviStub::$settings['environment'] = NULL;
    $this->assertSame('Production', (new SiteInfo())()['context']['environment']);
  }

  public function testSystemCheckNeedsAdminPermission(): void {
    $this->expectException(ToolCallException::class);
    $this->expectExceptionMessage('Permission denied');
    (new SystemCheck())();
  }

  public function testSystemCheckFiltersBySeverityAndCleansHtml(): void {
    \CiviStub::$permissions[] = 'administer CiviCRM';
    \CiviStub::$systemChecks = [
      ['name' => 'checkCron', 'title' => 'Cron', 'message' => '<p>Last run <b>never</b>.</p>', 'severity_id' => 4, 'is_visible' => 1],
      ['name' => 'checkInfo', 'title' => 'FYI', 'message' => 'fine', 'severity_id' => 1, 'is_visible' => 1],
    ];
    $result = (new SystemCheck())('warning');
    $this->assertSame(1, $result['total']);
    $this->assertSame('error', $result['messages'][0]['severity']);
    $this->assertSame('Last run never.', $result['messages'][0]['message']);
    $this->assertSame(['severity_id', '>=', 3], \CiviStub::$lastQuery['where'][0]);
  }

  public function testListEntitiesFiltersAndHidesCustomGroupsByDefault(): void {
    $result = (new ListEntities())('member');
    $this->assertSame(['Membership'], array_column($result['entities'], 'name'));

    $all = array_column((new ListEntities())()['entities'], 'name');
    $this->assertNotContains('Custom_Extra', $all);
    $this->assertNotContains('Setting', $all);
    $this->assertContains('Custom_Extra', array_column((new ListEntities())('', TRUE)['entities'], 'name'));
  }

  public function testDescribeEntityAsksForGetFieldsAndCapsOptions(): void {
    \CiviStub::$apiHandler = fn() => [
      ['name' => 'status_id', 'label' => 'Status', 'data_type' => 'Integer', 'options' => array_combine(range(1, 60), array_map(fn($i) => "S$i", range(1, 60)))],
    ];
    $result = (new DescribeEntity())('Membership');
    [$entity, $action, $params] = end(\CiviStub::$apiCalls);
    $this->assertSame(['Membership', 'getFields', 'get'], [$entity, $action, $params['action']]);
    $this->assertCount(50, $result['fields'][0]['options']);
    $this->assertSame(60, $result['fields'][0]['options_truncated']);
  }

  public function testDescribeEntityRefusesDeniedEntities(): void {
    $this->expectException(ToolCallException::class);
    (new DescribeEntity())('Setting');
  }

  public function testSearchContactsExcludesDeletedAndSearchesSeveralFields(): void {
    $result = (new SearchContacts())('smith');
    $this->assertSame('Jane Smith', $result['contacts'][0]['display_name']);
    $this->assertContains(['is_deleted', '=', FALSE], \CiviStub::$lastQuery['where']);
    [$operator, $conditions] = \CiviStub::$lastQuery['clauses'][0];
    $this->assertSame('OR', $operator);
    $this->assertContains('email_primary.email', array_column($conditions[0], 0));
  }

  public function testSearchContactsMatchesIdForNumbers(): void {
    (new SearchContacts())('123');
    $conditions = \CiviStub::$lastQuery['clauses'][0][1][0];
    $this->assertContains(['id', '=', 123], $conditions);
  }

  public function testSearchContactsRejectsTooShortQueries(): void {
    $this->expectException(ToolCallException::class);
    (new SearchContacts())(' a ');
  }

}
