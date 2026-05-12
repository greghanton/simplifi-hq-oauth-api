<?php

declare(strict_types=1);

use SimplifiApi\ApiResponse;

/**
 * Helper to invoke private methods on ApiResponse.
 * On PHP 8.1+ private members are reflectively accessible without setAccessible().
 */
function invokePrivate(ApiResponse $response, string $method, array $args = []): mixed
{
    $ref = new ReflectionMethod($response, $method);

    return $ref->invokeArgs($response, $args);
}

it('detects legacy paginator shape', function () {
    $response = makeApiResponse(loadFixtureDecoded('legacy', 'index-page-1'));

    expect(invokePrivate($response, 'getPaginatorShape'))->toBe('paginator');
});

it('detects new meta/links shape', function () {
    $response = makeApiResponse(loadFixtureDecoded('new', 'index-page-1'));

    expect(invokePrivate($response, 'getPaginatorShape'))->toBe('meta');
});

it('returns null shape for non-paginated responses', function () {
    $response = makeApiResponse(loadFixtureDecoded('new', 'show'));

    expect(invokePrivate($response, 'getPaginatorShape'))->toBeNull();
});

it('hasNextPage() is true on legacy page 1 of 3', function () {
    $response = makeApiResponse(loadFixtureDecoded('legacy', 'index-page-1'));

    expect(invokePrivate($response, 'hasNextPage'))->toBeTrue();
});

it('hasNextPage() is false on legacy final page', function () {
    $response = makeApiResponse(loadFixtureDecoded('legacy', 'index-final-page'));

    expect(invokePrivate($response, 'hasNextPage'))->toBeFalse();
});

it('hasNextPage() is true on new envelope page 1 of 3', function () {
    $response = makeApiResponse(loadFixtureDecoded('new', 'index-page-1'));

    expect(invokePrivate($response, 'hasNextPage'))->toBeTrue();
});

it('hasNextPage() is false on new envelope final page', function () {
    $response = makeApiResponse(loadFixtureDecoded('new', 'index-final-page'));

    expect(invokePrivate($response, 'hasNextPage'))->toBeFalse();
});

it('getCurrentPage() returns 1 on legacy page 1', function () {
    $response = makeApiResponse(loadFixtureDecoded('legacy', 'index-page-1'));

    expect(invokePrivate($response, 'getCurrentPage'))->toBe(1);
});

it('getCurrentPage() returns 3 on legacy final page', function () {
    $response = makeApiResponse(loadFixtureDecoded('legacy', 'index-final-page'));

    expect(invokePrivate($response, 'getCurrentPage'))->toBe(3);
});

it('getCurrentPage() returns 1 on new envelope page 1', function () {
    $response = makeApiResponse(loadFixtureDecoded('new', 'index-page-1'));

    expect(invokePrivate($response, 'getCurrentPage'))->toBe(1);
});

it('getCurrentPage() returns 3 on new envelope final page', function () {
    $response = makeApiResponse(loadFixtureDecoded('new', 'index-final-page'));

    expect(invokePrivate($response, 'getCurrentPage'))->toBe(3);
});

it('getCurrentPage() throws on non-paginated response', function () {
    $response = makeApiResponse(loadFixtureDecoded('new', 'show'));

    expect(fn () => invokePrivate($response, 'getCurrentPage'))->toThrow(Exception::class);
});

it('nextPage() returns false on legacy final page', function () {
    $response = makeApiResponse(loadFixtureDecoded('legacy', 'index-final-page'));

    expect($response->nextPage())->toBeFalse();
});

it('nextPage() returns false on new envelope final page', function () {
    $response = makeApiResponse(loadFixtureDecoded('new', 'index-final-page'));

    expect($response->nextPage())->toBeFalse();
});

it('nextPage() returns false on a non-paginated response', function () {
    $response = makeApiResponse(loadFixtureDecoded('new', 'show'));

    expect($response->nextPage())->toBeFalse();
});
