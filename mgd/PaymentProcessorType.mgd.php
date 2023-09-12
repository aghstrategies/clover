<?php
/**

 * - user_name_label, password_label, signature_label, subject_label - these
 * are generally about telling the plugin what to call these when they pass
 * them to Omnipay. They are also shown to users so some reformatting is done
 * to turn it into lower-first-letter camel case. Take a look at the gateway
 * file for your gateway. This is directly under src. Some provide more than
 * one and the 'getName' function distinguishes them. The getDefaultParameters
 * will tell you what to pass. eg if you see
 * 'apiKey' you should enter 'user_name' => 'Api Key' (you might ? be able to
 * get away with 'API Key' - need to check). You can provide as many or as few
 * as you want of these and it's irrelevant which field you put them in but
 * note that the signature field is the longest and that in future versions of
 * CiviCRM hashing may be done on password and signature on the screen.
 *
 * - 'class_name' => 'Payment_OmnipayMultiProcessor', (always)
 *
 * - 'url_site_default' - this is ignored. But, by giving one you make it
 * easier for people adding processors
 *
 * - 'billing_mode' - 1 = onsite, 4 = redirect offsite (including transparent
 * redirects).
 *
 * - payment_mode - 1 = credit card, 2 = debit card, 3 = transparent redirect.
 * In practice 3 means that billing details are gathered on-site so it may also
 * be used with automatic redirects where address fields need to be mandatory
 * for the signature.
 *
 * The record will be automatically inserted, updated, or deleted from the
 * database as appropriate. For more details, see "hook_civicrm_managed" at:
 * http://wiki.civicrm.org/confluence/display/CRMDOC/Hook+Reference
 */
return [
  [
    'name' => 'Clover',
    'entity' => 'payment_processor_type',
    'params' => [
      'version' => 3,
      'title' => 'Clover',
      'name' => 'Clover',
      'description' => 'Clover',
      // this will cause the user to be presented with a field,
      //  when adding this processor, labelled
      // api key which will save to civicrm_payment_processor.user_name
      // on save
      'user_name_label' => 'Username',
      // as per user_name_label, but saves to password
      'password_label' => 'Password',
      // as per user_name_label, but saves to signature
      'signature_label' => 'MerchantID',
      // prefix of CRM_Core is implicit so the class ie CRM_Core_Payment_MyProcessor
      'class_name' => 'Payment_Clover',
      // Any urls you might need stored for the user to be redirect to, for example.
      // Note it is quite common these days to hard code the urls in the processors
      // as they are not necessarily seen as configuration. But, if you enter
      // something here it will be the default for data entry.
      'url_site_default' => 'https://boltgw-uat.cardconnect.com/itoke/ajax-tokenizer.html',
      'url_api_default' => 'https://yoursite.cardconnect.com/cardconnect/rest',
      // this is a deprecated concept and these docs recommend you override
      // anything that references it. However, if you redirect the user offsite
      // enter 4 and if not enter 1 here.
      'billing_mode' => 1,
      // Generally 1 for credit card & 2 for direct debit. This will ideally
      // become an option group at some point but also note that it's mostly
      // or possibly only used from functions that this documentation recommends
      // you override (eg. `getPaymentTypeLabel`)
      'payment_type' => 1,
    ],
  ],
];
