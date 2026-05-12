<?php

declare(strict_types=1);

use SimplifiApi\AccessToken;
use SimplifiApi\ApiRequest;
use Tests\Support\FakeCurl;

/**
 * Invoke a private static method on ApiRequest via reflection.
 */
function invokeApiRequestPrivate(string $method, array $args = []): mixed
{
    $ref = new ReflectionMethod(ApiRequest::class, $method);

    return $ref->invokeArgs(null, $args);
}

it('responseIsAuthenticationException detects legacy auth-failure shape (type=AuthenticationException)', function () {
    $curl = new FakeCurl;
    $curl->response = (object) ['type' => 'AuthenticationException', 'error' => (object) ['message' => 'Unauthenticated.']];

    expect(invokeApiRequestPrivate('responseIsAuthenticationException', [$curl]))->toBeTrue();
});

it('responseIsAuthenticationException detects new auth-failure shape (message=Unauthenticated.)', function () {
    $curl = new FakeCurl;
    $curl->response = (object) ['message' => 'Unauthenticated.'];

    expect(invokeApiRequestPrivate('responseIsAuthenticationException', [$curl]))->toBeTrue();
});

it('responseIsAuthenticationException returns false on a normal response', function () {
    $curl = new FakeCurl;
    $curl->response = (object) ['data' => (object) ['id' => 1]];

    expect(invokeApiRequestPrivate('responseIsAuthenticationException', [$curl]))->toBeFalse();
});

it('AccessToken::clearCache() invokes the custom del callable when store_as=custom', function () {
    // Use the same in-memory store as the mutex test
    require_once __DIR__.'/MutexTest.php';

    MutexTest::reset();
    MutexTest::$store['simplifi-test-key'] = 'something';

    setAccessTokenConfig(mutexConfig());

    AccessToken::clearCache();

    expect(MutexTest::$store)->toBe([]);
});
