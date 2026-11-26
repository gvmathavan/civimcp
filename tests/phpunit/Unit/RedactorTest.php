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
      ['smtpPassword'], ['recaptchaPrivateKey'], ['mapAPIKey'], ['geoAPIKey'], ['client_secret'], ['refresh_token'],
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

  public function testRedactTextHidesEmailsKeysAndTokens(): void {
    $text = 'login jane.doe@example.org api_key=abc123 password: hunter2 "site_key":"s3cr3t" /civicrm/x?cs=9f8e&id=5 Authorization: Bearer eyJhbGciOi.abc';
    $out = Redactor::redactText($text);
    foreach (['jane.doe@example.org', 'abc123', 'hunter2', 's3cr3t', '9f8e', 'eyJhbGciOi'] as $secret) {
      $this->assertStringNotContainsString($secret, $out);
    }
    $this->assertStringContainsString('id=5', $out);
    $this->assertStringContainsString('[email]', $out);
  }

  public function testRedactTextLeavesOrdinaryTextAlone(): void {
    $text = 'Nov 26 10:00:00  [info] Processed 25 memberships for contact 42 in 3s';
    $this->assertSame($text, Redactor::redactText($text));
  }

  public function testScalarsPassThrough(): void {
    $this->assertSame('text', Redactor::redact('text'));
    $this->assertSame(42, Redactor::redact(42));
    $this->assertNull(Redactor::redact(NULL));
  }

}
