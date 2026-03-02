<?php

namespace SimplifiApi;

use Curl\Curl;
use GuzzleHttp\Promise\PromiseInterface;

/**
 * Class ApiRequest
 * @package SimplifiApi
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
    const CONFIG_FILE = __DIR__ . '/../config.php';

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
        'method'                            => 'GET',

        /**
         * Endpoint to request.
         * This will be appended to 'url-base' in config.php to get the full URL
         * If method=GET then 'data' will be appended to this as well using http_build_query()
         *
         * This MUST BE PASSED IN via $options in self::request()
         */
        //'url'             => 'sales',       // Must be passed in

        /**
         * Data to be send as payload or querystring along with the request
         * This can be used for any request GET/POST/etc.
         * May be:
         *      Array: of key value pairs ready for http_build_query()
         *      String: To be set as the request payload e.g. for a JSON request
         *
         * This MUST BE PASSED IN via $options in self::request()
         */
        'data'                              => [],

        /**
         * Any headers to pass add to the curl requestData to be send as payload or querystring along with the request
         * This can be used for any request GET/POST/etc.
         * May be:
         *      Array: of key value pairs ready for http_build_query()
         *      String: To be set as the request payload e.g. for a JSON request
         *
         * This MUST BE PASSED IN via $options in self::request()
         */
        'headers'                           => [
            'Accept' => 'application/json',
            //'Content-type' => 'application/json',
        ],

        /**
         * Automatically add access token to the request
         * TRUE(default): you do not need to specify access_token in 'data' (it will be automatically added)
         * FALSE: access_token will not be automatically added to 'data' (useful for endpoints that do not request an
         *      access_token to be set)
         */
        'with-access-token'                 => true,

        /**
         * Expected response type. If response does not match this then it is considered invalid
         *      'json'(default): If response body is not valid JSON then ApiResponse->success() will return false
         *      NULL: don't care what format the response body is in.
         */
        'response-type'                     => 'json',

        /**
         * Always leave this as true, it is an internal variable that.
         * If set to false we will not clear the accesstoken cache and retry the request if an AuthenticationException occurs
         */
        'retry-on-authentication-exception' => true,
    ];

    /**
     * Do a request
     *
     * @param array $options check $defaultRequestOptions for a list of available options
     * @param array $overrideConfig This will override options in the config.php file if you want
     *      This will almost never by passed in
     * @param ?float $timerStart leave this blank it is an internal variable
     *
     * @return ApiResponse result from the request i.e. check ApiResponse::success() to see if it was successful
     * @throws \Exception if URL isn't specified
     * @see $defaultRequestOptions
     * @see ApiResponse::success()
     */
    public static function request(array $options, array $overrideConfig = [], ?float $timerStart = null): ApiResponse
    {
        $config = self::getConfig($overrideConfig);

        $thisOptions = array_merge(self::$defaultRequestOptions, $options);

        if ($thisOptions['with-access-token'] && !isset($thisOptions['data']['access_token'])) {
            $accessToken = self::getAccessToken();
            if (is_string($accessToken)) {
//                $options['data']['access_token'] = $accessToken;
                $thisOptions['headers']['Authorization'] = 'Bearer ' . $accessToken;
            } else {
                // An error occurred while getting access token so return the ApiResponse from getAccessToken
                return $accessToken;
            }
        }

        if (!isset($thisOptions['url']) && !isset($thisOptions['url-absolute'])) {
            throw new \Exception("ERROR: Url not specified for curl request.");
        }

        if (isset(self::$events[self::EVENT_BEFORE_REQUEST])) {
            foreach (self::$events[self::EVENT_BEFORE_REQUEST] as $event) {
                $event($thisOptions, $config);
            }
        }


        $curl = new Curl();

        // By default, php-curl-class sets 30sec as the timeout, so let's remove the timeout (0)
        $curl->setTimeout($config['CURLOPT_TIMEOUT'] ?? 0);

        // SSL verification — configurable via config, defaults to false for backwards compatibility
        $sslVerify = $config['ssl_verify'] ?? false;
        $curl->setOpt(CURLOPT_SSL_VERIFYPEER, $sslVerify);
        $curl->setOpt(CURLOPT_SSL_VERIFYHOST, $sslVerify ? 2 : false);
        //$curl->setOpt(CURLOPT_FOLLOWLOCATION, true);

        if (isset($thisOptions['url-absolute'])) {
            $url = $config['url-base'] . self::urlToString($thisOptions['url-absolute']);
        } else {
            $url = $config['url-base'] . $config['url-version'] . self::urlToString($thisOptions['url']);
        }

        if ($config['add_trace_debug_header']) {
            if ($debugHeader = (ApiResponse::getCallerFromBacktrace(debug_backtrace(), __FILE__, __CLASS__)[0] ?? null)) {
                $thisOptions['headers']['trace-debug-header'] = $debugHeader;
            }
        }

        // Add config headers first, then per-request headers (per-request wins)
        if (!empty($config['headers'])) {
            foreach ($config['headers'] as $key => $value) {
                $curl->setHeader($key, $value);
            }
        }

        if (isset($thisOptions['headers'])) {
            foreach ($thisOptions['headers'] as $key => $value) {
                $curl->setHeader($key, $value);
            }
        }

        $curl->setUserAgent(self::getDefaultUserAgent($config['VERSION']));

        $return = null;
        if (!$timerStart) {
            $timerStart = microtime(true);
        }

        switch (strtoupper($thisOptions['method'])) {
            case('GET'):

                $curl->get($url, $thisOptions['data']);
                $return = self::createApiResponse($config, $curl, $thisOptions, $options, $overrideConfig, $timerStart);

                break;
            case('POST'):

                $curl->post($url, $thisOptions['data']);
                $return = self::createApiResponse($config, $curl, $thisOptions, $options, $overrideConfig, $timerStart);

                break;
            case('PUT'):

                $curl->put($url, $thisOptions['data']);
                $return = self::createApiResponse($config, $curl, $thisOptions, $options, $overrideConfig, $timerStart);

                break;
            case('OPTIONS'):

                break;
            case('DELETE'):

                $curl->delete($url, null, $thisOptions['data']);
                $return = self::createApiResponse($config, $curl, $thisOptions, $options, $overrideConfig, $timerStart);

                break;
            case('HEAD'):

                break;
            case('PATCH'):

                $curl->patch($url, $thisOptions['data']);
                $return = self::createApiResponse($config, $curl, $thisOptions, $options, $overrideConfig, $timerStart);

                break;
        }

        if ($return === null) {
            throw new \Exception("Invalid method");
        }

        // after request?
        if (isset(self::$events[self::EVENT_AFTER_REGULAR_REQUEST])) {
            foreach (self::$events[self::EVENT_AFTER_REGULAR_REQUEST] as $event) {
                $event($return);
            }
        }

        return $return;

    }

    /**
     * Make an asynchronous request using Guzzle
     *
     * Returns a Promise that resolves to an ApiResponse.
     * Use ->wait() to block and get the result, or ->then() for async handling.
     *
     * @param array $options Request options (same as request() method)
     * @param array $overrideConfig Optional config overrides
     * @return PromiseInterface Promise that resolves to ApiResponse
     *
     * @example
     * // Async with callback
     * $promise = ApiRequest::requestAsync(['url' => 'sales/123']);
     * $promise->then(function($response) {
     *     echo $response->data->name;
     * });
     *
     * @example
     * // Block and wait for result
     * $response = ApiRequest::requestAsync(['url' => 'sales/123'])->wait();
     *
     * @see request() for synchronous requests
     * @see batch() for multiple concurrent requests
     */
    public static function requestAsync(array $options, array $overrideConfig = []): PromiseInterface
    {
        $config = self::getConfig($overrideConfig);
        $thisOptions = array_merge(self::$defaultRequestOptions, $options);

        // Handle access token
        if ($thisOptions['with-access-token'] && !isset($thisOptions['data']['access_token'])) {
            $accessToken = self::getAccessToken();
            if (is_string($accessToken)) {
                $thisOptions['headers']['Authorization'] = 'Bearer ' . $accessToken;
            } else {
                // Return a rejected promise with the error response
                return \GuzzleHttp\Promise\Create::promiseFor($accessToken);
            }
        }

        if (!isset($thisOptions['url']) && !isset($thisOptions['url-absolute'])) {
            return \GuzzleHttp\Promise\Create::rejectionFor(
                new \Exception("ERROR: Url not specified for curl request.")
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
        if (!empty($config['headers'])) {
            foreach ($config['headers'] as $key => $value) {
                if (!isset($thisOptions['headers'][$key])) {
                    $thisOptions['headers'][$key] = $value;
                }
            }
        }

        return AsyncClient::requestAsync($thisOptions, $config)
            ->then(function (ApiResponse $response) use ($thisOptions, $options, $overrideConfig, $config) {
                // Check for authentication exception and retry
                if ($thisOptions['retry-on-authentication-exception'] && self::asyncResponseIsAuthenticationException($response)) {
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
     * All requests are executed in parallel using Guzzle's async capabilities.
     * Returns an array of response objects in the same order as the input requests.
     *
     * @param array $requests Array of request options arrays
     * @param array $overrideConfig Optional config overrides applied to all requests
     * @return ApiResponseInterface[] Array of responses (same order as requests)
     *
     * @example
     * $responses = ApiRequest::batch([
     *     ['url' => 'sales/123'],
     *     ['url' => 'customers', 'data' => ['limit' => 10]],
     *     ['url' => 'inventory'],
     * ]);
     *
     * foreach ($responses as $response) {
     *     if ($response->success()) {
     *         // Handle success
     *     }
     * }
     */
    public static function batch(array $requests, array $overrideConfig = []): array
    {
        $config = self::getConfig($overrideConfig);

        // Get access token once for all requests
        $accessToken = null;
        $needsToken = false;

        foreach ($requests as $options) {
            $merged = array_merge(self::$defaultRequestOptions, $options);
            if ($merged['with-access-token'] && !isset($merged['data']['access_token'])) {
                $needsToken = true;
                break;
            }
        }

        if ($needsToken) {
            $accessToken = self::getAccessToken();
            if (!is_string($accessToken)) {
                // Token fetch failed - return the error response for all requests
                return array_fill_keys(array_keys($requests), $accessToken);
            }
        }

        // Calculate debug header once outside loop (performance: avoids repeated debug_backtrace calls)
        $debugHeader = null;
        if ($config['add_trace_debug_header']) {
            $debugHeader = ApiResponse::getCallerFromBacktrace(debug_backtrace(), __FILE__, __CLASS__)[0] ?? null;
        }

        // Prepare all requests with merged options
        $preparedRequests = [];
        foreach ($requests as $key => $options) {
            $thisOptions = array_merge(self::$defaultRequestOptions, $options);

            // Add access token if needed
            if ($thisOptions['with-access-token'] && !isset($thisOptions['data']['access_token']) && $accessToken) {
                $thisOptions['headers']['Authorization'] = 'Bearer ' . $accessToken;
            }

            // Validate URL
            if (!isset($thisOptions['url']) && !isset($thisOptions['url-absolute'])) {
                throw new \Exception("ERROR: Url not specified for request at index {$key}");
            }

            // Fire before request events
            if (isset(self::$events[self::EVENT_BEFORE_REQUEST])) {
                foreach (self::$events[self::EVENT_BEFORE_REQUEST] as $event) {
                    $event($thisOptions, $config);
                }
            }

            // Add debug header if configured
            if ($debugHeader) {
                $thisOptions['headers']['trace-debug-header'] = $debugHeader;
            }

            // Add config headers (don't overwrite per-request headers)
            if (!empty($config['headers'])) {
                foreach ($config['headers'] as $headerKey => $value) {
                    if (!isset($thisOptions['headers'][$headerKey])) {
                        $thisOptions['headers'][$headerKey] = $value;
                    }
                }
            }

            $preparedRequests[$key] = $thisOptions;
        }

        // Execute all requests concurrently
        $responses = AsyncClient::batch($preparedRequests, $config);

        // Fire after request events and handle auth exceptions
        foreach ($responses as $key => $response) {
            // Check for auth exception
            $thisOptions = $preparedRequests[$key];
            if ($thisOptions['retry-on-authentication-exception'] && self::asyncResponseIsAuthenticationException($response)) {
                // Clear cache and retry this single request synchronously
                AccessToken::clearCache();
                $retryOptions = $requests[$key];
                $retryOptions['retry-on-authentication-exception'] = false;
                $responses[$key] = self::request($retryOptions, $overrideConfig);
            } else {
                // Fire after request events
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
     * Similar to batch() but limits the number of simultaneous requests.
     * Useful when making many requests to avoid overwhelming the server.
     *
     * @param array $requests Array of request options arrays
     * @param int $concurrency Maximum simultaneous requests (default: 5)
     * @param array $overrideConfig Optional config overrides
     * @return (ApiResponse|AsyncApiResponse)[] Array of responses
     *
     * @example
     * // Make 100 requests, but only 10 at a time
     * $responses = ApiRequest::batchWithConcurrency($requests, 10);
     */
    public static function batchWithConcurrency(array $requests, int $concurrency = 5, array $overrideConfig = []): array
    {
        $config = self::getConfig($overrideConfig);

        // Get access token once for all requests
        $accessToken = null;
        $needsToken = false;

        foreach ($requests as $options) {
            $merged = array_merge(self::$defaultRequestOptions, $options);
            if ($merged['with-access-token'] && !isset($merged['data']['access_token'])) {
                $needsToken = true;
                break;
            }
        }

        if ($needsToken) {
            $accessToken = self::getAccessToken();
            if (!is_string($accessToken)) {
                return array_fill_keys(array_keys($requests), $accessToken);
            }
        }

        // Calculate debug header once outside loop
        $debugHeader = null;
        if ($config['add_trace_debug_header']) {
            $debugHeader = ApiResponse::getCallerFromBacktrace(debug_backtrace(), __FILE__, __CLASS__)[0] ?? null;
        }

        // Prepare all requests
        $preparedRequests = [];
        foreach ($requests as $key => $options) {
            $thisOptions = array_merge(self::$defaultRequestOptions, $options);

            if ($thisOptions['with-access-token'] && !isset($thisOptions['data']['access_token']) && $accessToken) {
                $thisOptions['headers']['Authorization'] = 'Bearer ' . $accessToken;
            }

            if (!isset($thisOptions['url']) && !isset($thisOptions['url-absolute'])) {
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
            if (!empty($config['headers'])) {
                foreach ($config['headers'] as $headerKey => $value) {
                    if (!isset($thisOptions['headers'][$headerKey])) {
                        $thisOptions['headers'][$headerKey] = $value;
                    }
                }
            }

            $preparedRequests[$key] = $thisOptions;
        }

        return AsyncClient::batchWithConcurrency($preparedRequests, $config, $concurrency);
    }

    /**
     * Check if an async response indicates an authentication exception
     */
    private static function asyncResponseIsAuthenticationException(ApiResponseInterface $response): bool
    {
        $data = $response->response();
        return (isset($data->type) && $data->type === 'AuthenticationException') ||
            (isset($data->message) && $data->message === 'Unauthenticated.');
    }

    /**
     * Usually just the same as "new ApiResponse($config, $curl, $thisOptions);"
     * However, if the response is {"type":"AuthenticationException","error":"Unauthenticated."}
     * We should delete the cached access token and try the request again
     */
    private static function createApiResponse(array $config, Curl $curl, array $thisOptions, array $options, array $overrideConfig, float $timerStart): ApiResponse
    {
        if ($thisOptions['retry-on-authentication-exception'] && self::responseIsAuthenticationException($curl)) {
            AccessToken::clearCache();
            $options['retry-on-authentication-exception'] = false;      // Prevent infinite recursion
            return self::request($options, $overrideConfig, $timerStart);
        }
        $options['retry-on-authentication-exception'] = true;      // Reset this variable

        return new ApiResponse($config, $curl, $thisOptions, $timerStart);
    }

    /**
     * Returns true if response is {"type":"AuthenticationException","error":"Unauthenticated."}
     */
    private static function responseIsAuthenticationException(Curl $curl): bool
    {
        return isset($curl->response->type) && $curl->response->type === 'AuthenticationException' ||
            isset($curl->response->message) && $curl->response->message === "Unauthenticated.";
    }

    /**
     * Grab the config out of the config.php file and store it in $config field
     *
     * @param array $overrideConfig if you want to override any values in the config.php file.
     *      This won't usually be passed in
     * @see $config
     */
    private static function getConfig(array $overrideConfig = []): array
    {
        if (!self::$config) {
            self::$config = require(self::CONFIG_FILE);
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
     * @see AccessToken::getAccessToken()
     */
    public static function getAccessToken(): ApiResponse|string
    {
        return AccessToken::getAccessToken(self::getConfig());
    }

    /**
     * Add an event listener
     *
     * @param $event string e.g. "beforeRequest"
     * @param $callback callable function to be called
     */
    public static function addEventListener(string $event, callable $callback): void
    {
        self::$events[$event][] = $callback;
    }

    /**
     * Get the default user agent string
     *
     * @param string $urlVersion e.g. "1.0.0"
     * @return string e.g.
     *      "Simplifi-HQ-API/1.0.0 (+https://github.com/greghanton/simplifi-hq-oauth-api) PHP-Curl-Class/8.8.0 curl/7.58.0 PHP/7.4.5"
     */
    private static function getDefaultUserAgent(string $urlVersion): string
    {
        $user_agent = 'Simplifi-HQ-API/' . $urlVersion . ' (+https://github.com/greghanton/simplifi-hq-oauth-api)';
        $user_agent .= ' PHP-Curl-Class/' . Curl::VERSION;
        $user_agent .= ' curl/' . (curl_version()['version']);
        $user_agent .= ' PHP/' . PHP_VERSION;
        $user_agent .= ($_SERVER['SERVER_NAME'] ?? null) ? ' Domain/' . $_SERVER['SERVER_NAME'] : '';
        return $user_agent;
    }

    /**
     * Get the url as a string
     *
     * @param array|string $url EG:
     *      - "sales/102/invoice"
     *      - OR ["sales/$/invoice", 102]
     * @return string
     */
    private static function urlToString(array|string $url): string
    {
        if (is_string($url)) {
            return ltrim($url, '/\\');
        } else if (is_array($url)) {
            if (!count($url)) {
                throw new \Exception("Invalid url");
            }

            // Get the string part of the URL EG "sales/$/invoice"
            $urlString = array_shift($url);

            // Get the array of url parts EG [102]
            array_walk($url, fn($value) => rawurlencode($value));

            // combine $urlString and $url into a string EG "sales/102/invoice"
            return ltrim(self::substituteStringReplacements($urlString, $url), '/\\');
        } else {
            return '';
        }
    }

    /**
     * Replace the $ parts of the string with the values in the array
     *
     * @param string $string EG "sales/$/invoice"
     * @param array $replacments EG [102]
     * @return string EG "sales/102/invoice"
     */
    private static function substituteStringReplacements(string $string, array $replacments): string
    {

        // Split the string into parts
        $stringParts = explode(self::URL_REPLACEMENT_CHARACTER, $string);

        // Check that the number of replacement characters matches the number of replacment strings.
        if (count($stringParts) !== count($replacments) + 1) {
            throw new \Exception("Invalid url number of replacement characters is incorrect");
        }

        // Make sure the arrays have numerical indexes
        $stringParts = array_values($stringParts);
        $replacments = array_values($replacments);

        // Now construct the output string
        $output = "";
        foreach ($stringParts as $index => $stringPart) {
            $output .= $stringPart . ($replacments[$index] ?? '');
        }

        return $output;

    }

}