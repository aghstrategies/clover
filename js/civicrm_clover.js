/**
 * @file
 * JS Integration between CiviCRM & clover.
 */
CRM.$(function ($) {
  window.addEventListener('message', function(event) {
        var token = JSON.parse(event.data);
        $('#payment_token').val(token.clovertoken);
    }, false);
});
