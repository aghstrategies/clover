# clover

Clover payment processor for CiviCRM

This payment processor can handle:

- One time Contributions (Front End and Back End)
- Recurring Contributions
- Editing Recurring Contribution amounts
- Refunding/Voiding

The extension is licensed under [AGPL-3.0](LICENSE.txt).

## Requirements

* PHP v8.1+
* CiviCRM 5.64.0+

## Installation (Web UI)

Learn more about installing CiviCRM extensions in the [CiviCRM Sysadmin Guide](https://docs.civicrm.org/sysadmin/en/latest/customize/extensions/).

## Alternate Installation (Git, CLI, Zip)

It is assumed if you are installing outside of the Web UI, you know what you are doing.

## Getting Started

### Get Your Credentials
First you will need to configure an account with clover. They will provide you with a login to the cardconnect -> cardpointe portal.

The credentials needed for the clover payment processor integration are:

1. Username
2. Password
3. Merchant ID
4. Site URL
5. API URL

The MID or Merchant ID can be found on the "My Account" tab of the cardconnect portal: https://cardpointe.com/account/#/myaccount/products

To get the Username, Password, Site URL and API URL credentials:

1. go to cardconnect.com select "Log in" -> "CardPointe"
2. Enter your login information
2. click the "Support" menu item at the top
3. click "Create Ticket"
4. Submit a ticket with the following text:

> Could you please share:
> - production credentials
> - what <site> we should use for: PROD: https://<site>.cardconnect.com/cardconnect/rest/

### Configuring your Payment Processor
After Installing the extension on your site:

1. go to CiviCRM Admin Menu -> Administer -> System Settings -> Payment Processors
2. click the "Add Payment Processor" button
3. for "Payment Processor Type" select "Clover"

The production credentials: go in the Username and Password fields when configuring the payment processor in Civi

The <SITE> should be the subdomain of the Site URL and the API URL when configuring the payment processor in Civi.

The Merchant ID goes in the Merchant ID field.

Then you may configure relevant contribution/event registration forms to use it.

## Known Issues

## Mapping of Clover Response to Civi

| Civi Field       | Clover Field  |
|------------------|---------------|
| trxn_id          | retref        |
| trxn_result_code | response text |

## Other Resources
- CardPointe API Documentation: https://developer.cardpointe.com/cardconnect-api
- CardPointe Test Cards: https://developer.cardpointe.com/guides/cardpointe-gateway#uat-test-card-data
