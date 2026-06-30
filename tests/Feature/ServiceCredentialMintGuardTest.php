<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use SimplifiApi\AccessToken;
use SimplifiApi\ApiRequest;
use SimplifiApi\ApiResponse;
use SimplifiApi\AsyncClient;

require_once __DIR__.'/MutexTest.php';

/**
 * Install a mocked Guzzle client with an EMPTY response queue, so any attempted
 * HTTP call is detectable via $mock->getLastRequest() (set unconditionally by
 * MockHandler before the queue is consulted, even when the queue is empty).
 */
function installEmptyMockGuzzle(array $config): MockHandler
{
    $mock = new MockHandler([]);
    $stack = HandlerStack::create($mock);
    $client = new Client(['handler' => $stack, 'http_errors' => false]);

    $clientRef = new ReflectionProperty(AsyncClient::class, 'client');
    $clientRef->setValue(null, $client);

    $asyncConfigRef = new ReflectionProperty(AsyncClient::class, 'config');
    $asyncConfigRef->setValue(null, $config);

    // AccessToken::generateNewAccessToken() mints via a nested ApiRequest::request() call with
    // no $overrideConfig of its own — it relies on ApiRequest's own static $config already
    // holding the right values (normally seeded by the outer ApiRequest::request() call that
    // triggered the mint). Seed it directly here to reproduce that for these focused tests.
    $apiRequestConfigRef = new ReflectionProperty(ApiRequest::class, 'config');
    $apiRequestConfigRef->setValue(null, $config);

    return $mock;
}

function userContextConfig(array $overrides = []): array
{
    return array_merge(mutexConfig(), [
        'url-base' => 'https://api.example.test/',
        'url-version' => 'api/v1/',
        'add_trace_debug_header' => false,
        'ssl_verify' => false,
        'headers' => [],
        'grant_type' => 'client_credentials',
        'client_id' => 'test-client',
        'client_secret' => 'test-secret',
        'username' => null,
        'password' => null,
        'scope' => '*',
        'allow_service_credential_mint' => false,
    ], $overrides);
}

afterEach(function () {
    AsyncClient::resetClient();
    $apiRequestConfigRef = new ReflectionProperty(ApiRequest::class, 'config');
    $apiRequestConfigRef->setValue(null, []);
});

it('fails closed (no HTTP call) when allow_service_credential_mint=false and the cache is empty', function () {
    MutexTest::reset();
    $config = userContextConfig();
    $mock = installEmptyMockGuzzle($config);

    $result = AccessToken::getAccessToken($config);

    expect($mock->getLastRequest())->toBeNull()
        ->and($result)->toBeInstanceOf(ApiResponse::class)
        ->and($result->success())->toBeFalse()
        ->and($result->errorsToString())->toContain('allow_service_credential_mint');

    // Nothing was written back to the per-session store under the blocked config.
    expect(MutexTest::$store)->toBe([]);
});

it('still mints normally when allow_service_credential_mint is unset (default true)', function () {
    MutexTest::reset();
    $config = userContextConfig();
    unset($config['allow_service_credential_mint']);

    $mock = installEmptyMockGuzzle($config);
    $mock->append(new Response(200, ['Content-Type' => 'application/json'], json_encode([
        'access_token' => 'fresh-token',
        'token_type' => 'Bearer',
        'expires_in' => 3600,
    ])));

    $result = AccessToken::getAccessToken($config);

    expect($result)->toBe('fresh-token')
        ->and($mock->getLastRequest())->not->toBeNull();
});

it('reads a cached token without ever consulting allow_service_credential_mint', function () {
    MutexTest::reset();
    $config = userContextConfig();

    MutexTest::$store['simplifi-test-key'] = (string) json_encode([
        'access_token' => 'cached-token',
        'token_type' => 'Bearer',
        'expires_in' => 3600,
        'expires_at' => time() + 3600,
    ]);

    $mock = installEmptyMockGuzzle($config);

    $result = AccessToken::getAccessToken($config);

    expect($result)->toBe('cached-token')
        ->and($mock->getLastRequest())->toBeNull();
});
