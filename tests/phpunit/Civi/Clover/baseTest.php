<?php
namespace Civi\Clover;

use CRM_Clover_ExtensionUtil as E;
use Civi\Test\CiviEnvBuilder;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;

/**
 * FIXME - Add test description.
 *
 * Tips:
 *  - With HookInterface, you may implement CiviCRM hooks directly in the test class.
 *    Simply create corresponding functions (e.g. "hook_civicrm_post(...)" or similar).
 *  - With TransactionalInterface, any data changes made by setUp() or test****() functions will
 *    rollback automatically -- as long as you don't manipulate schema or truncate tables.
 *    If this test needs to manipulate schema or truncate tables, then either:
 *       a. Do all that using setupHeadless() and Civi\Test.
 *       b. Disable TransactionalInterface, and handle all setup/teardown yourself.
 *
 * @group headless
 */
class baseTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  protected $_contributionID;
  protected $_invoiceID = 'in_19WvbKAwDouDdbFCkOnSwAN7';
  protected $_financialTypeID = 1;
  protected $org;
  protected $_orgID;
  protected $contact;
  protected $_contactID;
  protected $_contributionPageID;
  protected $_paymentProcessorID;
  protected $_paymentProcessor;
  protected $_testPaymentProcessor;
  protected $_testPaymentProcessorID;
  protected $_trxn_id;
  protected $_created_ts;
  protected $_subscriptionID;
  protected $_membershipTypeID;
  protected $_completedStatusID;
  protected $_failedStatusID;
  protected $_paymentInstruments;
  protected $_cloverCreds;

  /**
   * Setup used when HeadlessInterface is implemented.
   *
   * Civi\Test has many helpers, like install(), uninstall(), sql(), and sqlFile().
   *
   * @link https://github.com/civicrm/org.civicrm.testapalooza/blob/master/civi-test.md
   *
   * @return \Civi\Test\CiviEnvBuilder
   *
   * @throws \CRM_Extension_Exception_ParseException
   */
  public function setUpHeadless(): CiviEnvBuilder {
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  public function setUp():void {
    $this->createPaymentProcessor();
    $this->createContact();
    $this->createContributionPage();
    $this->_created_ts = time();
    $this->_completedStatusID = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Completed');
    $this->_failedStatusID = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Failed');
    $mode = 'test';
    $pp = $this->_paymentProcessor;
    $clover = new CRM_Core_Payment_Clover($mode, $pp);
    $this->_cloverCreds = $clover::getPaymentProcessorSettings($this->_paymentProcessorID);
    $instruments = civicrm_api3('contribution', 'getoptions', array('field' => 'payment_instrument_id'));
    $this->_paymentInstruments = $instruments['values'];
    parent::setUp();
  }

  public function tearDown():void {
    parent::tearDown();
  }


  /**
   * Run the cloverrecurringcontributions cron job
   * @param  string $time time to run the job as
   * @return array        results from the job
   */
//@TODO change out this recurring job for an api4 job once the recurring job exists
  /*public function assertCronRuns($time) {
    CRM_Utils_Time::setTime($time);
    try {
      $recurJob = civicrm_api3('job', 'cloverrecurringcontributions');
    }
    catch (CiviCRM_API3_Exception $e) {
      $error = $e->getMessage();
    }
    if (!empty($recurJob)) {
      return $recurJob;
    }
  }*/

  /**
   * Create contact.
   */
  function createContact() {
    if (!empty($this->_contactID)) {
      return;
    }
    $results = civicrm_api3('Contact', 'create', array(
      'contact_type' => 'Individual',
      'first_name' => 'Jose',
      'last_name' => 'Lopez'
    ));;
    $this->_contactID = $results['id'];
    $this->contact = (Object) array_pop($results['values']);

    // Now we have to add an email address.
    $email = 'susie@example.org';
    civicrm_api3('email', 'create', array(
      'contact_id' => $this->_contactID,
      'email' => $email,
      'location_type_id' => 1
    ));
    $this->contact->email = $email;
  }

  /**
   * Create a clover payment processor.
   */
  function createPaymentProcessor($params = array()) {
    // Get the payment processor type id
    $pptId = civicrm_api3('PaymentProcessorType', 'getsingle', [
      'return' => ["id"],
      'name' => "clover",
    ]);

    //@TODO change this payment processor setup
    if (!empty($pptId['id'])) {
      $params = array(
        'name' => 'clover Payment Processor',
        'domain_id' => CRM_Core_Config::domainID(),
        'payment_processor_type_id' => $pptId['id'],
        'is_active' => 1,
        'is_default' => 0,
        'is_test' => 0,
        'is_recur' => 1,
        'url_site' => 'https://isv-uat.cardconnect.com/itoke/ajax-tokenizer.html',
        'url_recur' => 'https://isv-uat.cardconnect.com/itoke/ajax-tokenizer.html',
        'class_name' => 'Payment_clover',
        'billing_mode' => 1,
      );

      // To test one must send the following environment variables
      $credentials = array(
        'clover_user_name',
        'clover_password',
        'clover_subject',
        'clover_signature',
      );
      foreach ($credentials as $key => $credential) {
        if (getenv($credential)) {
          $params[substr($credential, 5)] = getenv($credential);
        }
        else {
          $this->fail("no {$credential} environment variable passed.");
        }
      }

      // First see if it already exists.
      $cloverPaymentProcessor = civicrm_api3('PaymentProcessor', 'get', $params);
      if ($cloverPaymentProcessor['count'] != 1) {
        // Nope, create it.
        $cloverPaymentProcessor = civicrm_api3('PaymentProcessor', 'create', $params);
      }
      // return civicrm_api3_create_success($cloverPaymentProcessor['values']);
      $processor = array_pop($cloverPaymentProcessor['values']);
      $this->_paymentProcessor = $processor;
      $this->_paymentProcessorID = $cloverPaymentProcessor['id'];

      // check for test processor
      $params['is_test'] = 1;
      $cloverTestPaymentProcessor = civicrm_api3('PaymentProcessor', 'get', $params);
      if ($cloverTestPaymentProcessor['count'] != 1) {
        // Nope, create it.
        $cloverTestPaymentProcessor = civicrm_api3('PaymentProcessor', 'create', $params);
      }
      // return civicrm_api3_create_success($cloverPaymentProcessor['values']);
      $testProcessor = array_pop($cloverTestPaymentProcessor['values']);
      $this->_testPaymentProcessor = $testProcessor;
      $this->_testPaymentProcessorID = $cloverTestPaymentProcessor['id'];
    }
  }

  /**
   * Create a clover contribution page.
   */
  function createContributionPage($params = array()) {
    $params = array_merge(array(
      'title' => "Test Contribution Page",
      'financial_type_id' => $this->_financialTypeID,
      'currency' => 'USD',
      'payment_processor' => $this->_paymentProcessorID,
      'max_amount' => 1000,
      'receipt_from_email' => 'gaia@the.cosmos',
      'receipt_from_name' => 'Pachamama',
      'is_email_receipt' => FALSE,
      ), $params);
    $result = civicrm_api3('ContributionPage', 'create', $params);
    $this->assertEquals(0, $result['is_error']);
    $this->_contributionPageID = $result['id'];
  }

  /**
   * Submit to clover
   */
  public function preparePayment($params = array(), $mode = 'live') {
    $params = array_merge(array(
      'payment_processor_id' => $this->_paymentProcessorID,
      'payment_processor' => $this->_paymentProcessorID,
      'total_amount' => 1.01,
      'cvv2' => '123',
      'credit_card_exp_date' => array(
        'M' => '09',
        'Y' => '2025',
      ),
      'location_type_id' => 5,
      'billing_street_address-5' => '555 north',
      'billing_postal_code-5' => '12324',
      'billing_first_name' => 'first',
      'billing_last_name' => 'last',
      'credit_card_number' => '4012000033330026',
      'email' => $this->contact->email,
      'contact_id' => $this->contact->id,
      'contactID' => $this->contact->id,
      'description' => 'Test from clover Test Code',
      'currencyID' => 'USD',
      'invoiceID' => $this->_invoiceID,
      'invoice_number' => rand(1, 9999999),
      'financial_type_id' => $this->_financialTypeID,
      'currency' => 'USD',
      'sequential' => 1,
      'is_test' => 0,
      'version' => 3,
      'unit_test' => 1,
    ), $params);
    return $params;
  }

  /**
   * Create a recurring contribution
   */
  public function createRecurringContribution($extraParams = array()) {
    $params = [
      'contact_id' => $this->contact->id,
      'amount' => 10.00,
      'frequency_interval' => 1,
      'frequency_unit' => 'day',
      'currency' => 'USD',
      'payment_processor_id' =>  $this->_paymentProcessorID,
    ];
    if (!empty($extraParams)) {
      $params = array_merge($params, $extraParams);
    }
    $recurring = civicrm_api3('ContributionRecur', 'create', $params);
    return $recurring;
  }

//@TODO turn on recurring test set once recurring contritbuions exist and are working
  /*public function processRecurringContribution($params, $recurringParams = array()) {
    // Create a recurring transaction so you have a recur id to use
    if (empty($params['contributionRecurID'])) {
      if (empty($recurringParams)) {
        $recurringParams = ['amount' => $params['total_amount']];
      }
      $recurringContribution = $this->createRecurringContribution($recurringParams);
      $params['contributionRecurID'] = $params['contribution_recur_id'] = $recurringContribution['id'];
    }
    $params = $this->preparePayment($params);
    // create a payment against the recurring contribution
    try {
      $contribution = civicrm_api3('Contribution', 'transact', $params);
    }
    catch (CiviCRM_API3_Exception $e) {
      $error = $e->getMessage();
      CRM_Core_Error::debug_log_message(ts('API Error %1', array(
        'domain' => E::LONG_NAME,
        1 => $error,
      )));
    }
    $contribution = $contribution['values'][0];
    return $contribution;
  }


  public function processRecurringContributionResponse(&$contribution, $expectedStatus) {
    // if expcted to complete
    if ($expectedStatus == $this->_completedStatusID) {
      $this->assertEquals($contribution['contribution_status_id'], $this->_completedStatusID);
      $recurringContribution = civicrm_api3('ContributionRecur', 'getsingle', [
        'id' => $contribution['contribution_recur_id'],
      ]);
      // Make sure the card was boarded
      $this->assertGreaterThan(0, $recurringContribution['payment_token_id']);
      $paymentToken = civicrm_api3('PaymentToken', 'getsingle', [
        'id' => $recurringContribution['payment_token_id'],
      ]);
      $this->assertGreaterThan(0, $paymentToken['token']);
      $contribution['vault_token'] = $paymentToken['token'];
      $contribution['payment_processor'] = $paymentToken['payment_processor_id'];
    }
    if ($expectedStatus == $this->_failedStatusID) {
      $this->assertEquals($contribution['contribution_status_id'], $this->_failedStatusID);
    }
    return $contribution;
  }*/

  /**
   * Create contribition
   */
  public function setupTransaction($params = array()) {
     $contribution = civicrm_api3('contribution', 'create', array_merge(array(
      'contact_id' => $this->_contactID,
      'contribution_status_id' => 2,
      'payment_processor_id' => $this->_paymentProcessorID,
      // processor provided ID - use contact ID as proxy.
      'processor_id' => $this->_contactID,
      'total_amount' => 1.01,
      'invoice_id' => $this->_invoiceID,
      'financial_type_id' => $this->_financialTypeID,
      'contribution_status_id' => 'Pending',
      'contact_id' => $this->_contactID,
      'contribution_page_id' => $this->_contributionPageID,
      'payment_processor_id' => $this->_paymentProcessorID,
      'is_test' => 0,
    ), $params));
    $this->assertEquals(0, $contribution['is_error']);
    $this->_contributionID = $contribution['id'];
  }

  /**
   * Create Organization
   */
  public function createOrganization() {
    if (!empty($this->_orgID)) {
      return;
    }
    $results = civicrm_api3('Contact', 'create', array(
      'contact_type' => 'Organization',
      'organization_name' => 'My Great Group'
    ));;
    $this->_orgID = $results['id'];
  }

  /**
   * Create Membership Type
   */
  public function createMembershipType() {
    CRM_Member_PseudoConstant::flush('membershipType');
    CRM_Core_Config::clearDBCache();
    $this->createOrganization();
    $params = array(
      'name' => 'General',
      'duration_unit' => 'year',
      'duration_interval' => 1,
      'period_type' => 'rolling',
      'member_of_contact_id' => $this->_orgID,
      'domain_id' => 1,
      'financial_type_id' => 2,
      'is_active' => 1,
      'sequential' => 1,
      'visibility' => 'Public',
    );

    $result = civicrm_api3('MembershipType', 'Create', $params);

    $this->_membershipTypeID = $result['id'];

    CRM_Member_PseudoConstant::flush('membershipType');
    CRM_Utils_Cache::singleton()->flush();
  }

  /**
   * Print the results of the tests to the command line
   */
  public function spitOutResults($question, $results) {
    echo "\r\n\r\n$question \r\n";
    $thingsToPrint = [
      'total_amount' => 'Amount',
      'credit_card_number' => 'Credit Card',
      'approval_status' => 'Approval Status',
      'clover_token' => 'Previous Trxn Token',
      'vault_token' => 'Vault Token',
      'trxn_id' => 'Transaction ID',
    ];
    foreach ($thingsToPrint as $key => $pretty) {
      if (!empty($results[$key])) {
        echo "$pretty: $results[$key] \r\n";
      }
    }
  }

  /**
   * Example: Test that a version is returned.
   */
  public function testWellFormedVersion():void {
    $this->assertNotEmpty(E::SHORT_NAME);
    $this->assertRegExp('/^([0-9\.]|alpha|beta)*$/', \CRM_Utils_System::version());
  }

  /**
   * Example: Test that we're using a fake CMS.
   */
  public function testWellFormedUF():void {
    $this->assertEquals('UnitTests', CIVICRM_UF);
  }

}
