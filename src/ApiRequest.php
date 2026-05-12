<?php

namespace SimplifiApi;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;

/**
 * Class ApiRequest
 */
class ApiRequest
{
    /**
     * Used when parsing in a url as an array
     * EG ApiRequest::request(['url' => ["sales/$/invoice", 102], 'method' => 'get'])
     * the url would be decoded to sales/102/invoice
     *
     * @var string this can multiple characters EG "%%"
     */
    const URL_REPLACEMENT_CHARACTER = '$';

    /**
     * Location of the config file (contains OAuth credentials etc.)
     */
    const CONFIG_FILE = __DIR__.'/../config.php';

    /**
     * The before request event identifier
     */
    const EVENT_BEFORE_REQUEST = 'beforeRequest';

    /**
     * The after request event identifier
     */
    const EVENT_AFTER_REGULAR_REQUEST = 'afterRegularRequest';

    /**
     * The after async request event identifier
     */
    const EVENT_AFTER_ASYNC_REQUEST = 'afterAsyncRequest';

    /**
     * The after batch request event identifier
     */
    const EVENT_AFTER_BATCH_REQUEST = 'afterBatchRequest';

    /**
     * An array of events added by self::addEventListener()
     */
    private static array $events = [];

    /**
     * This will hold the contents of CONFIG_FILE in an array for quick access
     */
    private static array $config = [];

    /**
     * These are the default request options. Each can be overridden by the $options parameter in the request method
     */
    private static array $defaultRequestOptions = [

        /**
         * Request method
         * GET(default)/POST/PUT/OPTIONS/DELETE/HEAD/PATCH
         */
        'method' => 'GET',

        /**
         * Endpoint to request.
         * This will be appended to 'url-base' in config.php to get the full URL
         * If method=GET then 'data' will be appended to this as well using http_build_query()
         *
         * This MUST BE PASSED IN via $options in self::request()
         */
        // 'url'             => 'sales',       // Must be passed in

        /**
         * Data to be send as payload or querystring along with the request
         * This can be used for any request GET/POST/etc.
         * May be:
         *      Array: of key value pairs ready for http_build_query()
         *      String: To be set as the request payload e.g. for a JSON request
         *
         * This MUST BE PASSED IN via $options in self::request()
         */
        'data' => [],

        /**
         * Any headers to pass add to the curl requestData to be send as payload or querystring along with the request
         * This can be used for any request GET/POST/etc.
         * May be:
         *      Array: of key value pairs ready for http_build_query()
         *      String: To be set as the request payload e.g. for a JSON request
         *
         * This MUST BE PASSED IN via $options in self::request()
         */
        'headers' => [
            'Accept' => 'application/json',
        ],

        /**
         * Automatically add access token to the request
         * TRUE(default): you do not need to specify access_token in 'data' (it will be automatically added)
         * FALSE: access_token will not be automatically added to 'data' (useful for endpoints that do not request an
         *      access_token to be set)
         */
        'with-access-token' => true,

        /**
         * Expected response type. If response does not match this then it is considered invalid
         *      'json'(default): If response body is not valid JSON then ApiResponse->success() will return false
         *      NULL: don't care what format the response body is in.
         */
        'response-type' => 'json',

        /**
         * Always leave this as true, it is an internal variable that.
         * If set to false we will not clear the accesstoken cache and retry the request if an AuthenticationException occurs
         */
        'retry-on-authentication-exception' => true,
    ];

