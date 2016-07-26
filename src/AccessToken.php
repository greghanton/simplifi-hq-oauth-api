<?php

namespace UberAccountingApi;

class AccessToken {

    private static $config;

    /**
     * Get access token.
     * First try from from cache (config.php 'access_token_filename')
     * If we cant get it from there then generate a new one
     * @param $config
     * @return ApiResponse|string
     *      string: (success) newly generated access_token
     *      ApiResponse: (failed) We could not generate an access_token for some reason so return the ApiResponse from
     *          the failed request to generate one
     * @throws \Exception
     */
    public static function getAccessToken($config)
    {

        self::$config = $config;

        // First Attempt to get an access_token from the json file
        if( $accessToken = self::getCachedAccessToken() ) {
            return $accessToken;
        }

        // We could not find a valid access token so we must generate a new one
        return self::generateNewAccessToken(true);
    }

    /**
     * Attempt to get cached access token in config.php 'access_token_filename'
     * Only return an access token if it isn't about to expire
     * @return string|null
     *      String: access_token.
     *      NULL: we could not get an access token
     */
    private static function getCachedAccessToken() {

        if(file_exists(self::getAccessTokenFilePath())) {

            // the cache file exists
            $accessToken = require(self::getAccessTokenFilePath());

            // Ignore any access token that has expired or is about to expire
            if(!empty($accessToken->access_token) &&
                strtotime($accessToken->expires_at) < time() - self::$config['access_token_expire_buffer']
            ) {
                // Success we have found a previously generated access token that has not yet expired
                return $accessToken->access_token;
            }

        }

        return null;
    }

    /**
     * Generate a new access token through the Uber Accounting API and cache
     * @param bool $andCache Cache the resulting acess_token in config.php 'access_token_filename'
     * @return ApiResponse|string
     *      string: (success) newly generated access_token
     *      ApiResponse: (failed) We could not generate an access_token for some reason so return the ApiResponse from
     *          the failed request to generate one
     * @throws \Exception
     */
    private static function generateNewAccessToken($andCache = false)
    {

        $apiResponse = ApiRequest::request([
            'method'            => 'POST',
            'url'               => 'oauth/access_token',
            'with-access-token' => false,
            'data'              => [
                'grant_type'    => 'client_credentials',
                'client_id'     => self::$config['client_id'],
                'client_secret' => self::$config['client_secret'],
            ],
        ]);

        if( $apiResponse->success() ) {

            $data = $apiResponse->response();

            if(!isset($data->access_token)) {
                // ERROR access_token was not set in the response
                $apiResponse->setSuccess(false);
                return $apiResponse;
            }

            if($andCache) {

                self::cacheAccessToken($data);

            }

            // SUCCESS
            return $data->access_token;

        } else {
            // ERROR during request
            return $apiResponse;
        }
    }

    private static function getAccessTokenFilePath()
    {
        if( empty(self::$config['access_token_filename']) ) {
            throw new \Exception("Access token filename missing");
        }
        return  __DIR__ . DIRECTORY_SEPARATOR . self::$config['access_token_filename'];
    }

    private static function cacheAccessToken($data)
    {
        if( ! @file_put_contents(self::getAccessTokenFilePath(), '<?php return ' . var_export((array)$data, true) . ';') ) {

            error_log(
                "Error writing to file.\n" .
                "  When attempting to cache access_token to json file.\n" .
                "  File: '" . self::getAccessTokenFilePath() . "'\n" .
                "  Data: '" . json_encode($data) . "'"
            );

            trigger_error(
                "Error writing to file.\n" .
                "  When attempting to cache access_token to json file.\n" .
                "  File: '" . self::getAccessTokenFilePath() . "'\n" .
                "  Data: '" . json_encode($data) . "'",
                E_USER_NOTICE);

        }
    }

}