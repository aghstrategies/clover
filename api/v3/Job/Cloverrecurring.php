<?php
use CRM_Clover_ExtensionUtil as E;

/**
 * Job.Cloverrecurring API specification (optional)
 * This is used for documentation and validation.
 *
 * @param array $spec description of fields supported by this API call
 *
 * @see https://docs.civicrm.org/dev/en/latest/framework/api-architecture/
 */
 function _civicrm_api3_job_cloverrecurringcontributions_spec(&$spec) {
   $spec['recur_id'] = [
     'name' => 'recur_id',
     'title' => 'Recurring payment id',
     'api.required' => 0,
     'type' => 1,
   ];
   $spec['cycle_day'] = [
     'name' => 'cycle_day',
     'title' => 'Only contributions that match a specific cycle day.',
     'api.required' => 0,
     'type' => 1,
   ];
   $spec['failure_count'] = [
     'name' => 'failure_count',
     'title' => 'Filter by number of failure counts',
     'api.required' => 0,
     'type' => 1,
   ];
   $spec['catchup'] = [
     'title' => 'Process as if in the past to catch up.',
     'api.required' => 0,
   ];
   $spec['ignoremembership'] = [
     'title' => 'Ignore memberships',
     'api.required' => 0,
   ];
 }

/**
 * Job.Cloverrecurring API
 *
 * @param array $params
 *
 * @return array
 *   API result descriptor
 *
 * @see civicrm_api3_create_success
 *
 * @throws API_Exception
 */
