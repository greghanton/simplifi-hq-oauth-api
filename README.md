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

## Docs

- [Stage 1 hardening plan](./OAUTH_MODERNISATION_PLAN.md#stage-1--hardening-envelope-contract-smoke-tests)

