# simplifi-hq-oauth-api

OAuth2 client and request dispatcher for the Joy Pilot API tier.

## Install

```bash
composer require greghanton/simplifi-hq-oauth-api
```

## Basic usage

1. Add API credentials and endpoint values in `config.php`.
2. Dispatch a request with `SimplifiApi\\ApiRequest`.

```php
<?php

require __DIR__.'/vendor/autoload.php';

use SimplifiApi\ApiRequest;

$response = ApiRequest::request([
    'method' => 'GET',
    'url' => 'sales',
]);

if ($response->success()) {
    $data = $response->response();
    var_dump($data);
}
```

## Access token storage

This package supports two access-token storage modes:

- `custom` (recommended for production, typically Redis)
- `temp_file` (kept as-is for local/dev fallback)

No breaking change: `temp_file` remains available and unchanged.

### Redis via custom callables (recommended)

Set storage mode to `custom` and provide JSON-encoded callables for `get`, `set`, and `del`.

```dotenv
SIMPLIFI_API_ACCESS_TOKEN_STORE_AS=custom
SIMPLIFI_API_ACCESS_TOKEN_CUSTOM_KEY=simplifi-hq-oauth-api-access-token
SIMPLIFI_API_ACCESS_TOKEN_GET="[\"\\\\App\\\\Cache\\\\TokenStore\", \"get\"]"
SIMPLIFI_API_ACCESS_TOKEN_SET="[\"\\\\App\\\\Cache\\\\TokenStore\", \"set\"]"
SIMPLIFI_API_ACCESS_TOKEN_DEL="[\"\\\\App\\\\Cache\\\\TokenStore\", \"del\"]"
```

Callable contracts:

- `get($customKey): string|null`
- `set($customKey, $tokenJson): mixed`
- `del($customKey): mixed`

### Laravel example (`Cache::store('redis')`)

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
        // Access token payload already includes expiry metadata.
        return Cache::store('redis')->forever($key, $value);
    }

    public static function del(string $key): bool
    {
        return Cache::store('redis')->forget($key);
    }
}
```

### Important Laravel `config:cache` gotcha

The package reads env values through `simplifiHqOauthApiLibEnv()` in `src/helpers.php`:

1. If your app defines `simplifiHqOauthApiEnv()`, that is used.
2. Otherwise, it falls back to Laravel `env()`.

In Laravel apps with `config:cache`, calling `env()` outside config files returns `null`. If you want Redis/custom token storage to keep working under cached config, define and use your own `simplifiHqOauthApiEnv()` wrapper so values come from a safe source (for example cached config values), not direct runtime `env()`.

## Token refresh mutex (parallel-call protection)

When multiple requests try to refresh an expired token simultaneously, this package uses an optional lock/unlock pair to ensure only one process calls `oauth/token`. This prevents duplicate auth requests and reduces load on the OAuth server.

**Mutex is opt-in.** If you do not configure `lock` and `unlock` callables, the package works without it (the current behaviour).

### When to enable the mutex

Enable the mutex for any production deployment with **parallel requests**:

- Laravel Inertia partial reloads (multiple page fragments load concurrently)
- Queue workers processing jobs in parallel
- API serverless functions running concurrently
- Any scenario where multiple PHP processes can refresh the token simultaneously

### Setting up the mutex in Laravel

```php
<?php

namespace App\Cache;

use Illuminate\Support\Facades\Cache;

class TokenLock
{
    /**
     * Try to acquire the token-refresh lock
     *
     * @param  string  $customKey  The cache key for the token
     * @param  int  $ttl  Lock time-to-live in seconds
     * @return bool True if lock acquired, false otherwise
     */
    public static function acquire(string $customKey, int $ttl): bool
    {
        // Laravel's Cache::lock() returns a Lock instance if acquired, null if not
        // The lock auto-releases after $ttl seconds
        $lock = Cache::store('redis')->lock($customKey . ':lock', $ttl);
        
        if ($lock->get()) {
            // Store the lock in a thread-safe way so release() can find it
            // (Laravel's lock() doesn't maintain a reference, so we use a static)
            static::$currentLock = $lock;
            return true;
        }
        
        return false;
    }

    /**
     * Release the token-refresh lock
     */
    public static function release(string $customKey): void
    {
        if (isset(static::$currentLock)) {
            static::$currentLock->release();
            unset(static::$currentLock);
        }
    }

    private static $currentLock;
}
```

Then add to your `.env`:

```dotenv
SIMPLIFI_API_ACCESS_TOKEN_LOCK="[\"\\App\\Cache\\TokenLock\", \"acquire\"]"
SIMPLIFI_API_ACCESS_TOKEN_UNLOCK="[\"\\App\\Cache\\TokenLock\", \"release\"]"
```

### Mutex contract

- **`lock($customKey, $ttl)` must return:** `bool` — `true` if lock acquired, `false` if another process holds it
- **`unlock($customKey)` must return:** void or bool (return value is ignored)

### How the mutex works

When the cache is empty and a token refresh is needed:

1. The package tries to acquire the lock (~10s TTL).
2. **If acquired:** The package refreshes the token, caches it, then releases the lock.
3. **If not acquired:** Another process holds the lock. This process waits 1–2 seconds, then rechecks the cache. If a fresh token was cached by the lock holder, it uses that token. If the cache is still empty after waiting, it fetches a fresh token (without holding the lock).

This ensures that under concurrent load, at most **one** `oauth/token` call fires per token expiry — the lock holder generates it, and other processes wait and reuse it.

## Docs

- [Stage 1 hardening plan](./OAUTH_MODERNISATION_PLAN.md#stage-1--hardening-envelope-contract-smoke-tests)