function civicrm_api3_job_Cloverrecurring($params) {
  //taken from TSYS

  // Running this job in parallel could generate bad duplicate contributions.
  $lock = new CRM_Core_Lock('civicrm.job.Cloverrecurring');
  if (!$lock->acquire()) {
    return civicrm_api3_create_success(E::ts('Failed to acquire lock. No contribution records were processed.'));
  }
  $catchup = !empty($params['catchup']);
  unset($params['catchup']);
  $domemberships = empty($params['ignoremembership']);
  unset($params['ignoremembership']);

  // do calculations based on yyyymmddhhmmss representation of the time
  // not sure about time-zone issues.
  $dtCurrentDay    = date("Ymd", mktime(0, 0, 0, date("m"), date("d"), date("Y")));
  $dtCurrentDayStart = $dtCurrentDay . "000000";
  $dtCurrentDayEnd   = $dtCurrentDay . "235959";
  $expiry_limit = date('ym');

  // Before triggering payments, we need to do some housekeeping of the civicrm_contribution_recur records.
  // First update the end_date and then the complete/in-progress values.
  // We do this both to fix any failed settings previously, and also
  // to deal with the possibility that the settings for the number of payments (installments) for an existing record has changed.
  // First check for recur end date values on non-open-ended recurring contribution records that are either complete or in-progress.

  $dao = CRM_Core_Payment_Clover::getInstallmentsDone('dates');
  while ($dao->fetch()) {
    // Check for end dates that should be unset because I haven't finished
    // at least one more installment to do.
    if ($dao->installments_done < $dao->installments) {
      // Unset the end_date.
      if (($dao->end_date > 0) && ($dao->end_date <= $dao->test_now)) {
        $update = clover_apiv3helper('ContributionRecur', 'create', [
          'end_date' => NULL,
          'contribution_status_id' => "In Progress",
          'id' => $dao->id,
        ]);
      }
    }
    // otherwise, check if my end date should be set to the past because I have finished
    // I'm done with installments.
    elseif ($dao->installments_done >= $dao->installments) {
      if (empty($dao->end_date) || ($dao->end_date >= $dao->test_now)) {
        $enddate = strtotime ('-1 hour' , strtotime(date('Y-m-d H:i:s'))) ;
        $enddate = date('Y-m-d H:i:s' , $enddate);
        // This interval complete, set the end_date to an hour ago.
        $update = clover_apiv3helper('ContributionRecur', 'create', [
          'end_date' => $enddate,
          'id' => $dao->id,
        ]);
      }
    }
  }

  // Put together an array of clover payment processors
  $processorIDs = [];
  $paymentProcessors = \Civi\Api4\PaymentProcessor::get(FALSE)
    ->addSelect('id')
    ->addWhere('class_name', '=', 'Payment_Clover')
    ->execute();
  foreach ($paymentProcessors as $paymentProcessor) {
    $processorIDs[] = $paymentProcessor['id'];
  }

  // Search for payments that need to be made
  $recurParams = [
    'contribution_status_id' => ['IN' => ["In Progress", "Pending"]],
    'payment_processor_id' => ['IN' => $processorIDs],
    'next_sched_contribution_date' => ['<=' => date("Y-m-d") . ' 23:59:59'],
    'return' => [
      'contact_id',
      'amount',
      'currency',
      'payment_token_id.token',
      'payment_instrument_id',
      'financial_type_id',
      'next_sched_contribution_date',
      'failure_count',
      'is_test',
      'payment_processor_id',
      'frequency_interval',
      'frequency_unit',
    ],
  ];
  // Also filter by cycle day if it exists.
  if (!empty($params['cycle_day'])) {
    $recurParams['cycle_day'] = $params['cycle_day'];
  }
  // Also filter by Failure Count
  if (isset($params['failure_count'])) {
    $recurParams['failure_count'] = ['<=' => 3];
  }
  $recurringDonations = clover_apiv3helper('ContributionRecur', 'get', $recurParams);

  // get set up to start processing
  $counter = 0;
  $error_count  = 0;
  $output  = [];

  // By default, after 3 failures move the next scheduled contribution date forward.
  $failure_threshhold = 3;
  $failure_report_text = '';

  // Foreach thru recurring contributions that need to be processed
  if(!empty($recurringDonations['values'])) {
    foreach ($recurringDonations['values'] as $key => $donation) {
      $contact_id = $donation['contact_id'];
      $total_amount = $donation['amount'];
      $hash = md5(uniqid(rand(), TRUE));
      $contribution_recur_id = $donation['id'];
      $failure_count = $donation['failure_count'];
      $source = "Clover Recurring Contribution (id=$contribution_recur_id)";
      $receive_ts = $catchup ? strtotime($donation['next_sched_contribution_date']) : time();
      $receive_date = date("YmdHis", $receive_ts);
      $errors = [];

      $contribution_template = CRM_Core_Payment_Clover::getContributionTemplate([
        'contribution_recur_id' => $donation['id'],
        'total_amount' => $donation['amount'],
      ]);

      $contribution = [
        'contact_id'             => $contact_id,
        'receive_date'           => $receive_date,
        'total_amount'           => $total_amount,
        'net_amount'             => $total_amount,
        'payment_instrument_id'  => $donation['payment_instrument_id'],
        'contribution_recur_id'  => $contribution_recur_id,
        'invoice_id'             => $hash,
        'source'                 => $source,
        /* initialize as pending, so we can run completetransaction after taking the money */
        'contribution_status_id' => "Pending",
        'currency'               => $donation['currency'],
        'payment_processor'      => $donation['payment_processor_id'],
        'is_test'                => $donation['is_test'],
        'financial_type_id'      => $donation['financial_type_id'],
      ];
      $get_from_template = ['campaign_id', 'amount_level', 'tax_amount'];
      foreach ($get_from_template as $field) {
        if (isset($contribution_template[$field])) {
          $contribution[$field] = is_array($contribution_template[$field]) ? implode(', ', $contribution_template[$field]) : $contribution_template[$field];
        }
      }

      $options = [];
      // If the most recent contribution in the sequence is a membership payment, make this one also.
      // Note that we don't use the template_contribution for this purpose, so that
      // we can support changing membership amounts.
      if ($domemberships) {
        // retrieve the most recent previous contribution to check for a membership payment
        $latest_contribution = CRM_Core_Payment_Clover::getContributionTemplate(['contribution_recur_id' => $contribution_recur_id, 'is_test' => $donation['is_test']]);
        $membership_payment = clover_apiv3helper('MembershipPayment', 'getsingle', ['contribution_id' => $latest_contribution['contribution_id']]);
        if (!empty($membership_payment['membership_id'])) {
          $options['membership_id'] = $membership_payment['membership_id'];
        }
      }
      // Before talking to clover, advance the next collection date now so that in case of partial server failure I don't try to take money again.
      // Save the current value to restore in some cases of confirmed payment failure
      $saved_next_sched_contribution_date = $donation['next_sched_contribution_date'];
      // calculate the next collection date, based on the recieve date (note effect of catchup mode, above)
      $next_collection_date = date('Y-m-d H:i:s', strtotime("+{$donation['frequency_interval']} {$donation['frequency_unit']}", $receive_ts));
      // advance to the next scheduled date
      $contribution_recur_set = [
        'id' => $contribution['contribution_recur_id'],
        'next_sched_contribution_date' => $next_collection_date,
      ];

      // Process Recurring Contribution Payment
      $result = CRM_Core_Payment_Clover::processRecurringContributionPayment($contribution, $options, $contribution_template['original_contribution_id']);
      $output[] = $result;

      $pendingStatusId = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Pending');

      // IF Contribution Failed
      if ($pendingStatusId == $contribution['contribution_status_id']) {
        $contribution_recur_set['contribution_status_id'] = $contribution['contribution_status_id'];
        $contribution_recur_set['failure_count'] = $failure_count + 1;
        $contribution_recur_set['next_sched_contribution_date'] = $saved_next_sched_contribution_date;
        ++$error_count;
        // if it has failed but the failure threshold will not be reached with this failure, leave the next sched contribution date as it was
        if ($contribution_recur_set['failure_count'] < $failure_threshhold) {
          // Should the failure count be reset otherwise? It is not.
          unset($contribution_recur_set['next_sched_contribution_date']);
        }
      }

      // Update recurring Contribution based on response from CRM_Tsys_Recur::processContributionPayment
      $recurUpdate = clover_apiv3helper('ContributionRecur', 'create', $contribution_recur_set);
      $result = clover_apiv3helper('Activity', 'create',
        [
          'activity_type_id'    => "Contribution",
          'source_contact_id'   => $contact_id,
          'source_record_id'    => $contribution['id'],
          'assignee_contact_id' => $contact_id,
          'subject'             => "Attempted Clover Recurring Contribution for " . $total_amount,
          'status_id'           => "Completed",
          'activity_date_time'  => date("YmdHis"),
        ]
      );
      if ($result['is_error']) {
        $output[] = E::ts(
          'An error occurred while creating activity record for contact id %1: %2',
          [
            1 => $contact_id,
            2 => $result['error_message'],
          ]
        );
        ++$error_count;
      }
      else {
        $output[] = E::ts('Created activity record for contact id %1', [1 => $contact_id]);
      }
      ++$counter;
    }
  }

  // Now update the end_dates and status for non-open-ended contribution series if they are complete (so that the recurring contribution status will show correctly)
  // This is a simplified version of what we did before the processing.
  $dao = CRM_Core_Payment_Clover::getInstallmentsDone('simple');
  while ($dao->fetch()) {
    // Check if my end date should be set to now because I have finished
    // I'm done with installments.
    if ($dao->installments_done >= $dao->installments) {
      $update = clover_apiv3helper('ContributionRecur', 'create', [
        'contribution_status_id' => "Completed",
        'end_date' => $dtCurrentDay,
        'id' => $dao->id,
      ]);
    }
  }
  $lock->release();

  // If errors ..
  if ($error_count > 0) {
    return civicrm_api3_create_error(
      E::ts("Completed, but with %1 errors. %2 records processed.",
        [
          1 => $error_count,
          2 => $counter,
        ]
      ) . "<br />" . implode("<br />", $output)
    );
  }
  // If no errors and records processed ..
  if ($counter) {
    return civicrm_api3_create_success(
      E::ts(
        '%1 contribution record(s) were processed.',
        [
          1 => $counter,
        ]
      ) . "<br />" . implode("<br />", $output)
    );
  }
  // No records processed.
  return civicrm_api3_create_success(E::ts('No contribution records were processed.'));
}
