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

  /**
   * Parts of a key name that always mean a secret, e.g. "smtpPassword",
   * "recaptchaPrivateKey", "mapAPIKey", "client_secret".
   */
  private const SECRET_FRAGMENTS = ['password', 'passwd', 'secret', 'privatekey', 'private_key', 'apikey', 'api_key'];

  public static function isSecretKey(string $key): bool {
    $key = strtolower($key);
    // Also catch joined fields such as "contact_id.api_key".
    $last = substr(strrchr('.' . $key, '.'), 1);
    if (in_array($last, self::SECRET_KEYS, TRUE) || str_ends_with($last, 'token')) {
      return TRUE;
    }
    foreach (self::SECRET_FRAGMENTS as $fragment) {
      if (str_contains($last, $fragment)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Redact secrets and personal contact details from free text such as log
   * lines: email addresses, "key=value" pairs for secret-looking keys,
   * bearer tokens and checksums in URLs.
   */
  public static function redactText(string $text): string {
    $patterns = [
      // Email addresses.
      '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/' => '[email]',
      // Authorization headers.
      '/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=\-]{8,}/i' => '$1 [redacted]',
      // key=value, key: value, "key":"value" for secret-looking keys.
      '/(["\']?\b[\w.\-]*(?:api_?key|password|passwd|secret|token|site_?key|checksum|private_?key|\bcs\b)[\w.\-]*["\']?\s*(?:=|:|=>)\s*)(["\']?)[^\s"\'&,;}\]]+\2/i' => '$1$2[redacted]$2',
    ];
    return (string) preg_replace(array_keys($patterns), array_values($patterns), $text);
  }

}
