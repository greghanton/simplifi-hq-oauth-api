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
     * @see ApiRequest::$config
     */
    private static array $config;

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
    public static function getAccessToken(array $config): ApiResponse|string
    {
        self::$config = $config;

        // First Attempt to get an access_token from the JSON file
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
    private static function getCachedAccessToken(): ?string
    {
        if ($accessToken = self::actuallyGetCachedAccessToken()) {

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
    private static function generateNewAccessToken(bool $andCache = false): ApiResponse|string
    {
        $apiResponse = ApiRequest::request([
            'method'            => 'POST',
            'url-absolute'      => 'oauth/token',
            'with-access-token' => false,
            'data'              => array_filter([
                'grant_type'    => self::$config['grant_type'],
                'client_id'     => self::$config['client_id'],
                'client_secret' => self::$config['client_secret'],
                'username'      => self::$config['username'],
                'password'      => self::$config['password'],
                'scope'         => self::$config['scope'],

                //'grant_type'    => 'client_credentials',
                //'client_id'     => self::$config['client_id'],
                //'client_secret' => self::$config['client_secret'],
            ]),
        ]);
//        $apiResponse->dd();
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

                self::cacheAccessToken((array)$data);

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
     * This is only used when 'SIMPLIFI_API_ACCESS_TOKEN_STORE_AS'='temp_file'
     *
     * @return string e.g. 'G:/Windows/Temp/ua-access-token.php'
     * @throws \Exception
     */
    private static function getAccessTokenFilePath(): string
    {
        if ((self::$config['access_token']['store_as'] ?? null) !== 'temp_file') {
            // This indicates ta coding error.
            throw new \Exception("Impossible");
        }
        if (empty(self::$config['access_token']['temp_file']['filename'])) {
            throw new \Exception("Access token filename missing");
        }
        $tmpDir = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR;
        return $tmpDir . self::$config['access_token']['temp_file']['filename'];
    }

    /**
     * Cache the access token
     *
     * @param array $data probably of the form [
     *          'token_type' => 'Bearer',
     *          'expires_in' => 3155673599,
     *          'access_token' => '...',
     *          'refresh_token' => '...',
     *          'expires_at' => 4630717897,
     *      ]
     */
    private static function cacheAccessToken(array $data): void
    {
        if ($error = self::actuallyCacheAccessToken($data)) {

            call_user_func(self::getCallableLogFunction(), $error);

            trigger_error($error, E_USER_NOTICE);

        }
    }

    /**
     * Remove the cached OAuth Access Token
     */
    public static function clearCache(): void
    {
        if ((self::$config['access_token']['store_as'] ?? null) === 'temp_file') {

            $cacheFile = self::getAccessTokenFilePath();
            if (is_file($cacheFile)) {
                unlink($cacheFile);
            }

        } else if ((self::$config['access_token']['store_as'] ?? null) === 'custom') {

            call_user_func(
                self::toCallable(self::$config['access_token']['custom']['del']),
                self::$config['access_token']['custom']['custom_key']
            );

        }
    }

    /**
     * Get the cached access_token as an array. Null will be returned if there is no access token cached
     *
     * @return array|null
     * @throws \Exception
     */
    private static function actuallyGetCachedAccessToken(): ?array
    {

        if ((self::$config['access_token']['store_as'] ?? null) === 'temp_file') {

            if (file_exists(self::getAccessTokenFilePath())) {

                // the cache file exists
                if ($accessToken = require(self::getAccessTokenFilePath())) {
                    return $accessToken;
                }

            }

        } else if ((self::$config['access_token']['store_as'] ?? null) === 'custom') {

            if ($accessToken = call_user_func(
                self::toCallable(self::$config['access_token']['custom']['get']),
                self::$config['access_token']['custom']['custom_key']
            )) {
                if ($accessToken = @json_decode($accessToken, true)) {
                    return $accessToken;
                }
            }

        }

        return null;
    }

    /**
     * Cache the access token
     *
     * @param array $data
     * @return string|null
     * @throws \Exception
     */
    private static function actuallyCacheAccessToken(array $data): ?string
    {
        if ((self::$config['access_token']['store_as'] ?? null) === 'temp_file') {

            if (!@file_put_contents(self::getAccessTokenFilePath(), '<?php return ' . var_export($data, true) . ';')) {

                return
                    "Error writing to file.\n" .
                    "  When attempting to cache access_token to json file.\n" .
                    "  File: '" . self::getAccessTokenFilePath() . "'\n" .
                    "  Data: '" . json_encode($data) . "'";

            }

        } else if ((self::$config['access_token']['store_as'] ?? null) === 'custom') {

            try {
                call_user_func(
                    self::toCallable(self::$config['access_token']['custom']['set']),
                    self::$config['access_token']['custom']['custom_key'],
                    json_encode($data)
                );
            } catch (\Throwable $e) {
                return $e->getMessage();
            }

        }

        return null;
    }

    public static function getCallableLogFunction(): callable
    {
        $setting = self::$config['error_log_function'];
        if (is_string($setting)) {
            $setting = json_decode($setting, true);
        }
        return $setting;
    }

    private static function toCallable(mixed $callable): callable
    {
        if (is_string($callable)) {
            $callable = json_decode($callable, true);
        }
        return $callable;
    }

}