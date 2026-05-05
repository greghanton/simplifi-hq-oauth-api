<?php

/**
 * This function is used to get environment variables.
 * It first checks if a custom simplifiHqOauthApiEnv() function exists (allowing projects to define their own implementation).
 * If not, it falls back to env() for backwards compatibility with existing projects.
 *
 * @param  string  $key  The environment variable key
 * @param  mixed  $default  The default value if the environment variable is not set
 * @return mixed
 */
if (! function_exists('simplifiHqOauthApiLibEnv')) {
    function simplifiHqOauthApiLibEnv(string $key, mixed $default = null): mixed
    {
        if (function_exists('simplifiHqOauthApiEnv')) {
            return simplifiHqOauthApiEnv($key, $default);
        }

        if (function_exists('env')) {
            return env($key, $default);
        }

        return $default;
    }
}
