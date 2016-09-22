<?php
return [


    /*
     * Client id for Uber Accounting API OAuth
     */
    'client_id'  => env('UBER_API_CLIENT_ID'),


    /*
     * Client secret for Uber Accounting API OAuth
     */
    'client_secret'  => env('UBER_API_CLIENT_SECRET'),


    /*
     * Username for Uber Accounting API OAuth
     */
    'username'  => env('UBER_API_USERNAME'),


    /*
     * Password for Uber Accounting API OAuth
     */
    'password'  => env('UBER_API_PASSWORD'),


    /*
     * URL base
     */
    'url-base'  => env('UBER_URL_BASE', 'https://api.uberaccounting.co.uk/'),


    /*
     * URL version (will be appended to the url-base)
     */
    'url-version'  => 'api/v1/',

    
    /*
     * Temp file name to store a cached access token
     * This will be created in sys_get_temp_dir()
     */
    'access_token_filename' => "ua-access-token.php",

    
    /*
     * Seconds
     * We don't want to use an access token that is about to expire.
     * Here you can specify e.g. don't use an access token that is going to expire in 10 seconds
     */
    'access_token_expire_buffer' => 10,


];