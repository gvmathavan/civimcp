<?php

namespace Civi\Mcp\Tests\Unit;

use Civi\Mcp\Safety\Redactor;
use Civi\Mcp\Tool\Get;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\TestCase;

final class GetToolTest extends TestCase {

  protected function setUp(): void {
    \CiviStub::reset();
    \CiviStub::$apiHandler = fn($entity, $action, $params) => new \StubResult([
      ['id' => 1, 'end_date' => '2026-12-01', 'row_count' => 3, 'api_key' => 'LEAK'],
      ['id' => 2, 'end_date' => '2026-12-05', 'row_count' => 3],
    ], 3);
  }

  private function lastCall(): array {
    $this->assertNotEmpty(\CiviStub::$apiCalls, 'Expected an APIv4 call');
    return end(\CiviStub::$apiCalls);
  }

  public function testOnlyEverCallsGetWithPermissionChecks(): void {
    (new Get())('Membership', ['id', 'end_date']);
    [$entity, $action, $params] = $this->lastCall();
    $this->assertSame('Membership', $entity);
    $this->assertSame('get', $action);
    $this->assertTrue($params['checkPermissions']);
  }

  public function testLimitIsCappedAtOneHundred(): void {
    (new Get())('Membership', [], [], [], 5000);
    $this->assertSame(Get::MAX_LIMIT, $this->lastCall()[2]['limit']);
  }

  public function testNegativeOffsetBecomesZero(): void {
    (new Get())('Membership', [], [], [], 10, -20);
    $this->assertSame(0, $this->lastCall()[2]['offset']);
  }

  public function testRowCountIsRequestedOnlyForDatabaseEntities(): void {
    (new Get())('Membership', ['id']);
    $this->assertContains('row_count', $this->lastCall()[2]['select']);

    (new Get())('Basicish', ['id']);
    $this->assertNotContains('row_count', $this->lastCall()[2]['select']);
  }

  public function testResultIsPaginatedRedactedAndLabelled(): void {
    $result = (new Get())('Membership', ['id', 'end_date'], [], [], 2);
    $this->assertSame(3, $result['total_matched']);
    $this->assertSame(2, $result['returned']);
    $this->assertTrue($result['has_more']);
    $this->assertSame(2, $result['next_offset']);
    $this->assertSame(Redactor::REDACTED, $result['values'][0]['api_key']);
    $this->assertArrayNotHasKey('row_count', $result['values'][0]);
    $this->assertSame('Development', $result['context']['environment']);
  }

  public function testDeniedEntityNeverReachesTheApi(): void {
    try {
      (new Get())('PaymentProcessor');
      $this->fail('Expected refusal');
    }
    catch (ToolCallException $e) {
      $this->assertStringContainsString('not available through CiviMCP', $e->getMessage());
    }
    $this->assertSame([], \CiviStub::$apiCalls);
  }

  public function testGroupByRequiresSelect(): void {
    $this->expectException(ToolCallException::class);
    $this->expectExceptionMessage('When using groupBy');
    (new Get())('Membership', [], [], [], 25, 0, ['status_id']);
  }

  public function testGroupByAndHavingArePassedThrough(): void {
    (new Get())('Membership', ['status_id:label', 'COUNT(id) AS total'], [], ['total' => 'DESC'], 25, 0, ['status_id'], [['total', '>', 5]]);
    $params = $this->lastCall()[2];
    $this->assertSame(['status_id'], $params['groupBy']);
    $this->assertSame([['total', '>', 5]], $params['having']);
  }

  public function testBadSortDirectionIsRefused(): void {
    $this->expectException(ToolCallException::class);
    (new Get())('Membership', ['id'], [], ['id' => 'SIDEWAYS']);
  }

  public function testPermissionDeniedBecomesAClearMessageAndIsLogged(): void {
    \CiviStub::$apiHandler = fn() => throw new \Civi\API\Exception\UnauthorizedException('Authorization failed: CiviCRM APIv4 (Membership::get)');
    try {
      (new Get())('Membership', ['id']);
      $this->fail('Expected permission denied');
    }
    catch (ToolCallException $e) {
      $this->assertStringStartsWith('Permission denied', $e->getMessage());
    }
    $entry = end(\CiviStub::$log)[1];
    $this->assertSame('get', $entry['tool']);
    $this->assertSame('denied', $entry['outcome']);
  }

  public function testLargeResultsStopEarlyWithANote(): void {
    $big = str_repeat('x', 5000);
    \CiviStub::$apiHandler = fn() => new \StubResult(array_map(fn($i) => ['id' => $i, 'notes' => $big], range(1, 100)), 100);
    $result = (new Get())('Membership', ['id', 'notes'], [], [], 100);
    $this->assertLessThan(100, $result['returned']);
    $this->assertArrayHasKey('note', $result);
    $this->assertTrue($result['has_more']);
  }

  public function testEveryCallIsAuditLogged(): void {
    (new Get())('Membership', ['id'], [['id', '=', 1]]);
    $entry = end(\CiviStub::$log)[1];
    $this->assertSame('get', $entry['tool']);
    $this->assertSame('ok', $entry['outcome']);
    $this->assertSame(2, $entry['contact_id']);
    $this->assertSame('Membership', $entry['args']['entity']);
  }

}
