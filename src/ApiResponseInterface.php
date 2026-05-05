<?php

namespace SimplifiApi;

/**
 * Common interface for API responses (sync and async)
 *
 * Both ApiResponse and AsyncApiResponse implement this interface,
 * allowing code to work with either response type.
 */
interface ApiResponseInterface extends \Countable, \Iterator, \JsonSerializable
{
    /**
     * Check if request was successful
     */
    public function success(): bool;

    /**
     * Get the response body
     */
    public function response(): mixed;

    /**
     * Get errors array
     */
    public function errors(): array;

    /**
     * Return errors as a string
     */
    public function errorsToString(string $glue = ', ', bool $escape = false): string;

    /**
     * Get simple array of error titles
     */
    public function getSimpleErrorsArray(): array;

    /**
     * Throw an exception with the error details
     */
    public function throwException(string $message): void;

    /**
     * Get HTTP status code
     */
    public function getHttpCode(): mixed;

    /**
     * Get the request URL
     */
    public function getRequestUrl(): mixed;

    /**
     * Get request method
     */
    public function getMethod(): string;

    /**
     * Get request options
     */
    public function getRequestOptions(bool $anonymise = true): array;

    /**
     * Set request options
     */
    public function setRequestOptions(array $options): void;

    /**
     * Override success status
     */
    public function setSuccess(bool $success): void;

    /**
     * Serialise the response for debugging
     */
    public function serialise(bool $anonymise = true): array;
}
