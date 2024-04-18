<?php
return [

    /**
     * The version
     */
    'VERSION'                    => '1.0.0',


    /**
     * The environment e.g. "local" / "staging" / "production"
     */
    'APP_ENV'                    => env('APP_ENV'),


    /**
     * Client id for OAuth API
     */
    'client_id'                  => env('SIMPLIFI_API_CLIENT_ID'),


    /**
     * Client secret for OAuth API
     */
    'client_secret'              => env('SIMPLIFI_API_CLIENT_SECRET'),


    /**
     * Username for OAuth API  (probably an email)
     */
    'username'                   => env('SIMPLIFI_API_USERNAME'),


    /**
     * Password for OAuth API
     */
    'password'                   => env('SIMPLIFI_API_PASSWORD'),


    /**
     * Scope to request
     */
    'scope'                      => env('SIMPLIFI_API_SCOPE', '*'),


    /**
     * URL base EG 'https://api.simplifi.com/'
     */
    'url-base'                   => env('SIMPLIFI_API_URL_BASE'),


    /**
     * URL version (will be appended to the url-base)
     */
    'url-version'                => 'api/v1/',


    /**
     * Seconds
     * We don't want to use an access token that is about to expire.
     * Here you can specify e.g. don't use an access token that is going to expire in 10 seconds
     */
    'access_token_expire_buffer' => 10,


    /**
     * Where to store the access token either:
     *  • 'temp_file' Temporary file in sys_get_temp_dir() e.g. "/tmp/simplifi-hq-oauth-api-access-token.php"
     *  • OR 'custom' Custom e.g. Redis
     */
    'access_token'               => [

        /**
         * string
         * Must be either 'temp_file' or 'custom'
         * (DEFAULT) if 'store_as'='temp_file' then 'temp_file'.'filename' is REQUIRED
         * if 'store_as'='custom' then 'custom'.* are all REQUIRED
         */
        'store_as'  => env('SIMPLIFI_API_ACCESS_TOKEN_STORE_AS', 'temp_file'),

        /**
         * 'store_as'='temp_file' Is the easiest method, you just need to make sure php has permission to the systems temp directory.
         * But won't work in serverless setups like Laravel Vapor
         */
        'temp_file' => [

            /**
             * String
             * This is the file name in the server temporary directory sys_get_temp_dir()
             * e.g. "simplifi-hq-oauth-api-access-token.php" might cause the access_token to be stored at:
             * "/tmp/simplifi-hq-oauth-api-access-token.php"
             * By default: md5(__DIR__) this means the file will be unique to the directory the config file is in.
             * Which means you can have multiple projects on the same server and not have a collision.
             */
            'filename' => env('SIMPLIFI_API_ACCESS_TOKEN_TEMP_FILE_FILENAME', md5(__DIR__) . '-access-token.php'),
        ],

        /**
         * If you are using 'store_as'='custom': 'custom_key', 'get', 'set', 'del' are all REQUIRED
         *  • 'get', 'set', 'del' must all be Callables
         *  • e.g. ["\App\Classes\MyRedis", "get"]
         *  • If using dotenv your .env file might look like:
         * SIMPLIFI_API_ACCESS_TOKEN_STORE_AS=custom
         * SIMPLIFI_API_ACCESS_TOKEN_CUSTOM_KEY=simplifi-hq-oauth-api-access-token
         * SIMPLIFI_API_ACCESS_TOKEN_GET="[\"\\\\App\\\\Classes\\\\MyRedis\", \"get\"]"
         * SIMPLIFI_API_ACCESS_TOKEN_SET="[\"\\\\App\\\\Classes\\\\MyRedis\", \"set\"]"
         * SIMPLIFI_API_ACCESS_TOKEN_DEL="[\"\\\\App\\\\Classes\\\\MyRedis\", \"del\"]"
         */
        'custom'    => [

            /**
             * string
             * This will be passed as the first parameter to 'get', 'set' and 'del'
             * If using Redis this would be the key in redis
             */
            'custom_key' => env('SIMPLIFI_API_ACCESS_TOKEN_CUSTOM_KEY', 'simplifi-hq-oauth-api-access-token'),

            /**
             * Callable
             * 'get' will be passed one parameter (<custom_key>)
             */
            'get'        => env('SIMPLIFI_API_ACCESS_TOKEN_GET'),

            /**
             * Callable
             * 'set' will be passed two parameter (<custom_key>, <Access Token Data as a string>)
             */
            'set'        => env('SIMPLIFI_API_ACCESS_TOKEN_SET'),

            /**
             * Callable
             * 'del' will be passed one parameter (<custom_key>)
             */
            'del'        => env('SIMPLIFI_API_ACCESS_TOKEN_DEL'),
        ],

    ],


    /**
     * Callable
     * Where to send error messages. Errors will be sent as a single string.
     * SIMPLIFI_API_ERROR_LOG_FUNCTION should be a json encoded callable EG:
     *  • "error_log"
     *  • ["\App\Classes\Utils", "notifyError"]
     *  • If using dotenv your .env file might look like:
     * SIMPLIFI_API_ERROR_LOG_FUNCTION="[\"\\\\App\\\\Classes\\\\Utils\", \"notifyError\"]"
     */
    'error_log_function'         => env('SIMPLIFI_API_ERROR_LOG_FUNCTION', '"error_log"'),


    /**
     * JSON encoded array of headers to send with every request
     * These are only the default headers set, and they can be overridden when sending a request
     *  • If using dotenv your .env file might look like:
     * SIMPLIFI_API_DEFAULT_HEADERS="{\"Accept\":\"application/json\",\"Content-type\":\"application/json\"}"
     */
    'headers'                    => !env('SIMPLIFI_API_DEFAULT_HEADERS') ?
        [] :
        (is_string(env('SIMPLIFI_API_DEFAULT_HEADERS')) ?
            json_decode(env('SIMPLIFI_API_DEFAULT_HEADERS'), true) :
            env('SIMPLIFI_API_DEFAULT_HEADERS')
        ),


    /**
     * If this is true then a header will be added like 'trace-debug-header' => ''
     */
    'add_trace_debug_header'     => !!env('SIMPLIFI_API_ADD_TRACE_DEBUG_HEADER'),


];