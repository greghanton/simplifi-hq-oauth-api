<?php

namespace SimplifiApi;

use Curl\Curl;

/**
 * Class ApiRequest
 * @package SimplifiApi
 */
class ApiRequest
{

    /**
     * Location of the config file (contains Oauth credentials etc.)
     */
    const CONFIG_FILE = __DIR__ . '/../config.php';

    /**
     * An array of events added by self::addEventListener()
     * @var array
     */
    private static $events = [];

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
        'method'                            => 'GET',

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
        'data'                              => [],

        /**
         * Any headers to pass add to the curl requestData to be send as payload or querystring along with the request
         * This can be used for any request GET/POST/etc.
         * May be:
         *      Array: of key value pairs ready for http_build_query()
         *      String: To be set as the request payload e.g. for a json request
         *
         * This MUST BE PASSED IN via $options in self::request()
         */
        'headers'                           => [],

        /**
         * Automatically add access token to the request
         * TRUE(default): you do not need to specify access_token in 'data' (it will be automatically added)
         * FALSE: access_token will not be automatically added to 'data' (useful for endpoints that do not request an
         *      access_token to be set)
         */
        'with-access-token'                 => true,

        /**
         * Expected response type. If response does not match this then it is considered invalid
         *      'json'(default): If response body is not valid json then ApiResponse->success() will return false
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
     * @return ApiResponse result from the request i.e. check ApiResponse::success() to see if it was successful
     * @throws \Exception if url not specified
     * @see $defaultRequestOptions
     * @see ApiResponse::success()
     */
    public static function request($options, $overrideConfig = [])
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

        if(isset(self::$events['beforeRequest'])) {
            foreach(self::$events['beforeRequest'] as $event) {
                $event($thisOptions, $config);
            }
        }


        $curl = new Curl();

        $curl->setTimeout(0);

        // TODO remove these (they are only here for testing)
        $curl->setOpt(CURLOPT_SSL_VERIFYPEER, false);
        $curl->setOpt(CURLOPT_SSL_VERIFYHOST, false);
        //$curl->setOpt(CURLOPT_FOLLOWLOCATION, true);

        if (isset($thisOptions['url-absolute'])) {
            $url = $config['url-base'] . $thisOptions['url-absolute'];
        } else {
            $url = $config['url-base'] . $config['url-version'] . ltrim($thisOptions['url'], '/\\');
        }

        if (isset($thisOptions['headers'])) {
            foreach ($thisOptions['headers'] as $key => $value) {
                $curl->setHeader($key, $value);
            }
        }

        $return = null;

        switch (strtoupper($thisOptions['method'])) {
            case('GET'):

                $curl->get($url, $thisOptions['data']);
                $return = self::createApiResponse($config, $curl, $thisOptions, $options, $overrideConfig);

                break;
            case('POST'):

                $curl->post($url, $thisOptions['data']);
                $return = self::createApiResponse($config, $curl, $thisOptions, $options, $overrideConfig);

                break;
            case('PUT'):

                $curl->put($url, $thisOptions['data']);
                $return = self::createApiResponse($config, $curl, $thisOptions, $options, $overrideConfig);

                break;
            case('OPTIONS'):

                break;
            case('DELETE'):

                $curl->delete($url, null, $thisOptions['data']);
                $return = self::createApiResponse($config, $curl, $thisOptions, $options, $overrideConfig);

                break;
            case('HEAD'):

                break;
            case('PATCH'):

                $curl->patch($url, $thisOptions['data']);
                $return = self::createApiResponse($config, $curl, $thisOptions, $options, $overrideConfig);

                break;
        }

        if($return === null) {
            throw new \Exception("Invalid method");
        }

        return $return;

    }

    /**
     * Usually just the same as "new ApiResponse($config, $curl, $thisOptions);"
     * However; if the the response is {"type":"AuthenticationException","error":"Unauthenticated."}
     * We should delete the cached access token and try the request again
     *
     * @param $config
     * @param $curl
     * @param $thisOptions
     * @param $options
     * @param $overrideConfig
     * @return ApiResponse
     */
    private static function createApiResponse($config, $curl, $thisOptions, $options, $overrideConfig)
    {
        if( $thisOptions['retry-on-authentication-exception'] && self::responseIsAuthenticationException($curl) ) {
            AccessToken::clearCache();
            $options['retry-on-authentication-exception'] = false;      // Prevent infinate recursion
            return self::request($options, $overrideConfig);
        }

        return new ApiResponse($config, $curl, $thisOptions);
    }

    /**
     * Returns true if response is {"type":"AuthenticationException","error":"Unauthenticated."}
     *
     * @param $curl
     * @return boolean
     */
    private static function responseIsAuthenticationException($curl)
    {
        return isset($curl->response->type) && $curl->response->type === 'AuthenticationException';
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
    public static function getAccessToken()
    {
        return AccessToken::getAccessToken(self::getConfig());
    }

    /**
     * Add an event listener
     * 
     * @param $event string e.g. "beforeRequest"
     * @param $closure \Closure function to be called
     */
    public static function addEventListener($event, $closure)
    {
        self::$events[$event][] = $closure;
    }

}