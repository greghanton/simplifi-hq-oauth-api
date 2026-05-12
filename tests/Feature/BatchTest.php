<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use SimplifiApi\AsyncClient;

/**
 * Default test config matching what AsyncClient::getClient() will compare against.
 */
function batchTestConfig(): array
{
    return [
        'url-base' => 'https://api.example.test/',
        'url-version' => 'api/v1/',
        'VERSION' => '1.0.0',
        'add_trace_debug_header' => false,
        'ssl_verify' => false,
        'headers' => [],
    ];
}

/**
 * Install a mocked Guzzle client into AsyncClient with a queue of pre-canned responses.
 * Also pins AsyncClient::$config so a later call to getClient($config) does not
 * rebuild the client (which would replace our mocked handler).
 *
 * @param  Response[]  $responses
 */
function installMockGuzzle(array $responses, array $config): MockHandler
{
    $mock = new MockHandler($responses);
    $stack = HandlerStack::create($mock);
    $client = new Client(['handler' => $stack, 'http_errors' => false]);

    $clientRef = new ReflectionProperty(AsyncClient::class, 'client');
    $clientRef->setValue(null, $client);

    $configRef = new ReflectionProperty(AsyncClient::class, 'config');
    $configRef->setValue(null, $config);

    return $mock;
}

afterEach(function () {
    AsyncClient::resetClient();
});

it('batch() returns responses in the same order as the input requests', function () {
    $config = batchTestConfig();
    installMockGuzzle([
        new Response(200, ['Content-Type' => 'application/json'], (string) file_get_contents(fixturePath('new', 'show'))),
        new Response(200, ['Content-Type' => 'application/json'], json_encode(['data' => ['id' => 2]])),
        new Response(200, ['Content-Type' => 'application/json'], json_encode(['data' => ['id' => 3]])),
    ], $config);

    $prepared = [
        ['method' => 'GET', 'url' => 'sales/102', 'headers' => [], 'data' => [], 'response-type' => 'json'],
        ['method' => 'GET', 'url' => 'sales/2', 'headers' => [], 'data' => [], 'response-type' => 'json'],
        ['method' => 'GET', 'url' => 'sales/3', 'headers' => [], 'data' => [], 'response-type' => 'json'],
    ];

    $responses = AsyncClient::batch($prepared, $config);

    expect($responses)->toHaveCount(3)
        ->and($responses[0]->success())->toBeTrue()
        ->and($responses[0]->response()->data->id)->toBe(102)
        ->and($responses[1]->response()->data->id)->toBe(2)
        ->and($responses[2]->response()->data->id)->toBe(3);
});

it('batch() preserves keys when an input has string keys', function () {
    $config = batchTestConfig();
    installMockGuzzle([
        new Response(200, ['Content-Type' => 'application/json'], json_encode(['data' => ['name' => 'first']])),
        new Response(200, ['Content-Type' => 'application/json'], json_encode(['data' => ['name' => 'second']])),
    ], $config);

    $prepared = [
        'alpha' => ['method' => 'GET', 'url' => 'a', 'headers' => [], 'data' => [], 'response-type' => 'json'],
        'beta' => ['method' => 'GET', 'url' => 'b', 'headers' => [], 'data' => [], 'response-type' => 'json'],
    ];

    $responses = AsyncClient::batch($prepared, $config);

    expect(array_keys($responses))->toBe(['alpha', 'beta'])
        ->and($responses['alpha']->response()->data->name)->toBe('first')
        ->and($responses['beta']->response()->data->name)->toBe('second');
});

it('batch() returns a non-success response when an upstream returns 5xx', function () {
    $config = batchTestConfig();
    installMockGuzzle([
        new Response(500, ['Content-Type' => 'application/json'], (string) file_get_contents(fixturePath('new', 'server-error'))),
    ], $config);

    $prepared = [
        ['method' => 'GET', 'url' => 'broken', 'headers' => [], 'data' => [], 'response-type' => 'json'],
    ];

    $responses = AsyncClient::batch($prepared, $config);

    expect($responses[0]->success())->toBeFalse()
        ->and($responses[0]->errors())->not->toBeEmpty();
});
