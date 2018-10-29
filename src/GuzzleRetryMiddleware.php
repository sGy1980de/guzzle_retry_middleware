<?php
/**
 * Guzzle Retry Middleware Library
 *
 * @license http://opensource.org/licenses/MIT
 * @link https://github.com/caseyamcl/guzzle_retry_middleware
 * @version 2.0
 * @package caseyamcl/guzzle_retry_middleware
 * @author Casey McLaughlin <caseyamcl@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * ------------------------------------------------------------------
 */

namespace GuzzleRetry;

use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Promise;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Retry After Middleware
 *
 * Guzzle 6 middleware that retries requests when encountering responses
 * with certain conditions (429 or 503).  This middleware also respects
 * the `RetryAfter` header
 *
 * @author Casey McLaughlin <caseyamcl@gmail.com>
 */
class GuzzleRetryMiddleware
{
    // HTTP date format
    const DATE_FORMAT = 'D, d M Y H:i:s T';

    // Default retry header (off by default; configurable)
    const RETRY_HEADER = 'X-Retry-Counter';

    /**
     * @var array
     */
    private $defaultOptions = [

        // Retry enabled.  Toggle retry on or off per request
        'retry_enabled'                    => true,

        // If server doesn't provide a Retry-After header, then set a default back-off delay
        // NOTE: This can either be a float, or it can be a callable that returns a (accepts count and response|null)
        'default_retry_multiplier'         => 1.5,

        // Set a maximum number of attempts per request
        'max_retry_attempts'               => 10,

        // Set this to TRUE to retry only if the HTTP Retry-After header is specified
        'retry_only_if_retry_after_header' => false,

        // Only retry when status is equal to these response codes
        'retry_on_status'                  => ['429', '503'],

        // Callback to trigger when delay occurs (accepts count, delay, request, response, options)
        'on_retry_callback'                => null,

        // Retry on connect timeout?
        'retry_on_timeout'                 => false,

        // Add the number of retries to an X-Header
        'expose_retry_header'              => false,

        // The header key
        'retry_header'                     => self::RETRY_HEADER
    ];

    /**
     * @var callable
     */
    private $nextHandler;

    /**
     * Provides a closure that can be pushed onto the handler stack
     *
     * Example:
     * <code>$handlerStack->push(GuzzleRetryMiddleware::factory());</code>
     *
     * @param array $defaultOptions
     * @return \Closure
     */
    public static function factory(array $defaultOptions = [])
    {
        return function (callable $handler) use ($defaultOptions) {
            return new static($handler, $defaultOptions);
        };
    }

    /**
     * GuzzleRetryMiddleware constructor.
     *
     * @param callable $nextHandler
     * @param array $defaultOptions
     */
    public function __construct(callable $nextHandler, array $defaultOptions = [])
    {
        $this->nextHandler = $nextHandler;
        $this->defaultOptions = array_replace($this->defaultOptions, $defaultOptions);
    }

    /**
     * @param RequestInterface $request
     * @param array $options
     * @return Promise
     */
    public function __invoke(RequestInterface $request, array $options)
    {
        // Combine options with defaults specified by this middleware
        $options = array_replace($this->defaultOptions, $options);

        // Set the retry count if not already set
        if (! isset($options['retry_count'])) {
            $options['retry_count'] = 0;
        }

        /** @var callable $next */
        $next = $this->nextHandler;
        return $next($request, $options)
            ->then(
                $this->onFulfilled($request, $options),
                $this->onRejected($request, $options)
            );
    }

    /**
     * No exceptions were thrown during processing
     *
     * Depending on where this middleware is in the stack, the response could still
     * be unsuccessful (e.g. 429 or 503), so check to see if it should be retried
     *
     * @param RequestInterface $request
     * @param array $options
     * @return callable
     */
    protected function onFulfilled(RequestInterface $request, array $options)
    {
        return function (ResponseInterface $response) use ($request, $options) {
            return $this->shouldRetryHttpResponse($options, $response)
                ? $this->doRetry($request, $options, $response)
                : $this->returnResponse($options, $response);
        };
    }

    /**
     * An exception or error was thrown during processing
     *
     * If the reason is a BadResponseException exception, check to see if
     * the request can be retried.  Otherwise, pass it on.
     *
     * @param RequestInterface $request
     * @param array $options
     * @return callable
     */
    protected function onRejected(RequestInterface $request, array $options)
    {
        return function ($reason) use ($request, $options) {
            // If was bad response exception, test if we retry based on the response headers
            if ($reason instanceof BadResponseException
                && $this->shouldRetryHttpResponse($options, $reason->getResponse())
            ) {
                return $this->doRetry($request, $options, $reason->getResponse());
            }

            // If this was a connection exception, test to see if we should retry based on connect timeout rules
            if ($reason instanceof ConnectException
                && $this->shouldRetryConnectException($reason, $options)
            ) {
                return $this->doRetry($request, $options);
            }

            // If made it here, then we have decided not to retry the request
            return \GuzzleHttp\Promise\rejection_for($reason);
        };
    }

