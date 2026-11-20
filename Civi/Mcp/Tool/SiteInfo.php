<?php

namespace Civi\Mcp\Tool;

/**
 * site_info: what this CiviCRM site is and what the current user may do.
 */
final class SiteInfo extends AbstractTool {

  public const NAME = 'site_info';
  public const TITLE = 'Site information';

  /**
   * Permissions worth telling Claude about, so it knows the user's limits up front.
   */
  private const KEY_PERMISSIONS = [
    'access CiviCRM',
    'administer CiviCRM',
    'view all contacts',
    'edit all contacts',
    'access CiviContribute',
    'access CiviMember',
    'access CiviEvent',
    'access CiviReport',
  ];

  public function description(): string {
    return 'Describe this CiviCRM site: CiviCRM, CMS and PHP versions, site name and URL, environment (Production/Staging/Development), enabled components, number of enabled extensions, and the current user with their key permissions. Call this first when you need to know what the site is or what you are allowed to do.';
  }

  public function inputSchema(): array {
    return self::noArguments();
  }

  /**
   * @return array<string, mixed>
   */
  public function __invoke(): array {
    return $this->execute([], function (): array {
      $permissions = [];
      foreach (self::KEY_PERMISSIONS as $permission) {
        $permissions[$permission] = \CRM_Core_Permission::check($permission);
      }

      $extensions = \CRM_Extension_System::singleton()->getManager()->getStatuses();
      $enabledExtensions = count(array_filter(
        $extensions,
        static fn($status) => $status === \CRM_Extension_Manager::STATUS_INSTALLED
      ));

      return [
        'civicrm_version' => \CRM_Utils_System::version(),
        'cms' => defined('CIVICRM_UF') ? CIVICRM_UF : 'unknown',
        'cms_version' => function_exists('get_bloginfo') ? get_bloginfo('version') : NULL,
        'php_version' => PHP_VERSION,
        'components' => array_values((array) \Civi::settings()->get('enable_components')),
        'enabled_extensions' => $enabledExtensions,
        'current_user' => [
          'contact_id' => \CRM_Core_Session::getLoggedInContactID(),
          'display_name' => \CRM_Core_Session::singleton()->getLoggedInContactDisplayName(),
          'permissions' => $permissions,
        ],
        'civimcp_version' => \Civi\Mcp\ServerFactory::VERSION,
      ];
    });
  }

}
