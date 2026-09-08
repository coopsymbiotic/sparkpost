<?php

namespace SparkPost;

/**
 * Minimal transport contract used by SparkPost.
 *
 * The default implementation is CurlClient. Implement this interface to
 * substitute a different transport (e.g. a fake client in unit tests).
 */
interface HttpClientInterface {
  /**
   * Starts a request and returns a promise for its response.
   *
   * @param array $request    - ['method' => string, 'url' => string, 'headers' => array, 'body' => string|null]
   * @param array $options    - transfer options: retries, timeout, connect_timeout, curl_options
   * @param array $debugRequest - the request values to attach to the response/exception (debug mode), or null
   *
   * @return SparkPostPromise
   */
  public function send(array $request, array $options = [], $debugRequest = null);

  /**
   * Blocks until every request started through this client has settled.
   */
  public function waitAll();

}
