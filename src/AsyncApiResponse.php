<?php

namespace SimplifiApi;

use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\Exception\RequestException;
use JetBrains\PhpStorm\NoReturn;

/**
 * ApiResponse implementation for Guzzle async responses
 *
 * Provides the same interface as ApiResponse but works with Guzzle's PSR-7 responses.
 * Implements JsonSerializable, Iterator, and Countable for compatibility.
 */
class AsyncApiResponse implements ApiResponseInterface
{
    private ?ResponseInterface $guzzleResponse = null;
    private ?\Throwable $exception = null;
    private mixed $decodedResponse = null;
    private array $config = [];
    private array $requestOptions = [];
    private float $requestTime = 0;
    private ?int $httpCode = null;
    private ?string $effectiveUrl = null;
    private bool $hasError = false;
    private string $errorMessage = '';
    private int $errorCode = 0;
    private ?bool $forceSuccess = null;

    /**
     * Create an AsyncApiResponse from a Guzzle response
     */
    public static function fromGuzzleResponse(
        ResponseInterface $response,
        array $config,
        array $requestOptions,
        float $timerStart
    ): self {
        $instance = new self();
        $instance->guzzleResponse = $response;
        $instance->config = $config;
        $instance->requestOptions = $requestOptions;
        $instance->requestTime = round(microtime(true) - $timerStart, 4);
        $instance->httpCode = $response->getStatusCode();
        $instance->effectiveUrl = AsyncClient::buildUrl($requestOptions, $config);

        // Decode response body
        $body = (string) $response->getBody();
        $contentType = $response->getHeaderLine('Content-Type');

        if (str_contains($contentType, 'application/json') || ($requestOptions['response-type'] ?? 'json') === 'json') {
            $decoded = json_decode($body);
            if (json_last_error() === JSON_ERROR_NONE) {
                $instance->decodedResponse = $decoded;
            } else {
                $instance->decodedResponse = $body;
            }
        } else {
            $instance->decodedResponse = $body;
        }

        // Check for HTTP errors (4xx, 5xx)
        $statusCode = $response->getStatusCode();
        if ($statusCode >= 400) {
            $instance->hasError = true;
            $instance->errorCode = $statusCode;
            $instance->errorMessage = $response->getReasonPhrase();
        }

        // Log slow requests
        if ($instance->requestTime > 30) {
            $instance->logRequest("This request took > 30 seconds to run ({$instance->requestTime}s).");
        }

        return $instance;
    }

    /**
     * Create an AsyncApiResponse from an exception (connection error, timeout, etc.)
     */
    public static function fromException(
        \Throwable $exception,
        array $config,
        array $requestOptions,
        float $timerStart
    ): self {
        $instance = new self();
        $instance->exception = $exception;
        $instance->config = $config;
        $instance->requestOptions = $requestOptions;
        $instance->requestTime = round(microtime(true) - $timerStart, 4);
        $instance->effectiveUrl = AsyncClient::buildUrl($requestOptions, $config);
        $instance->hasError = true;
        $instance->errorCode = $exception->getCode();
        $instance->errorMessage = $exception->getMessage();
        $instance->httpCode = 0;
        $instance->decodedResponse = null;

        // If there's a response in the exception, use it
        if ($exception instanceof RequestException && $exception->hasResponse()) {
            $response = $exception->getResponse();
            $instance->httpCode = $response->getStatusCode();
            $body = (string) $response->getBody();
            $instance->decodedResponse = json_decode($body) ?: $body;
        }

        return $instance;
    }

    /**
     * Check if request was successful
     */
    public function success(): bool
    {
        if ($this->forceSuccess !== null) {
            return $this->forceSuccess;
        }
        return !$this->hasError;
    }

    /**
     * Get the response body
     */
    public function response(): mixed
    {
        return $this->decodedResponse;
    }

    /**
     * Get errors array
     */
    public function errors(): array
    {
        $errors = [];
        $response = (array) $this->decodedResponse;

        if (isset($response['errors'])) {
            $errors = array_merge($errors, json_decode(json_encode($response['errors']), true));
        }

        if (isset($response['error'])) {
            $error = $response['error'];
            $errors[] = [
                'title' => is_object($error) && isset($error->message) ? $error->message : $error,
            ];
        }

        if (empty($errors) && $this->hasError) {
            $errors[] = [
                'title' => $this->errorCode . ': ' . $this->errorMessage .
                    " " . substr(json_encode($this->serialise()['response'] ?? ''), 0, 300),
            ];
        }

        return $errors;
    }

