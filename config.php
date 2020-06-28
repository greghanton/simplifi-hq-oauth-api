<?php
return [

    /**
     * NOTE: UBER_... values are only added here for backwards compatibility and will be removed in a future release.
     */


    /*
     * The environment e.g. "local" / "staging" / "production"
     */
    'APP_ENV'                    => env('APP_ENV'),


    /*
     * Client id for Uber Accounting API OAuth
     */
    'client_id'                  => env('SIMPLIFI_API_CLIENT_ID') ?: env('UBER_API_CLIENT_ID'),


    /*
     * Client secret for Uber Accounting API OAuth
     */
    'client_secret'              => env('SIMPLIFI_API_CLIENT_SECRET') ?: env('UBER_API_CLIENT_SECRET'),


    /*
     * Username for Uber Accounting API OAuth
     */
    'username'                   => env('SIMPLIFI_API_USERNAME') ?: env('UBER_API_USERNAME'),


    /*
     * Password for Uber Accounting API OAuth
     */
    'password'                   => env('SIMPLIFI_API_PASSWORD') ?: env('UBER_API_PASSWORD'),


    /*
     * Scope to request
     */
    'scope'                      => env('SIMPLIFI_API_SCOPE', '*'),


    /*
     * URL base
     */
    'url-base'                   => env('SIMPLIFI_URL_BASE') ?: env('UBER_URL_BASE', 'https://api.simplifi.com/'),


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

];