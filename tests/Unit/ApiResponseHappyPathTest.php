<?php

declare(strict_types=1);

it('returns success() === true on a happy path response (legacy envelope)', function () {
    $response = makeApiResponse(loadFixtureDecoded('legacy', 'show'));

    expect($response->success())->toBeTrue()
        ->and($response->errors())->toBe([])
        ->and($response->data->id)->toBe(102);
});

it('returns success() === true on a happy path response (new envelope)', function () {
    $response = makeApiResponse(loadFixtureDecoded('new', 'show'));

    expect($response->success())->toBeTrue()
        ->and($response->errors())->toBe([])
        ->and($response->data->id)->toBe(102);
});

it('returns response() with the decoded body', function () {
    $body = loadFixtureDecoded('new', 'show');
    $response = makeApiResponse($body);

    expect($response->response())->toEqual($body);
});

it('returns getHttpCode() and getMethod() correctly', function () {
    $response = makeApiResponse(
        loadFixtureDecoded('new', 'show'),
        httpCode: 200,
        requestOptions: ['method' => 'POST', 'url' => 'sales', 'data' => []],
    );

    expect($response->getHttpCode())->toBe(200)
        ->and($response->getMethod())->toBe('POST');
});
