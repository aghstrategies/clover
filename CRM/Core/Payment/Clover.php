<?php

use CRM_Clover_ExtensionUtil as E;

/**
 * Payment Processor class for Clover
 */
class CRM_Core_Payment_Clover extends CRM_Core_Payment {

  /**
   * We only need one instance of this object. So we use the singleton
   * pattern and cache the instance in this variable
   *
   * @var object
   * @static
   */
  static private $_singleton = NULL;

  /**
   * Mode of operation: live or test.
   *
   * @var object
   */
  protected $_mode = NULL;

  /**
   * TRUE if we are dealing with a live transaction
   *
   * @var boolean
   */
  private $_islive = FALSE;

  /**
   * can use the smartdebit processor on the backend
   * @return bool
   */
  public function supportsBackOffice() {
    return TRUE;
  }

  public function supportsRecurring() {
    return TRUE;
  }

  /**
   * can edit smartdebit recurring contributions
   * @return bool
   */
  public function supportsEditRecurringContribution() {
    return TRUE;
  }

  /**
   * can edit amount for recurring contributions
   * @return bool
   */
  public function changeSubscriptionAmount() {
    return TRUE;
  }

  /**
   * Does this payment processor support refund?
   *
   * @return bool
   */
  public function supportsRefund() {
    return TRUE;
  }


  /**
   * Constructor
   *
   * @param string $mode
   *   The mode of operation: live or test.
   *
   * @param object $paymentProcessor
   *
   * @return void
   */
  public function __construct($mode, &$paymentProcessor) {
    $this->_mode = $mode;
    $this->_islive = ($mode == 'live') ? 1 : 0;
    $this->_paymentProcessor = $paymentProcessor;
    $this->_processorName = E::ts('Clover');
  }

  /**
   * This function checks to see if we have the right config values.
   *
   * @return string
   *   The error message if any.
   *
   * @public
   */
  public function checkConfig() {
    $error = array();
    $credFields = array(
      'user_name' => 'Merchant Name',
      'password' => 'Web API Key',
      'signature' => 'Merchant Site Key',
      //'subject' => 'Merchant Site ID',
    );
    foreach ($credFields as $name => $label) {
      if (empty($this->_paymentProcessor[$name])) {
        $error[] = E::ts("The '%1' is not set in the Clover Payment Processor settings.", array(1 => $label));
      }
    }
    if (!empty($error)) {
      return implode('<p>', $error);
    }
    else {
      return NULL;
    }
  }

  /**
  * Get array of fields that should be displayed on the payment form for credit cards.
  *
  * @return array
  */
  protected function getCreditCardFormFields() {
    //we should only need payment token from the buildForm. Other fields come via iframe
    return [
    ];
    /*return array(
      'credit_card_type',
      'credit_card_number',
      'cvv2',
      'credit_card_exp_date',
      // ADD PAYMENT TOKEN
      'payment_token',
    );*/
  }

  /**
   * Set default values when loading the (payment) form
   *
   * @param \CRM_Core_Form $form
   */
  public function buildForm(&$form) {
    //@TODO change this - testing with static value
    $merchantUrl = 'https://boltgw-uat.cardconnect.com/itoke/ajax-tokenizer.html';
    $params = [
      'useexpiry=true',
      'usecvv=true',
      'tokenizewheninactive=true',
      'inactivityto=500'
    ];
    $sendParams = '?' . implode('&', $params);
    $form->assign('merchantUrl', $merchantUrl . $sendParams);
    //add clover iframe
    $templatePath = \Civi::resources()->getPath(E::LONG_NAME, "templates/clover_iframe.tpl");
    var_dump($templatePath);
    //die();
    CRM_Core_Region::instance('billing-block')->add([
      'template' => "{$templatePath}"
    ]);
    /*$form->add(
      'static',
      'clover_iframe',

    // );*
    //add hidden field to receive payment token
    $form->add(
      'hidden',
      'clover_payment_token',
    );
    //add our catcher for the payment token
    CRM_Core_Region::instance('billing-block')->add([
      'scriptUrl' => \Civi::resources()->getUrl(E::LONG_NAME, "js/civicrm_clover.js"),
    ]);
    // Don't use \Civi::resources()->addScriptFile etc as they often don't work on AJAX loaded forms (eg. participant backend registration)
    /*\Civi::resources()->addVars('tsys', [
      'allApiKeys' => CRM_Core_Payment_Tsys::getAllTsysPaymentProcessors(),
      'pp' => CRM_Utils_Array::value('id', $form->_paymentProcessor),
    ]);
    CRM_Core_Region::instance('billing-block')->add([
      'scriptUrl' => \Civi::resources()->getUrl(E::LONG_NAME, "js/civicrm_tsys.js"),
    ]);*/
  }

}