    protected function shouldRetryConnectException(ConnectException $e, array $options)
    {
        switch (true) {
            case $options['retry_enabled'] === false:
            case $this->countRemainingRetries($options) === 0:
                return false;

            // Test if this was a connection or response timeout exception
            case isset($e->getHandlerContext()['errno']) && (int) $e->getHandlerContext()['errno'] === 28:
                return isset($options['retry_on_timeout']) && (bool) $options['retry_on_timeout'] === true;
        }

        return false;
    }

    /**
     * Check to see if a request can be retried
     *
     * This checks two things:
     *
     * 1. The response status code against the status codes that should be retried
     * 2. The number of attempts made thus far for this request
     *
     * @param array $options
     * @param ResponseInterface|null $response
     * @return bool  TRUE if the response should be retried, FALSE if not
     */
    protected function shouldRetryHttpResponse(array $options, ResponseInterface $response)
    {
        $statuses = array_map('intval', (array) $options['retry_on_status']);

        switch (true) {
            case $options['retry_enabled'] === false:
            case $this->countRemainingRetries($options) === 0:
            // No Retry-After header, and it is required?  Give up
            case (! $response->hasHeader('Retry-After') && $options['retry_only_if_retry_after_header']):
                return false;

            // Conditions met; see if status code matches one that can be retried
            default:
                return in_array($response->getStatusCode(), $statuses, true);
        }
    }

    /**
     * Count the number of retries remaining.  Always returns 0 or greater.
     * @param array $options
     * @return int
     */
    protected function countRemainingRetries(array $options)
    {
        $retryCount  = isset($options['retry_count']) ? (int) $options['retry_count'] : 0;

        $numAllowed  = isset($options['max_retry_attempts'])
            ? (int) $options['max_retry_attempts']
            : $this->defaultOptions['max_retry_attempts'];

        return max([$numAllowed - $retryCount, 0]);
    }

    /**
     * Retry the request
     *
     * Increments the retry count, determines the delay (timeout), executes callbacks, sleeps, and re-send the request
     *
     * @param RequestInterface $request
     * @param array $options
     * @param ResponseInterface|null $response
     * @return Promise
     */
    protected function doRetry(RequestInterface $request, array $options, ResponseInterface $response = null)
    {
        // Increment the retry count
        ++$options['retry_count'];

        // Determine the delay timeout
        $delayTimeout = $this->determineDelayTimeout($options, $response);

        // Callback?
        if ($options['on_retry_callback']) {
            call_user_func(
                $options['on_retry_callback'],
                (int) $options['retry_count'],
                $delayTimeout,
                $request,
                $options,
                $response
            );
        }

        // Delay!
        usleep($delayTimeout * 1000000);

        // Return
        return $this($request, $options);
    }

    protected function returnResponse(array $options, ResponseInterface $response)
    {
        if ($options['expose_retry_header'] === false
            || $options['retry_count'] === 0
        ) {
            return $response;
        }

        return $response->withHeader($options['retry_header'], $options['retry_count']);
    }

    /**
     * Determine the delay timeout
     *
     * Attempts to read and interpret the HTTP `Retry-After` header, or defaults
     * to a built-in incremental back-off algorithm.
     *
     * @param ResponseInterface $response
     * @param array $options
     * @return float  Delay timeout, in seconds
     */
    protected function determineDelayTimeout(array $options, ResponseInterface $response = null)
    {
        if (is_callable($options['default_retry_multiplier'])) {
            $defaultDelayTimeout = (float) call_user_func(
                $options['default_retry_multiplier'],
                $options['retry_count'],
                $response
            );
        } else {
            $defaultDelayTimeout = (float) $options['default_retry_multiplier'] * $options['retry_count'];
        }

        // Retry-After can be a delay in seconds or a date
        // (see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Retry-After)
        if ($response && $response->hasHeader('Retry-After')) {
            return
                $this->deriveTimeoutFromHeader($response->getHeader('Retry-After')[0])
                ?: $defaultDelayTimeout;
        }

        return $defaultDelayTimeout;
    }

    /**
     * Attempt to derive the timeout from the HTTP `Retry-After` header
     *
     * The spec allows the header value to either be a number of seconds or a datetime.
     *
     * @param string $headerValue
     * @return float|null  The number of seconds to wait, or NULL if unsuccessful (invalid header)
     */
    protected function deriveTimeoutFromHeader($headerValue)
    {
        // The timeout will either be a number or a HTTP-formatted date, or seconds (integer)
        if ((string) ($intTimeout = (int) trim($headerValue)) === $headerValue) {
            return $intTimeout;
        }

        if ($date = \DateTime::createFromFormat(self::DATE_FORMAT, trim($headerValue))) {
            return (int) $date->format('U') - time();
        }

        return null;
    }
}
