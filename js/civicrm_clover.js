/**
 * @file
 * JS Integration between CiviCRM & clover.
 */
CRM.$(function ($) {
  // Disable the browser "Leave Page Alert" which is triggered
  // because we mess with the form submit function.
  window.onbeforeunload = null;

  var form = getBillingForm();
  var submit = getBillingSubmit(form);
  //check for clover selected and optionally load it on front-end form
  if ($('input.payment_processor_clover').length) {
    if ($('input.payment_processor_clover').prop('checked')) {
      loadClover();
    }
  }
  else {
    //otherwise load
    loadClover();
  }
  //check for clover selected and undo it's disable if not
  $('.payment_processor-section input').click(function() {
      $(submit).removeAttr('disabled');
  });

  //utility function to load up clover and prevent form submission
  //ONLY if clover is the selected payment processor
  function loadClover() {
    //check if we're still selected OR we are the only option
    if ($('input.payment_processor_clover').prop('checked') || !$('input.payment_processor_clover').length) {
      $(submit).attr('disabled', 'disabled');
      //listen for the clover event
      window.addEventListener('message', function(event) {
        var token = JSON.parse(event.data);
        if (token.validationError) {
          console.log(token.validationError);
        }
        if (token) {
          $('input[name="clover_token"]').val(token.clovertoken);
          // Restore any onclickAction that was removed.
          // Note this means with no token callback, the
          $(submit).removeAttr('disabled');
        }
      }, false);

      $('#cloveriframe').insertAfter('.credit_card_type-section');
      //change iframe height to fit styling
      //@TODO this is a placeholder value
      $('#tokenFrame').css('height', '15em');
    }
  }

  //utility function to determine if this is a civi payment form or drupal webform
  function getIsWebform() {
    return $('.webform-client-form').length;
  }

  //utility function to get the billing form in case of webform or multiple payment processors
  function getBillingForm() {
    // If we have a clover billing form on the page
    var billingForm = $('input[name="clover_token"]').closest('form');
    if (!billingForm.length && getIsWebform()) {
      // If we are in a webform
      //@TODO Can we distinguish that this is a webform w/ a payment in case
      // there's another webform in the sidebar?
      billingForm = $('.webform-client-form');
    }
    /*if (!billingForm.length) {
      // If we have multiple payment processors to select and clover is not currently loaded
      billingForm = $('input[name=hidden_processor]').closest('form');
    }*/

    return billingForm;
  }

  //utility function to get the submit from the billing form
  //we use this to prevent submit before we get a token back
  function getBillingSubmit(form) {
    var isWebform = getIsWebform();

    if (isWebform) {
      var submit = form.find('[type="submit"].webform-submit');
    } else {
      var submit = form.find('[type="submit"].validate');
    }

    return submit;
  }
});
