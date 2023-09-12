<?php
/*
 * Class for Clover Api calls
 */
class CRM_Clover_Client {

  protected $client;
  protected $baseUrl;
  protected $username;
  protected $apiKey;
  protected $merchantId;

  public $response;

  function __construct($cloverCreds = []) {
    $this->baseUrl = $cloverCreds['url_api'];
    $this->username = $cloverCreds['user_name'];
    $this->apiKey = $cloverCreds['password'];
    $this->merchantId = $cloverCreds['signature'];
    $this->client = new \GuzzleHttp\Client([
        // Base URI is used with relative requests
        'base_uri' => $this->baseUrl,
        //@TODO is this a relevant setting?
        'timeout'  => 2.0,
    ]);
  }

  /**
    * function for 1 time payment authorize and capture
    * @params array of parameters from the billing form
    */
  public function authorizeAndCapture($params) {
    //start building JSON for request
    $json = [
      'merchid' => $this->merchantId,
      'amount' => $params['amount'],
      //@TODO testing makes this seem not required with token. validate for prod.
      //'expiry' => '0825',
      'account' => $params['clover_token'],
      'address' => $params['billing_street_address-5'],
      'city' => $params['billing_city-5'],
      'postal' => $params['billing_postal_code-5'],
      //@TODO testing makes this seem not required with token. validate for prod.
      //'cvv2' => '456',
      'ecomind' => 'E',
      'currency' => 'USD',
      'capture' => 'y'
    ];
    //transform state and country to 2-char abbreviations
    //@TODO civi API only promises "2-4" char abbreviation so this could be a problem, checking for length 2 now
    $stateProvinces = \Civi\Api4\StateProvince::get(FALSE)
      ->addSelect('abbreviation')
      ->addWhere('id', '=', $params['billing_state-5'])
      ->setLimit(1)
      ->execute();
    if (count($stateProvinces) == 1 && strlen($stateProvinces[0] == 2)) {
      $json['region'] = $stateProvinces[0];
    }
    $countries = \Civi\Api4\Country::get(FALSE)
      ->addSelect('iso_code')
      ->addWhere('id', '=', $params['billing_country-5'])
      ->setLimit(1)
      ->execute();
    if (count($countries) == 1 && strlen($countries[0] == 2)) {
      $json['country'] = $countries[0];
    }

    $response = $this->client->request('POST', 'auth', [
      'headers' => [
        'Content-Type' => 'application/json',
        'Authentication' => 'Basic'
      ],
      'auth' => [
        $this->username,
        $this->apiKey
      ],
      'json' => $json
    ]);

    $this->response = json_decode($response->getBody()->getContents());

  }

}
