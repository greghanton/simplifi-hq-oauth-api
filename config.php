<?php
return [

    /**
     * NOTE: UBER_... values are only added here for backwards compatibility and will be removed in a future release.
     */


    /*
     * The version
     */
    'VERSION'                    => '1.0.0',


    /*
     * The environment e.g. "local" / "staging" / "production"
     */
    'APP_ENV'                    => env('APP_ENV'),


    /*
     * Client id for Uber Accounting API OAuth
     */
    'client_id'                  => env('SIMPLIFI_API_CLIENT_ID'),


    /*
     * Client secret for Uber Accounting API OAuth
     */
    'client_secret'              => env('SIMPLIFI_API_CLIENT_SECRET'),


    /*
     * Username for Uber Accounting API OAuth (probably an email)
     */
    'username'                   => env('SIMPLIFI_API_USERNAME'),


    /*
     * Password for Uber Accounting API OAuth
     */
    'password'                   => env('SIMPLIFI_API_PASSWORD'),


    /*
     * Scope to request
     */
    'scope'                      => env('SIMPLIFI_API_SCOPE', '*'),


    /*
     * URL base
     */
    'url-base'                   => env('SIMPLIFI_API_URL_BASE', 'https://api.simplifi.com/'),


    /*
     * URL version (will be appended to the url-base)
     */
    'url-version'                => 'api/v1/',


    /*
     * Temp file name to store a cached access token
     * This will be created in sys_get_temp_dir()
     */
    'access_token_filename'      => "ua-access-token.php",


    /*
     * Seconds
     * We don't want to use an access token that is about to expire.
     * Here you can specify e.g. don't use an access token that is going to expire in 10 seconds
     */
    'access_token_expire_buffer' => 10,


    /*
     * Callable
     * Where to send error messages. Errors will be sent as a single string.
     * SIMPLIFI_API_ERROR_LOG_FUNCTION should be a json encoded callable EG:
     *  • "error_log"
     *  • ["\App\Classes\Utils", "notifyError"]
     *  • If using dotenv the .env file might look like: SIMPLIFI_API_ERROR_LOG_FUNCTION="[\"\\\\App\\\\Classes\\\\Utils\", \"notifyError\"]"
     */
    'error_log_function' => json_decode(env('SIMPLIFI_API_ERROR_LOG_FUNCTION', '"error_log"'), true),

];