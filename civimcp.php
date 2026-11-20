<?php

require_once 'civimcp.civix.php';

use CRM_Civimcp_ExtensionUtil as E;

/**
 * Implements hook_civicrm_config().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_config/
 */
function civimcp_civicrm_config(&$config): void {
  _civimcp_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_install().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_install
 */
function civimcp_civicrm_install(): void {
  _civimcp_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_enable
 */
function civimcp_civicrm_enable(): void {
  _civimcp_civix_civicrm_enable();
}
