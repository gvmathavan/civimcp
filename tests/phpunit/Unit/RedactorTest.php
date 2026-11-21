<?php

namespace Civi\Mcp\Tests\Unit;

use Civi\Mcp\Safety\Redactor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RedactorTest extends TestCase {

  public static function secretKeys(): array {
    return [
      ['api_key'], ['hash'], ['password'], ['checksum'], ['access_token'], ['site_key'],
      ['credit_card_number'], ['bank_account_number'], ['signature'],
      ['API_KEY'], ['Password'],
      ['contact_id.api_key'], ['contact_id.employer_id.hash'],
    ];
  }

  #[DataProvider('secretKeys')]
  public function testSecretKeysAreRedacted(string $key): void {
    $this->assertSame(Redactor::REDACTED, Redactor::redact([$key => 'secret-value'])[$key]);
  }

  public function testOrdinaryFieldsAreKept(): void {
    $row = ['id' => 5, 'display_name' => 'Jane', 'email_primary.email' => 'jane@example.org', 'hash_note' => 'kept', 'passport_office' => 'kept'];
    $this->assertSame($row, Redactor::redact($row));
  }

  public function testNestedArraysAndObjectsAreRedacted(): void {
    $result = Redactor::redact([
      'values' => [
        ['id' => 1, 'api_key' => 'abc', 'nested' => ['token' => 'xyz', 'ok' => TRUE]],
      ],
      'object' => (object) ['password' => 'p', 'name' => 'n'],
    ]);
    $this->assertSame(Redactor::REDACTED, $result['values'][0]['api_key']);
    $this->assertSame(Redactor::REDACTED, $result['values'][0]['nested']['token']);
    $this->assertTrue($result['values'][0]['nested']['ok']);
    $this->assertSame(['password' => Redactor::REDACTED, 'name' => 'n'], $result['object']);
  }

  public function testScalarsPassThrough(): void {
    $this->assertSame('text', Redactor::redact('text'));
    $this->assertSame(42, Redactor::redact(42));
    $this->assertNull(Redactor::redact(NULL));
  }

}
