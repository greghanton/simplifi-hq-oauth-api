# simplifi-hq-oauth-api

OAuth2 client and request dispatcher for the Joy Pilot API tier.

## Install

```bash
composer require greghanton/simplifi-hq-oauth-api
```

## Configuration

This package loads defaults from `config.php` and then reads values from environment variables using `simplifiHqOauthApiLibEnv()`.

### Environment variables

| Variable | Required | Default | Notes |
| --- | --- | --- | --- |
| `SIMPLIFI_API_GRANT_TYPE` | No | `client_credentials` | Recommended for server-to-server. `password` is deprecated (OAuth 2.1 / RFC 9700 guidance). |
| `SIMPLIFI_API_CLIENT_ID` | Yes | - | OAuth client id. |
| `SIMPLIFI_API_CLIENT_SECRET` | Yes | - | OAuth client secret. |
| `SIMPLIFI_API_USERNAME` | Only for `password` grant | - | Username/email for password grant flows. |
| `SIMPLIFI_API_PASSWORD` | Only for `password` grant | - | Password for password grant flows. |
| `SIMPLIFI_API_SCOPE` | No | `*` | OAuth scope. |
| `SIMPLIFI_API_URL_BASE` | Yes | - | API base URL, e.g. `https://api.example.com/`. |
| `SIMPLIFI_API_ACCESS_TOKEN_STORE_AS` | No | `temp_file` | `custom` is recommended for production, `temp_file` is fine for local/dev. |
| `SIMPLIFI_API_ACCESS_TOKEN_TEMP_FILE_FILENAME` | No | Derived from project path/version | Only used when `store_as=temp_file`. |
| `SIMPLIFI_API_ACCESS_TOKEN_CUSTOM_KEY` | For `custom` store | `simplifi-hq-oauth-api-access-token` | Key passed to custom callables. |
| `SIMPLIFI_API_ACCESS_TOKEN_GET` | For `custom` store | - | JSON-encoded callable. Signature: `get($customKey): ?string`. |
| `SIMPLIFI_API_ACCESS_TOKEN_SET` | For `custom` store | - | JSON-encoded callable. Signature: `set($customKey, $tokenJson): mixed`. |
| `SIMPLIFI_API_ACCESS_TOKEN_DEL` | For `custom` store | - | JSON-encoded callable. Signature: `del($customKey): mixed`. |
| `SIMPLIFI_API_ACCESS_TOKEN_LOCK` | Optional | - | JSON-encoded callable. Signature: `lock($customKey, $ttlSeconds): bool`. |
| `SIMPLIFI_API_ACCESS_TOKEN_UNLOCK` | Optional | - | JSON-encoded callable. Signature: `unlock($customKey): mixed`. |
| `SIMPLIFI_API_ERROR_LOG_FUNCTION` | No | `"error_log"` | JSON-encoded callable for internal error logging. |
| `SIMPLIFI_API_DEFAULT_HEADERS` | No | `[]` | JSON object of headers merged into each request. |
| `SIMPLIFI_API_SSL_VERIFY` | No | `true` | TLS certificate verification for HTTP requests. |
| `SIMPLIFI_API_ADD_TRACE_DEBUG_HEADER` | No | `false` | Adds `trace-debug-header` from caller backtrace. |

## Basic usage

### Synchronous request

```php
<?php

require __DIR__.'/vendor/autoload.php';

use SimplifiApi\ApiRequest;

$response = ApiRequest::request([
    'method' => 'GET',
    'url' => 'sales',
]);

if (! $response->success()) {
    throw new RuntimeException($response->errorsToString());
}

foreach ($response as $row) {
    // ApiResponse implements Iterator when response has a top-level "data" array.
    var_dump($row);
}
```

### Async request

```php
<?php

use SimplifiApi\ApiRequest;

$promise = ApiRequest::requestAsync([
    'method' => 'GET',
    'url' => 'sales',
]);

$response = $promise->wait();

if ($response->success()) {
    var_dump($response->response());
}
```

### Batch request

```php
<?php

use SimplifiApi\ApiRequest;

$responses = ApiRequest::batch([
    ['method' => 'GET', 'url' => 'sales'],
    ['method' => 'GET', 'url' => ['sales/$/invoice', 102]],
]);

foreach ($responses as $response) {
    if (! $response->success()) {
        error_log($response->errorsToString());
    }
}
```

## Access token storage

Supported modes:

- `custom` (recommended for production; usually Redis)
- `temp_file` (unchanged local/dev fallback)

### Redis setup via custom callables

