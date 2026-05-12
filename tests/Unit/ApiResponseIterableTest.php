<?php

declare(strict_types=1);

it('count() returns the number of items in legacy envelope data', function () {
    $response = makeApiResponse(loadFixtureDecoded('legacy', 'index-page-1'));

    expect(count($response))->toBe(3);
});

it('count() returns the number of items in new envelope data', function () {
    $response = makeApiResponse(loadFixtureDecoded('new', 'index-page-1'));

    expect(count($response))->toBe(3);
});

it('iterates legacy envelope data with foreach', function () {
    $response = makeApiResponse(loadFixtureDecoded('legacy', 'index-page-1'));

    $ids = [];
    foreach ($response as $row) {
        $ids[] = $row->id;
    }

    expect($ids)->toBe([1, 2, 3]);
});

it('iterates new envelope data with foreach', function () {
    $response = makeApiResponse(loadFixtureDecoded('new', 'index-page-1'));

    $ids = [];
    foreach ($response as $row) {
        $ids[] = $row->id;
    }

    expect($ids)->toBe([1, 2, 3]);
});

it('throws when counting a non-iterable response', function () {
    $response = makeApiResponse(loadFixtureDecoded('new', 'show'));

    // show fixture has data as object, not array — count() should throw
    expect(fn () => count($response))->toThrow(Exception::class);
});
