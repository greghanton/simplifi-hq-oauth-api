<?php

namespace SimplifiApi;

use GuzzleHttp\Client;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\Utils;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;

/**
 * Guzzle-backed HTTP client used by both sync and async paths.
 */
class AsyncClient
{
    private static ?Client $client = null;

    private static array $config = [];

    /**
     * Get or create the Guzzle client instance
     */
    public static function getClient(array $config = []): Client
    {
        if (self::$client === null || $config !== self::$config) {
            self::$config = $config;
            self::$client = new Client([
                'base_uri' => $config['url-base'] ?? '',
                'timeout' => $config['CURLOPT_TIMEOUT'] ?? 0,
                'verify' => $config['ssl_verify'] ?? true,
                'http_errors' => false, // Don't throw on 4xx/5xx - let ApiResponse handle it
                'headers' => array_merge(
                    ['Accept' => 'application/json'],
                    $config['headers'] ?? []
                ),
            ]);
        }

        return self::$client;
    }

    /**
     * Reset the client (useful for testing or config changes)
     */
    public static function resetClient(): void
    {
        self::$client = null;
        self::$config = [];
    }

    /**
     * Build Guzzle request options from SimplifiApi options
     */
    public static function buildRequestOptions(array $options, array $config): array
    {
        $guzzleOptions = [
            RequestOptions::HEADERS => $options['headers'] ?? [],
        ];

        // Add data based on method
        $method = strtoupper($options['method'] ?? 'GET');
        $data = $options['data'] ?? [];

        if ($method === 'GET') {
            if (! empty($data)) {
                $guzzleOptions[RequestOptions::QUERY] = $data;
            }
        } else {
            // For POST, PUT, PATCH, DELETE - send as form params or JSON
            if (! empty($data)) {
                // Check if we should send as JSON (case-insensitive header check)
                $isJson = false;
                foreach ($options['headers'] ?? [] as $key => $value) {
                    if (strtolower($key) === 'content-type' && str_contains($value, 'application/json')) {
                        $isJson = true;
                        break;
                    }
                }

                if (is_string($data)) {
                    $guzzleOptions[RequestOptions::BODY] = $data;
                } elseif ($isJson) {
                    $guzzleOptions[RequestOptions::JSON] = $data;
                } else {
                    $guzzleOptions[RequestOptions::FORM_PARAMS] = $data;
                }
            }
        }

        // Add timeout if specified
        if (isset($config['CURLOPT_TIMEOUT'])) {
            $guzzleOptions[RequestOptions::TIMEOUT] = $config['CURLOPT_TIMEOUT'];
        }

        return $guzzleOptions;
    }

    /**
     * Build the full URL for a request
     */
    public static function buildUrl(array $options, array $config): string
    {
        if (isset($options['url-absolute'])) {
            return ($config['url-base'] ?? '').self::urlToString($options['url-absolute']);
        }

        return ($config['url-base'] ?? '').($config['url-version'] ?? '').self::urlToString($options['url'] ?? '');
    }

    /**
     * Convert URL to string (supports array format with replacements)
     */
    private static function urlToString(array|string $url): string
    {
        if (is_string($url)) {
            return ltrim($url, '/\\');
        }

        if (is_array($url)) {
            if (empty($url)) {
                throw new \Exception('Invalid url');
            }

            $urlString = array_shift($url);
            array_walk($url, fn (&$value) => $value = rawurlencode($value));

            return ltrim(self::substituteStringReplacements($urlString, $url), '/\\');
        }

        return '';
    }

    /**
     * Replace $ placeholders in URL string
     */
    private static function substituteStringReplacements(string $string, array $replacements): string
    {
        $parts = explode('$', $string);

        if (count($parts) !== count($replacements) + 1) {
            throw new \Exception('Invalid url: number of replacement characters is incorrect');
        }

        $parts = array_values($parts);
        $replacements = array_values($replacements);

        $output = '';
        foreach ($parts as $index => $part) {
            $output .= $part.($replacements[$index] ?? '');
        }

        return $output;
    }

    /**
     * Make a synchronous request via Guzzle.
     */
    public static function request(array $options, array $config): ApiResponse
    {
        $client = self::getClient($config);
        $method = strtoupper($options['method'] ?? 'GET');
        $url = self::buildUrl($options, $config);
        $guzzleOptions = self::buildRequestOptions($options, $config);
        $timerStart = $options['__timerStart'] ?? microtime(true);

        try {
            $response = $client->request($method, $url, $guzzleOptions);

            return ApiResponse::fromGuzzleResponse($response, $config, $options, $timerStart, $url);
        } catch (\Throwable $e) {
            return ApiResponse::fromException($e, $config, $options, $timerStart, $url);
        }
    }

    /**
     * Make an async request
     *
     * @return PromiseInterface Promise that resolves to ApiResponse
     */
    public static function requestAsync(array $options, array $config): PromiseInterface
    {
        $client = self::getClient($config);
        $method = strtoupper($options['method'] ?? 'GET');
        $url = self::buildUrl($options, $config);
        $guzzleOptions = self::buildRequestOptions($options, $config);
        $timerStart = microtime(true);

        return $client->requestAsync($method, $url, $guzzleOptions)
            ->then(
                function (ResponseInterface $response) use ($config, $options, $timerStart, $url) {
                    return ApiResponse::fromGuzzleResponse($response, $config, $options, $timerStart, $url);
                },
                function (\Throwable $e) use ($config, $options, $timerStart, $url) {
                    // Handle request errors - still return an ApiResponse
                    if (method_exists($e, 'getResponse') && $e->getResponse()) {
                        return ApiResponse::fromGuzzleResponse($e->getResponse(), $config, $options, $timerStart, $url);
                    }

                    return ApiResponse::fromException($e, $config, $options, $timerStart, $url);
                }
            );
    }

    /**
     * Execute multiple requests concurrently
     *
     * @return ApiResponse[]
     */
    public static function batch(array $requests, array $config): array
    {
        $promises = [];

        foreach ($requests as $key => $options) {
            $promises[$key] = self::requestAsync($options, $config);
        }

        // Wait for all promises to settle (fulfill or reject)
        $results = Utils::settle($promises)->wait();

        // Convert results to ApiResponse objects
        $responses = [];
        foreach ($results as $key => $result) {
            if ($result['state'] === 'fulfilled') {
                $responses[$key] = $result['value'];
            } else {
                // Promise was rejected - create error response
                $responses[$key] = ApiResponse::fromException(
                    $result['reason'],
                    $config,
                    $requests[$key],
                    microtime(true)
                );
            }
        }

        return $responses;
    }

    /**
     * Execute multiple requests concurrently with a concurrency limit
     *
     * @return ApiResponse[]
     */
    public static function batchWithConcurrency(array $requests, array $config, int $concurrency = 5): array
    {
        $responses = [];

        // Process in chunks based on concurrency limit
        $chunks = array_chunk($requests, $concurrency, true);

        foreach ($chunks as $chunk) {
            $chunkResponses = self::batch($chunk, $config);
            $responses = array_merge($responses, $chunkResponses);
        }

        return $responses;
    }
}