    /**
     * Do a request (synchronous, Guzzle-backed)
     *
     * @param  array  $options  check $defaultRequestOptions for a list of available options
     * @param  array  $overrideConfig  This will override options in the config.php file if you want
     * @param  ?float  $timerStart  leave this blank it is an internal variable
     * @return ApiResponse result from the request i.e. check ApiResponse::success() to see if it was successful
     *
     * @throws \Exception if URL isn't specified
     */
    public static function request(array $options, array $overrideConfig = [], ?float $timerStart = null): ApiResponse
    {
        $config = self::getConfig($overrideConfig);
        $thisOptions = array_merge(self::$defaultRequestOptions, $options);

        if ($thisOptions['with-access-token'] && ! isset($thisOptions['data']['access_token'])) {
            $accessToken = self::getAccessToken();
            if (is_string($accessToken)) {
                $thisOptions['headers']['Authorization'] = 'Bearer '.$accessToken;
            } else {
                // An error occurred while getting access token so return the ApiResponse from getAccessToken
                return $accessToken;
            }
        }

        if (! isset($thisOptions['url']) && ! isset($thisOptions['url-absolute'])) {
            throw new \Exception('ERROR: Url not specified for request.');
        }

        if (isset(self::$events[self::EVENT_BEFORE_REQUEST])) {
            foreach (self::$events[self::EVENT_BEFORE_REQUEST] as $event) {
                $event($thisOptions, $config);
            }
        }

        if ($config['add_trace_debug_header']) {
            if ($debugHeader = (ApiResponse::getCallerFromBacktrace(debug_backtrace(), __FILE__, __CLASS__)[0] ?? null)) {
                $thisOptions['headers']['trace-debug-header'] = $debugHeader;
            }
        }

        // Add config headers first, then per-request headers (per-request wins)
        if (! empty($config['headers'])) {
            foreach ($config['headers'] as $key => $value) {
                if (! isset($thisOptions['headers'][$key])) {
                    $thisOptions['headers'][$key] = $value;
                }
            }
        }

        // Ensure User-Agent
        if (! isset($thisOptions['headers']['User-Agent'])) {
            $thisOptions['headers']['User-Agent'] = self::getDefaultUserAgent($config['VERSION'] ?? '');
        }

        if (! $timerStart) {
            $timerStart = microtime(true);
        }
        $thisOptions['__timerStart'] = $timerStart;

        $response = AsyncClient::request($thisOptions, $config);

        // Retry once on AuthenticationException
        if ($thisOptions['retry-on-authentication-exception'] && self::responseIsAuthenticationException($response->response())) {
            AccessToken::clearCache();
            $options['retry-on-authentication-exception'] = false;

            return self::request($options, $overrideConfig, $timerStart);
        }

        // After request events
        if (isset(self::$events[self::EVENT_AFTER_REGULAR_REQUEST])) {
            foreach (self::$events[self::EVENT_AFTER_REGULAR_REQUEST] as $event) {
                $event($response);
            }
        }

        return $response;
    }

    /**
     * Make an asynchronous request using Guzzle
     *
     * Returns a Promise that resolves to an ApiResponse.
     *
     * @return PromiseInterface Promise that resolves to ApiResponse
     */
    public static function requestAsync(array $options, array $overrideConfig = []): PromiseInterface
    {
        $config = self::getConfig($overrideConfig);
        $thisOptions = array_merge(self::$defaultRequestOptions, $options);

        // Handle access token
        if ($thisOptions['with-access-token'] && ! isset($thisOptions['data']['access_token'])) {
            $accessToken = self::getAccessToken();
            if (is_string($accessToken)) {
                $thisOptions['headers']['Authorization'] = 'Bearer '.$accessToken;
            } else {
                // Return a rejected promise with the error response
                return Create::promiseFor($accessToken);
            }
        }

        if (! isset($thisOptions['url']) && ! isset($thisOptions['url-absolute'])) {
            return Create::rejectionFor(
                new \Exception('ERROR: Url not specified for request.')
            );
        }

        // Fire before request events
        if (isset(self::$events[self::EVENT_BEFORE_REQUEST])) {
            foreach (self::$events[self::EVENT_BEFORE_REQUEST] as $event) {
                $event($thisOptions, $config);
            }
        }

        // Add debug header if configured
        if ($config['add_trace_debug_header']) {
            if ($debugHeader = (ApiResponse::getCallerFromBacktrace(debug_backtrace(), __FILE__, __CLASS__)[0] ?? null)) {
                $thisOptions['headers']['trace-debug-header'] = $debugHeader;
            }
        }

        // Add config headers
        if (! empty($config['headers'])) {
            foreach ($config['headers'] as $key => $value) {
                if (! isset($thisOptions['headers'][$key])) {
                    $thisOptions['headers'][$key] = $value;
                }
            }
        }

        // Ensure User-Agent
        if (! isset($thisOptions['headers']['User-Agent'])) {
            $thisOptions['headers']['User-Agent'] = self::getDefaultUserAgent($config['VERSION'] ?? '');
        }

        return AsyncClient::requestAsync($thisOptions, $config)
            ->then(function (ApiResponse $response) use ($thisOptions, $options, $overrideConfig) {
                // Check for authentication exception and retry
                if ($thisOptions['retry-on-authentication-exception'] && self::responseIsAuthenticationException($response->response())) {
                    AccessToken::clearCache();
                    $options['retry-on-authentication-exception'] = false;

                    return self::requestAsync($options, $overrideConfig)->wait();
                }

                // Fire after request events
                if (isset(self::$events[self::EVENT_AFTER_ASYNC_REQUEST])) {
                    foreach (self::$events[self::EVENT_AFTER_ASYNC_REQUEST] as $event) {
                        $event($response);
                    }
                }

                return $response;
            });
    }

