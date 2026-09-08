<?php

namespace SparkPost;

class SparkPostException extends \Exception {
  /**
   * Variable to hold json decoded body from http response.
   */
  private $body = NULL;

  /**
   * Array with the request values sent.
   */
  private $request;

  /**
   * @var SparkPostResponse|NULL the response, when the API answered with an error status
   */
  private $response;

  /**
   * @var int CURLE_* code, when the request failed at the transport level
   */
  private $curlErrorNumber = 0;

  /**
   * @param string|\Throwable $message  - error message, or an exception to wrap
   * @param int         $code   - HTTP status code (0 for transport errors)
   * @param array|NULL    $request  - the request values sent (debug mode)
   * @param \Throwable|NULL   $previous
   */
  public function __construct($message = '', $code = 0, $request = NULL, ?\Throwable $previous = NULL) {
    // Backwards compatibility with the previous (exception, request) signature.
    if ($message instanceof \Throwable) {
      $previous = $message;
      $request = is_array($code) ? $code : $request;
      $code = $previous->getCode();
      $message = $previous->getMessage();
      if ($previous instanceof self) {
        $this->body = $previous->getBody();
        $this->response = $previous->getResponse();
        $this->curlErrorNumber = $previous->getCurlErrorNumber();
      }
    }

    $this->request = $request;

    parent::__construct((string) $message, (int) $code, $previous);
  }

  /**
   * Builds the exception for an API error response (status >= 400).
   *
   * The message is the raw response body and the code is the HTTP status.
   *
   * @param SparkPostResponse $response
   * @param array|NULL    $request - the request values sent (debug mode)
   *
   * @return SparkPostException
   */
  public static function fromResponse(SparkPostResponse $response, $request = NULL) {
    $exception = new self($response->getRawBody(), $response->getStatusCode(), $request);
    $exception->response = $response;
    $exception->body = $response->getBody();
    return $exception;
  }

  /**
   * Builds the exception for a transport (curl) failure.
   *
   * @param int    $errorNumber - CURLE_* code
   * @param string   $error     - curl error message
   * @param array|NULL $request   - the request values sent (debug mode)
   *
   * @return SparkPostException
   */
  public static function fromCurlError($errorNumber, $error, $request = NULL) {
    $exception = new self(sprintf('cURL error %d: %s', $errorNumber, $error), 0, $request);
    $exception->curlErrorNumber = (int) $errorNumber;
    return $exception;
  }

  /**
   * Returns the request values sent.
   *
   * @return array|NULL $request
   */
  public function getRequest() {
    return $this->request;
  }

  /**
   * Returns the body.
   *
   * @return array|NULL $body - the json decoded body from the http response
   */
  public function getBody() {
    return $this->body;
  }

  /**
   * @return SparkPostResponse|NULL the error response, NULL for transport errors
   */
  public function getResponse() {
    return $this->response;
  }

  /**
   * @return int CURLE_* code, 0 when the failure was not a transport error
   */
  public function getCurlErrorNumber() {
    return $this->curlErrorNumber;
  }

}
