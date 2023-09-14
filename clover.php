<?php

require_once 'clover.civix.php';
use CRM_Clover_ExtensionUtil as E;

/**
 * Implements hook_civicrm_links().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_links
 */
function clover_civicrm_links($op, $objectName, $objectId, &$links, &$mask, &$values) {

  // Adds a refund link to each payment that is refundable or voidable
  if ($objectName == 'Payment' && $op == 'Payment.edit.action') {

    // First check the contribution status
    if (!empty($values['contribution_id'])) {
      $contributions = \Civi\Api4\Contribution::get(TRUE)
        ->addSelect('contribution_status_id:name')
        ->addWhere('id', '=', $values['contribution_id'])
        ->setLimit(1)
        ->execute();
      foreach ($contributions as $contribution) {
        if (in_array($contribution['contribution_status_id:name'], ['Completed', 'Partially Paid', 'Pending refund']) && !empty($values['id'])) {
          $financialTrxn = CRM_Core_Payment_Clover::getFinancialTrxn($values['id']);
          // Only allow refunding for transactions:
          //  - with status completed
          //  - from a clover processor
          //  - with a transaction id
          //  - with a total amount greater than 0
          if ($financialTrxn['status_id:name'] == 'Completed'
            && $financialTrxn['payment_processor_id.payment_processor_type_id:name'] == 'Clover'
            && !empty($financialTrxn['trxn_id'])
            && $financialTrxn['total_amount'] > 0
            && $financialTrxn['action']
          ) {
            $links[] = [
              'name' => ucfirst($financialTrxn['action']) . ' Payment',
              'icon' => 'fa-undo',
              'url' => 'civicrm/clover/refund',
              'class' => 'medium-popup',
              'qs' => 'reset=1&payment_id=%%id%%&contribution_id=%%contribution_id%%',
              'title' =>  ucfirst($financialTrxn['action']) . ' Payment',
              'bit' => 2,
            ];
          }
        }
      }
    }
  }
}

function clover_apiv3helper($entity, $action, $params) {
  try {
    $result = civicrm_api3($entity, $action, $params);
  }
  catch (CiviCRM_API3_Exception $e) {
    $error = $e->getMessage();
    CRM_Core_Error::debug_log_message(E::ts('clover API v3 Error %1', [
      'domain' => E::LONG_NAME,
      1 => $error,
    ]));
  }
  return $result;
}

/**
 * Implements hook_civicrm_config().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_config/
 */
function clover_civicrm_config(&$config): void {
  _clover_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_install().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_install
 */
function clover_civicrm_install(): void {
  _clover_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_enable
 */
function clover_civicrm_enable(): void {
  _clover_civix_civicrm_enable();
}

// --- Functions below this ship commented out. Uncomment as required. ---

/**
 * Implements hook_civicrm_preProcess().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_preProcess
 */
//function clover_civicrm_preProcess($formName, &$form): void {
//
//}

/**
 * Implements hook_civicrm_navigationMenu().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_navigationMenu
 */
//function clover_civicrm_navigationMenu(&$menu): void {
//  _clover_civix_insert_navigation_menu($menu, 'Mailings', [
//    'label' => E::ts('New subliminal message'),
//    'name' => 'mailing_subliminal_message',
//    'url' => 'civicrm/mailing/subliminal',
//    'permission' => 'access CiviMail',
//    'operator' => 'OR',
//    'separator' => 0,
//  ]);
//  _clover_civix_navigationMenu($menu);
//}