    /**
     * Execute multiple requests concurrently
     *
     * @return ApiResponse[] Array of responses (same order as requests)
     */
    public static function batch(array $requests, array $overrideConfig = []): array
    {
        $config = self::getConfig($overrideConfig);

        // Get access token once for all requests
        $accessToken = null;
        $needsToken = false;

        foreach ($requests as $options) {
            $merged = array_merge(self::$defaultRequestOptions, $options);
            if ($merged['with-access-token'] && ! isset($merged['data']['access_token'])) {
                $needsToken = true;
                break;
            }
        }

        if ($needsToken) {
            $accessToken = self::getAccessToken();
            if (! is_string($accessToken)) {
                return array_fill_keys(array_keys($requests), $accessToken);
            }
        }

        // Calculate debug header once outside loop
        $debugHeader = null;
        if ($config['add_trace_debug_header']) {
            $debugHeader = ApiResponse::getCallerFromBacktrace(debug_backtrace(), __FILE__, __CLASS__)[0] ?? null;
        }

        // Prepare all requests with merged options
        $preparedRequests = [];
        foreach ($requests as $key => $options) {
            $thisOptions = array_merge(self::$defaultRequestOptions, $options);

            if ($thisOptions['with-access-token'] && ! isset($thisOptions['data']['access_token']) && $accessToken) {
                $thisOptions['headers']['Authorization'] = 'Bearer '.$accessToken;
            }

            if (! isset($thisOptions['url']) && ! isset($thisOptions['url-absolute'])) {
                throw new \Exception("ERROR: Url not specified for request at index {$key}");
            }

            if (isset(self::$events[self::EVENT_BEFORE_REQUEST])) {
                foreach (self::$events[self::EVENT_BEFORE_REQUEST] as $event) {
                    $event($thisOptions, $config);
                }
            }

            if ($debugHeader) {
                $thisOptions['headers']['trace-debug-header'] = $debugHeader;
            }

            // Add config headers (don't overwrite per-request headers)
            if (! empty($config['headers'])) {
                foreach ($config['headers'] as $headerKey => $value) {
                    if (! isset($thisOptions['headers'][$headerKey])) {
                        $thisOptions['headers'][$headerKey] = $value;
                    }
                }
            }

            if (! isset($thisOptions['headers']['User-Agent'])) {
                $thisOptions['headers']['User-Agent'] = self::getDefaultUserAgent($config['VERSION'] ?? '');
            }

            $preparedRequests[$key] = $thisOptions;
        }

        // Execute all requests concurrently
        $responses = AsyncClient::batch($preparedRequests, $config);

        // Fire after request events and handle auth exceptions
        foreach ($responses as $key => $response) {
            $thisOptions = $preparedRequests[$key];
            if ($thisOptions['retry-on-authentication-exception'] && self::responseIsAuthenticationException($response->response())) {
                AccessToken::clearCache();
                $retryOptions = $requests[$key];
                $retryOptions['retry-on-authentication-exception'] = false;
                $responses[$key] = self::request($retryOptions, $overrideConfig);
            } else {
                if (isset(self::$events[self::EVENT_AFTER_BATCH_REQUEST])) {
                    foreach (self::$events[self::EVENT_AFTER_BATCH_REQUEST] as $event) {
                        $event($response);
                    }
                }
            }
        }

        return $responses;
    }

