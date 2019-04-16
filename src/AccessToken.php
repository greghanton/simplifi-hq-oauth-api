<?php

namespace SimplifiApi;

/**
 * Class AccessToken
 *
 * Access token will be cached in '%temp%/ua-access-token.php' file
 *
 * @package SimplifiApi
 */
class AccessToken
{

    /**
     * @var array
     * @see ApiRequest::$config
     */
    private static $config;

    /**
     * Get access token.
     * First try from from cache (config.php 'access_token_filename')
     * If we cant get it from there then generate a new one
     *
     * @param array $config see ApiRequest::$config
     * @return ApiResponse|string
     *      string: (success) newly generated access_token
     *      ApiResponse: (failed) We could not generate an access_token for some reason so return the ApiResponse from
     *          the failed request to generate one
     * @throws \Exception
     * @see ApiRequest::$config
     */
    public static function getAccessToken($config)
    {
        self::$config = $config;

        // First Attempt to get an access_token from the json file
        if ($accessToken = self::getCachedAccessToken()) {
            return $accessToken;
        }

        // We could not find a valid access token so we must generate a new one
        return self::generateNewAccessToken(true);
    }

    /**
     * Attempt to get cached access token in config.php 'access_token_filename'
     * Only return an access token if it isn't about to expire
     *
     * @return string|null
     *      String: access_token.
     *      NULL: we could not get an access token
     */
    private static function getCachedAccessToken()
    {
        if (file_exists(self::getAccessTokenFilePath())) {

            // the cache file exists
            $accessToken = require(self::getAccessTokenFilePath());

            // Ignore any access token that has expired or is about to expire
            if (!empty($accessToken['access_token']) &&
                is_int($accessToken['expires_at']) &&
                $accessToken['expires_at'] > time() - self::$config['access_token_expire_buffer']
            ) {
                // Success we have found a previously generated access token that has not yet expired
                return $accessToken['access_token'];
            }

        }

        return null;
    }

    /**
     * Generate a new access token through the Uber Accounting API and cache
     *
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
            'url-absolute'      => 'oauth/token',
            'with-access-token' => false,
            'data'              => [
                'grant_type'    => 'password',
                'client_id'     => self::$config['client_id'],
                'client_secret' => self::$config['client_secret'],
                'username'      => self::$config['username'],
                'password'      => self::$config['password'],
                'scope'         => self::$config['scope'],

                //'grant_type'    => 'client_credentials',
                //'client_id'     => self::$config['client_id'],
                //'client_secret' => self::$config['client_secret'],
            ],
        ]);
        if ($apiResponse->success()) {

            $data = $apiResponse->response();

            if (!isset($data->access_token)) {
                // ERROR access_token was not set in the response
                $apiResponse->setSuccess(false);
                return $apiResponse;
            }

            // Set expires_at
            $data->expires_at = time() + $data->expires_in;

            if ($andCache) {

                self::cacheAccessToken($data);

            }

            // SUCCESS
            return $data->access_token;

        } else {
            // ERROR during request
            return $apiResponse;
        }
    }

    /**
     * Get the absolute path to the access token file location
     *
     * @return string e.g. 'G:/Windows/Temp/ua-access-token.php'
     * @throws \Exception
     */
    private static function getAccessTokenFilePath()
    {
        if (empty(self::$config['access_token_filename'])) {
            throw new \Exception("Access token filename missing");
        }
        $tmpDir = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR;
        return $tmpDir . self::$config['access_token_filename'];
    }

    /**
     * Cache the access token in the getAccessTokenFilePath() file
     *
     * @param array $data probably of the form [
     *          'token_type' => 'Bearer',
     *          'expires_in' => 3155673599,
     *          'access_token' => '...',
     *          'refresh_token' => '...',
     *          'expires_at' => 4630717897,
     *      ]
     */
    private static function cacheAccessToken($data)
    {
        if (!@file_put_contents(self::getAccessTokenFilePath(), '<?php return ' . var_export((array)$data, true) . ';')) {

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

    /**
     * Remove the cached oauth token in %temp%
     */
    public static function clearCache()
    {
        $cacheFile = self::getAccessTokenFilePath();
        if (is_file($cacheFile)) {
            unlink($cacheFile);
        }
    }

}