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
| `SIMPLIFI_API_GRANT_TYPE` | No | `client_credentials` | The only grant this package itself can mint. The `password` grant is **disabled server-side** (not just deprecated) — see [Per-consumer grant matrix](#per-consumer-grant-matrix). |
| `SIMPLIFI_API_CLIENT_ID` | Yes | - | OAuth client id. |
| `SIMPLIFI_API_CLIENT_SECRET` | Yes | - | OAuth client secret. |
| `SIMPLIFI_API_USERNAME` | No (legacy) | - | Dead unless a future grant reintroduces it; the `password` grant that read this is disabled server-side. |
| `SIMPLIFI_API_PASSWORD` | No (legacy) | - | Dead alongside `SIMPLIFI_API_USERNAME`, same reason. |
| `SIMPLIFI_API_SCOPE` | No | `*` | OAuth scope, forwarded as-is to `/oauth/token`. |
| `SIMPLIFI_API_ALLOW_SERVICE_MINT` | No | `true` | Set `false` on any **per-session/user-context** config (see [User-scoped tokens](#user-scoped-tokens-the-package-never-mints-them)). Leave `true` (default) on the global/anonymous config. |
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

## Per-consumer grant matrix

The API tier's identity model (Stage 1.5) draws a hard line between three kinds of token, and this
package only ever mints one of them. Use this table to decide how each consumer should be configured:

| Consumer | Grant this package mints | How the token is bound | Notes |
| --- | --- | --- | --- |
| **Anonymous server-to-server** (password reset, signup, email verification, the public login/onboarding front doors) | `client_credentials` via `SIMPLIFI_API_GRANT_TYPE=client_credentials` | No actor — app-level only | This is the **only** flow this package's `AccessToken::generateNewAccessToken()` performs. Use a narrowed service scope (e.g. `svc:auth`/`svc:onboarding`), not `*`, where the client supports it. Cached under the **global** `custom_key`. |
| **First-party user-scoped** (a logged-in GUI/No Worries/Apex Wealth session) | **Not minted by this package.** The consumer calls `POST /oauth/token` directly with the API's `login_token` grant (exchanging the one-time login token issued at login) to get the user's access token, and `grant_type=refresh_token` to renew it. | Per-session — the consumer writes the token into a **per-session** `custom_key` (see [User-scoped tokens](#user-scoped-tokens-the-package-never-mints-them)) | `password` is **disabled server-side** — do not configure it for this case. This package only ever *reads* the token the consumer already obtained. |
| **Third-party / MCP / AI integrations** | Not via this package at all | `authorization_code + PKCE`, entity-bound | These consumers talk to Passport's authorization-code flow directly; listed here for completeness only. |

## User-scoped tokens: the package never mints them

Per the Stage 1.5 identity model, **the GUI/consumer owns acquiring and refreshing user-scoped
tokens; this package only owns using them.** Concretely:

1. At login, the consumer exchanges its one-time login token for a user-scoped access + refresh
   token pair directly against `/oauth/token` (`grant_type=login_token`), outside this package.
2. The consumer writes that token into a **per-session** `custom` store — i.e. a `custom_key` that
   is unique per user session, not the shared service-token key — using the same `get`/`set`/`del`
   callable hooks described above ([Redis setup](#redis-setup-via-custom-callables)).
3. The consumer calls `ApiRequest::request($options, $overrideConfig)` with `$overrideConfig`
   pointing `access_token.custom.custom_key` (and `get`/`set`/`del`) at that per-session store.
4. **`$overrideConfig` must also set `'allow_service_credential_mint' => false`.**

That last step matters: without it, if the per-session cache entry is missing or expired (token
revoked, refresh not yet run, cache evicted), `AccessToken::generateNewAccessToken()` would fall
through to minting a *service* (`client_credentials`) token from the global
`client_id`/`client_secret` — and cache it under that user's session key. The next request would
then silently run **as the service account** while believing it was acting as that user — exactly
the identity-laundering hole the Stage 1.5 model closes. With `allow_service_credential_mint=false`
set, a cache miss on a user-context config fails closed instead: `ApiRequest::request()` returns a
non-success `ApiResponse` (no HTTP call is made), and the consumer should re-authenticate or run its
own refresh flow rather than receive a token at all.

```php
<?php

use SimplifiApi\ApiRequest;

// Per-session config for an authenticated user — set once after login/refresh writes the
// token into Redis under $sessionCustomKey.
$response = ApiRequest::request(
    ['method' => 'GET', 'url' => 'sales'],
    [
        'access_token' => [
            'store_as' => 'custom',
            'custom' => [
                'custom_key' => $sessionCustomKey,
                'get' => ['App\\Cache\\TokenStore', 'get'],
                'set' => ['App\\Cache\\TokenStore', 'set'],
                'del' => ['App\\Cache\\TokenStore', 'del'],
            ],
        ],
        'allow_service_credential_mint' => false,
    ]
);
```

Leave `allow_service_credential_mint` unset (defaults `true`) on the global/anonymous config used
for `client_credentials` calls — there is no user identity to protect there, and that path is
exactly what `generateNewAccessToken()` is for.

## Development

```bash
composer run lint:check
composer run stan
composer run test
```

## Docs

- [Modernisation plan](./OAUTH_MODERNISATION_PLAN.md)
- [Stage 1.5 — Identity & Scopes (cross-repo source of truth)](./STAGE1-5_MODERNISATION_PLAN.md)

