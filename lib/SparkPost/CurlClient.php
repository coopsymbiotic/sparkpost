<?php

namespace SparkPost;

/**
 * curl based HTTP transport tuned for sending very large volumes of small
 * API requests (one transmission per email) to a single host.
 *
 * All requests, synchronous or asynchronous, go through one curl_multi
 * handle, which owns the connection cache. This means:
 *
 *  - TCP/TLS connections are established once and reused across requests,
 *    even across separate SparkPost instances when the shared client is used
 *    (new SparkPost() uses CurlClient::shared() by default).
 *  - HTTP/2 is negotiated when available, so concurrent requests are
 *    multiplexed over a single connection.
 *  - Easy handles are recycled instead of re-created for every request.
 *  - Asynchronous requests are driven by curl_multi, with a bounded number of
 *    requests in flight (see setMaxConcurrency()).
 */
class CurlClient implements HttpClientInterface
{
    /**
     * Transport errors for which retrying is safe and useful. They all happen
     * before or while (re)establishing a connection, which is typically what
     * goes wrong when a kept-alive connection was closed by the server.
     */
    const RETRYABLE_CURL_ERRORS = [
        CURLE_COULDNT_RESOLVE_HOST,
        CURLE_COULDNT_CONNECT,
        CURLE_SSL_CONNECT_ERROR,
        CURLE_GOT_NOTHING,
        CURLE_SEND_ERROR,
        CURLE_RECV_ERROR,
    ];

    const DEFAULT_TIMEOUT = 30;
    const DEFAULT_CONNECT_TIMEOUT = 10;
    const DEFAULT_MAX_CONCURRENCY = 10;

    /**
     * @var CurlClient|null process-wide instance
     */
    private static $shared;

    /**
     * @var \CurlMultiHandle
     */
    private $multi;

    /**
     * @var \CurlHandle[] easy handles not currently used by a transfer
     */
    private $idleHandles = [];

    /**
     * @var \stdClass[] in-flight transfers, keyed by spl_object_id of the easy handle
     */
    private $transfers = [];

    /**
     * @var int maximum number of requests in flight at once
     */
    private $maxConcurrency;

    /**
     * Returns the process-wide client, creating it on first use.
     *
     * Using a single client for the whole process is what allows connection
     * reuse between SparkPost instances.
     *
     * @return CurlClient
     */
    public static function shared()
    {
        if (self::$shared === null) {
            self::$shared = new self();
        }

        return self::$shared;
    }

    /**
     * @param int $maxConcurrency - maximum number of requests in flight at once
     */
    public function __construct($maxConcurrency = self::DEFAULT_MAX_CONCURRENCY)
    {
        if (!function_exists('curl_multi_init')) {
            throw new \RuntimeException('The SparkPost library requires the curl PHP extension.');
        }

        $this->multi = curl_multi_init();
        // Allow HTTP/2 multiplexing of several requests over one connection.
        curl_multi_setopt($this->multi, CURLMOPT_PIPELINING, CURLPIPE_MULTIPLEX);
        $this->setMaxConcurrency($maxConcurrency);
    }

    /**
     * Sets the maximum number of requests in flight at once.
     *
     * send() blocks (while driving pending transfers) once this many requests
     * are pending, so callers can fire requests in a loop without worrying
     * about memory or overwhelming the API. This also caps the number of
     * connections opened to a host when HTTP/2 is not available.
     *
     * @param int $maxConcurrency
     *
     * @return CurlClient
     */
    public function setMaxConcurrency($maxConcurrency)
    {
        $this->maxConcurrency = max(1, (int) $maxConcurrency);
        curl_multi_setopt($this->multi, CURLMOPT_MAX_HOST_CONNECTIONS, $this->maxConcurrency);

        return $this;
    }

    /**
     * @return int
     */
    public function getMaxConcurrency()
    {
        return $this->maxConcurrency;
    }

    /**
     * @return int number of requests currently in flight
     */
    public function getPendingCount()
    {
        return count($this->transfers);
    }

