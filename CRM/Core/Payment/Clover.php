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
        $error[] = E::ts('The "%1" is not set in the Clover Payment Processor settings.', array(1 => $label));
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
  public function getPaymentFormFields() {
    //stop cc fields, clover iframe will provide
    //@TODO test if we need cvv2 and exp date for the api call before we add
    //MAYBE giving them to the token is enough
    return [
      //'cvv2',
      //'credit_card_exp_date'
    ];
  }

  /**
   * Set default values when loading the (payment) form
   *
   * @param \CRM_Core_Form $form
   */
  public function buildForm(&$form) {
    $merchantUrl = $form->_paymentProcessor['url_site'];
    $params = [
      'useexpiry=true',
      'usecvv=true',
      'tokenizewheninactive=true',
      'inactivityto=500',
      'tokenpropname=clovertoken',
      'invalidcreditcardevent=true',
      'invalidcvvevent=true',
      'invalidexpiryevent=true',
      'cardnumbernumericonly=true',
    ];
    $sendParams = '?' . implode('&', $params);
    $form->assign('merchantUrl', $merchantUrl . $sendParams);
    //add clover iframe
    $templatePath = \Civi::resources()->getPath(E::LONG_NAME, 'templates');
    CRM_Core_Region::instance('billing-block')->add([
      'template' => $templatePath . 'clover_iframe.tpl'
    ]);
    // Don't use \Civi::resources()->addScriptFile etc as they often don't work on AJAX loaded forms (eg. participant backend registration)
    //add our catcher for the payment token from clover
    CRM_Core_Region::instance('billing-block')->add([
      'scriptUrl' => \Civi::resources()->getUrl(E::LONG_NAME, 'js/civicrm_clover.js'),
    ]);
    //add our field to catch the token
    $form->add('hidden', 'clover_token');
  }

  /**
   * Process payment
   *
   * @param array $params
   *   Assoc array of input parameters for this transaction.
   *
   * @param string $component
   *
   * @return array
   *   Result array
   *
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   */
  public function doPayment(&$params, $component = 'contribute') {
    //if amount is zero skip clover and record a completed payment
    if ($params['amount'] == 0) {
      $completedStatusId = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Completed');
      $params['payment_status_id'] = $params['contribution_status_id'] = $completedStatusId;
      return $params;
    }
    //generate an invoice # if we don't get one
    if (empty($params['invoice_number'])) {
      $params['invoice_number'] = rand(1, 9999999);
    }

    // Make sure using us dollars as the currency
    //@TODO check if clover actually supports other currencies
    $currency = self::checkCurrencyIsUSD($params);

    // Get proper entry URL for returning on error.
    if (!(array_key_exists('qfKey', $params))) {
      // Probably not called from a civicrm form (e.g. webform) -
      // will return error object to original api caller.
      $params['clover_error_url'] = NULL;
    }
    else {
      $qfKey = $params['qfKey'];
      $parsed_url = parse_url($params['entryURL']);
      $url_path = substr($parsed_url['path'], 1);
      $params['clover_error_url'] = CRM_Utils_System::url($url_path,
      $parsed_url['query'] . '&_qf_Main_display=1&qfKey={$qfKey}', FALSE, NULL, FALSE);
    }

    //IF currency is not USD throw error and quit
    //@TODO check if clover actually supports other currencies
    if ($currency == FALSE) {
      $errorMessage = self::handleErrorNotification('Clover only works with USD, Contribution not processed', $params['clover_error_url']);
      throw new \Civi\Payment\Exception\PaymentProcessorException(' Failed to create Clover Charge ' . $errorMessage);
    }

    // Get clover credentials ($params come from a form)
    if (!empty($params['payment_processor_id'])) {
      $cloverCreds = CRM_Core_Payment_Clover::getPaymentProcessorSettings($params['payment_processor_id']);
    }

    // Get clover credentials ($params come from a Contribution.transact api call)
    if (!empty($params['payment_processor'])) {
      $cloverCreds = CRM_Core_Payment_Clover::getPaymentProcessorSettings($params['payment_processor']);
    }

    // Throw an error if no credentials found
    if (empty($cloverCreds)) {
      $errorMessage = self::handleErrorNotification('No valid Clover payment processor credentials found', $params['clover_error_url']);
      throw new \Civi\Payment\Exception\PaymentProcessorException('Failed to create Clover Charge: ' . $errorMessage);
    }

    if (!empty($params['clover_token']) && $params['clover_token'] != 'Authorization token') {
      //Use payment token to run the transaction
      //$token = $params['payment_token'];

      //clover collects amount information in currency MINOR units
      //for now we assume USD
      $params['amount'] = $params['amount'] * 100;

      // Make transaction
      $client = new CRM_Clover_Client($cloverCreds);
      $client->authorizeAndCapture($params);

      $cloverResponse = $client->response->resptext;

    }
    else {
      $errorMessage = self::handleErrorNotification('No Payment Token', $params['clover_error_url']);
      throw new \Civi\Payment\Exception\PaymentProcessorException('Failed to create Clover Charge: ' . $errorMessage);
    }
    $params = self::processTransaction($cloverResponse, $params, $cloverCreds);
    return $params;
  }

  /**
   * After making the Clover API call, deal with the response
   * @param  object $makeTransaction response from clover
   * @param  array $params           payment params
   * @param  array $tsysCreds        clover Credentials
   * @return array $params           payment params updated to inculde relevant info from clover
   */
  public static function processTransaction($cloverResponse, &$params, $cloverCreds) {
    $failedStatusId = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Failed');

    // If transaction approved
    //@TODO update with actual clover positive condition
    if ($cloverResponse == 'Approval') {
      //$params = self::processResponseFromTsys($params, $makeTransaction->Body->SaleResponse->SaleResult, 'sale');
      // Successful contribution update the status and get the rest of the info from Response
      $completedStatusId = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Completed');
      $params['payment_status_id'] = $completedStatusId;

      // Check if the token has been saved to the database
      //@TODO i'm confused about this statement from TSYS. the token should still be in $params['clover_token']
      //maybe that was just a TSYS thing
      //$previousTransactionToken = (string) $makeTransaction->Body->SaleResponse->SaleResult->Token;

      //@TODO need to add the checkForSavedVaultToken
      $savedTokens = self::checkForSavedVaultToken($params['payment_processor_id'], $params['clover_token']);

      // If transaction is recurring AND there is not an existing vault token saved, create a boarded card and save it

      //@TODO discuss... I don't think this should be here.
      //I think this should be at doPayment, because we need to pass different type of authorization
      //to have properly authorized for recurring transactions.
      if (CRM_Utils_Array::value('is_recur', $params)
      && $savedTokens == 0
      && !empty($params['contributionRecurID'])) {
        $paymentTokenId = CRM_Tsys_Recur::boardCard(
          $params['contributionRecurID'],
          $previousTransactionToken,
          $tsysCreds,
          $params['contactID'],
          $params['payment_processor_id']
        );
        $params['payment_token_id'] = $paymentTokenId;
      }
      return $params;
    }
    // If transaction fails
    else {
      $params['contribution_status_id'] = $failedStatusId;
      $errorMessage = 'Clover rejected card ';
      if (!empty($params['error_message'])) {
        $errorMessage .= $params['error_message'];
      }
      //@TODO what do we do with "Partial Approval"? I'm not even really sure what that means or when it happens,
      //but it is a possible response.
      if ($cloverResponse != 'Approved') {
        $errorMessage .= $cloverResponse;
      }
      // If its a unit test return the params
      if (isset($params['unit_test']) && $params['unit_test'] == 1) {
        return $params;
      }
      $errorMessage = self::handleErrorNotification($errorMessage, NULL, $makeTransaction);
      throw new \Civi\Payment\Exception\PaymentProcessorException('Failed to create Clover Charge: ' . $errorMessage);
    }
  }

  /**
   * Check that the Currency is USD
   * @param array $params  contribution params
   * @return boolean       if Currency is USD
   */
  public function checkCurrencyIsUSD(&$params) {
    // when coming from a contribution form
    if (!empty($params['currencyID']) && $params['currencyID'] == 'USD') {
      return TRUE;
    }

    // when coming from a contribution.transact api call
    if (!empty($params['currency']) && $params['currency'] == 'USD') {
      return TRUE;
    }

    $currency = FALSE;
    try {
      $defaultCurrency = civicrm_api3('Setting', 'get', [
        'sequential' => 1,
        'return' => ['defaultCurrency'],
        'defaultCurrency' => 'USD',
      ]);
    }
    catch (CiviCRM_API3_Exception $e) {
      $error = $e->getMessage();
      CRM_Core_Error::debug_log_message(E::ts('API Error %1', array(
        'domain' => 'com.aghstrategies.tsys',
        1 => $error,
      )));
    }
    // look up the default currency, if its usd set this transaction to use us dollars
    if (!empty($defaultCurrency['values'][0]['defaultCurrency'])
    && $defaultCurrency['values'][0]['defaultCurrency'] == 'USD'
    && empty($params['currencyID'])
    && empty($params['currency'])) {
      $currency = TRUE;
    }
    return $currency;
  }

  /**
   * Handle an error and notify the user
   * @param  string $errorMessage Error Message to be displayed to user
   * @param  string $bounceURL    Bounce URL
   * @param  string $makeTransaction response from Clover
   * @return string  Error Message (or statusbounce if URL is specified)
   */
  public static function handleErrorNotification($errorMessage, $bounceURL = NULL, $makeTransaction = []) {
    Civi::log()->debug('Clover Payment Error: ' . $errorMessage);
    if (!empty($makeTransaction)) {
      CRM_Core_Error::debug_var('makeTransaction', $makeTransaction);
    }
    if ($bounceURL) {
      CRM_Core_Error::statusBounce($errorMessage, $bounceURL, 'Payment Error');
    }
    return $errorMessage;
  }

  /**
   * Given a payment processor id, return details
   *
   * @param int $paymentProcessorId
   * @return array
   */
  public static function getPaymentProcessorSettings($paymentProcessorId) {
    $fields = ['signature', 'user_name', 'password', 'url_api', 'is_test'];
    try {
      $paymentProcessorDetails = civicrm_api4('PaymentProcessor', 'get', [
        'select' => $fields,
        'where' => [
          ['id', '=', $paymentProcessorId],
        ],
        'checkPermissions' => FALSE,
      ]);
    }
    catch (API_Exception $e) {
      $error = $e->getMessage();
      CRM_Core_Error::debug_log_message(E::ts('API Error %1', array(
        'domain' => 'clover',
        1 => $error,
      )));
      return [];
    }

    // Throw an error if credential not found
    foreach ($fields as $key => $field) {
      if (!isset($paymentProcessorDetails[0][$field])) {
        CRM_Core_Error::statusBounce(E::ts('Could not find valid Clover Payment Processor credentials'));
        Civi::log()->debug('Clover Credential $field not found.');
      }
    }
    return $paymentProcessorDetails[0];
  }

}
