<?php

namespace SparkPost;

class SparkPost {
  /**
   * @var string Library version, used for setting User-Agent
   */
  private $version = '3.0.0';

  /**
   * @var HttpClientInterface used to make requests
   */
  private $httpClient;

  /**
   * @var array Options for requests
   */
  private $options;

  /**
   * @var Array Default options for requests that can be overridden with the setOptions function
   */
  private static $defaultOptions = [
    'host' => 'api.sparkpost.com',
    'protocol' => 'https',
    'port' => 443,
    'key' => '',
    'version' => 'v1',
    'async' => TRUE,
    'debug' => FALSE,
    'retries' => 0,
    // seconds allowed for the whole request
    'timeout' => CurlClient::DEFAULT_TIMEOUT,
    // seconds allowed to establish a connection
    'connect_timeout' => CurlClient::DEFAULT_CONNECT_TIMEOUT,
    // extra CURLOPT_* => value pairs applied to every request (proxy, CA bundle, ...)
    'curl_options' => [],
  ];

  /**
   * @var Transmission Instance of Transmission class
   */
  public $transmissions;

  /**
   * Sets up the SparkPost instance.
   *
   * @param array $options an array of options
   */
  public function __construct($options) {
    $httpClient = NULL;
    $this->setOptions($options === NULL ? [] : $options);
    $this->setHttpClient($httpClient);
    $this->setupEndpoints();
  }

  /**
   * Sends either sync or async request based on async option.
   *
   * @param string $method
   * @param string $uri
   * @param array $payload - either used as the request body or url query params
   * @param array $headers
   *
   * @return SparkPostPromise|SparkPostResponse Promise or Response depending on sync or async request
   */
  public function request($method = 'GET', $uri = '', $payload = [], $headers = []) {
    if ($this->options['async'] === TRUE) {
      return $this->asyncRequest($method, $uri, $payload, $headers);
    } else {
      return $this->syncRequest($method, $uri, $payload, $headers);
    }
  }

  /**
   * Sends sync request to SparkPost API.
   *
   * @param string $method
   * @param string $uri
   * @param array $payload
   * @param array $headers
   *
   * @return SparkPostResponse
   *
   * @throws SparkPostException
   */
  public function syncRequest($method = 'GET', $uri = '', $payload = [], $headers = []) {
    return $this->asyncRequest($method, $uri, $payload, $headers)->wait();
  }

  /**
   * Sends async request to SparkPost API.
   *
   * The request starts immediately. Call wait() on the returned promise (or
   * waitAll() on this object) to get the result. The number of requests in
   * flight at once is bounded by the client (see CurlClient::setMaxConcurrency).
   *
   * @param string $method
   * @param string $uri
   * @param array  $payload
   * @param array  $headers
   *
   * @return SparkPostPromise
   */
  public function asyncRequest($method = 'GET', $uri = '', $payload = [], $headers = []) {
    $requestValues = $this->buildRequestValues($method, $uri, $payload, $headers);
    return $this->httpClient->send($requestValues, $this->getTransferOptions(), $this->ifDebug($requestValues));
  }

  /**
   * Blocks until every request started through the HTTP client has settled.
   *
   * Rejections are delivered to the promises' then() callbacks (or to wait()),
   * they are not thrown from here.
   */
  public function waitAll() {
    $this->httpClient->waitAll();
  }

  /**
   * Builds request values from given params.
   *
   * @param string $method
   * @param string $uri
   * @param array  $payload
   * @param array  $headers
   *
   * @return array $requestValues
   */
  public function buildRequestValues($method, $uri, $payload, $headers) {
    $method = trim(strtoupper($method));

    if ($method === 'GET') {
      $params = $payload;
      $body = NULL;
    }
    else {
      $params = [];
      $body = json_encode($payload);
    }

    $url = $this->getUrl($uri, $params);
    $headers = $this->getHttpHeaders($headers);

    return [
      'method' => $method,
      'url' => $url,
      'headers' => $headers,
      'body' => $body,
    ];
  }

