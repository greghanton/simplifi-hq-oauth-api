<?php
return [


    /*
     * Client id for Uber Accounting API OAuth
     */
    'client_id'  => '1',


    /*
     * Client secret for Uber Accounting API OAuth
     */
    'client_secret'  => '123',


    /*
     * URL base
     */
    'url-base'  => 'https://api.uberaccounting.co.uk/api/v1/',

    
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