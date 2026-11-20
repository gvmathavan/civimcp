<?php

namespace Civi\Mcp\Safety;

/**
 * Removes secrets from anything a tool is about to return.
 *
 * Runs on every tool result, so a tool can never leak an API key, password
 * hash or checksum to Claude even by mistake.
 */
final class Redactor {

  public const REDACTED = '[redacted]';

  /**
   * Field names (case-insensitive) whose values are never returned.
   */
  private const SECRET_KEYS = [
    'api_key',
    'hash',
    'password',
    'pass',
    'secret',
    'token',
    'access_token',
    'refresh_token',
    'checksum',
    'cs',
    'site_key',
    'credit_card_number',
    'cvv2',
    'bank_account_number',
    'signature',
  ];

  public static function redact(mixed $value): mixed {
    if (is_array($value)) {
      $out = [];
      foreach ($value as $key => $item) {
        $out[$key] = (is_string($key) && self::isSecretKey($key)) ? self::REDACTED : self::redact($item);
      }
      return $out;
    }
    if (is_object($value)) {
      return self::redact(get_object_vars($value));
    }
    return $value;
  }

  public static function isSecretKey(string $key): bool {
    $key = strtolower($key);
    // Also catch joined fields such as "contact_id.api_key".
    $last = substr(strrchr('.' . $key, '.'), 1);
    return in_array($last, self::SECRET_KEYS, TRUE);
  }

}
