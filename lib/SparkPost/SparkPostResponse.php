<?php

namespace SparkPost;

/**
 * An HTTP response from the SparkPost API.
 *
 * Immutable value object. The accessor names follow PSR-7 so existing code
 * written against the previous (PSR-7 backed) version keeps working.
 */
class SparkPostResponse {
  /**
   * @var int
   */
  private $statusCode;

  /**
   * @var string
   */
  private $reasonPhrase;

  /**
   * @var string
   */
  private $protocolVersion;

  /**
   * @var array header name => list of values, in the order received
   */
  private $headers = [];

  /**
   * @var array lowercased header name => original header name
   */
  private $headerNames = [];

  /**
   * @var string raw response body
   */
  private $body;

  /**
   * Array with the request values sent (debug mode only).
   */
  private $request;

  /**
   * @param int     $statusCode
   * @param array     $headers     - header name => string|string[] value(s)
   * @param string    $body      - raw body
   * @param array|NULL  $request     - the request values sent (debug mode)
   * @param string    $reasonPhrase
   * @param string    $protocolVersion
   */
  public function __construct($statusCode, array $headers = [], $body = '', $request = NULL, $reasonPhrase = '', $protocolVersion = '1.1') {
    $this->statusCode = (int) $statusCode;
    $this->body = (string) $body;
    $this->request = $request;
    $this->reasonPhrase = (string) $reasonPhrase;
    $this->protocolVersion = (string) $protocolVersion;

    foreach ($headers as $name => $values) {
      $this->setHeader($name, $values);
    }
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
    return json_decode($this->body, TRUE);
  }

  /**
   * Returns the raw, undecoded body.
   *
   * @return string
   */
  public function getRawBody() {
    return $this->body;
  }

  public function getProtocolVersion() {
    return $this->protocolVersion;
  }

  public function withProtocolVersion($version) {
    $new = clone $this;
    $new->protocolVersion = (string) $version;

    return $new;
  }

  /**
   * @return array header name => string[] values
   */
  public function getHeaders() {
    return $this->headers;
  }

  public function hasHeader($name) {
    return isset($this->headerNames[strtolower($name)]);
  }

  /**
   * @return string[] values, empty array if the header is absent
   */
  public function getHeader($name) {
    $key = strtolower($name);
    if (!isset($this->headerNames[$key])) {
      return [];
    }

    return $this->headers[$this->headerNames[$key]];
  }

  /**
   * @return string comma separated values, empty string if the header is absent
   */
  public function getHeaderLine($name) {
    return implode(', ', $this->getHeader($name));
  }

  public function withHeader($name, $value) {
    $new = clone $this;
    $new->removeHeader($name);
    $new->setHeader($name, $value);

    return $new;
  }

  public function withAddedHeader($name, $value) {
    $new = clone $this;
    $new->setHeader($name, $value);

    return $new;
  }

  public function withoutHeader($name) {
    $new = clone $this;
    $new->removeHeader($name);

    return $new;
  }

  /**
   * @param string $body - raw body
   */
  public function withBody($body) {
    $new = clone $this;
    $new->body = (string) $body;

    return $new;
  }

  public function getStatusCode() {
    return $this->statusCode;
  }

  public function withStatus($code, $reasonPhrase = '') {
    $new = clone $this;
    $new->statusCode = (int) $code;
    $new->reasonPhrase = (string) $reasonPhrase;

    return $new;
  }

  public function getReasonPhrase() {
    return $this->reasonPhrase;
  }

  private function setHeader($name, $values) {
    $key = strtolower($name);
    if (isset($this->headerNames[$key])) {
      $name = $this->headerNames[$key];
    }
    else {
      $this->headerNames[$key] = $name;
      $this->headers[$name] = [];
    }

    foreach ((array) $values as $value) {
      $this->headers[$name][] = (string) $value;
    }
  }

  private function removeHeader($name) {
    $key = strtolower($name);
    if (isset($this->headerNames[$key])) {
      unset($this->headers[$this->headerNames[$key]], $this->headerNames[$key]);
    }
  }

}
