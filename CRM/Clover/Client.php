<?php
/*
 * Class for Clover Api calls
 * @TODO document inputs
 */
class CRM_Clover_Client {

  protected $client;
  protected $baseUrl;
  protected $userName;
  protected $apiKey;
  protected $merchantId;

  public $response;

  function __construct($cloverCreds = []) {
    //we expect these to be set and check in Core_Payment_Clover but if they're not,
    //proceed and we will just log the bad request error
    $this->baseUrl = $cloverCreds['url_api'];
    $this->userName = $cloverCreds['user_name'];
    $this->apiKey = $cloverCreds['password'];
    $this->merchantId = $cloverCreds['signature'];
    $this->client = new \GuzzleHttp\Client([
        // Base URI is used with relative requests
        'base_uri' => $this->baseUrl,
        //@TODO is this a relevant setting for this use-case?
        'timeout'  => 2.0,
    ]);
  }

  /**
    * Authorize API call
    * @param array $params     parameters from the billing form
    */
  public function authorize($params) {

    //start building JSON for request
    $json = [
      'merchid' => $this->merchantId,
      'amount' => $params['amount'],
      'account' => $params['clover_token'],
      'currency' => 'USD',
    ];

    $paramsToAddWhenAvailable = [
      'name' => 'billing_first_name',
      'email' => 'email-5',
      'address' => 'billing_street_address-5',
      'city' => 'billing_city-5',
      'postal' => 'billing_postal_code-5',
      'region' => 'billing_state-5',
      'country' => 'billing_country-5',
      'expiry' => 'expiry',
      'ecomind' => 'ecomind',
      'cof' => 'cof',
      'cofscheduled' => 'cofscheduled',
      'capture' => 'capture',
    ];

    foreach ($paramsToAddWhenAvailable as $cloverName => $civiName) {
      if (isset($params[$civiName])) {

        switch ($cloverName) {
          case 'name':
            if (isset($params['billing_last_name'])) {
              $json[$cloverName] = $params['billing_first_name'] . ' ' . $params['billing_last_name'];
            }
            break;

          // transform state to 2-char abbreviations
          case 'region':
            $json['region'] = $this->transformStateInput($params['billing_state-5']);
            break;

          // transform country to 2-char abbreviations
          case 'country':
            $json['country'] = $this->transformCountryInput($params['billing_country-5']);
            break;

          case 'expiry':
            $json[$cloverName] = date_format(date_create($params[$civiName]), "Ym");
            break;

          default:
            if (isset($params[$civiName])) {
              $json[$cloverName] = $params[$civiName];
            }
            break;
        }
      }
    }

    //make the request
    $response = $this->client->request('POST', 'auth', [
      'headers' => [
        'Content-Type' => 'application/json',
        'Authentication' => 'Basic'
      ],
      'auth' => [
        $this->userName,
        $this->apiKey
      ],
      'json' => $json
    ]);

    //set client response to most recent transaction for later
    $this->response = json_decode($response->getBody()->getContents());

    // AGH #38622 to complete Solution Script_RPCT_01202023.xlsx uncomment this line and check the logs after running a transaction.
    // CRM_Core_Error::debug($this->response);
  }

  /**
    * function for looking up details of a transaction
    * @param array $params array of parameters from the billing form
    */
  public function inquire($params) {
    $response = $this->client->request('GET', "inquire/{$params['trxn_id']}/$this->merchantId", [
      'auth' => [
        $this->userName,
        $this->apiKey
      ]
    ]);
    $this->response = json_decode($response->getBody()->getContents());
  }

  /**
    * function for voiding
    * @param array $params array of parameters from the billing form
    */
  public function void($params) {
    //start building JSON for request
    $json = [
      'merchid' => $this->merchantId,
      'retref' => $params['trxn_id'],
    ];
    $response = $this->client->request('POST', 'void', [
      'headers' => [
        'Content-Type' => 'application/json',
        'Authentication' => 'Basic'
      ],
      'auth' => [
        $this->userName,
        $this->apiKey
      ],
      'json' => $json
    ]);
    //set client response to most recent transaction for later
    $this->response = json_decode($response->getBody()->getContents());
  }

  /**
    * function for refunding
    * @param array $params array of parameters from the billing form
    */
  public function refund($params) {
    //start building JSON for request
    $json = [
      'merchid' => $this->merchantId,
      'retref' => $params['trxn_id'],
    ];
    $response = $this->client->request('POST', 'refund', [
      'headers' => [
        'Content-Type' => 'application/json',
        'Authentication' => 'Basic'
      ],
      'auth' => [
        $this->userName,
        $this->apiKey
      ],
      'json' => $json
    ]);
    //set client response to most recent transaction for later
    $this->response = json_decode($response->getBody()->getContents());
  }

  /**
   * utility function to transform state inputs to the docs required 2 char code
   * @param int valid state ID input for Civi api4 from our forms
   * @return string 2char abbreviation or NULL
   */
  public function transformStateInput($stateId) {
    $abbr = NULL;
    //@TODO civi API only promises "2-4" char abbreviation so this could be a problem, checking for length 2 now
    $stateProvinces = \Civi\Api4\StateProvince::get(FALSE)
      ->addSelect('abbreviation')
      ->addWhere('id', '=', $stateId)
      ->setLimit(1)
      ->execute();
    if (count($stateProvinces) == 1 && strlen($stateProvinces[0] == 2)) {
      $abbr = $stateProvinces[0];
    }

    return $abbr;
  }

  /**
   * utility function to transform country inputs to the docs required 2 char code
   * @param int valid country ID input for Civi api4 from our forms
   * @return string 2char ISO code or NULL
   */
  public function transformCountryInput($countryId) {
    $iso = NULL;
    $countries = \Civi\Api4\Country::get(FALSE)
      ->addSelect('iso_code')
      ->addWhere('id', '=', $countryId)
      ->setLimit(1)
      ->execute();
    if (count($countries) == 1 && strlen($countries[0] == 2)) {
      $iso = $countries[0];
    }

    return $iso;
  }

}
