<?php

declare(strict_types=1);

use SimplifiApi\AccessToken;

/**
 * In-memory token + lock store used as the {get, set, del, lock, unlock} callables.
 * Tracks call counts so we can assert "only one oauth/token request fires".
 */
class MutexTest
{
    /** @var array<string, string> */
    public static array $store = [];

    /** @var array<string, bool> */
    public static array $locks = [];

    public static int $getCalls = 0;

    public static int $setCalls = 0;

    public static int $lockCalls = 0;

    public static int $unlockCalls = 0;

    /** When this flag is set, the next lock acquisition fails. */
    public static bool $lockHeldByOther = false;

    public static function reset(): void
    {
        self::$store = [];
        self::$locks = [];
        self::$getCalls = 0;
        self::$setCalls = 0;
        self::$lockCalls = 0;
        self::$unlockCalls = 0;
        self::$lockHeldByOther = false;
    }

    public static function get(string $key): ?string
    {
        self::$getCalls++;

        return self::$store[$key] ?? null;
    }

    public static function set(string $key, string $value): bool
    {
        self::$setCalls++;
        self::$store[$key] = $value;

        return true;
    }

    public static function del(string $key): bool
    {
        unset(self::$store[$key]);

        return true;
    }

    public static function lock(string $key, int $ttl): bool
    {
        self::$lockCalls++;

        if (self::$lockHeldByOther) {
            return false;
        }

        if (! empty(self::$locks[$key])) {
            return false;
        }

        self::$locks[$key] = true;

        return true;
    }

    public static function unlock(string $key): void
    {
        self::$unlockCalls++;
        unset(self::$locks[$key]);
    }
}

/** Helper to install our fake store into a config array suitable for AccessToken. */
function mutexConfig(): array
{
    return [
        'APP_ENV' => 'testing',
        'VERSION' => '1.0.0',
        'access_token_expire_buffer' => 10,
        'error_log_function' => json_encode([MutexTest::class, 'noop']) ?: '"error_log"',
        'access_token' => [
            'store_as' => 'custom',
            'custom' => [
                'custom_key' => 'simplifi-test-key',
                'get' => json_encode([MutexTest::class, 'get']),
                'set' => json_encode([MutexTest::class, 'set']),
                'del' => json_encode([MutexTest::class, 'del']),
                'lock' => json_encode([MutexTest::class, 'lock']),
                'unlock' => json_encode([MutexTest::class, 'unlock']),
            ],
        ],
    ];
}

/** Invoke private static method on AccessToken. */
function invokeAccessTokenPrivate(string $method, array $args = []): mixed
{
    $ref = new ReflectionMethod(AccessToken::class, $method);

    return $ref->invokeArgs(null, $args);
}

/** Inject a config into AccessToken::$config via reflection. */
function setAccessTokenConfig(array $config): void
{
    $ref = new ReflectionProperty(AccessToken::class, 'config');
    $ref->setValue(null, $config);
}

beforeEach(function () {
    MutexTest::reset();
});

it('hasMutexCallables returns true when lock/unlock configured', function () {
    setAccessTokenConfig(mutexConfig());

    expect(invokeAccessTokenPrivate('hasMutexCallables'))->toBeTrue();
});

it('hasMutexCallables returns false when lock/unlock missing', function () {
    $config = mutexConfig();
    unset($config['access_token']['custom']['lock'], $config['access_token']['custom']['unlock']);
    setAccessTokenConfig($config);

    expect(invokeAccessTokenPrivate('hasMutexCallables'))->toBeFalse();
});

it('hasMutexCallables returns false for temp_file storage', function () {
    setAccessTokenConfig([
        'access_token' => [
            'store_as' => 'temp_file',
            'custom' => ['lock' => null, 'unlock' => null],
        ],
    ]);

    expect(invokeAccessTokenPrivate('hasMutexCallables'))->toBeFalse();
});

it('tryAcquireLock returns true on a fresh key and false when already held', function () {
    setAccessTokenConfig(mutexConfig());

    expect(invokeAccessTokenPrivate('tryAcquireLock'))->toBeTrue()
        ->and(MutexTest::$lockCalls)->toBe(1);

    // Second attempt against the same in-memory lock store fails
    expect(invokeAccessTokenPrivate('tryAcquireLock'))->toBeFalse()
        ->and(MutexTest::$lockCalls)->toBe(2);
});

it('releaseLock invokes the configured unlock callable', function () {
    setAccessTokenConfig(mutexConfig());

    invokeAccessTokenPrivate('tryAcquireLock');
    invokeAccessTokenPrivate('releaseLock');

    expect(MutexTest::$unlockCalls)->toBe(1)
        ->and(MutexTest::$locks)->toBe([]);
});

it('waitForCacheRefresh returns cached token written by another process during the wait', function () {
    setAccessTokenConfig(mutexConfig());

    // Pre-populate cache as if another process refreshed and wrote it
    $tokenData = [
        'access_token' => 'tok-from-holder',
        'token_type' => 'Bearer',
        'expires_in' => 3600,
        'expires_at' => time() + 3600,
    ];
    MutexTest::$store['simplifi-test-key'] = (string) json_encode($tokenData);

    // The implementation sleeps 1-2s; we only assert correctness, not perf
    $token = invokeAccessTokenPrivate('waitForCacheRefresh');

    expect($token)->toBe('tok-from-holder');
});

it('waitForCacheRefresh returns null when cache is still empty after wait', function () {
    setAccessTokenConfig(mutexConfig());

    // Cache is empty
    $token = invokeAccessTokenPrivate('waitForCacheRefresh');

    expect($token)->toBeNull();
});
