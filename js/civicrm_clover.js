/**
 * @file
 * JS Integration between CiviCRM & clover.
 */
CRM.$(function ($) {
  window.addEventListener('message', function(event) {
        var token = JSON.parse(event.data);
        var validationError = JSON.parse(event.validationError);
        if (token) {
          $('input[name="clover_token"]').val(token.clovertoken);
        }
    }, false);
});
