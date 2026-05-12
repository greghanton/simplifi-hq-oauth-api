<?php

declare(strict_types=1);

namespace Tests\Support;

use Curl\Curl;

/**
 * A stub Curl class used to build ApiResponse instances in tests
 * without making real HTTP requests.
 *
 * Extends the real Curl class (so type-checks pass) but overrides
 * getInfo() to return predictable values backed by public properties.
 */
class FakeCurl extends Curl
{
    public string $effectiveUrl = 'https://api.example.test/sales';

    public function __construct()
    {
        // Initialise an empty curl handle (no network) so parent state is valid.
        // ext-curl is required by composer.json, so curl_init() is always available.
        parent::__construct();
    }

    /**
     * Override getInfo to avoid relying on the underlying (un-executed) curl handle.
     */
    public function getInfo($opt = null): mixed
    {
        if ($opt === CURLINFO_EFFECTIVE_URL) {
            return $this->effectiveUrl;
        }

        if ($opt === CURLINFO_HTTP_CODE) {
            return $this->httpStatusCode;
        }

        return null;
    }
}
