<?php

namespace UberAccountingApi;

use Curl\Curl;

/**
 * Class ApiRequest
 * @package UberAccountingApi
 */
class ApiRequest
{

    /**
     * Location of the config file (contains Oauth credentials etc.)
     */
    const CONFIG_FILE = __DIR__ . '/../config.php';

    /**
     * This will hold the contents of CONFIG_FILE in an array for quick access
     * @var null
     */
    private static $config = null;

    /**
     * These are the default request options. Each can be overridden by the $options parameter in the request method
     * @var array
     */
    private static $defaultRequestOptions = [

        /**
         * Request method
         * GET(default)/POST/PUT/OPTIONS/DELETE/HEAD/PATCH
         */
        'method'            => 'GET',

        /**
         * Endpoint to request.
         * This will be appended to 'url-base' in config.php to get the full url
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
         *      String: To be set as the request payload e.g. for a json request
         *
         * This MUST BE PASSED IN via $options in self::request()
         */
        'data'            => [],

        /**
         * Any headers to pass add to the curl requestData to be send as payload or querystring along with the request
         * This can be used for any request GET/POST/etc.
         * May be:
         *      Array: of key value pairs ready for http_build_query()
         *      String: To be set as the request payload e.g. for a json request
         *
         * This MUST BE PASSED IN via $options in self::request()
         */
        'headers'            => [],

        /**
         * Automatically add access token to the request
         * TRUE(default): you do not need to specify access_token in 'data' (it will be automatically added)
         * FALSE: access_token will not be automatically added to 'data' (useful for endpoints that do not request an
         *      access_token to be set)
         */
        'with-access-token' => true,

        /**
         * Expected response type. If response does not match this then it is considered invalid
         *      'json'(default): If response body is not valid json then ApiResponse->success() will return false
         *      NULL: don't care what format the response body is in.
         */
        'response-type'     => 'json',
    ];

    /**
     * Do a request
     *
     * @param array $options check $defaultRequestOptions for a list of available options
     * @param array $overrideConfig This will override options in the config.php file if you want
     *      This will almost never by passed in
     * @return ApiResponse result from the request i.e. check ApiResponse::success() to see if it was successful
     * @throws \Exception if url not specified
     * @see $defaultRequestOptions
     * @see ApiResponse::success()
     */
    public static function request($options, $overrideConfig = [])
    {
        $config = self::getConfig($overrideConfig);

        $options = array_merge(self::$defaultRequestOptions, $options);

        if ($options['with-access-token'] && !isset($options['data']['access_token'])) {
            $accessToken = self::getAccessToken();
            if (is_string($accessToken)) {
//                $options['data']['access_token'] = $accessToken;
                $options['headers']['Authorization'] = 'Bearer '.$accessToken;
            } else {
                // An error occurred while getting access token so return the ApiResponse from getAccessToken
                return $accessToken;
            }
        }

        if( !isset($options['url']) && !isset($options['url-absolute']) ) {
            throw new \Exception("ERROR: Url not specified for curl request.");
        }


        $curl = new Curl();

        // TODO remove these (they are only here for testing)
        $curl->setOpt(CURLOPT_SSL_VERIFYPEER, false);
        $curl->setOpt(CURLOPT_SSL_VERIFYHOST, false);
        //$curl->setOpt(CURLOPT_FOLLOWLOCATION, true);

        if( isset($options['url-absolute']) ) {
            $url = $config['url-base'] . $options['url-absolute'];
        } else {
            $url = $config['url-base'] . $config['url-version'] . $options['url'];
        }

        if(isset($options['headers'])) {
            foreach($options['headers'] as $key => $value) {
                $curl->setHeader($key, $value);
            }
        }

        switch (strtoupper($options['method'])) {
            case('GET'):

                $curl->get($url, $options['data']);
                return new ApiResponse($config, $curl, $options);

                break;
            case('POST'):

                $curl->post($url, $options['data']);
                return new ApiResponse($config, $curl, $options);

                break;
            case('PUT'):

                $curl->put($url, $options['data']);
                return new ApiResponse($config, $curl, $options);

                break;
            case('OPTIONS'):

                break;
            case('DELETE'):

                $curl->delete($url, null, $options['data']);
                return new ApiResponse($config, $curl, $options);

                break;
            case('HEAD'):

                break;
            case('PATCH'):

                $curl->patch($url, $options['data']);
                return new ApiResponse($config, $curl, $options);

                break;
        }

        throw new \Exception("Invalid method");
    }

    /**
     * Grab the config out of the config.php file and store it in $config field
     *
     * @param array $overrideConfig if you want to override any values in the config.php file.
     *      This wont usually be passed in
     * @return mixed|null
     * @see $config
     */
    private static function getConfig($overrideConfig = [])
    {
        if(!self::$config) {
            self::$config = require(self::CONFIG_FILE);
        }
        if($overrideConfig) {
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
    public static function getAccessToken()
    {
        return AccessToken::getAccessToken(self::getConfig());
    }

}