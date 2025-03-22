<?php

namespace SimplifiApi;

use Curl\Curl;
use JetBrains\PhpStorm\NoReturn;

/**
 * Class ApiResponse
 *
 * @method throw(string $message) alias of throwException()
 * @see ApiResponse::throwException
 *
 * @package SimplifiApi
 */
class ApiResponse implements \JsonSerializable, \Iterator, \Countable
{

    private Curl $curl;
    private array $config;
    private ?bool $forceSuccess = null;
    private array $requestOptions;
    private float $requestTime;

    /**
     * The response created event identifier
     */
    const EVENT_RESPONSE_CREATED = 'responseCreated';

    /**
     * An array of events added by self::addEventListener()
     */
    private static array $events = [];

    /**
     * ApiResponse constructor.
     *
     * @param array $config This is the config array from the config.php file (sometimes some values will be
     *      overridden by the user, but usually it is exactly the array from the file
     * @param $curl Curl instance of the php-curl-class/php-curl-class library Curl class
     * @param array $requestOptions this contains the request method, URL etc @see ApiRequest::$defaultRequestOptions
     * @param $timerStart float the time that the curl request was initiated
     */
    public function __construct(array $config, Curl $curl, array $requestOptions, float $timerStart)
    {
        $this->curl = $curl;
        $this->config = $config;
        $this->requestOptions = $requestOptions;
        $this->requestTime = round(microtime(true) - $timerStart, 4);

        // If the request took > 30 seconds to run then log it
        if ($this->requestTime > 30) {
            $this->logRequest("This request took > 30 seconds to run ({$this->requestTime}s).");
        }

        // If the URL > 80000 characters log it
        if (strlen($this->getRequestUrl()) > 80000) {
            $this->logRequest("This requests url is > 80000 characters in length (" . strlen($this->getRequestUrl()) . ").");        // Max url length could be as little as 2,048
        }

        // Fire the response created event
        $this->fireEvent(self::EVENT_RESPONSE_CREATED, [$this]);

        return $this;
    }

    /**
     * Check if there was an error with the request e.g. a 404 occurred
     * Will return true if HTTP response code is not in 4xx or 5xx AND there was no curl_errno() e.g. 404
     *
     * NOTE: The Uber Accounting API is set up so that if an errors occur, it will return a status code of not 200
     *      and will set the "errors" array element
     */
    public function success(): bool
    {
        if ($this->forceSuccess !== null) {
            return $this->forceSuccess;
        } else {
            return !$this->curl->error;
        }
    }

    /**
     * Get the raw response (e.g. if 'Content-Type:application/json' then a json_decode() result is returned)
     */
    public function response(): mixed
    {
        return $this->curl->response;
    }

    /**
     * Return an array of errors that occurred OR empty array if no errors occurred.
     * The end point may return an array of errors if it finds an error
     * Or if there was an HTTP error then return that
     *
     * @return array of errors (empty if not errors occurred) array elements are of the form:
     *      ['title'=>'string message (always present)', 'message'=>'detailed description (may not be set)']
     */
    public function errors(): array
    {
        $errors = [];
        if (array_key_exists('errors', (array)$this->response())) {
            $errors = array_merge($errors, json_decode(json_encode($this->errors), true));      // Cast ->errors to array
        }
        if (array_key_exists('error', (array)$this->response())) {
            $temp = ((array)$this->response());
            $errors[] = [
                'title' => isset($temp['error']->message) ? $temp['error']->message : $temp['error'],
            ];
        }
        if (count($errors) === 0 && $this->curl->error) {
            $errors[] = [
                'title' => $this->curl->errorCode . ': ' . $this->curl->errorMessage . " " .
                    (substr(json_encode($this->serialise()['response'] ?? ''), 0, 300))
                ,
            ];
        }
        return $errors;
    }

