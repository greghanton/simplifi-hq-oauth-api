<?php

namespace UberAccountingApi;

use Curl\Curl;

class ApiResponse
{

    private $curl;
    private $config;
    private $forceSuccess = null;
    private $requestOptions;

    public function __construct($config, Curl $curl)
    {
        $this->curl = $curl;
        $this->config = $config;
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
        if($this->curl->error) {
            $errors[] = [
                'title' => $this->curl->errorCode . ': ' . $this->curl->errorMessage,
            ];
        }
        if( isset($this->errors) ) {
            $errors = array_merge($errors, $this->errors);
        }
        return $errors;
    }

    public function errorsToString($glue = ", ")
    {
        $errors = $this->errors();
        $response = [];
        foreach($errors as $error) {
            $response[] = $error['title'];
        }
        return implode($glue, $response);
    }

    public function header() {
        return $this->curl->response;
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
//        echo "Getting '$name'\n";
        $response = self::response();
        if (isset($response->{$name})) {
            return $response->{$name};
        }

        $trace = debug_backtrace();
        trigger_error(
            'Undefined property via __get(): ' . $name .
            ' in ' . $trace[0]['file'] .
            ' on line ' . $trace[0]['line'],
            E_USER_NOTICE);
        return null;
    }

}