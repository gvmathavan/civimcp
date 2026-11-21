<?php

namespace Civi\Mcp\Tests\Unit;

use Civi\Mcp\Safety\EntityPolicy;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EntityPolicyTest extends TestCase {

  protected function setUp(): void {
    \CiviStub::reset();
  }

  public function testDeniedEntitiesAreHiddenFromTheList(): void {
    $names = array_keys(EntityPolicy::entities());
    $this->assertContains('Contact', $names);
    $this->assertContains('Membership', $names);
    $this->assertNotContains('Setting', $names);
    $this->assertNotContains('PaymentProcessor', $names);
  }

  public static function deniedEntities(): array {
    return [['Setting'], ['PaymentProcessor'], ['PaymentToken'], ['OAuthClient'], ['OAuthSysToken'], ['OAuthContactToken'], ['MailSettings'], ['System'], ['Log'], ['Session']];
  }

  #[DataProvider('deniedEntities')]
  public function testDeniedEntitiesAreRefused(string $entity): void {
    $this->expectException(ToolCallException::class);
    $this->expectExceptionMessage('not available through CiviMCP');
    EntityPolicy::assertReadable($entity);
  }

  public static function invalidNames(): array {
    return [[''], ['Contact; DROP TABLE'], ['../Contact'], ['1Contact'], ['Contact.get']];
  }

  #[DataProvider('invalidNames')]
  public function testInvalidNamesAreRefused(string $entity): void {
    $this->expectException(ToolCallException::class);
    $this->expectExceptionMessage('not a valid entity name');
    EntityPolicy::assertReadable($entity);
  }

  public function testUnknownEntityIsRefusedWithAHint(): void {
    $this->expectException(ToolCallException::class);
    $this->expectExceptionMessage('list_entities');
    EntityPolicy::assertReadable('NoSuchThing');
  }

  public function testKnownEntityIsAllowed(): void {
    EntityPolicy::assertReadable('Membership');
    $this->addToAssertionCount(1);
  }

}
