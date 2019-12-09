<?php

namespace SimplifiApi;

use Curl\Curl;

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

    /**
     * @var Curl
     */
    private $curl;

    /**
     * @var array
     */
    private $config;

    /**
     * @var boolean
     */
    private $forceSuccess = null;

    /**
     * @var array
     */
    private $requestOptions;

    /**
     * @var float
     */
    private $requestTime;

    /**
     * ApiResponse constructor.
     *
     * @param array $config This is the config array from the ../config.php file (sometimes some values will be
     *      overridden by the user but usually it is exactly the array from the file)
     * @param $curl Curl instance of the php-curl-class/php-curl-class librarys Curl class
     * @param array $requestOptions this contains the request method, url etc @see ApiRequest::$defaultRequestOptions
     * @param $timerStart float the time that the curl request was initiated
     */
    public function __construct($config, Curl $curl, $requestOptions, $timerStart)
    {
        $this->curl = $curl;
        $this->config = $config;
        $this->requestOptions = $requestOptions;
        $this->requestTime = round(microtime(true) - $timerStart, 4);

        // If the request took > 30 seconds to run then log it
        if($this->requestTime > 30) {
            $serialisedRequest = $this->serialise();

            // Remove the authentication header
            unset($serialisedRequest['requestOptions']['headers']['Authorization']);

            // Response can potentially be massive, so truncate at 500 characters
            $response = json_encode($serialisedRequest['response']);
            $serialisedRequest['response'] = strlen($response) > 500 ?
                substr($response, 0, 500) . '<TRUNCATED ' . strlen($response) . '>' :
                $serialisedRequest['response'];

            error_log("This request took > 30 seconds to run " . json_encode($serialisedRequest));
        }

        return $this;
    }

    /**
     * Check if there was an error with the request e.g. a 404 occurred
     * Will return true if http response code is not in 4xx or 5xx AND there was no curl_errno() e.g. 404
     *
     * NOTE: The Uber Accounting API is setup so that if an errors occur it will return a status code of not 200
     *      and will set the "errors" array element
     *
     * @return bool
     */
    public function success()
    {
        if ($this->forceSuccess !== null) {
            return $this->forceSuccess;
        } else {
            return $this->curl->error ? false : true;
        }
    }

    /**
     * Get the raw response (e.g. if 'Content-Type:application/json' then a json_decode() result is returned)
     *
     * @return mixed
     */
    public function response()
    {
        return $this->curl->response;
    }

    /**
     * Return an array of errors that occurred (may be blank if no errors occurred)
     * The end point may return an array of errors if it finds an error
     * Or if there was an http error then return that
     *
     * @return array of errors (may be blank) array elemnts are of the form:
     *      ['title'=>'string message (always present)', 'message'=>'detailed description (may not be set)']
     */
    public function errors()
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
                'title' => $this->curl->errorCode . ': ' . $this->curl->errorMessage,
            ];
        }
        return $errors;
    }

    /**
     * Return $this->getSimpleErrorsArray() as an imploded string
     *
     * @param string $glue
     * @return string
     * @see getSimpleErrorsArray()
     */
    public function errorsToString($glue = ", ")
    {
        return implode($glue, $this->getSimpleErrorsArray());
    }

    /**
     * Return a simple array of error title's
     *
     * @return string[] an array of strings
     * @see errors()
     */
    public function getSimpleErrorsArray()
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
    public function throwException($message)
    {
        if (env('APP_ENV') === 'local') {
            $this->dd();
        } else {
            error_log($message . "\n" . json_encode($this->serialise()));
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
     * @return Curl
     * @see curl
     */
    public function getCurl()
    {
        return $this->curl;
    }

    /**
     * Just like php's native curl_getinfo()
     *
     * @param int $opt see http://php.net/manual/en/function.curl-getinfo.php
     * @return mixed
     * @see http://php.net/manual/en/function.curl-getinfo.php
     */
    public function getCurlInfo($opt)
    {
        return $this->curl->getInfo($opt);
    }

    /**
     * Get the full url of the request
     * useful for debugging
     *
     * @return string
     */
    public function getRequestUrl()
    {
        return $this->getCurlInfo(CURLINFO_EFFECTIVE_URL);
    }

    /**
     * Get the http response code e.g. 200 for success
     *
     * @return integer
     */
    public function getHttpCode()
    {
        return $this->getCurlInfo(CURLINFO_HTTP_CODE);
    }

    /**
     * Get the http request method used e.g. 'POST'
     *
     * @return string
     */
    public function getMethod()
    {
        return $this->requestOptions['method'];
    }

    /**
     * Serialise the object
     * useful for debugging
     *
     * @return array
     */
    public function serialise()
    {
        $response = $this->response();
        return [
            'url'            => $this->getRequestUrl(),
            'http-code'      => $this->getHttpCode(),
            'method'         => $this->getMethod(),
            'requestTime'    => $this->requestTime,
            'requestOptions' => $this->getRequestOptions(),
            'response'       => $this->isJson($response) ? json_decode($response) : $response,
        ];
    }

    /**
     * Override the success() response
     *
     * @param boolean $success
     */
    public function setSuccess($success)
    {
        $this->forceSuccess = $success;
    }

    /**
     * @param Curl $curl
     * @see $curl
     */
    public function setCurl($curl)
    {
        $this->curl = $curl;
    }

    /**
     * @return array
     * @see requestOptions
     */
    public function getRequestOptions()
    {
        return $this->requestOptions;
    }

    /**
     * @param array $options
     * @see requestOptions
     */
    public function setRequestOptions($options)
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
     * @return bool|ApiResponse TRUE: If there in another page and it was successfully received.
     * @throws \Exception
     */
    public function nextPage()
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
     *
     * @return bool
     */
    private function hasNextPage()
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
     *             throw new \Exception("Unknown error while fetching all pages of paginated api response.");
     *         }
     *     } else {
     *         throw new \Exception("Unknown error on paginated response. " . $response->errorsToString());
     *     }
     *
     * }
     *
     * @return array
     */
    protected function fetchAllPageData()
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
     * @return array
     * @see fetchAllPageData
     * @throws \Exception
     */
    public function allPages()
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
     * @return string|int
     * @throws \Exception when attempting to get the page number of a non paginated response
     */
    private function getCurrentPage()
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
    private function property()
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
     * Like phps native trigger_error($string, E_USER_NOTICE) function except the file and line number will be from the closest
     * debug_backtrace() value outside this object
     *
     * @param string $message
     * @param array $debugBackTrace debug_backtrace()
     * @param string $class
     * @param string $functionName
     * @param array $functionArgs func_get_args()
     */
    private function triggerError($message, $debugBackTrace, $class, $functionName, $functionArgs)
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
    public function dd()
    {
        header("Content-type: application/json");
        $serialised = $this->serialise();
        $backtrace = debug_backtrace();

        $caller = $this->getCallerFromBacktrace($backtrace);
        if ($caller) {
            $serialised['caller'] = $caller;
        }

        die(json_encode($serialised, JSON_PRETTY_PRINT));
    }

    /**
     * Check if string is valid json
     *
     * @param string $string string that we want to check if it is json format or not
     * @return bool true if the $string passed in is json
     */
    private function isJson($string)
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
     * @return array
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
    function jsonSerialize()
    {
        return $this->serialise();
    }

    /**
     * Classes implementing Countable can be used with the count() function.
     *
     * @return int
     * @throws \Exception
     */
    public function count()
    {
        if (is_array($this->response()->data)) {
            return count($this->response()->data);
        } else {
            throw new \Exception("Error: Attempting to count a non countable object.");
        }
    }

    /**************** START Iterator methods ****************/

    /**
     * Is the api response iteratable?
     * @return bool
     */
    private function dataIsIteratable()
    {
        return is_array($this->response()->data);
    }

    /**
     * Throw an exception if response data is not an array
     * @throws \Exception
     */
    private function iteratableCheck()
    {
        if (!$this->dataIsIteratable()) {
            throw new \Exception("Invalid argument api response is not iteratable.");
        }
    }

    /**
     * Rewind the Iterator to the first element
     * @link http://php.net/manual/en/iterator.rewind.php
     * @return void Any returned value is ignored.
     * @since 5.0.0
     */
    public function rewind()
    {
        $this->iteratableCheck();
        reset($this->response()->data);
    }

    /**
     * Return the current element
     * @link http://php.net/manual/en/iterator.current.php
     * @return mixed Can return any type.
     * @since 5.0.0
     */
    public function current()
    {
        $this->iteratableCheck();
        return current($this->response()->data);
    }

    /**
     * Return the key of the current element
     * @link http://php.net/manual/en/iterator.key.php
     * @return mixed scalar on success, or null on failure.
     * @since 5.0.0
     */
    public function key()
    {
        $this->iteratableCheck();
        return key($this->response()->data);
    }

    /**
     * Move forward to next element
     * @link http://php.net/manual/en/iterator.next.php
     * @ return void Any returned value is ignored.
     * @since 5.0.0
     */
    public function next()
    {
        $this->iteratableCheck();
        return next($this->response()->data);
    }

    /**
     * Checks if current position is valid
     * @link http://php.net/manual/en/iterator.valid.php
     * @return boolean The return value will be casted to boolean and then evaluated.
     * Returns true on success or false on failure.
     * @since 5.0.0
     */
    public function valid()
    {
        $this->iteratableCheck();
        $key = key($this->response()->data);
        return ($key !== NULL && $key !== FALSE);
    }

    /**************** END Iterator methods ****************/


    /**
     * Get the first frame of a backtrace outside this file
     *
     * @param $backtrace
     * @return string
     */
    public function getCallerFromBacktrace($backtrace)
    {

        // NOTE: Php will not populate all elements of a frame every time
        // e.g. 'line' will often be missing from a frame
        // @see https://www.php.net/manual/en/function.debug-backtrace.php
        // @see https://stackoverflow.com/questions/4581969/why-is-debug-backtrace-not-including-line-number-sometimes

        if (count($backtrace) === 0) {
            return null;
        }

        // Get the first part of the debug_backtrace outside this file
        $i = 0;
        while (
            (isset($backtrace[$i]['file']) && $backtrace[$i]['file'] === __FILE__)
            || (isset($backtrace[$i]['class']) && $backtrace[$i]['class'] === __CLASS__)
        ) {
            $i++;
        }

        // if class is in previous stack then we need to minus one
        if (isset($backtrace[$i - 1]['class']) && $backtrace[$i - 1]['class'] === __CLASS__ &&
            isset($backtrace[$i - 1]['file']) && isset($backtrace[$i - 1]['line'])
        ) {
            $i--;
        }

        if ($i >= count($backtrace)) {
            // No part of the debug_backtrace is outside this file
            // So just grab the first frame
            $i = 0;
        }

        if ($i < 0) {
            $i = 0;
        }

        $stacks = [];

        // Grab the 3 closest stacks
        for ($j = $i; $j < $i + 3; $j++) {

            if (isset($backtrace[$j])) {

                $stack = $backtrace[$j];

                if (isset($stack['file']) && isset($stack['line'])) {

                    // Remove the document root from the url as it's unnecessary
                    $file = isset($_SERVER['DOCUMENT_ROOT']) ?
                        preg_replace("/^" . preg_quote(realpath($_SERVER['DOCUMENT_ROOT'] . "/.."), '/') . "/", '', $stack['file']) :
                        $stack['file'];

                    $stacks[] = $file . '::' . $stack['line'];
                } elseif (isset($stack['class']) && isset($stack['function'])) {
                    $stacks[] = $stack['class'] .
                        (!empty($stack['type']) ? $stack['type'] : '::') .
                        $stack['function'];
                } else {
                    $stacks[] = null;
                }

            }

        }

        if ($stacks) {
            return $stacks;
        }

        return null;
    }
}