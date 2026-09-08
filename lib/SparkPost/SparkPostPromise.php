<?php

namespace SparkPost;

/**
 * A small promise for asynchronous requests.
 *
 * The public API mirrors the HTTPlug promise the library used to wrap:
 * then(), getState() and wait(). Promises are settled by the transport
 * (see CurlClient) and wait() drives the transport until that happens.
 */
class SparkPostPromise {
  const PENDING = 'pending';
  const FULFILLED = 'fulfilled';
  const REJECTED = 'rejected';

  /**
   * @var string one of the state constants
   */
  private $state = self::PENDING;

  /**
   * @var mixed value the promise was fulfilled with (normally a SparkPostResponse)
   */
  private $value;

  /**
   * @var \Throwable reason the promise was rejected with (normally a SparkPostException)
   */
  private $reason;

  /**
   * @var callable|null function ($promise) that blocks until this promise settles
   */
  private $waitFn;

  /**
   * @var array list of [onFulfilled, onRejected, childPromise] registered with then()
   */
  private $handlers = [];

  /**
   * Array with the request values sent (debug mode only).
   */
  private $request;

  /**
   * @param callable|null $waitFn  - called with this promise; must drive the transport until the promise settles
   * @param array|null  $request - the request values sent (debug mode)
   */
  public function __construct(?callable $waitFn = null, $request = null) {
    $this->waitFn = $waitFn;
    $this->request = $request;
  }

  /**
   * Returns the request values sent.
   *
   * @return array|null $request
   */
  public function getRequest() {
    return $this->request;
  }

  /**
   * Registers callbacks and returns a new promise for their outcome.
   *
   * @param callable|null $onFulfilled - receives the SparkPostResponse
   * @param callable|null $onRejected  - receives the SparkPostException
   *
   * @return SparkPostPromise
   */
  public function then(?callable $onFulfilled = null, ?callable $onRejected = null) {
    $child = new self(function () {
      $this->wait(false);
    }, $this->request);

    $handler = [$onFulfilled, $onRejected, $child];

    if ($this->state === self::PENDING) {
      $this->handlers[] = $handler;
    }
    else {
      $this->runHandler($handler);
    }

    return $child;
  }

  /**
   * @return string one of 'pending', 'fulfilled', 'rejected'
   */
  public function getState() {
    return $this->state;
  }

  /**
   * Blocks until the promise is settled.
   *
   * @param bool $unwrap - when true, return the response or throw the rejection reason
   *
   * @return SparkPostResponse|mixed|null
   *
   * @throws SparkPostException
   */
  public function wait($unwrap = true) {
    if ($this->state === self::PENDING && $this->waitFn !== null) {
      call_user_func($this->waitFn, $this);
    }

    if ($this->state === self::PENDING) {
      throw new \RuntimeException('The promise did not settle while waiting.');
    }

    if (!$unwrap) {
      return null;
    }

    if ($this->state === self::REJECTED) {
      throw $this->reason;
    }

    return $this->value;
  }

  /**
   * Fulfills the promise. Called by the transport.
   *
   * @internal
   */
  public function resolve($value) {
    if ($this->state !== self::PENDING) {
      return;
    }
    $this->state = self::FULFILLED;
    $this->value = $value;
    $this->waitFn = null;
    $this->flushHandlers();
  }

  /**
   * Rejects the promise. Called by the transport.
   *
   * @internal
   */
  public function reject(\Throwable $reason) {
    if ($this->state !== self::PENDING) {
      return;
    }
    $this->state = self::REJECTED;
    $this->reason = $reason;
    $this->waitFn = null;
    $this->flushHandlers();
  }

  private function flushHandlers() {
    $handlers = $this->handlers;
    $this->handlers = [];
    foreach ($handlers as $handler) {
      $this->runHandler($handler);
    }
  }

  private function runHandler(array $handler) {
    list($onFulfilled, $onRejected, $child) = $handler;

    try {
      if ($this->state === self::FULFILLED) {
        $result = $onFulfilled !== null ? $onFulfilled($this->value) : $this->value;
      }
      else {
        if ($onRejected === null) {
          $child->reject($this->reason);
          return;
        }
        $result = $onRejected($this->reason);
      }
    }
    catch (\Throwable $e) {
      $child->reject($e);
      return;
    }

    if ($result instanceof self) {
      $result->then([$child, 'resolve'], [$child, 'reject']);
    }
    else {
      $child->resolve($result);
    }
  }

}
