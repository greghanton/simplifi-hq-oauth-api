<?php

namespace SimplifiApi;

/**
 * Back-compat alias for the unified ApiResponse class.
 *
 * Historically there were two response classes (ApiResponse for sync php-curl-class
 * requests and AsyncApiResponse for Guzzle async/batch). After the HTTP-client
 * consolidation onto Guzzle, there is a single ApiResponse class that backs both
 * sync and async paths. This subclass remains so existing type hints continue to
 * resolve.
 *
 * @deprecated Use SimplifiApi\ApiResponse directly.
 */
class AsyncApiResponse extends ApiResponse {}