    /**
     * {@inheritdoc}
     */
    public function send(array $request, array $options = [], $debugRequest = null)
    {
        // Back-pressure: keep at most maxConcurrency requests in flight.
        while (count($this->transfers) >= $this->maxConcurrency) {
            $this->step(true);
        }

        $transfer = new \stdClass();
        $transfer->request = $request;
        $transfer->options = $options;
        $transfer->debug = $debugRequest;
        $transfer->retries = isset($options['retries']) ? (int) $options['retries'] : 0;
        $transfer->attempts = 0;
        $transfer->promise = new SparkPostPromise([$this, 'waitFor'], $debugRequest);
        $this->resetAttemptState($transfer);

        $handle = $this->acquireHandle();
        $transfer->handle = $handle;
        $this->configureHandle($handle, $transfer);

        $this->transfers[spl_object_id($handle)] = $transfer;
        curl_multi_add_handle($this->multi, $handle);

        return $transfer->promise;
    }

    /**
     * Drives pending transfers until the given promise settles.
     *
     * Used as the promise's wait function; it can also be called directly.
     *
     * @param SparkPostPromise $promise
     */
    public function waitFor(SparkPostPromise $promise)
    {
        while ($promise->getState() === SparkPostPromise::PENDING) {
            if (empty($this->transfers)) {
                // Nothing left to drive, the promise cannot settle from here.
                return;
            }
            $this->step(true);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function waitAll()
    {
        while (!empty($this->transfers)) {
            $this->step(true);
        }
    }

    /**
     * Performs any pending network activity without blocking.
     *
     * Useful when interleaving other work with in-flight async requests.
     */
    public function tick()
    {
        $this->step(false);
    }

    /**
     * One iteration of the event loop: perform I/O, dispatch completed
     * transfers and optionally wait for activity on the sockets.
     *
     * @param bool $block - whether to wait for socket activity
     */
    private function step($block)
    {
        do {
            $status = curl_multi_exec($this->multi, $running);
        } while ($status === CURLM_CALL_MULTI_PERFORM);

        if ($status !== CURLM_OK) {
            throw new \RuntimeException('curl_multi error: '.curl_multi_strerror($status));
        }

        $completed = false;
        while (($info = curl_multi_info_read($this->multi)) !== false) {
            $this->complete($info['handle'], (int) $info['result']);
            $completed = true;
        }

        if ($block && !$completed && !empty($this->transfers)) {
            if (curl_multi_select($this->multi, 1.0) === -1) {
                // Select failed (rare, platform dependent). Avoid a busy loop.
                usleep(1000);
            }
        }
    }

    /**
     * Handles a finished transfer: retry, resolve or reject.
     *
     * @param \CurlHandle $handle
     * @param int         $result - CURLE_* code for this transfer
     */
    private function complete($handle, $result)
    {
        $id = spl_object_id($handle);
        if (!isset($this->transfers[$id])) {
            return;
        }
        $transfer = $this->transfers[$id];
        ++$transfer->attempts;
        curl_multi_remove_handle($this->multi, $handle);

        if ($result === CURLE_OK) {
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $body = (string) curl_multi_getcontent($handle);

            if ($status >= 500 && $status <= 599 && $transfer->attempts <= $transfer->retries) {
                $this->retry($transfer);

                return;
            }

            $this->finish($transfer);

            $response = new SparkPostResponse(
                $status,
                $transfer->headers,
                $body,
                $transfer->debug,
                $transfer->reason,
                $transfer->protocol
            );

            if ($status >= 400) {
                $transfer->promise->reject(SparkPostException::fromResponse($response, $transfer->debug));
            } else {
                $transfer->promise->resolve($response);
            }

            return;
        }

        $error = curl_error($handle);

        if (in_array($result, self::RETRYABLE_CURL_ERRORS, true) && $transfer->attempts <= $transfer->retries) {
            $this->retry($transfer);

            return;
        }

        $this->finish($transfer);
        $transfer->promise->reject(SparkPostException::fromCurlError($result, $error, $transfer->debug));
    }

    /**
     * Re-queues the transfer on the same handle.
     */
    private function retry($transfer)
    {
        $this->resetAttemptState($transfer);
        curl_multi_add_handle($this->multi, $transfer->handle);
    }

    /**
     * Removes the transfer from the in-flight list and recycles its handle.
     */
    private function finish($transfer)
    {
        unset($this->transfers[spl_object_id($transfer->handle)]);
        $this->releaseHandle($transfer->handle);
        $transfer->handle = null;
    }

    private function resetAttemptState($transfer)
    {
        $transfer->headers = [];
        $transfer->reason = '';
        $transfer->protocol = '1.1';
    }

    /**
     * @return \CurlHandle
     */
    private function acquireHandle()
    {
        $handle = array_pop($this->idleHandles);
        if ($handle === null) {
            $handle = curl_init();
        }

        return $handle;
    }

    /**
     * @param \CurlHandle $handle
     */
    private function releaseHandle($handle)
    {
        // Drop callbacks/options (and the closure referencing the transfer).
        curl_reset($handle);
        if (count($this->idleHandles) < $this->maxConcurrency) {
            $this->idleHandles[] = $handle;
        }
    }

    /**
     * Applies the request and the performance related options to the handle.
     *
     * @param \CurlHandle $handle
     * @param \stdClass   $transfer
     */
    private function configureHandle($handle, $transfer)
    {
        $request = $transfer->request;
        $options = $transfer->options;

        $headers = [];
        foreach ($request['headers'] as $name => $value) {
            $headers[] = $name.': '.$value;
        }
        // Never wait for a "100 Continue": it costs a round trip (or a full
        // second on servers that ignore it) on every request with a body.
        $headers[] = 'Expect:';

        $curlOptions = [
            CURLOPT_URL => $request['url'],
            CURLOPT_CUSTOMREQUEST => $request['method'],
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_HEADERFUNCTION => function ($handle, $line) use ($transfer) {
                $this->parseHeaderLine($transfer, $line);

                return strlen($line);
            },
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => isset($options['connect_timeout']) ? $options['connect_timeout'] : self::DEFAULT_CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => isset($options['timeout']) ? $options['timeout'] : self::DEFAULT_TIMEOUT,
            // Accept (and transparently decode) compressed responses.
            CURLOPT_ACCEPT_ENCODING => '',
            // Keep idle connections alive and send small requests promptly.
            CURLOPT_TCP_KEEPALIVE => 1,
            CURLOPT_TCP_NODELAY => 1,
            // The API host does not change; do not re-resolve it every minute.
            CURLOPT_DNS_CACHE_TIMEOUT => 600,
            // HTTP/2 over TLS when the server supports it (multiplexing, header
            // compression), transparent fallback to HTTP/1.1 otherwise.
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2TLS,
        ];

        // CURLOPT_PROTOCOLS_STR was introduced in PHP 8.3 (for now we support PHP 8.2)
        if (defined('CURLOPT_PROTOCOLS_STR')) {
            // phpcs:ignore
            $curlOptions[CURLOPT_PROTOCOLS_STR] = 'https,http';
        } else {
            $curlOptions[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS | CURLPROTO_HTTP;
        }

        $body = isset($request['body']) ? $request['body'] : null;
        if ($request['method'] !== 'GET' && $request['method'] !== 'HEAD' && $body !== null && $body !== '') {
            $curlOptions[CURLOPT_POSTFIELDS] = $body;
        }

        if (!empty($options['curl_options']) && is_array($options['curl_options'])) {
            foreach ($options['curl_options'] as $option => $value) {
                $curlOptions[$option] = $value;
            }
        }

        if (!curl_setopt_array($handle, $curlOptions)) {
            throw new \RuntimeException('Unable to configure curl handle: '.curl_error($handle));
        }
    }

    /**
     * Collects response headers. Called once per header line by curl.
     */
    private function parseHeaderLine($transfer, $line)
    {
        $line = trim($line);
        if ($line === '') {
            return;
        }

        // A new status line starts a new set of headers (e.g. after an
        // informational 1xx response).
        if (stripos($line, 'HTTP/') === 0) {
            $transfer->headers = [];
            if (preg_match('#^HTTP/(\S+)\s+\d{3}\s*(.*)$#', $line, $matches)) {
                $transfer->protocol = $matches[1];
                $transfer->reason = $matches[2];
            }

            return;
        }

        $pos = strpos($line, ':');
        if ($pos === false) {
            return;
        }

        $name = trim(substr($line, 0, $pos));
        $value = trim(substr($line, $pos + 1));
        $transfer->headers[$name][] = $value;
    }
}
