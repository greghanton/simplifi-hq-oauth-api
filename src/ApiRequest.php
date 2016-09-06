<?php

namespace UberAccountingApi;

use Curl\Curl;

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
        //'data'            => [],

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
     * @return ApiResponse result from the request i.e. check ->success() to see if it was successful
     * @throws \Exception
     * @see $defaultRequestOptions
     */
    public static function request($options)
    {
        $config = self::getConfig();

        $options = array_merge(self::$defaultRequestOptions, $options);

        if ($options['with-access-token'] && !isset($options['data']['access_token'])) {
            $accessToken = self::getAccessToken();
            if (is_string($accessToken)) {
                $options['data']['access_token'] = $accessToken;
            } else {
                // An error occurred while getting access token so return the ApiResponse from getAccessToken
                return $accessToken;
            }
        }

        if( !isset($options['url']) ) {
            throw new \Exception("ERROR: Url not specified for curl request.");
        }


        $curl = new Curl();

        // TODO remove these (they are only here for testing)
        $curl->setOpt(CURLOPT_SSL_VERIFYPEER, false);
        $curl->setOpt(CURLOPT_SSL_VERIFYHOST, false);
        //$curl->setOpt(CURLOPT_FOLLOWLOCATION, true);

        switch (strtoupper($options['method'])) {
            case('GET'):

                $curl->get($config['url-base'] . $options['url'], $options['data']);
                return new ApiResponse($config, $curl, $options);

                break;
            case('POST'):

                $curl->post($config['url-base'] . $options['url'], $options['data']);
                return new ApiResponse($config, $curl, $options);

                break;
            case('PUT'):

                $curl->put($config['url-base'] . $options['url'], $options['data']);
                return new ApiResponse($config, $curl, $options);

                break;
            case('OPTIONS'):

                break;
            case('DELETE'):

                break;
            case('HEAD'):

                break;
            case('PATCH'):

                break;
        }

        throw new \Exception("Invalid method");
    }

    /**
     * Grab the config out of the config.php file and store it in $config field
     * @return mixed|null
     */
    private static function getConfig()
    {
        if(!self::$config) {
            self::$config = require(self::CONFIG_FILE);
        }
        return self::$config;
    }

    /**
     * @return ApiResponse|string
     */
    public static function getAccessToken()
    {
        return AccessToken::getAccessToken(self::getConfig());
    }

}