<?php

use CRM_Clover_ExtensionUtil as E;
use Brick\Money\Money;
use Brick\Money\Context\DefaultContext;
use Brick\Math\RoundingMode;

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
    $error = [];
    $credFields = [
      'user_name' => 'Merchant Name',
      'password' => 'Web API Key',
      'signature' => 'Merchant Site Key',
      //'subject' => 'Merchant Site ID',
    ];
    foreach ($credFields as $name => $label) {
      if (empty($this->_paymentProcessor[$name])) {
        $error[] = E::ts('The "%1" is not set in the Clover Payment Processor settings.', [1 => $label]);
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
    return [
      'credit_card_type'
    ];
  }

  /**
   * Set default values when loading the (payment) form
   *
   * @param \CRM_Core_Form $form
   */
  public function buildForm(&$form) {
    $merchantUrl = $form->_paymentProcessor['url_site'];
    //@TODO we should try to dynamically get css from the site
    //if we can match the site theme, it looks less jank and sketchy to a user
    //they only have very basic html available so fonts will be tough
    $css = '
      #tokenform {
        font-family:Source Sans Pro, Helvetica, sans serif;
        font-size: 18px;
      }
      input {
        margin: 5px;
        height: 1.8em;
        border-radius: 3px;
      }
      #ccnumfield {
        width: 80%;
      }
      #ccexpirymonth {
        width: 50%;
      }
      .error {
        color:red;
        border-color:red;
      }
      select {
        margin: 5px;
        height: 1.8em;
        border-radius: 3px;
      }';
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
      'css=' . urlencode($css),
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
    $currency = self::checkCurrencyIsUSD($params);

    // IF currency is not USD throw error and quit
    if ($currency == FALSE) {
      $errorMessage = self::handleErrorNotification('Clover only works with USD, Contribution not processed', $params['clover_error_url']);
      throw new \Civi\Payment\Exception\PaymentProcessorException(' Failed to create Clover Charge ' . $errorMessage);
    }

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

    // Get clover credentials ($params come from a form)
    $cloverCreds = $this->getCloverCreds($params);
    $client = new CRM_Clover_Client($cloverCreds);

    if (!empty($params['clover_token']) && $params['clover_token'] != 'Authorization token') {

      //clover collects amount information in currency MINOR units
      $params['amount'] = Money::of($params['amount'], $params['currency'], new DefaultContext(), RoundingMode::CEILING)->getAmount();

      //default for a one-off user-initiated transaction card-not-present
      $params['ecomind'] = 'E';
      $params['capture'] = 'y';
      // If transaction is recurring AND there is not an existing vault token saved, create a boarded card and save it
      // authorize a recurring transaction
      // if it gets authorized, save the payment token for next time
      if (CRM_Utils_Array::value('is_recur', $params)
      && !empty($params['contributionRecurID'])) {
        $savedTokens = self::checkForSavedToken($params['payment_processor_id'], $params['clover_token'], $params['contributionRecurID']);
        //@TODO here modify the params array
        //to add/update required params for a recurring transaction

        //Required for initial and subsequent transactions using stored cardholder payment information.
        //The cof parameter specifies whether the transaction is initiated by the customer or merchant.
        //C = customer, M = merchant
        $params['cof'] = 'C';
        //Required for transactions using stored cardholder payment information.
        //For a merchant-initiated transaction (MIT), the cofscheduled parameter specifies whether the transaction is a one-time payment
        //or a scheduled recurring payment.
        //y = merchant initiated recurring transaction, n = one-time cardholder scheduled transaction
        $params['cofscheduled'] = 'N';

      if ($savedTokens == 0) {

          //if there isn't one, create payment token and update recurring contribution entity with token
          $recurringToken = $client->authorize($params);

          //@TODO log this response somewhere
          $recurringAuthResponse = $client->response->resptext;
          $params['payment_token_id'] = $paymentTokenId;
          $params['credit_card_exp_date']['M'] = substr($client->response->expiry, 0, 2);
          $params['credit_card_exp_date']['Y'] = "20" . substr($client->response->expiry, 2, 4);
          $params['expiry_date'] = CRM_Utils_Date::format($params['credit_card_exp_date']);
          $paymentTokenId = self::createPaymentToken($params);
        }
      }

      // Make transaction
      $client->authorize($params);
      $cloverResponse = $client->response;
    }
    else {
      $errorMessage = self::handleErrorNotification('No Payment Token', $params['clover_error_url']);
      throw new \Civi\Payment\Exception\PaymentProcessorException('Failed to create Clover Charge: ' . $errorMessage);
    }
    $params = self::processTransaction($cloverResponse, $params, $cloverCreds);

    return $params;
  }

  /**
   * Submit a refund payment
   *
   * @param array $params
   *   Assoc array of input parameters for this transaction.
   *
   * @return array
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   */
  public function doRefund(&$params) {
    $requiredParams = ['trxn_id', 'amount'];

    foreach ($requiredParams as $required) {
      if (!isset($params[$required])) {
        $message = "Clover doRefund: Missing mandatory parameter: {$required}";
        Civi::log()->error($message);
        throw new PaymentProcessorException($message);
      }
    }
    $action = CRM_Core_Payment_Clover::refundOrVoid($params);

    // Get clover credentials
    $cloverCreds = $this->getCloverCreds($params);
    // Set up Client
    $client = new CRM_Clover_Client($cloverCreds);

    if ($action == 'void') {
      $client->void($params);
      if ($client->response->authcode == 'REVERS') {
        $refundStatus = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Completed');
        $refundStatusName = 'Completed';
      }
      else {
        $refundStatus = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Failed');
        $refundStatusName = 'Failed';
      }
    }
    elseif ($action == 'refund') {
      $client->refund($params);
      if ($client->response->respstat == 'A') {
        $refundStatus = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Completed');
        $refundStatusName = 'Completed';
      }
      else {
        $refundStatus = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Failed');
        $refundStatusName = 'Failed';
      }
    }
    else {
      $message = "Clover cannot void or refund.";
      Civi::log()->error($message);
      throw new PaymentProcessorException($message);
    }

    return [
      'charge' => $params['trxn_id'],
      'amount' => $params['amount'],
      'refund_trxn_id' => $client->response->retref,
      'refund_status_id' => $refundStatus,
      'refund_status' => $refundStatusName,
      'trxn_date' => date('Y-m-d H:i:s'),
      'fee_amount' => 0,
    ];
  }

  /**
   * Decide if payment should be refunded or voided
   *
   * @param array $params
   *   Assoc array of input parameters for this transaction.
   *
   * @return string
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   */
  public static function refundOrVoid($params) {
    $action = NULL;
    // Get clover credentials
    $cloverCreds = CRM_Core_Payment_Clover::getCloverCreds($params);
    // Set up Client
    $client = new CRM_Clover_Client($cloverCreds);
    $client->inquire($params);
    if ($client->response->voidable == 'Y') {
      $action = "void";
    }
    elseif ($client->response->refundable == 'Y') {
      $action = "refund";
    }
    return $action;
  }

  /**
   * After making the Clover API call, deal with the response
   * @param  object $makeTransaction response from clover
   * @param  array $params           payment params
   * @param  array $cloverCreds        clover Credentials
   * @return array $params           payment params updated to inculde relevant info from clover
   */
  public static function processTransaction($cloverResponse, &$params, $cloverCreds) {
    // If transaction approved
    //@TODO what do we do with "Partially Approved"? I'm not sure when this could happen but it's a valid response
    if ($cloverResponse->resptext == 'Approval') {
      // Successful contribution update the status and get the rest of the info from Response
      $completedStatusId = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Completed');
      if (isset($params['contribution_recur_id'])) {
        $params['contribution_status_id'] = $completedStatusId;
      }
      $params['payment_status_id'] = $completedStatusId;
      $params['trxn_id'] = $cloverResponse->retref;
      $params['trxn_result_code'] = $cloverResponse->resptext;
      $params['pan_truncation'] = substr($cloverResponse->account, -4);
      return $params;
    }
    // If transaction fails
    else {
      $failedStatusId = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Failed');
      $params['contribution_status_id'] = $failedStatusId;
      $errorMessage = 'Clover rejected card ';
      if (!empty($params['error_message'])) {
        $errorMessage .= $params['error_message'];
      }
      //@TODO what do we do with "Partial Approval"? I'm not even really sure what that means or when it happens,
      //but it is a possible response.
      if ($cloverResponse->resptext != 'Approved') {
        $errorMessage .= $cloverResponse->resptext;
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
   * Get Clover Credentials
   *
   * @param array $params
   *   Assoc array of input parameters for this transaction.
   *
   * @return array
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   */
  public function getCloverCreds($params) {
    $cloverCreds = [];
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
    return $cloverCreds;
  }

  /**
   * Check that the Currency is USD
   * @param array $params  contribution params
   * @return boolean       if Currency is USD
   */
  public function checkCurrencyIsUSD(&$params) {
    // when coming from a contribution form
    if (!empty($params['currencyID']) && $params['currencyID'] == 'USD') {
      $params['currency'] = 'USD';
      return TRUE;
    }

    // when coming from a contribution.transact api call
    if (!empty($params['currency']) && $params['currency'] == 'USD') {
      return TRUE;
    }

    $currency = FALSE;
    $defaultCurrency = clover_apiv3helper('Setting', 'get', [
      'sequential' => 1,
      'return' => ['defaultCurrency'],
      'defaultCurrency' => 'USD',
    ]);
    // look up the default currency, if its usd set this transaction to use us dollars
    if (!empty($defaultCurrency['values'][0]['defaultCurrency'])
    && $defaultCurrency['values'][0]['defaultCurrency'] == 'USD'
    && empty($params['currencyID'])
    && empty($params['currency'])) {
      $params['currency'] = 'USD';
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
      CRM_Core_Error::debug_log_message(E::ts('API Error %1', [
        'domain' => E::LONG_NAME,
        1 => $error,
      ]));
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

  /**
   * Get the financial trxn details
   * @param  int $paymentId payment id
   * @return array   payment info
   */
  public static function getFinancialTrxn($paymentId) {
    $trxn = [];
    // Get payment info
    $financialTrxns = \Civi\Api4\FinancialTrxn::get(TRUE)
      ->addSelect('trxn_id', 'eft.entity_id', 'order_reference', 'trxn_result_code', 'status_id:name', 'payment_processor_id', 'total_amount', 'payment_processor_id.payment_processor_type_id:name', 'currency')
      ->addJoin('EntityFinancialTrxn AS eft', 'LEFT', ['id', '=', 'eft.financial_trxn_id'], ['eft.entity_table', '=', '"civicrm_contribution"'])
      ->addWhere('id', '=', $paymentId)
      ->addWhere('is_payment', '=', TRUE)
      ->setLimit(1)
      ->execute();
    foreach ($financialTrxns as $financialTrxn) {
      $trxn = $financialTrxn;
    }
    $action = CRM_Core_Payment_Clover::refundOrVoid($trxn);
    $trxn['action'] = $action;
    return $trxn;
  }

  /**
   * Check if the vault token has ben saved to the database already
   * @param  int $paymentProcessor payment processor id
   * @param  string $token       token to check for
   * @return int   number of tokens saved to the database
   */
  public static function checkForSavedToken($paymentProcessor, $token, $recurId) {
    $paymentTokenCount = 0;
    try {
      $paymentTokens = \Civi\Api4\PaymentToken::get(FALSE)
        ->addJoin('ContributionRecur AS contribution_recur', 'INNER', ['contribution_recur.payment_token_id', '=', 'id'])
        ->addWhere('token', '=', $token)
        ->addWhere('payment_processor_id', '=', $paymentProcessor)
        ->addWhere('contribution_recur.id', '=', $recurId)
        ->execute();
    }
    catch (API_Exception $e) {
      $error = $e->getMessage();
      CRM_Core_Error::debug_log_message(E::ts('API Error %1', [
        'domain' => E::LONG_NAME,
        1 => $error,
      ]));
    }
    if ($paymentTokens) {
      $paymentTokenCount = count($paymentTokens);
    }

    return $paymentTokenCount;
  }

  /**
   * Create payment token entity for recurring contributions
   * @param array $params payment parameters used
   * @return mixed payment token ID or NULL
   */
  public static function createPaymentToken($params) {
    //create the payment token
    try {
      $paymentToken = \Civi\Api4\PaymentToken::create(FALSE)
        ->addValue('contact_id', $params['contactID'])
        ->addValue('payment_processor_id', $params['payment_processor_id'])
        ->addValue('token', $params['clover_token'])
        ->addValue('billing_first_name', $params['billing_first_name'])
        ->addValue('billing_middle_name', $params['billing_middle_name'])
        ->addValue('billing_last_name', $params['billing_last_name'])
        ->addValue('email', $params['email-5'])
        ->addValue('expiry_date', $params['expiry_date'])
        ->execute();
      $updateRecurringContribution = \Civi\Api4\ContributionRecur::update(FALSE)
        ->addValue('payment_token_id', $paymentToken[0]['id'])
        ->addValue('processor_id', $params['payment_processor_id'])
        ->addWhere('id', '=', $params['contributionRecurID'])
        ->execute();
    }
    catch (API_Exception $e) {
      $error = $e->getMessage();
      CRM_Core_Error::debug_log_message(E::ts('API Error %1', [
        'domain' => E::LONG_NAME,
        1 => $error,
      ]));

      return NULL;
    }

    return $paymentToken[0]['id'];
  }

  /**
   * For a recurring contribution, find a reasonable candidate for a template, where possible.
   */
  static function getContributionTemplate($contribution) {
    // Get the most recent contribution in this series that matches the same total_amount, if present.
    $template = [];
    $get = ['options' => ['sort' => ' id DESC', 'limit' => 1]];
    foreach (['contribution_recur_id', 'total_amount', 'is_test'] as $key) {
      if (!empty($contribution[$key])) {
        $get[$key] = $contribution[$key];
      }
    }
    // CRM_Core_Error::debug_var('Contribution', $get);
    $result = civicrm_api3('contribution', 'get', $get);
    if (!empty($result['values'])) {
      $template = reset($result['values']);
      $contribution_id = $template['id'];
      $template['original_contribution_id'] = $contribution_id;
      $template['line_items'] = [];
      $get = ['entity_table' => 'civicrm_contribution', 'entity_id' => $contribution_id];
      $result = clover_apiv3helper('LineItem', 'get', $get);
      if (!empty($result['values'])) {
        foreach ($result['values'] as $initial_line_item) {
          $line_item = [];
          foreach (['price_field_id', 'qty', 'line_total', 'unit_price', 'label', 'price_field_value_id', 'financial_type_id'] as $key) {
            $line_item[$key] = $initial_line_item[$key];
          }
          $template['line_items'][] = $line_item;
        }
      }
    }
    return $template;
  }

  //note I don't know where this came from originally. This was lifted from TSYS. There is probably a better way.
  /**
   * Database Queries to get how many Installments are done and how many are left
   * @param  string $type simple or dates -- determines which query to use
   * @return object $dao  result from database
   */
  public static function getInstallmentsDone($type = 'simple') {
    // Restrict this method of recurring contribution processing to only this payment processors.
    $args = [1 => ['Payment_Clover', 'String']];

    if ($type == 'simple') {
      $select = 'SELECT cr.id, count(c.id) AS installments_done, cr.installments
        FROM civicrm_contribution_recur cr
          INNER JOIN civicrm_contribution c ON cr.id = c.contribution_recur_id
          INNER JOIN civicrm_payment_processor pp ON cr.payment_processor_id = pp.id
          LEFT JOIN civicrm_option_group og
            ON og.name = "contribution_status"
          LEFT JOIN civicrm_option_value rs
            ON cr.contribution_status_id = rs.value
            AND rs.option_group_id = og.id
          LEFT JOIN civicrm_option_value cs
            ON c.contribution_status_id = cs.value
            AND cs.option_group_id = og.id
        WHERE
          (pp.class_name = %1)
          AND (cr.installments > 0)
          AND (rs.name IN ("In Progress"))
          AND (cs.name IN ("Completed", "Pending"))
        GROUP BY c.contribution_recur_id';
    }
    elseif ($type == 'dates') {
      $select = 'SELECT cr.id, count(c.id) AS installments_done, cr.installments, cr.end_date, NOW() as test_now
          FROM civicrm_contribution_recur cr
          INNER JOIN civicrm_contribution c ON cr.id = c.contribution_recur_id
          INNER JOIN civicrm_payment_processor pp
            ON cr.payment_processor_id = pp.id
              AND pp.class_name = %1
          LEFT JOIN civicrm_option_group og
            ON og.name = "contribution_status"
          LEFT JOIN civicrm_option_value rs
            ON cr.contribution_status_id = rs.value
            AND rs.option_group_id = og.id
          LEFT JOIN civicrm_option_value cs
            ON c.contribution_status_id = cs.value
            AND cs.option_group_id = og.id
          WHERE
            (cr.installments > 0)
            AND (rs.name IN ("In Progress", "Completed"))
            AND (cs.name IN ("Completed", "Pending"))
          GROUP BY c.contribution_recur_id';
    }
    $dao = CRM_Core_DAO::executeQuery($select, $args);
    return $dao;
  }

  /**
   * @param $contribution an array of a contribution to be created (or in case of future start date,
   * possibly an existing pending contribution to recycle, if it already has a contribution id).
   * @param $options like is membership or send email receipt
   * @param $original_contribution_id if included, use as a template for a recurring contribution.
   *
   *   A high-level utility function for making a contribution payment from an existing recurring schedule
   *   Used in the Cloverrecurringcontributions.php job
   *
   *
   * Borrowed from https://github.com/iATSPayments/com.iatspayments.civicrm/blob/master/iats.php#L1285 _iats_process_contribution_payment
   */
  public static function processRecurringContributionPayment(&$contribution, $options, $original_contribution_id) {
    // Get Contribution Statuses
    $completedStatusId = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Completed');

    // Get Vault Token
    $tokenFields = [
      "clover_token" => "payment_token_id.token",
      "expiry" => "payment_token_id.expiry_date"
    ];
    $paymentToken = clover_apiv3helper('ContributionRecur', 'getsingle', [
      'return' => array_values($tokenFields),
      'id' => $contribution['contribution_recur_id'],
    ]);
    foreach ($tokenFields as $cloverName => $civiName) {
      // Save the payment token to the contribution
      if (!empty($paymentToken[$civiName])) {
        $contribution[$cloverName] = $paymentToken[$civiName];
      }
      else {
        // CRM_Core_Error::statusBounce(E::ts('Unable to complete payment! Please this to the site administrator with a description of what you were trying to do.'));
        Civi::log()->debug('Clover token was not passed!  Report this message to the site administrator. $contribution: ' . print_r($contribution, TRUE));
        return E::ts('no payment token found for recurring contribution in series id %1: ', [1 => $contribution['contribution_recur_id']]);
      }
    }
    if (!isset($contribution['amount']) && isset($contribution['total_amount'])) {
      $contribution['amount'] = $contribution['total_amount'];
    }

    // Get clover credentials.
    if (!empty($contribution['payment_processor'])) {
      $cloverCreds = CRM_Core_Payment_Clover::getCloverCreds($contribution);
    }

    // Throw an error if no credentials found.
    if (empty($cloverCreds)) {
      CRM_Core_Error::statusBounce(E::ts('No valid payment processor credentials found'));
      Civi::log()->debug('No valid Clover credentials found.  Report this message to the site administrator. $contribution: ' . print_r($contribution, TRUE));
      return E::ts('no Clover Credentials found for payment processor id: %1 ', [1 => $contribution['payment_processor']]);
    }

    $contribution['capture'] = 'y';
    $contribution['ecomind'] = 'R';
    $contribution['cof'] = 'M';
    $contribution['cofscheduled'] = 'Y';

    // Use the payment token to make the transaction
    $client = new CRM_Clover_Client($cloverCreds);
    $client->authorize($contribution);
    // add relevant information from the clover response
    CRM_Core_Payment_Clover::processTransaction($client->response, $contribution, $cloverCreds);

    // This code assumes that the contribution_status_id has been set properly above, either completed or failed.
    $contributionResult = clover_apiv3helper('contribution', 'create', $contribution);

    if (!empty($contributionResult)) {
      // Pass back the created id indirectly since I'm calling by reference.
      $contribution['id'] = CRM_Utils_Array::value('id', $contributionResult);
      // Connect to a membership if requested.
      if (!empty($options['membership_id'])) {
        $membershipPayment = clover_apiv3helper('MembershipPayment', 'create', [
          'contribution_id' => $contribution['id'],
          'membership_id' => $options['membership_id']
        ]);
      }
      // if contribution needs to be completed
      if ($contribution['contribution_status_id'] == $completedStatusId) {
        $complete = [
         'id' => $contribution['id'],
         'payment_processor_id' => $contribution['payment_processor'],
         'trxn_id' => $contribution['trxn_id'],
         'receive_date' => $contribution['receive_date'],
        ];
        $complete['is_email_receipt'] = empty($options['is_email_receipt']) ? 0 : 1;
        $contributionResult = clover_apiv3helper('contribution', 'completetransaction', $complete);
      }
    }
    // Now return the appropriate message.
    if ($contribution['contribution_status_id'] == $completedStatusId && $contributionResult['is_error'] == 0) {
      return E::ts('Successfully processed recurring contribution in series id %1: ', [1 => $contribution['contribution_recur_id']]);
    }
    else {
      return E::ts('Failed to process recurring contribution id %1: ', [1 => $contribution['contribution_recur_id']]);
    }
  }

}
