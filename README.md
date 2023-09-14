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

After Installing create a payment processor of the type "Clover" and configure relevant contribution/event registration forms to use it.

## Known Issues

## Mapping of Clover Response to Civi

| Civi Field       | Clover Field  |
|------------------|---------------|
| trxn_id          | retref        |
| trxn_result_code | response text |

## Other Resources
- CardPointe API Documentation: https://developer.cardpointe.com/cardconnect-api
- CardPointe Test Cards: https://developer.cardpointe.com/guides/cardpointe-gateway#uat-test-card-data