    /**
     * Execute multiple requests with a concurrency limit
     *
     * @return ApiResponse[] Array of responses
     */
    public static function batchWithConcurrency(array $requests, int $concurrency = 5, array $overrideConfig = []): array
    {
        $config = self::getConfig($overrideConfig);

        $accessToken = null;
        $needsToken = false;

        foreach ($requests as $options) {
            $merged = array_merge(self::$defaultRequestOptions, $options);
            if ($merged['with-access-token'] && ! isset($merged['data']['access_token'])) {
                $needsToken = true;
                break;
            }
        }

        if ($needsToken) {
            $accessToken = self::getAccessToken();
            if (! is_string($accessToken)) {
                return array_fill_keys(array_keys($requests), $accessToken);
            }
        }

        $debugHeader = null;
        if ($config['add_trace_debug_header']) {
            $debugHeader = ApiResponse::getCallerFromBacktrace(debug_backtrace(), __FILE__, __CLASS__)[0] ?? null;
        }

        $preparedRequests = [];
        foreach ($requests as $key => $options) {
            $thisOptions = array_merge(self::$defaultRequestOptions, $options);

            if ($thisOptions['with-access-token'] && ! isset($thisOptions['data']['access_token']) && $accessToken) {
                $thisOptions['headers']['Authorization'] = 'Bearer '.$accessToken;
            }

            if (! isset($thisOptions['url']) && ! isset($thisOptions['url-absolute'])) {
                throw new \Exception("ERROR: Url not specified for request at index {$key}");
            }

            if (isset(self::$events[self::EVENT_BEFORE_REQUEST])) {
                foreach (self::$events[self::EVENT_BEFORE_REQUEST] as $event) {
                    $event($thisOptions, $config);
                }
            }

            if ($debugHeader) {
                $thisOptions['headers']['trace-debug-header'] = $debugHeader;
            }

            if (! empty($config['headers'])) {
                foreach ($config['headers'] as $headerKey => $value) {
                    if (! isset($thisOptions['headers'][$headerKey])) {
                        $thisOptions['headers'][$headerKey] = $value;
                    }
                }
            }

            if (! isset($thisOptions['headers']['User-Agent'])) {
                $thisOptions['headers']['User-Agent'] = self::getDefaultUserAgent($config['VERSION'] ?? '');
            }

            $preparedRequests[$key] = $thisOptions;
        }

        return AsyncClient::batchWithConcurrency($preparedRequests, $config, $concurrency);
    }

    /**
     * Returns true if a decoded response indicates an authentication exception.
     *
     * Accepts both stdClass and array shapes (covers both legacy
     * {"type":"AuthenticationException", ...} and new Laravel
     * {"message":"Unauthenticated."} shapes).
     */
    private static function responseIsAuthenticationException(mixed $response): bool
    {
        if (is_object($response)) {
            return (isset($response->type) && $response->type === 'AuthenticationException') ||
                (isset($response->message) && $response->message === 'Unauthenticated.');
        }

        if (is_array($response)) {
            return (isset($response['type']) && $response['type'] === 'AuthenticationException') ||
                (isset($response['message']) && $response['message'] === 'Unauthenticated.');
        }

        return false;
    }

    /**
     * Grab the config out of the config.php file and store it in $config field
     *
     * @param  array  $overrideConfig  if you want to override any values in the config.php file
     */
    private static function getConfig(array $overrideConfig = []): array
    {
        if (! self::$config) {
            self::$config = require self::CONFIG_FILE;
        }
        if ($overrideConfig) {
            self::$config = array_merge(self::$config, $overrideConfig);
        }

        return self::$config;
    }

    /**
     * Return the current access token for the API, or regenerate a new one and return that.
     *
     * @return ApiResponse|string string: success. ApiResponse: failed
     */
    public static function getAccessToken(): ApiResponse|string
    {
        return AccessToken::getAccessToken(self::getConfig());
    }

    /**
     * Add an event listener
     */
    public static function addEventListener(string $event, callable $callback): void
    {
        self::$events[$event][] = $callback;
    }

    /**
     * Get the default user agent string
     *
     * @param  string  $urlVersion  e.g. "1.0.0"
     */
    private static function getDefaultUserAgent(string $urlVersion): string
    {
        $userAgent = 'Simplifi-HQ-API/'.$urlVersion.' (+https://github.com/greghanton/simplifi-hq-oauth-api)';
        $userAgent .= ' GuzzleHttp/'.GuzzleClient::MAJOR_VERSION;
        $userAgent .= ' PHP/'.PHP_VERSION;
        $userAgent .= ($_SERVER['SERVER_NAME'] ?? null) ? ' Domain/'.$_SERVER['SERVER_NAME'] : '';

        return $userAgent;
    }
}