    /**
     * Return errors as a string
     */
    public function errorsToString(string $glue = ", ", bool $escape = false): string
    {
        $errors = $this->getSimpleErrorsArray();
        if ($escape) {
            array_walk($errors, fn(&$value) => $value = htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8'));
        }
        return implode($glue, $errors);
    }

    /**
     * Get simple array of error titles
     */
    public function getSimpleErrorsArray(): array
    {
        $errors = $this->errors();
        $response = [];
        foreach ($errors as $error) {
            $response[] = $error['title'] ?? 'Unknown error';
        }

        if (empty($response) && !$this->success()) {
            $response[] = "Unknown error occurred.";
        }

        return $response;
    }

    /**
     * Throw an exception with the error details
     */
    public function throwException(string $message): void
    {
        if (($this->config['APP_ENV'] ?? '') === 'local') {
            $this->dd();
        } else {
            $this->logRequest($message);
            $message = $message ? $message . "\n" : '';
            throw new \Exception($message . $this->errorsToString());
        }
    }

    /**
     * Magic method for ->throw() alias
     */
    public function __call($method, $args)
    {
        if ($method === 'throw') {
            call_user_func_array([$this, 'throwException'], $args);
        } else {
            throw new \Exception("Call to undefined method " . __CLASS__ . "::{$method}()");
        }
    }

    /**
     * Get HTTP status code
     */
    public function getHttpCode(): mixed
    {
        return $this->httpCode;
    }

    /**
     * Get the request URL
     */
    public function getRequestUrl(): mixed
    {
        return $this->effectiveUrl;
    }

    /**
     * Get request method
     */
    public function getMethod(): string
    {
        return strtoupper($this->requestOptions['method'] ?? 'GET');
    }

    /**
     * Get request options
     */
    public function getRequestOptions(bool $anonymise = true): array
    {
        $options = $this->requestOptions;

        if ($anonymise && !empty($options['headers']['Authorization'])) {
            $options['headers']['Authorization'] = preg_replace(
                '/Bearer (.*)/i',
                'Bearer ### REDACTED ###',
                $options['headers']['Authorization']
            );
        }

        return $options;
    }

    /**
     * Set request options
     */
    public function setRequestOptions(array $options): void
    {
        $this->requestOptions = $options;
    }

    /**
     * Override success status
     */
    public function setSuccess(bool $success): void
    {
        $this->forceSuccess = $success;
    }

    /**
     * Get the Guzzle response object
     */
    public function getGuzzleResponse(): ?ResponseInterface
    {
        return $this->guzzleResponse;
    }

    /**
     * Get request time in seconds
     */
    public function getRequestTime(): float
    {
        return $this->requestTime;
    }

    /**
     * Check if this is an async response
     */
    public function isAsync(): bool
    {
        return true;
    }

    /**
     * Serialise the response for debugging
     */
    public function serialise(bool $anonymise = true): array
    {
        $caller = ApiResponse::getCallerFromBacktrace(debug_backtrace());

        $return = [
            'method' => $this->getMethod(),
            'http-code' => $this->getHttpCode(),
            'url' => $this->getRequestUrl(),
            'requestTime' => $this->requestTime,
            'requestOptions' => $this->getRequestOptions($anonymise),
            'response' => $this->decodedResponse,
            'caller' => $caller ?: null,
            'async' => true,
        ];

        if ($anonymise) {
            array_walk_recursive($return, function (&$v, $k) {
                if (is_string($v) && preg_match('/token|pass|secret/i', $k)) {
                    $v = '### REDACTED ###';
                }
            });
        }

        return $return;
    }

    /**
     * Magic property access
     */
    public function __get($name)
    {
        return $this->property($name);
    }

    /**
     * Magic isset check
     */
    public function __isset($prop)
    {
        return array_key_exists($prop, (array) $this->decodedResponse);
    }

    /**
     * Get nested property value
     */
    private function property(): mixed
    {
        $args = func_get_args();
        $response = $this->decodedResponse;

        foreach ($args as $value) {
            if (array_key_exists($value, (array) $response)) {
                $response = is_object($response) ? $response->{$value} : $response[$value];
            } else {
                return null;
            }
        }

        return $response;
    }

    /**
     * Get next page of paginated results
     */
    public function nextPage(): self|false
    {
        if ($this->hasNextPage()) {
            $requestOptions = $this->requestOptions;
            $requestOptions['data']['page'] = $this->getCurrentPage() + 1;

            $response = ApiRequest::request($requestOptions);
            if (!$response->success()) {
                throw new \Exception("Unknown error while getting next page from API.");
            }

            // Convert to AsyncApiResponse if needed
            if ($response instanceof AsyncApiResponse) {
                return $response;
            }

            // Wrap regular ApiResponse - this shouldn't happen in practice
            return self::fromGuzzleResponse(
                new \GuzzleHttp\Psr7\Response(200, [], json_encode($response->response())),
                $this->config,
                $requestOptions,
                microtime(true)
            );
        }

        return false;
    }

    /**
     * Check if there's a next page
     */
    private function hasNextPage(): bool
    {
        return $this->property('paginator') &&
               $this->property('paginator', 'current_page') < $this->property('paginator', 'total_pages');
    }

    /**
     * Get current page number
     */
    private function getCurrentPage(): int|string
    {
        if (!$this->paginator) {
            throw new \Exception("Attempted to get the page number for a non paginated response.");
        }
        return $this->paginator->current_page;
    }

    /**
     * Fetch all pages and return combined data
     */
    public function allPages(): array
    {
        if ($this->success()) {
            $return = $this->fetchAllPageData();
            if ($return !== false) {
                return $return;
            }
            throw new \Exception("Unknown error while fetching all pages of paginated api response.");
        }
        throw new \Exception("Unknown error on paginated response. " . $this->errorsToString());
    }

    /**
     * Fetch all page data
     */
    protected function fetchAllPageData(): array
    {
        $tempResponse = $this;
        $allItems = [];

        do {
            foreach ($tempResponse as $value) {
                $allItems[] = $value;
            }
        } while ($tempResponse = $tempResponse->nextPage());

        return $allItems;
    }

    /**
     * Die and dump for debugging
     */
    #[NoReturn]
    public function dd(bool $prettyHtml = true, bool $addAdditionalDataToHtml = true): void
    {
        if ($prettyHtml) {
            $doctypeString = "<!DOCTYPE html>";
            if (is_string($this->decodedResponse) &&
                str_starts_with($this->decodedResponse, $doctypeString)
            ) {
                $respond = $this->decodedResponse;

                if ($addAdditionalDataToHtml) {
                    $serialised = $this->serialise();
                    unset($serialised['response']);
                    $respond .= "<pre>" . json_encode($serialised, JSON_PRETTY_PRINT) . "</pre>";
                }

                die($respond);
            }
        }

        header("Content-type: application/json");
        die(json_encode($this->serialise(), JSON_PRETTY_PRINT));
    }

    /**
     * Log a request
     */
    private function logRequest(string $message): void
    {
        $serialised = $this->serialise();
        unset($serialised['requestOptions']['headers']['Authorization']);

        array_walk_recursive($serialised, function (&$v) {
            if (is_string($v) && strlen($v) > 500) {
                $v = substr($v, 0, 500) . '<TRUNCATED ' . strlen($v) . '>';
            }
        });

        call_user_func(AccessToken::getCallableLogFunction(), "{$message} " . json_encode($serialised));
    }

    /**
     * Debug info for var_dump
     */
    public function __debugInfo(): array
    {
        return $this->serialise();
    }

    /**
     * JSON serialization
     */
    public function jsonSerialize(): mixed
    {
        return $this->serialise();
    }

    // Iterator implementation

    private function dataIsIterable(): bool
    {
        return is_array($this->decodedResponse?->data ?? null);
    }

    private function iterableCheck(): void
    {
        if (!$this->dataIsIterable()) {
            throw new \Exception("Invalid argument: API response is not iterable.");
        }
    }

    public function rewind(): void
    {
        $this->iterableCheck();
        reset($this->decodedResponse->data);
    }

    public function current(): mixed
    {
        $this->iterableCheck();
        return current($this->decodedResponse->data);
    }

    public function key(): mixed
    {
        $this->iterableCheck();
        return key($this->decodedResponse->data);
    }

    public function next(): void
    {
        $this->iterableCheck();
        next($this->decodedResponse->data);
    }

    public function valid(): bool
    {
        $this->iterableCheck();
        $key = key($this->decodedResponse->data);
        return $key !== null && $key !== false;
    }

    // Countable implementation

    public function count(): int
    {
        if (is_array($this->decodedResponse?->data ?? null)) {
            return count($this->decodedResponse->data);
        }
        throw new \Exception("Error: Attempting to count a non countable object.");
    }
}
