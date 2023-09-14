<?php

use Civi\Api4\LineItem;
use Civi\Api4\Membership;
use Civi\Api4\Participant;
use Civi\Api4\PaymentProcessor;
use Civi\Payment\Exception\PaymentProcessorException;
use CRM_Clover_ExtensionUtil as E;
use Brick\Money\Money;
use Brick\Money\Context\DefaultContext;
use Brick\Math\RoundingMode;

// borrowed heavily from https://lab.civicrm.org/extensions/mjwshared/-/blob/master/CRM/Mjwshared/Form/PaymentRefund.php?ref_type=heads#L36

/**
 * Form controller class
 *
 * @see https://docs.civicrm.org/dev/en/latest/framework/quickform/
 */
class CRM_Clover_Form_Refund extends CRM_Core_Form {

  /**
   * @var int $paymentID
   */
  private $paymentID;

  /**
   * @var int $contributionID
   */
  private $contributionID;

  /**
   * @var array $financialTrxn
   */
  private $financialTrxn;

  /**
   * @throws \CRM_Core_Exception
   */
  public function buildQuickForm(): void {

    $this->paymentID = CRM_Utils_Request::retrieveValue('payment_id', 'Positive', NULL, FALSE, 'REQUEST');
    if (!$this->paymentID) {
      CRM_Core_Error::statusBounce('Payment not found!');
    }

    $this->contributionID = CRM_Utils_Request::retrieveValue('contribution_id', 'Positive', NULL, FALSE, 'REQUEST');
    if (!$this->contributionID) {
      CRM_Core_Error::statusBounce('Contribution not found!');
    }

    $this->financialTrxn = CRM_Core_Payment_Clover::getFinancialTrxn($this->paymentID);
    $this->add('hidden', 'payment_id', $this->paymentID);
    $this->add('hidden', 'contribution_id', $this->contributionID);

    $participantIDs = $membershipIDs = [];

    $lineItems = LineItem::get(FALSE)
      ->addWhere('contribution_id', '=', $this->contributionID)
      ->execute();
    foreach ($lineItems as $lineItemDetails) {
      switch ($lineItemDetails['entity_table']) {
        case 'civicrm_participant':
          $participantIDs[] = $lineItemDetails['entity_id'];
          break;

        case 'civicrm_membership':
          $membershipIDs[] = $lineItemDetails['entity_id'];
          break;
      }
    }
    if (!empty($participantIDs)) {
      $participantsForAssign = [];
      $this->set('participant_ids', $participantIDs);
      $participants = Participant::get()
        ->addSelect('*', 'event_id.title', 'status_id:label', 'contact_id.display_name')
        ->addWhere('id', 'IN', $participantIDs)
        ->execute();
      foreach ($participants->getArrayCopy() as $participant) {
        $participant['status'] = $participant['status_id:label'];
        $participant['event_title'] = $participant['event_id.title'];
        $participant['display_name'] = $participant['contact_id.display_name'];
        $participantsForAssign[] = $participant;
      }
      $this->addYesNo('cancel_participants', E::ts('Do you want to cancel these registrations when you %1 the payment?', [1 => $this->financialTrxn['action']]), NULL, TRUE);
    }
    $this->assign('participants', $participantsForAssign ?? NULL);

    if (!empty($membershipIDs)) {
      $membershipsForAssign = [];
      $this->set('membership_ids', $membershipIDs);
      $memberships = Membership::get(FALSE)
        ->addSelect('*', 'membership_type_id:label', 'status_id:label', 'contact_id.display_name')
        ->addWhere('id', 'IN', $membershipIDs)
        ->execute();
      foreach ($memberships->getArrayCopy() as $membership) {
        $membership['status'] = $membership['status_id:label'];
        $membership['type'] = $membership['membership_type_id:label'];
        $membership['display_name'] = $membership['contact_id.display_name'];
        $membershipsForAssign[] = $membership;
      }
      $this->addYesNo('cancel_memberships', E::ts('Do you want to cancel these memberships when you %1 the payment?', [1 => $this->financialTrxn['action']]), NULL, TRUE);
    }
    $this->assign('memberships', $membershipsForAssign ?? NULL);

    CRM_Core_Session::setStatus(E::ts('Based on the status of this payment it is eligibale to be %1ed. At this time you must %1 the whole payment amount.', [1 => $this->financialTrxn['action']]), '', 'no-popup');
    $this->addMoney('refund_amount',
      E::ts('%1 Amount', [1 => ucfirst($this->financialTrxn['action'])]),
      TRUE,
      // FOR NOW readonly because requiring user to void the full payment amount
      ['readonly' => TRUE],
      FALSE, 'currency', NULL, TRUE
    );
    $this->addButtons([
      [
        'type' => 'submit',
        'name' => E::ts('Submit'),
        'isDefault' => TRUE,
      ],
    ]);

    $this->setDefaults(['refund_amount' => $this->financialTrxn['total_amount']]);

    // export form elements
    $this->assign('elementNames', $this->getRenderableElementNames());
    parent::buildQuickForm();
  }