  /**
   * Builds the request values from given params.
   *
   * @return array - see buildRequestValues()
   */
  public function buildRequest($method, $uri, $payload, $headers) {
    return $this->buildRequestValues($method, $uri, $payload, $headers);
  }

  /**
   * Returns an array for the request headers.
   *
   * @param array $headers - any custom headers for the request
   *
   * @return array $headers - headers for the request
   */
  public function getHttpHeaders($headers = []) {
    $constantHeaders = [
      'Authorization' => $this->options['key'],
      'Content-Type' => 'application/json',
      'User-Agent' => 'php-sparkpost/' . $this->version,
    ];

    foreach ($constantHeaders as $key => $value) {
      $headers[$key] = $value;
    }

    return $headers;
  }

  /**
   * Builds the request url from the options and given params.
   *
   * @param string $path   - the path in the url to hit
   * @param array  $params - query parameters to be encoded into the url
   *
   * @return string $url - the url to send the desired request to
   */
  public function getUrl($path, $params = []) {
    $options = $this->options;
    $paramsArray = [];

    foreach ($params as $key => $value) {
      if (!is_array($value)) {
        $value = [$value];
      }
      $value = implode(',', array_map('rawurlencode', $value));
      array_push($paramsArray, rawurlencode($key) . '=' . $value);
    }

    $paramsString = implode('&', $paramsArray);
    return $options['protocol'] . '://' . $options['host'] . ($options['port'] ? ':' . $options['port'] : '') . '/api/' . $options['version'] . '/' . $path . ($paramsString ? '?' . $paramsString : '');
  }

  /**
   * Sets the HTTP client used for requests.
   *
   * Passing NULL (or any object that is not an HttpClientInterface, such as
   * an HTTPlug adapter from the previous version of this library) selects
   * the process-wide curl client, which shares connections between all
   * SparkPost instances.
   *
   * @param HttpClientInterface|object|NULL $httpClient
   *
   * @return SparkPost
   */
  public function setHttpClient($httpClient = NULL) {
    if (!$httpClient instanceof HttpClientInterface) {
      $httpClient = CurlClient::shared();
    }

    $this->httpClient = $httpClient;
    return $this;
  }

  /**
   * @return HttpClientInterface
   */
  public function getHttpClient() {
    return $this->httpClient;
  }

  /**
   * Sets the options from the param and defaults for the SparkPost object.
   *
   * @param array|string $options - either an string API key or an array of options
   *
   * @return SparkPost
   */
  public function setOptions($options) {
    // if the options map is a string we should assume that its an api key
    if (is_string($options)) {
      $options = ['key' => $options];
    }

    // Validate API key because its required
    if (!isset($this->options['key']) && (!isset($options['key']) || !preg_match('/\S/', $options['key']))) {
      throw new \Exception('You must provide an API key');
    }

    $this->options = isset($this->options) ? $this->options : self::$defaultOptions;

    // set options, overriding defaults
    foreach ($options as $option => $value) {
      if (key_exists($option, $this->options)) {
        $this->options[$option] = $value;
      }
    }

    return $this;
  }

  /**
   * @return array the current options
   */
  public function getOptions() {
    return $this->options;
  }

  /**
   * Options handed to the HTTP client for each transfer.
   *
   * @return array
   */
  private function getTransferOptions() {
    return [
      'retries' => $this->options['retries'],
      'timeout' => $this->options['timeout'],
      'connect_timeout' => $this->options['connect_timeout'],
      'curl_options' => $this->options['curl_options'],
    ];
  }

  /**
   * Returns the given value if debugging, an empty instance otherwise.
   *
   * @param any $param
   *
   * @return any $param
   */
  private function ifDebug($param) {
    return $this->options['debug'] ? $param : NULL;
  }

  /**
   * Sets up any endpoints to custom classes e.g. $this->transmissions.
   */
  private function setupEndpoints() {
    $this->transmissions = new Transmission($this);
  }

}
