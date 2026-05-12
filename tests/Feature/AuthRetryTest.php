<?php

declare(strict_types=1);

use SimplifiApi\AccessToken;
use SimplifiApi\ApiRequest;

/**
 * Invoke a private static method on ApiRequest via reflection.
 */
function invokeApiRequestPrivate(string $method, array $args = []): mixed
{
    $ref = new ReflectionMethod(ApiRequest::class, $method);

    return $ref->invokeArgs(null, $args);
}

it('responseIsAuthenticationException detects legacy auth-failure shape (type=AuthenticationException)', function () {
    $response = (object) ['type' => 'AuthenticationException', 'error' => (object) ['message' => 'Unauthenticated.']];

    expect(invokeApiRequestPrivate('responseIsAuthenticationException', [$response]))->toBeTrue();
});

it('responseIsAuthenticationException detects new auth-failure shape (message=Unauthenticated.)', function () {
    $response = (object) ['message' => 'Unauthenticated.'];

    expect(invokeApiRequestPrivate('responseIsAuthenticationException', [$response]))->toBeTrue();
});

it('responseIsAuthenticationException returns false on a normal response', function () {
    $response = (object) ['data' => (object) ['id' => 1]];

    expect(invokeApiRequestPrivate('responseIsAuthenticationException', [$response]))->toBeFalse();
});

it('responseIsAuthenticationException accepts array shape too', function () {
    expect(invokeApiRequestPrivate('responseIsAuthenticationException', [['message' => 'Unauthenticated.']]))->toBeTrue()
        ->and(invokeApiRequestPrivate('responseIsAuthenticationException', [['type' => 'AuthenticationException']]))->toBeTrue()
        ->and(invokeApiRequestPrivate('responseIsAuthenticationException', [['data' => ['id' => 1]]]))->toBeFalse();
});

it('responseIsAuthenticationException returns false on null/scalar', function () {
    expect(invokeApiRequestPrivate('responseIsAuthenticationException', [null]))->toBeFalse()
        ->and(invokeApiRequestPrivate('responseIsAuthenticationException', ['']))->toBeFalse()
        ->and(invokeApiRequestPrivate('responseIsAuthenticationException', [42]))->toBeFalse();
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
