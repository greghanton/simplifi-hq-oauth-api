<?php

namespace UberAccountingApi;

use Curl\Curl;

class ApiResponse
{

    private $curl;
    private $config;
    private $forceSuccess = null;
    private $requestOptions;

    public function __construct($config, Curl $curl, $requestOptions)
    {
        $this->curl = $curl;
        $this->config = $config;
        $this->requestOptions = $requestOptions;
        return $this;
    }

    public function success() {
        if($this->forceSuccess !== null) {
            return $this->forceSuccess;
        } else {
            return $this->curl->error ? false : true;
        }
    }

    public function response() {
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
    public function errors() {
        $errors = [];
        if( array_key_exists('errors', (array)$this->response()) ) {
            $errors = array_merge($errors, json_decode(json_encode($this->errors), true));      // Cast ->errors to array
        }
        if(count($errors) === 0 && $this->curl->error) {
            $errors[] = [
                'title' => $this->curl->errorCode . ': ' . $this->curl->errorMessage,
            ];
        }
        return $errors;
    }

    public function errorsToString($glue = ", ")
    {
        return implode($glue, $this->getSimpleErrorsArray());
    }

    public function getSimpleErrorsArray()
    {
        $errors = $this->errors();
        $response = [];
        foreach($errors as $error) {
            $response[] = $error['title'];
        }
        return $response;
    }

    public function getCurl() {
        return $this->curl;
    }

    /**
     * @param int $opt see http://php.net/manual/en/function.curl-getinfo.php
     * @return mixed
     * @see http://php.net/manual/en/function.curl-getinfo.php
     */
    public function getCurlInfo($opt) {
        return $this->curl->getInfo($opt);
    }

    public function getRequestUrl() {
        return $this->getCurlInfo(CURLINFO_EFFECTIVE_URL);
    }

    public function getHttpCode() {
        return $this->getCurlInfo(CURLINFO_HTTP_CODE);
    }

    public function serialise() {
        return [
            'url'       => $this->getRequestUrl(),
            'http-code' => $this->getHttpCode(),
            'response'  => $this->response(),
        ];
    }

    /**
     * Override the success() response
     * @param $false
     */
    public function setSuccess($success)
    {
        $this->forceSuccess = $success;
    }

    public function setCurl($curl)
    {
        $this->curl = $curl;
    }

    public function setRequestOptions($options)
    {
        $this->requestOptions = $options;
    }

    /**
     * @return ApiResponse|string
     */
    public function __get($name)
    {
        return $this->property($name);

////        echo "Getting '$name'\n";
//        $response = $this->response();
//        if (array_key_exists('invoice-number', (array)$response)) { // Cannot use isset() here because if fails on NULL
//            return $response->{$name};
//        }
//
//        $this->triggerError("Undefined property via __get()", debug_backtrace(), __CLASS__, __FUNCTION__, func_get_args());
//
//        return null;
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
        if($this->hasNextPage()) {
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

    private function hasNextPage()
    {
        return $this->property('paginator') && $this->property('paginator', 'current_page') < $this->property('paginator', 'total_pages');
    }

    private function getCurrentPage()
    {
        if(!$this->paginator) {
            throw new \Exception("Attempted to get the page number for a non paginated response.");
        }
        return $this->paginator->current_page;
    }

    /**
     * Basically the same as __get() except you can get sub properties
     * e.g.
     * $currentPage = $this->property('paginator', 'current_page')
     * @return mixed NULL: if the property could not be found
     */
    private function property()
    {
        $args = func_get_args();
        $response = $this->response();
        foreach($args as $value) {

            if (array_key_exists($value, (array)$response)) { // Cannot use isset() here because if fails on NULL
                $response = $response->{$value};
            } else {

                $this->triggerError("Undefined property ($value)", debug_backtrace(), __CLASS__, __FUNCTION__, func_get_args());
                $response = null;
                break;

            }

        }
        return $response;
    }

    private function triggerError($message, $debugBackTrace, $class, $functionName, $functionArgs)
    {

        $externalTraceId = 0;
        while($debugBackTrace[$externalTraceId]['class'] === $class) {
            if(count($debugBackTrace) > $externalTraceId+1) {
                $externalTraceId++;
            } else {
                $externalTraceId = 0;
                break;
            }
        }

        trigger_error(
            $message .
            " in {$functionName}(" . implode(", ", $functionArgs) . ")" .
            " in {$debugBackTrace[0]['file']}:{$debugBackTrace[0]['line']}" .
            " called from {$debugBackTrace[$externalTraceId]['file']}:{$debugBackTrace[$externalTraceId]['line']}",
            E_USER_NOTICE);

    }

}