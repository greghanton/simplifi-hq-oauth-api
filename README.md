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

## Docs

Stage 1 documentation is being prepared:
- [Stage 1 hardening plan](./OAUTH_MODERNISATION_PLAN.md#stage-1--hardening-envelope-contract-smoke-tests)

