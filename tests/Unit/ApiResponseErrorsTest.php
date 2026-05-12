<?php

declare(strict_types=1);

it('populates errors() from legacy validation-error envelope', function () {
    $response = makeApiResponse(loadFixtureDecoded('legacy', 'validation-error'), httpCode: 422);
    $response->setSuccess(false);

    $errors = $response->errors();

    expect($response->success())->toBeFalse()
        ->and($errors)->toBeArray()
        ->and(count($errors))->toBe(2)
        ->and($errors[0]['title'])->toBe('The name field is required.')
        ->and($errors[1]['title'])->toBe('The email must be a valid email address.');
});

it('populates errors() from new (Laravel) validation-error envelope', function () {
    $response = makeApiResponse(loadFixtureDecoded('new', 'validation-error'), httpCode: 422);
    $response->setSuccess(false);

    $errors = $response->errors();

    expect($response->success())->toBeFalse()
        ->and($errors)->toBeArray()
        // 1 message + 1 (name) + 2 (email) = 4 titles
        ->and(count($errors))->toBe(4)
        ->and($errors[0]['title'])->toBe('The given data was invalid.')
        ->and(array_column($errors, 'title'))->toContain('The name field is required.')
        ->and(array_column($errors, 'title'))->toContain('The email must be a valid email address.')
        ->and(array_column($errors, 'title'))->toContain('The email field is required.');
});

it('populates errors() from legacy server-error envelope (top-level error object)', function () {
    $response = makeApiResponse(loadFixtureDecoded('legacy', 'server-error'), httpCode: 500);
    $response->setSuccess(false);

    $errors = $response->errors();

    expect($errors)->not->toBeEmpty()
        ->and($errors[0]['title'])->toBe('Internal Server Error: Something went wrong.');
});

it('populates errors() from new server-error envelope (top-level message)', function () {
    $response = makeApiResponse(loadFixtureDecoded('new', 'server-error'), httpCode: 500);
    $response->setSuccess(false);

    $errors = $response->errors();

    expect($errors)->not->toBeEmpty()
        ->and($errors[0]['title'])->toBe('Server Error');
});

it('populates errors() from a curl/transport error when response has no errors', function () {
    $response = makeApiResponse(
        response: null,
        error: true,
        errorCode: 7,
        errorMessage: 'Failed to connect to host',
        httpCode: 0,
    );

    $errors = $response->errors();

    expect($response->success())->toBeFalse()
        ->and($errors)->not->toBeEmpty()
        ->and($errors[0]['title'])->toContain('Failed to connect to host');
});

it('getSimpleErrorsArray() returns plain title strings', function () {
    $response = makeApiResponse(loadFixtureDecoded('new', 'validation-error'), httpCode: 422);
    $response->setSuccess(false);

    $simple = $response->getSimpleErrorsArray();

    expect($simple)->toBeArray()
        ->and($simple)->toContain('The given data was invalid.');
});

it('errorsToString() implodes titles with a glue', function () {
    $response = makeApiResponse(loadFixtureDecoded('legacy', 'validation-error'), httpCode: 422);
    $response->setSuccess(false);

    $str = $response->errorsToString(' | ');

    expect($str)->toBeString()
        ->and($str)->toContain('The name field is required.')
        ->and($str)->toContain(' | ');
});
