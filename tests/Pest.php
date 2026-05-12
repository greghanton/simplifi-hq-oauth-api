<?php

use SimplifiApi\ApiResponse;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
*/

uses()->in('Feature', 'Unit');

/*
|--------------------------------------------------------------------------
| Helpers — Fixture loading
|--------------------------------------------------------------------------
*/

function fixturePath(string $envelope, string $name): string
{
    return __DIR__.DIRECTORY_SEPARATOR.'Fixtures'.DIRECTORY_SEPARATOR.$envelope.DIRECTORY_SEPARATOR.$name.'.json';
}

function loadFixture(string $envelope, string $name): string
{
    $path = fixturePath($envelope, $name);
    if (! is_file($path)) {
        throw new RuntimeException("Fixture not found: {$path}");
    }

    return (string) file_get_contents($path);
}

function loadFixtureDecoded(string $envelope, string $name): mixed
{
    return json_decode(loadFixture($envelope, $name));
}

/*
|--------------------------------------------------------------------------
| Helpers — Build ApiResponse without a real HTTP round-trip
|--------------------------------------------------------------------------
*/

function makeApiResponse(
    mixed $response,
    bool $error = false,
    int $errorCode = 0,
    string $errorMessage = '',
    int $httpCode = 200,
    array $requestOptions = ['method' => 'GET', 'url' => 'sales', 'data' => []],
    array $config = ['APP_ENV' => 'testing'],
): ApiResponse {
    $decoded = is_string($response) ? json_decode($response) : $response;
    $effectiveUrl = 'https://api.example.test/'.($requestOptions['url'] ?? '');

    return ApiResponse::fromDecoded(
        decodedResponse: $decoded,
        config: $config,
        requestOptions: $requestOptions,
        timerStart: microtime(true),
        httpCode: $httpCode,
        effectiveUrl: $effectiveUrl,
        error: $error,
        errorCode: $errorCode,
        errorMessage: $errorMessage,
    );
}