```dotenv
SIMPLIFI_API_ACCESS_TOKEN_STORE_AS=custom
SIMPLIFI_API_ACCESS_TOKEN_CUSTOM_KEY=simplifi-hq-oauth-api-access-token
SIMPLIFI_API_ACCESS_TOKEN_GET="[\"\\\\App\\\\Cache\\\\TokenStore\", \"get\"]"
SIMPLIFI_API_ACCESS_TOKEN_SET="[\"\\\\App\\\\Cache\\\\TokenStore\", \"set\"]"
SIMPLIFI_API_ACCESS_TOKEN_DEL="[\"\\\\App\\\\Cache\\\\TokenStore\", \"del\"]"
```

Laravel example:

```php
<?php

namespace App\Cache;

use Illuminate\Support\Facades\Cache;

class TokenStore
{
    public static function get(string $key): ?string
    {
        return Cache::store('redis')->get($key);
    }

    public static function set(string $key, string $value): bool
    {
        return Cache::store('redis')->forever($key, $value);
    }

    public static function del(string $key): bool
    {
        return Cache::store('redis')->forget($key);
    }
}
```

## Token refresh mutex (optional, recommended in production)

When several processes see an expired token at the same time, configure lock/unlock callables to prevent duplicate `oauth/token` refresh requests.

```dotenv
SIMPLIFI_API_ACCESS_TOKEN_LOCK="[\"\\\\App\\\\Cache\\\\TokenLock\", \"acquire\"]"
SIMPLIFI_API_ACCESS_TOKEN_UNLOCK="[\"\\\\App\\\\Cache\\\\TokenLock\", \"release\"]"
```

Laravel lock example:

```php
<?php

namespace App\Cache;

use Illuminate\Support\Facades\Cache;

class TokenLock
{
    private static array $locks = [];

    public static function acquire(string $customKey, int $ttl): bool
    {
        $lockName = $customKey.':oauth-refresh-lock';
        $lock = Cache::store('redis')->lock($lockName, $ttl);

        if (! $lock->get()) {
            return false;
        }

        self::$locks[$lockName] = $lock;

        return true;
    }

    public static function release(string $customKey): void
    {
        $lockName = $customKey.':oauth-refresh-lock';

        if (! isset(self::$locks[$lockName])) {
            return;
        }

        self::$locks[$lockName]->release();
        unset(self::$locks[$lockName]);
    }
}
```

Mutex flow inside the package:

1. Try to lock (currently with ~10s TTL).
2. If lock is acquired, refresh token and unlock.
3. If lock is not acquired, wait 1-2s, re-check cache, and reuse token if present.
4. Only fetch a fresh token if cache is still empty after waiting.

## Event listener examples

Use listeners for metrics, tracing, or debugging without changing package internals.

```php
<?php

use SimplifiApi\ApiRequest;
use SimplifiApi\ApiResponse;

ApiRequest::addEventListener(ApiRequest::EVENT_BEFORE_REQUEST, function (array $requestOptions, array $config): void {
    // Example: emit request-start metric.
});

ApiRequest::addEventListener(ApiRequest::EVENT_AFTER_REGULAR_REQUEST, function (ApiResponse $response): void {
    // Example: emit sync request timing metric.
});

ApiRequest::addEventListener(ApiRequest::EVENT_AFTER_ASYNC_REQUEST, function (ApiResponse $response): void {
    // Example: emit async request metric.
});

ApiRequest::addEventListener(ApiRequest::EVENT_AFTER_BATCH_REQUEST, function (ApiResponse $response): void {
    // Example: emit per-item batch metric.
});

ApiResponse::addEventListener(ApiResponse::EVENT_RESPONSE_CREATED, function (ApiResponse $response): void {
    // Example: central hook after any response object is created.
});
```

## Laravel `config:cache` gotcha

`src/helpers.php` resolves env values in this order:

1. `simplifiHqOauthApiEnv($key, $default)` if your app defines it
2. fallback to Laravel `env($key, $default)` if available
3. fallback default

With Laravel `config:cache`, runtime `env()` calls outside config files return `null`. To keep custom token store and mutex callables reliable under cached config, define `simplifiHqOauthApiEnv()` in your app and read from your own config layer instead of direct runtime `env()`.

## OAuth grant type default and safe rollout

Default grant type is now `client_credentials`.

- `client_credentials` is recommended for server-to-server usage.
- `password` remains supported but is deprecated.

To avoid breaking existing GUI environments that may have implicitly relied on the old default:

1. In every consumer environment, set `SIMPLIFI_API_GRANT_TYPE` explicitly before upgrading.
2. Confirm the API tier Passport client is allowed to use `client_credentials`.
3. Upgrade this package.

## Development

```bash
composer run lint:check
composer run stan
composer run test
```

## Docs

- [Modernisation plan](./OAUTH_MODERNISATION_PLAN.md)