  public function postProcess(): void {
    $formValues = $this->getSubmitValues();
    $paymentID = $this->get('payment_id');
    $participantIDs = $this->get('participant_ids');
    $cancelParticipants = $formValues['cancel_participants'] ?? FALSE;
    $membershipIDs = $this->get('membership_ids');
    $cancelMemberships = $formValues['cancel_memberships'] ?? FALSE;

    // Check refund amount
    $refundAmount = Money::of($formValues['refund_amount'], $this->financialTrxn['currency'], new DefaultContext(), RoundingMode::CEILING);
    $paymentAmount = Money::of($this->financialTrxn['total_amount'], $this->financialTrxn['currency'], new DefaultContext(), RoundingMode::CEILING);

    if ($refundAmount->isGreaterThan($paymentAmount)) {
      throw new PaymentProcessorException('Cannot refund more than the original amount');
    }
    if ($refundAmount->isNegativeOrZero()) {
      throw new PaymentProcessorException('Cannot refund zero or negative amount');
    }

    $refundParams = [
      'payment_processor_id' => $this->financialTrxn['payment_processor_id'],
      'amount' => $refundAmount->getAmount()->toFloat(),
      'currency' => $this->financialTrxn['currency'],
      'trxn_id' => $this->financialTrxn['trxn_id'],
    ];
    $refund = reset(clover_apiv3helper('PaymentProcessor', 'Refund', $refundParams)['values']);
    if ($refund['refund_status'] === 'Completed') {
      $refundPaymentParams = [
        'contribution_id' => $this->financialTrxn['eft.entity_id'],
        'trxn_id' => $refund['refund_trxn_id'],
        'order_reference' => $this->financialTrxn['order_reference'] ?? NULL,
        'total_amount' => 0 - abs($refundAmount->getAmount()->toFloat()),
        'fee_amount' => 0 - abs($refund['fee_amount']),
        'payment_processor_id' => $this->financialTrxn['payment_processor_id'],
        'trxn_date' => $refund['trxn_date'],
      ];
      if ($paymentID) {
        $refundPaymentParams['id'] = $paymentID;
      }
      $lock = Civi::lockManager()->acquire('data.contribute.contribution.' . $refundPaymentParams['contribution_id']);
      if (!$lock->isAcquired()) {
        throw new PaymentProcessorException('Could not acquire lock to record refund for contribution: ' . $refundPaymentParams['contribution_id']);
      }
      $refundPayment = clover_apiv3helper('Payment', 'get', [
        'contribution_id' => $refundPaymentParams['contribution_id'],
        'total_amount' => $refundPaymentParams['total_amount'],
        'trxn_id' => $refundPaymentParams['trxn_id'],
      ]);
      if (empty($refundPayment['count'])) {
        // Record the refund in CiviCRM
        if (empty($refundPaymentParams['skipCleanMoney'])) {
          foreach (['total_amount', 'net_amount', 'fee_amount'] as $field) {
            if (isset($refundPaymentParams[$field])) {
              $refundPaymentParams[$field] = CRM_Utils_Rule::cleanMoney($refundPaymentParams[$field]);
            }
          }
        }
        // Check if it is an update
        if (!empty($refundPaymentParams['id'])) {
          $amount = $refundPaymentParams['total_amount'];
          clover_apiv3helper('Payment', 'cancel', $refundPaymentParams);
          $refundPaymentParams['total_amount'] = $amount;
        }
        $trxn = CRM_Financial_BAO_Payment::create($refundPaymentParams);
      }
      $lock->release();
      $message = E::ts('Refund was processed successfully.');

      if ($cancelParticipants && !empty($participantIDs)) {
        foreach ($participantIDs as $participantID) {
          clover_apiv3helper('Participant', 'create', [
            'id' => $participantID,
            'status_id' => 'Cancelled',
          ]);
        }
        $message .= ' ' . E::ts('Cancelled %1 participant registration(s).', [1 => count($participantIDs)]);
      }

      if ($cancelMemberships && !empty($membershipIDs)) {
        Membership::update(FALSE)
          ->addValue('status_id.name', 'Cancelled')
          ->addWhere('id', 'IN', $membershipIDs)
          ->execute();
        $message .= ' ' . E::ts('Cancelled %1 membership(s).', [1 => count($membershipIDs)]);
      }

      CRM_Core_Session::setStatus($message, 'Refund processed', 'success');
    }
    else {
      CRM_Core_Error::statusBounce("Refund status '{$refund['refund_status']}'is not supported at this time and was not recorded in CiviCRM.");
    }
    // Redirect to Contribution
    CRM_Utils_System::redirect(CRM_Utils_System::url('civicrm/contact/view/contribution', "reset=1&id={$this->financialTrxn['eft.entity_id']}&action=view"));
  }

  /**
   * Get the fields/elements defined in this form.
   *
   * @return array (string)
   */
  public function getRenderableElementNames(): array {
    // The _elements list includes some items which should not be
    // auto-rendered in the loop -- such as "qfKey" and "buttons".  These
    // items don't have labels.  We'll identify renderable by filtering on
    // the 'label'.
    $elementNames = [];
    foreach ($this->_elements as $element) {
      /** @var HTML_QuickForm_Element $element */
      $label = $element->getLabel();
      if (!empty($label)) {
        $elementNames[] = $element->getName();
      }
    }
    return $elementNames;
  }

}