    /**
     * Return $this->getSimpleErrorsArray() as an imploded string
     *
     * @see getSimpleErrorsArray()
     */
    public function errorsToString(string $glue = ", ", bool $escape = false): string
    {
        $errors = $this->getSimpleErrorsArray();
        if ($escape) {
            array_walk($errors, fn($value) => htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8'));
        }
        return implode($glue, $errors);
    }

    /**
     * Return a simple array of error title's
     *
     * @return string[] an array of strings
     * @see errors()
     */
    public function getSimpleErrorsArray(): array
    {
        $errors = $this->errors();
        $response = [];
        foreach ($errors as $error) {
            $response[] = $error['title'];
        }

        // If no error messages were found but the request was not a success then add a default error message.
        if (count($response) === 0 && !$this->success()) {
            $response[] = "Unknown error occurred.";
        }

        return $response;
    }

    /**
     * Throw Exception
     *
     * @param $message string Message to throw
     * @throws \Exception
     */
    public function throwException(string $message): void
    {
        if ($this->config['APP_ENV'] === 'local') {
            $this->dd();
        } else {
            $this->logRequest($message);
            $message = $message ? $message . "\n" : '';
            throw new \Exception($message . $this->errorsToString());
        }
    }

    /**
     * Map ->throw() to ->throwException() for backwards compatibility
     *
     * @param $method
     * @param $args
     * @throws \Exception
     */
    function __call($method, $args)
    {
        if ($method == 'throw') {
            call_user_func_array([$this, 'throwException'], $args);
        } else {
            $debugTrace = debug_backtrace();
            $debugTrace = count($debugTrace) > 0 ? $debugTrace[0] : null;

            throw new \Exception("Call to undefined method " . __CLASS__ . "::" . $method .
                "() in " . ($debugTrace ? $debugTrace['file'] : '') . ":" . ($debugTrace ? $debugTrace['line'] : '') . "\n");
        }
    }

    /**
     * Return the Curl object
     *
     * @see curl
     */
    public function getCurl(): Curl
    {
        return $this->curl;
    }

    /**
     * Just like php's native curl_getinfo()
     *
     * @param int $opt see http://php.net/manual/en/function.curl-getinfo.php
     * @see http://php.net/manual/en/function.curl-getinfo.php
     */
    public function getCurlInfo(int $opt): mixed
    {
        return $this->curl->getInfo($opt);
    }

    /**
     * Get the full URL of the request
     * useful for debugging
     */
    public function getRequestUrl(): mixed
    {
        return $this->getCurlInfo(CURLINFO_EFFECTIVE_URL);
    }

    /**
     * Get the http response code e.g. 200 for success
     *
     * @return integer
     */
    public function getHttpCode(): mixed
    {
        return $this->getCurlInfo(CURLINFO_HTTP_CODE);
    }

    /**
     * Get the http request method used, e.g. 'POST'
     */
    public function getMethod(): string
    {
        return $this->requestOptions['method'];
    }

    /**
     * Serialise the object
     * useful for debugging
     *
     * @param $anonymise boolean Remove sensitive info from the result
     * @return array
     */
    public function serialise(bool $anonymise = true): array
    {
        $response = $this->response();

        $caller = self::getCallerFromBacktrace(debug_backtrace());

        $return = [
            'method'         => $this->getMethod(),
            'http-code'      => $this->getHttpCode(),
            'url'            => $this->getRequestUrl(),
            'requestTime'    => $this->requestTime,
            'requestOptions' => $this->getRequestOptions($anonymise),
            'response'       => $this->isJson($response) ? json_decode($response) : $response,
            'caller'         => $caller ?: null,
        ];

        if ($anonymise) {
            // Recursively walk the array and any key that contains 'token','pass','secret' will be replaced with '### REDACTED ###'
            array_walk_recursive($return, function (&$v, $k) {
                if (is_string($v) && preg_match('/token|pass|secret/i', $k)) {
                    $v = '### REDACTED ###';
                }
            });
        }

        return $return;
    }

    /**
     * Override the success() response
     *
     * @param boolean $success
     */
    public function setSuccess(bool $success): void
    {
        $this->forceSuccess = $success;
    }

    /**
     * @param Curl $curl
     * @see $curl
     */
    public function setCurl(Curl $curl): void
    {
        $this->curl = $curl;
    }

    /**
     * @param $anonymise boolean Remove sensitive info from the result
     * @see requestOptions
     */
    public function getRequestOptions(bool $anonymise = true): array
    {
        $options = $this->requestOptions;

        if ($anonymise) {
            // In most cases the user doesn't need/want the Authorization header. Also; there could be a security concer
            // if it ends up in the logs "Authorization": "Bearer eyJ0eXAiO...
            if (!empty($options['headers']['Authorization'])) {
                $options['headers']['Authorization'] = preg_replace('/Bearer (.*)/i', 'Bearer ### REDACTED ###', $options['headers']['Authorization']);
            }
        }


        return $options;
    }

    /**
     * @param array $options
     * @see requestOptions
     */
    public function setRequestOptions(array $options): void
    {
        $this->requestOptions = $options;
    }

    /**
     * Magic method to get a value of a request
     * e.g. $response->data
     * e.g. $response->data->id
     * e.g. $response->paginator->total_count
     *
     * @param string $name
     * @return mixed
     * @see property()
     */
    public function __get($name)
    {
        return $this->property($name);
    }

    /**
     * Magic method to check the isset() of a property
     * e.g. isset($response->data) would always return false without this function
     * e.g. with this function isset($response->data) returns true if it is set
     *
     * @param $prop
     * @return mixed
     * @see __get()
     */
    public function __isset($prop)
    {
        if (array_key_exists($prop, (array)$this->response())) { // Cannot use isset() here because it fails on NULL
            return true;
        } else {
            return false;
        }
    }

    /**
     * Return an ApiResponse for the next page of this request.
     * This should only be used for paginated results.
     * Returns FALSE if request is not paginated or there are no more pages
     *
     * @return bool|ApiResponse FALSE: If there are no more pages.
     * @throws \Exception
     */
    public function nextPage(): ApiResponse|false
    {
        if ($this->hasNextPage()) {
            // modify the request so it will get the next page
            $requestOptions = $this->requestOptions;
            $requestOptions['data']['page'] = $this->getCurrentPage() + 1;

            $response = ApiRequest::request($requestOptions);
            if (!$response->success()) {
                throw new \Exception("Unknown error while getting next page from API.");
            }

            return $response;

        } else {
            return false;
        }
    }

    /**
     * Does this request have another page?
     * Should only be called on paginated endpoint responses
     */
    private function hasNextPage(): bool
    {
        return $this->property('paginator') && $this->property('paginator', 'current_page') < $this->property('paginator', 'total_pages');
    }

    /**
     * This will do as many requests as required to fetch every page's items into a single array and return that array
     *
     * e.g. of usage:
     *
     * function allPages() {
     *
     *     if ($this->success()) {
     *         $return = $this->fetchAllPageData();
     *         if (FALSE !== $return) {
     *             return $return;
     *         } else {
     *             throw new \Exception("Unknown error while fetching all pages of paginated API response.");
     *         }
     *     } else {
     *         throw new \Exception("Unknown error on paginated response. " . $response->errorsToString());
     *     }
     *
     * }
     */
    protected function fetchAllPageData(): array
    {

        $tempResponse = $this;
        $allItems = [];

        do {

            foreach ($tempResponse as $value) {
                $allItems[] = $value;
            }

        } while ($tempResponse = $tempResponse->nextPage());

        return $allItems;

    }

    /**
     * This will do as many requests as required to fetch every page's items into a single array and return that array
     * This function is the same as $this->fetchAllPageData() with a little additional error checking
     *
     * @see fetchAllPageData
     */
    public function allPages(): array
    {

        if ($this->success()) {
            $return = $this->fetchAllPageData();
            if (FALSE !== $return) {
                return $return;
            } else {
                throw new \Exception("Unknown error while fetching all pages of paginated api response.");
            }
        } else {
            throw new \Exception("Unknown error on paginated response. " . $this->errorsToString());
        }

    }

    /**
     * Get the current page number
     * Should only be called on paginated endpoint responses
     *
     * @throws \Exception when attempting to get the page number of a non-paginated response
     */
    private function getCurrentPage(): int|string
    {
        if (!$this->paginator) {
            throw new \Exception("Attempted to get the page number for a non paginated response.");
        }
        return $this->paginator->current_page;
    }

    /**
     * Basically the same as __get() except you can get sub properties
     * e.g.
     * $currentPage = $this->property('paginator', 'current_page')
     *
     * @param string ... any number of parameter names
     * @return mixed NULL: if the property could not be found
     * @see __get()
     */
    private function property(): mixed
    {
        $args = func_get_args();
        $response = $this->response();
        foreach ($args as $value) {

            if (array_key_exists($value, (array)$response)) { // Cannot use isset() here because it fails on NULL
                $response = $response->{$value};
            } else {

                $this->triggerError("Undefined property ($value)", debug_backtrace(), __CLASS__, __FUNCTION__, func_get_args());
                $response = null;
                break;

            }

        }
        return $response;
    }

    /**
     * Like PHPs native trigger_error($string, E_USER_NOTICE) function except the file and line number will be from the closest
     * debug_backtrace() value outside this object
     *
     * @param string $message
     * @param array $debugBackTrace debug_backtrace()
     * @param string $class
     * @param string $functionName
     * @param array $functionArgs func_get_args()
     */
    private function triggerError(string $message, array $debugBackTrace, string $class, string $functionName, array $functionArgs): void
    {

        $externalTraceId = 0;
        while (true) {
            if (isset($debugBackTrace[$externalTraceId + 1]['class'])) {
                if ($debugBackTrace[$externalTraceId]['class'] === $class) {
                    if (count($debugBackTrace) > $externalTraceId + 1) {
                        $externalTraceId++;
                        continue;
                    }
                }
            }

            $externalTraceId = 0;
            break;
        }

        $externalTraceId = $externalTraceId > 0 ? $externalTraceId - 1 : 0;

        while ($externalTraceId > 0 &&
            (!isset($debugBackTrace[$externalTraceId]['file']) &&
                !isset($debugBackTrace[$externalTraceId]['line']))
        ) {
            $externalTraceId--;
        }

        trigger_error(
            $message .
            " in {$functionName}(" . implode(", ", $functionArgs) . ")" .
            " in {$debugBackTrace[0]['file']}:{$debugBackTrace[0]['line']}" .
            " called from {$debugBackTrace[$externalTraceId]['file']}:{$debugBackTrace[$externalTraceId]['line']}",
            E_USER_NOTICE);

    }

    /**
     * Die and dump $this->serialise()
     * Functions similarly to Laravels dd() function
     * useful for debugging
     * NOTE: WILL CALL die()
     *
     * @see serialise()
     */
    #[NoReturn]
    public function dd($prettyHtml = true, $addAdditionalDataToHtml = true): void
    {
        // If error is html just output the html because chances are its a nicly formatted laravel exception
        if ($prettyHtml) {
            $doctypeString = "<!DOCTYPE html>";
            if (is_string($this->response()) &&
                substr($this->response(), 0, strlen($doctypeString)) === $doctypeString
            ) {

                $respond = $this->response();

                if ($addAdditionalDataToHtml) {
                    $serialised = $this->serialise();
                    unset($serialised['response']);
                    $respond .= "<pre>" . json_encode($serialised, JSON_PRETTY_PRINT) . "</pre>";
                }

                die($respond);
            }
        }

        header("Content-type: application/json");
        $serialised = $this->serialise();
        die(json_encode($serialised, JSON_PRETTY_PRINT));
    }

    /**
     * Check if string is valid JSON
     *
     * @param mixed $string string that we want to check if it is JSON format or not
     * @return bool true if the $string passed in is JSON
     */
    private function isJson(mixed $string): bool
    {
        if (!is_string($string)) {
            return false;
        }

        json_decode($string);
        return (json_last_error() == JSON_ERROR_NONE);
    }

    /**
     * Magic method called by var_dump() on this object
     *
     * @link http://php.net/manual/en/language.oop5.magic.php#object.debuginfo
     */
    public function __debugInfo()
    {
        return $this->serialise();
    }

    /**
     * Magic method used by json_encode($apiResponse)
     * NOTE: the implements \JsonSerializable on this class
     *
     * Specify data which should be serialized to JSON
     *
     * @link http://php.net/manual/en/jsonserializable.jsonserialize.php
     * @return mixed data which can be serialized by <b>json_encode</b>,
     * which is a value of any type other than a resource.
     * @since 5.4.0
     */
    function jsonSerialize(): mixed
    {
        return $this->serialise();
    }

    /**
     * Classes implementing Countable can be used with the count() function.
     *
     * @return int
     * @throws \Exception
     */
    public function count(): int
    {
        if (is_array($this->response()->data)) {
            return count($this->response()->data);
        } else {
            throw new \Exception("Error: Attempting to count a non countable object.");
        }
    }

    /**************** START Iterator methods ****************/

    /**
     * Is the api response iterable?
     * @return bool
     */
    private function dataIsIterable()
    {
        return is_array($this->response()->data);
    }

    /**
     * Throw an exception if response data is not an array
     * @throws \Exception
     */
    private function iterableCheck()
    {
        if (!$this->dataIsIterable()) {
            throw new \Exception("Invalid argument api response is not iterable.");
        }
    }

    /**
     * Rewind the Iterator to the first element
     * @link http://php.net/manual/en/iterator.rewind.php
     * @return void Any returned value is ignored.
     * @since 5.0.0
     */
    public function rewind(): void
    {
        $this->iterableCheck();
        reset($this->response()->data);
    }

    /**
     * Return the current element
     * @link http://php.net/manual/en/iterator.current.php
     * @return mixed Can return any type.
     * @since 5.0.0
     */
    public function current(): mixed
    {
        $this->iterableCheck();
        return current($this->response()->data);
    }

    /**
     * Return the key of the current element
     * @link http://php.net/manual/en/iterator.key.php
     * @return mixed scalar on success, or null on failure.
     * @since 5.0.0
     */
    public function key(): mixed
    {
        $this->iterableCheck();
        return key($this->response()->data);
    }

    /**
     * Move forward to next element
     * @link http://php.net/manual/en/iterator.next.php
     * @ return void Any returned value is ignored.
     * @since 5.0.0
     */
    public function next(): void
    {
        $this->iterableCheck();
        next($this->response()->data);
    }

    /**
     * Checks if current position is valid
     * @link http://php.net/manual/en/iterator.valid.php
     * @return boolean The return value will be casted to boolean and then evaluated.
     * Returns true on success or false on failure.
     * @since 5.0.0
     */
    public function valid(): bool
    {
        $this->iterableCheck();
        $key = key($this->response()->data);
        return ($key !== NULL && $key !== FALSE);
    }

    /**************** END Iterator methods ****************/

    private function logRequest($message): void
    {
        $serialisedRequest = $this->serialise();

        // Remove the authentication header
        unset($serialisedRequest['requestOptions']['headers']['Authorization']);

        // Some parts of the request can be massive so lets truncate all to 500 characters
        array_walk_recursive($serialisedRequest, function (&$v) {
            if (is_string($v) && strlen($v) > 500) {
                $v = substr($v, 0, 500) . '<TRUNCATED ' . strlen($v) . '>';
            }
        });

        call_user_func(AccessToken::getCallableLogFunction(), "{$message} " . json_encode($serialisedRequest));
    }

    /**
     * Get the first frame of a back trace outside this file
     */
    public static function getCallerFromBacktrace(array $backtrace, string $file = __FILE__, string $class = __CLASS__): ?array
    {

        // NOTE: Php will not populate all elements of a frame every time
        // e.g. 'line' will often be missing from a frame
        // @see https://www.php.net/manual/en/function.debug-backtrace.php
        // @see https://stackoverflow.com/questions/4581969/why-is-debug-backtrace-not-including-line-number-sometimes

        if (count($backtrace) === 0) {
            return null;
        }

        // Filter out all frame that are in the vendor directory
        $filteredBacktrace = array_filter($backtrace, function ($frame) use ($class, $file) {
            return !isset($frame['file']) ||
                (
                    !str_contains($frame['file'], 'vendor') &&
                    !str_contains($frame['file'], 'Middleware') &&
                    !str_ends_with($frame['file'], '/public/index.php') &&
                    !str_contains($frame['file'], $file) &&
                    !str_contains($frame['class'] ?? '', $class)
                );
        });
        if (count($filteredBacktrace) > 0) {
            $backtrace = $filteredBacktrace;
        }

        // Transform the backtrace into a string
        $arrayBacktrace = array_map(function ($frame) {
            if (isset($frame['file']) && isset($frame['line'])) {

                // Remove the document root from the url as it's unnecessary
                $file = isset($_SERVER['DOCUMENT_ROOT']) ?
                    preg_replace("/^" . preg_quote(realpath($_SERVER['DOCUMENT_ROOT'] . "/.."), '/') . "/", '', $frame['file']) :
                    $frame['file'];

                return $file . '::' . $frame['line'];
            } elseif (isset($frame['class']) && isset($frame['function'])) {
                return $frame['class'] .
                    (!empty($frame['type']) ? $frame['type'] : '::') .
                    $frame['function'];
            } else {
                return null;
            }
        }, $backtrace);

        $arrayBacktrace = array_filter($arrayBacktrace);

        // Only keep the first three stacks
        return array_slice($arrayBacktrace, 0, 3);

        // Get the first part of the debug_backtrace outside this file
//        $i = 0;
//        while (
//            (isset($backtrace[$i]['file']) && $backtrace[$i]['file'] === $file)
//            || (isset($backtrace[$i]['class']) && $backtrace[$i]['class'] === $class)
//        ) {
//            $i++;
//        }
//
//        // if class is in previous stack then we need to minus one
//        if (isset($backtrace[$i - 1]['class']) && $backtrace[$i - 1]['class'] === $class &&
//            isset($backtrace[$i - 1]['file']) && isset($backtrace[$i - 1]['line'])
//        ) {
//            $i--;
//        }
//
//        if ($i >= count($backtrace)) {
//            // No part of the debug_backtrace is outside this file
//            // So just grab the first frame
//            $i = 0;
//        }
//
//        if ($i < 0) {
//            $i = 0;
//        }
//
//        $stacks = [];
//
//        // Grab the 3 closest stacks
//        for ($j = $i; $j < $i + 3; $j++) {
//
//            if (isset($backtrace[$j])) {
//
//                $stack = $backtrace[$j];
//
//                if (isset($stack['file']) && isset($stack['line'])) {
//
//                    // Remove the document root from the url as it's unnecessary
//                    $file = isset($_SERVER['DOCUMENT_ROOT']) ?
//                        preg_replace("/^" . preg_quote(realpath($_SERVER['DOCUMENT_ROOT'] . "/.."), '/') . "/", '', $stack['file']) :
//                        $stack['file'];
//
//                    $stacks[] = $file . '::' . $stack['line'];
//                } elseif (isset($stack['class']) && isset($stack['function'])) {
//                    $stacks[] = $stack['class'] .
//                        (!empty($stack['type']) ? $stack['type'] : '::') .
//                        $stack['function'];
//                } else {
//                    $stacks[] = null;
//                }
//
//            }
//
//        }
//
//        if ($stacks) {
//            return $stacks;
//        }
//
//        return null;
    }

    /**
     * Add an event listener
     *
     * @param string $eventName e.g. "beforeRequest"
     * @param \Closure $callback function to be called
     * @return void
     */
    public static function addEventListener(string $eventName, \Closure $callback): void
    {
        self::$events[$eventName][] = $callback;
    }

    /**
     * Trigger an event loaded by addEventListener
     *
     * @param string $eventName The event identifier to trigger
     * @param array $callbackParameters The parameters to send to the callback
     */
    public static function fireEvent(string $eventName, array $callbackParameters): void
    {
        if (isset(self::$events[$eventName])) {
            foreach (self::$events[$eventName] as $event) {
                call_user_func_array($event, $callbackParameters);
            }
        }
    }

}