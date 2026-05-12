<?php

use SimplifiApi\ApiResponse;
use Tests\Support\FakeCurl;

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
| Helpers — Build ApiResponse with a stub Curl (no real HTTP)
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
    $curl = new FakeCurl;
    $curl->response = is_string($response) ? json_decode($response) : $response;
    $curl->error = $error;
    $curl->errorCode = $errorCode;
    $curl->errorMessage = $errorMessage;
    $curl->httpStatusCode = $httpCode;
    $curl->effectiveUrl = 'https://api.example.test/'.($requestOptions['url'] ?? '');

    return new ApiResponse($config, $curl, $requestOptions, microtime(true));
}
