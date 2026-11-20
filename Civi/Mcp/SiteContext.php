<?php

namespace Civi\Mcp;

/**
 * Describes which site, environment and user a result came from.
 *
 * Added to every tool result so Claude (and the person reading) always knows
 * whether they are looking at Production or Staging.
 */
final class SiteContext {

  /**
   * @return array{site: string, environment: string, url: string, user: string}
   */
  public static function get(): array {
    $session = \CRM_Core_Session::singleton();
    return [
      'site' => self::siteName(),
      'environment' => self::environment(),
      'url' => defined('CIVICRM_UF_BASEURL') ? rtrim(CIVICRM_UF_BASEURL, '/') : '',
      'user' => $session->getLoggedInContactDisplayName(),
    ];
  }

  /**
   * One-line label, e.g. "wpcrm6 (Development)".
   */
  public static function label(): string {
    return sprintf('%s (%s)', self::siteName(), self::environment());
  }

  /**
   * CiviCRM's own environment setting: Production, Staging or Development.
   */
  public static function environment(): string {
    return (string) (\Civi::settings()->get('environment') ?: 'Production');
  }

  private static function siteName(): string {
    try {
      $domain = \CRM_Core_BAO_Domain::getDomain();
      return (string) ($domain->name ?? 'CiviCRM');
    }
    catch (\Throwable $e) {
      return 'CiviCRM';
    }
  }

}
