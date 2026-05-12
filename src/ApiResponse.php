<?php

namespace SimplifiApi;

use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;

/**
 * Class ApiResponse
 *
 * Unified API response object backed by Guzzle / PSR-7. Used for both
 * synchronous (ApiRequest::request) and asynchronous (ApiRequest::requestAsync,
 * batch) calls.
 *
 * @method throw(string $message) alias of throwException()
 *
 * @see ApiResponse::throwException
 */
class ApiResponse implements ApiResponseInterface
{
    private array $config;

    private array $requestOptions;

    private float $requestTime;

    private ?ResponseInterface $guzzleResponse = null;

    private ?\Throwable $exception = null;

    private mixed $decodedResponse = null;

    private ?int $httpCode = null;

    private ?string $effectiveUrl = null;

    private bool $hasError = false;

    private string $errorMessage = '';

    private int $errorCode = 0;

    private ?bool $forceSuccess = null;

    /**
     * The response created event identifier
     */
    const EVENT_RESPONSE_CREATED = 'responseCreated';

    /**
     * An array of events added by self::addEventListener()
     */
    private static array $events = [];

    /**
     * Internal constructor — call via the fromGuzzleResponse() / fromException()
     * factories, or via the test factory in tests/Pest.php.
     */
    public function __construct(array $config, array $requestOptions, float $timerStart)
    {
        $this->config = $config;
        $this->requestOptions = $requestOptions;
        $this->requestTime = round(microtime(true) - $timerStart, 4);
    }

    /**
     * Build an ApiResponse from a successful (or 4xx/5xx) Guzzle PSR-7 response.
     */
    public static function fromGuzzleResponse(
        ResponseInterface $response,
        array $config,
        array $requestOptions,
        float $timerStart,
        ?string $effectiveUrl = null
    ): self {
        $instance = new self($config, $requestOptions, $timerStart);
        $instance->guzzleResponse = $response;
        $instance->httpCode = $response->getStatusCode();
        $instance->effectiveUrl = $effectiveUrl ?? AsyncClient::buildUrl($requestOptions, $config);

        // Decode the response body
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

        // Flag 4xx / 5xx as errors
        $statusCode = $response->getStatusCode();
        if ($statusCode >= 400) {
            $instance->hasError = true;
            $instance->errorCode = $statusCode;
            $instance->errorMessage = $response->getReasonPhrase();
        }

        $instance->postConstruct();

        return $instance;
    }

    /**
     * Build an ApiResponse from a thrown exception (connection error, timeout, etc.).
     */
    public static function fromException(
        \Throwable $exception,
        array $config,
        array $requestOptions,
        float $timerStart,
        ?string $effectiveUrl = null
    ): self {
        $instance = new self($config, $requestOptions, $timerStart);
        $instance->exception = $exception;
        $instance->effectiveUrl = $effectiveUrl ?? AsyncClient::buildUrl($requestOptions, $config);
        $instance->hasError = true;
        $instance->errorCode = (int) $exception->getCode();
        $instance->errorMessage = $exception->getMessage();
        $instance->httpCode = 0;
        $instance->decodedResponse = null;

        // If the exception carries a response, prefer that
        if ($exception instanceof RequestException && $exception->hasResponse()) {
            $response = $exception->getResponse();
            if ($response !== null) {
                $instance->guzzleResponse = $response;
                $instance->httpCode = $response->getStatusCode();
                $body = (string) $response->getBody();
                $decoded = json_decode($body);
                $instance->decodedResponse = json_last_error() === JSON_ERROR_NONE ? $decoded : $body;
            }
        }

        $instance->postConstruct();

        return $instance;
    }

    /**
     * Build an ApiResponse directly from a decoded body and metadata, without
     * a real HTTP round-trip. Used by the test factory and by edge cases (auth
     * pre-flight failures) that need to surface as a response object.
     */
    public static function fromDecoded(
        mixed $decodedResponse,
        array $config,
        array $requestOptions,
        float $timerStart,
        int $httpCode = 200,
        ?string $effectiveUrl = null,
        bool $error = false,
        int $errorCode = 0,
        string $errorMessage = ''
    ): self {
        $instance = new self($config, $requestOptions, $timerStart);
        $instance->decodedResponse = $decodedResponse;
        $instance->httpCode = $httpCode;
        $instance->effectiveUrl = $effectiveUrl;
        $instance->hasError = $error || $httpCode >= 400;
        $instance->errorCode = $errorCode;
        $instance->errorMessage = $errorMessage;

        $instance->postConstruct();

        return $instance;
    }

    /**
     * Common post-construction work (slow-request log, response-created event).
     */
    private function postConstruct(): void
    {
        if ($this->requestTime > 30) {
            $this->logRequest("This request took > 30 seconds to run ({$this->requestTime}s).");
        }

        $url = $this->getRequestUrl();
        if (is_string($url) && strlen($url) > 80000) {
            $this->logRequest('This requests url is > 80000 characters in length ('.strlen($url).').');
        }

        $this->fireEvent(self::EVENT_RESPONSE_CREATED, [$this]);
    }

    /**
     * Check if there was an error with the request e.g. a 404 occurred
     */
    public function success(): bool
    {
        if ($this->forceSuccess !== null) {
            return $this->forceSuccess;
        }

        return ! $this->hasError;
    }

    /**
     * Get the raw response (e.g. if Content-Type is application/json then a
     * json_decode() result is returned)
     */
    public function response(): mixed
    {
        return $this->decodedResponse;
    }

    /**
     * Return an array of errors that occurred OR empty array if no errors occurred.
     *
     * Supports both legacy and new API envelope shapes:
     * - Legacy: {errors: [...], error: {message}}
     * - New: {message, errors: {field: [msg]}}
     *
     * @return array of errors (empty if not errors occurred) array elements are of the form:
     *               ['title'=>'string message (always present)', 'message'=>'detailed description (may not be set)']
     */
    public function errors(): array
    {
        $errors = [];
        $response = (array) $this->response();

        // Check for new Laravel-style error format: {message, errors: {field: [msg]}}
        if (isset($response['message']) && is_string($response['message'])) {
            $errors[] = ['title' => $response['message']];

            // Also handle validation errors in new format: {field: [msg], ...}
            // The `errors` field may be either an array (assoc-array decode) or a stdClass (object decode).
            if (isset($response['errors']) && (is_array($response['errors']) || is_object($response['errors']))) {
                $errorsField = is_object($response['errors']) ? get_object_vars($response['errors']) : $response['errors'];
                // Distinguish Laravel's `errors: {field: [msg]}` from legacy `errors: [...flat...]`.
                // Laravel keys are field names (strings), legacy is a sequential list of error objects.
                $isLaravelShape = ! array_is_list($errorsField);

                if ($isLaravelShape) {
                    foreach ($errorsField as $messages) {
                        if (is_array($messages)) {
                            foreach ($messages as $message) {
                                $errors[] = ['title' => $message];
                            }
                        } else {
                            $errors[] = ['title' => $messages];
                        }
                    }
                } else {
                    // Sequential list of error entries — flow each into ['title' => ...].
                    foreach ($errorsField as $entry) {
                        if (is_object($entry) || is_array($entry)) {
                            $arr = (array) $entry;
                            if (isset($arr['title'])) {
                                $errors[] = ['title' => $arr['title']];
                            } elseif (isset($arr['message'])) {
                                $errors[] = ['title' => $arr['message']];
                            } else {
                                $errors[] = ['title' => (string) json_encode($arr)];
                            }
                        } else {
                            $errors[] = ['title' => (string) $entry];
                        }
                    }
                }
            }
        } else {
            // Legacy format: {errors: [...object], error: {message}}
            if (array_key_exists('errors', $response)) {
                $decoded = json_decode(json_encode($response['errors'] ?? []), true);
                if (is_array($decoded)) {
                    $errors = array_merge($errors, $decoded);
                }
            }
            if (array_key_exists('error', $response)) {
                $error = $response['error'];
                $errors[] = [
                    'title' => is_object($error) && isset($error->message) ? $error->message : $error,
                ];
            }
        }

        // If no errors found in response but request failed, add a transport-level error
        if (count($errors) === 0 && $this->hasError) {
            $errors[] = [
                'title' => $this->errorCode.': '.$this->errorMessage.' '.
                    (substr(json_encode($this->serialise()['response'] ?? ''), 0, 300)),
            ];
        }

        return $errors;
    }

    /**
     * Return $this->getSimpleErrorsArray() as an imploded string
     *
     * @see getSimpleErrorsArray()
     */
    public function errorsToString(string $glue = ', ', bool $escape = false): string
    {
        $errors = $this->getSimpleErrorsArray();
        if ($escape) {
            array_walk($errors, fn (&$value) => $value = htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8'));
        }

        return implode($glue, $errors);
    }

    /**
     * Return a simple array of error title's
     *
     * @return string[] an array of strings
     *
     * @see errors()
     */
    public function getSimpleErrorsArray(): array
    {
        $errors = $this->errors();
        $response = [];
        foreach ($errors as $error) {
            $response[] = $error['title'] ?? 'Unknown error';
        }

        // If no error messages were found but the request was not a success then add a default error message.
        if (count($response) === 0 && ! $this->success()) {
            $response[] = 'Unknown error occurred.';
        }

        return $response;
    }

    /**
     * Throw Exception
     *
     * @throws \Exception
     */
    public function throwException(string $message): void
    {
        if (($this->config['APP_ENV'] ?? null) === 'local') {
            $this->dd();
        } else {
            $this->logRequest($message);
            $message = $message ? $message."\n" : '';
            throw new \Exception($message.$this->errorsToString());
        }
    }

    /**
     * Map ->throw() to ->throwException() for backwards compatibility
     *
     * @throws \Exception
     */
    public function __call($method, $args)
    {
        if ($method == 'throw') {
            call_user_func_array([$this, 'throwException'], $args);
        } else {
            $debugTrace = debug_backtrace();
            $debugTrace = count($debugTrace) > 0 ? $debugTrace[0] : null;

            throw new \Exception('Call to undefined method '.__CLASS__.'::'.$method.
                '() in '.($debugTrace ? ($debugTrace['file'] ?? '') : '').':'.($debugTrace ? ($debugTrace['line'] ?? '') : '')."\n");
        }
    }

    /**
     * Get the full URL of the request (useful for debugging)
     */
    public function getRequestUrl(): mixed
    {
        return $this->effectiveUrl;
    }

    /**
     * Get the http response code e.g. 200 for success
     */
    public function getHttpCode(): mixed
    {
        return $this->httpCode;
    }

    /**
     * Get the http request method used, e.g. 'POST'
     */
    public function getMethod(): string
    {
        return strtoupper($this->requestOptions['method'] ?? 'GET');
    }

    /**
     * Get the underlying Guzzle PSR-7 response (if any).
     * May be null if the response was synthesised from an exception or decoded body.
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
     * Serialise the object (useful for debugging)
     *
     * @param  bool  $anonymise  Remove sensitive info from the result
     */
    public function serialise(bool $anonymise = true): array
    {
        $response = $this->response();

        $caller = self::getCallerFromBacktrace(debug_backtrace());

        $return = [
            'method' => $this->getMethod(),
            'http-code' => $this->getHttpCode(),
            'url' => $this->getRequestUrl(),
            'requestTime' => $this->requestTime,
            'requestOptions' => $this->getRequestOptions($anonymise),
            'response' => $this->isJson($response) ? json_decode($response) : $response,
            'caller' => $caller ?: null,
        ];

        if ($anonymise) {
            // Recursively walk the array and any key that contains 'token','pass','secret' will be replaced with '### REDACTED ###'
            array_walk_recursive($return, function (&$v, $k) {
                if (is_string($v) && preg_match('/token|pass|secret/i', $k)) {
                    $v = '### REDACTED ###';
                }
            });
        }

        return $return;
    }

    /**
     * Override the success() response
     */
    public function setSuccess(bool $success): void
    {
        $this->forceSuccess = $success;
    }

    /**
     * @param  bool  $anonymise  Remove sensitive info from the result
     *
     * @see requestOptions
     */
    public function getRequestOptions(bool $anonymise = true): array
    {
        $options = $this->requestOptions;

        if ($anonymise) {
            // In most cases the user doesn't need/want the Authorization header. Also; there could be a security concern
            // if it ends up in the logs "Authorization": "Bearer eyJ0eXAiO..."
            if (! empty($options['headers']['Authorization'])) {
                $options['headers']['Authorization'] = preg_replace('/Bearer (.*)/i', 'Bearer ### REDACTED ###', $options['headers']['Authorization']);
            }
        }

        return $options;
    }

    /**
     * @see requestOptions
     */
    public function setRequestOptions(array $options): void
    {
        $this->requestOptions = $options;
    }

    /**
     * Magic method to get a value of a response property
     * e.g. $response->data
     * e.g. $response->data->id
     * e.g. $response->paginator->total_count
     */
    public function __get($name)
    {
        return $this->property($name);
    }

    /**
     * Magic method to check the isset() of a property
     */
    public function __isset($prop)
    {
        return array_key_exists($prop, (array) $this->response());
    }

    /**
     * Return an ApiResponse for the next page of this request.
     * Returns FALSE if request is not paginated or there are no more pages
     *
     * @throws \Exception
     */
    public function nextPage(): self|false
    {
        if ($this->hasNextPage()) {
            $requestOptions = $this->requestOptions;
            $requestOptions['data']['page'] = $this->getCurrentPage() + 1;

            $response = ApiRequest::request($requestOptions);
            if (! $response->success()) {
                throw new \Exception('Unknown error while getting next page from API.');
            }

            return $response;
        }

        return false;
    }

    /**
     * Does this request have another page?
     * Supports both legacy (paginator) and new (meta/links) envelope shapes
     */
    private function hasNextPage(): bool
    {
        $shape = $this->getPaginatorShape();

        if ($shape === 'meta') {
            // New envelope: check meta.current_page < meta.last_page (or total_pages)
            $meta = $this->property('meta');
            if (! $meta) {
                return false;
            }
            $currentPage = $this->property('meta', 'current_page');
            $lastPage = $this->property('meta', 'last_page') ?? $this->property('meta', 'total_pages');

            return $currentPage !== null && $lastPage !== null && $currentPage < $lastPage;
        } elseif ($shape === 'paginator') {
            // Legacy envelope: check paginator.current_page < paginator.total_pages
            return $this->property('paginator') && $this->property('paginator', 'current_page') < $this->property('paginator', 'total_pages');
        }

        return false;
    }

    /**
     * Detect which paginator shape the response uses
     *
     * @return 'meta'|'paginator'|null The paginator shape type, or null if response is not paginated
     */
    private function getPaginatorShape(): ?string
    {
        $response = (array) $this->response();

        if (array_key_exists('meta', $response) && ! empty($response['meta'])) {
            return 'meta';
        }

        if (array_key_exists('paginator', $response) && ! empty($response['paginator'])) {
            return 'paginator';
        }

        return null;
    }

    /**
     * This will do as many requests as required to fetch every page's items into a single array and return that array
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
     * This will do as many requests as required to fetch every page's items into a single array and return that array
     *
     * @see fetchAllPageData
     */
    public function allPages(): array
    {
        if ($this->success()) {
            return $this->fetchAllPageData();
        }

        throw new \Exception('Unknown error on paginated response. '.$this->errorsToString());
    }

    /**
     * Get the current page number
     * Supports both legacy (paginator) and new (meta) envelope shapes
     *
     * @throws \Exception when attempting to get the page number of a non-paginated response
     */
    private function getCurrentPage(): int|string
    {
        $shape = $this->getPaginatorShape();

        if ($shape === 'meta') {
            $currentPage = $this->property('meta', 'current_page');
            if ($currentPage === null) {
                throw new \Exception('Attempted to get the page number for a non paginated response.');
            }

            return $currentPage;
        } elseif ($shape === 'paginator') {
            $currentPage = $this->property('paginator', 'current_page');
            if ($currentPage === null) {
                throw new \Exception('Attempted to get the page number for a non paginated response.');
            }

            return $currentPage;
        }

        throw new \Exception('Attempted to get the page number for a non paginated response.');
    }

    /**
     * Basically the same as __get() except you can get sub properties
     * e.g. $currentPage = $this->property('paginator', 'current_page')
     *
     * @return mixed NULL: if the property could not be found
     *
     * @see __get()
     */
    private function property(): mixed
    {
        $args = func_get_args();
        $response = $this->response();

        foreach ($args as $value) {
            if (array_key_exists($value, (array) $response)) {
                $response = is_object($response) ? $response->{$value} : $response[$value];
            } else {
                $this->triggerError("Undefined property ($value)", debug_backtrace(), __CLASS__, __FUNCTION__, func_get_args());

                return null;
            }
        }

        return $response;
    }

    /**
     * Like PHPs native trigger_error($string, E_USER_NOTICE) function except the file and line number will be from the closest
     * debug_backtrace() value outside this object
     */
    private function triggerError(string $message, array $debugBackTrace, string $class, string $functionName, array $functionArgs): void
    {
        $externalTraceId = 0;
        while (true) {
            if (isset($debugBackTrace[$externalTraceId + 1]['class'])) {
                if (($debugBackTrace[$externalTraceId]['class'] ?? null) === $class) {
                    if (count($debugBackTrace) > $externalTraceId + 1) {
                        $externalTraceId++;

                        continue;
                    }
                }
            }

            $externalTraceId = 0;
            break;
        }

        $externalTraceId = $externalTraceId > 0 ? $externalTraceId - 1 : 0;

        while ($externalTraceId > 0 &&
            (! isset($debugBackTrace[$externalTraceId]['file']) &&
                ! isset($debugBackTrace[$externalTraceId]['line']))
        ) {
            $externalTraceId--;
        }

        trigger_error(
            $message.
            " in {$functionName}(".implode(', ', $functionArgs).')'.
            ' in '.($debugBackTrace[0]['file'] ?? '').':'.($debugBackTrace[0]['line'] ?? '').
            ' called from '.($debugBackTrace[$externalTraceId]['file'] ?? '').':'.($debugBackTrace[$externalTraceId]['line'] ?? ''),
            E_USER_NOTICE);
    }

    /**
     * Die and dump $this->serialise() (Laravel-style)
     */
    public function dd($prettyHtml = true, $addAdditionalDataToHtml = true): void
    {
        if ($prettyHtml) {
            $doctypeString = '<!DOCTYPE html>';
            if (is_string($this->response()) &&
                substr($this->response(), 0, strlen($doctypeString)) === $doctypeString
            ) {
                $respond = $this->response();

                if ($addAdditionalDataToHtml) {
                    $serialised = $this->serialise();
                    unset($serialised['response']);
                    $respond .= '<pre>'.json_encode($serialised, JSON_PRETTY_PRINT).'</pre>';
                }

                exit($respond);
            }
        }

        header('Content-type: application/json');
        $serialised = $this->serialise();
        exit(json_encode($serialised, JSON_PRETTY_PRINT));
    }

    /**
     * Check if a value is a JSON-encoded string
     */
    private function isJson(mixed $string): bool
    {
        if (! is_string($string)) {
            return false;
        }

        json_decode($string);

        return json_last_error() == JSON_ERROR_NONE;
    }

    /**
     * Magic method called by var_dump() on this object
     */
    public function __debugInfo()
    {
        return $this->serialise();
    }

    /**
     * Magic method used by json_encode($apiResponse)
     */
    public function jsonSerialize(): mixed
    {
        return $this->serialise();
    }

    /**
     * Classes implementing Countable can be used with the count() function.
     *
     * @throws \Exception
     */
    public function count(): int
    {
        $response = $this->response();
        $data = is_object($response) ? ($response->data ?? null) : (is_array($response) ? ($response['data'] ?? null) : null);
        if (is_array($data)) {
            return count($data);
        }

        throw new \Exception('Error: Attempting to count a non countable object.');
    }

    /**************** START Iterator methods ****************/

    private function iterableData(): array
    {
        $response = $this->response();
        $data = is_object($response) ? ($response->data ?? null) : (is_array($response) ? ($response['data'] ?? null) : null);
        if (! is_array($data)) {
            throw new \Exception('Invalid argument api response is not iterable.');
        }

        return $data;
    }

    public function rewind(): void
    {
        // Trigger validation; iteration uses references back into the response object's data.
        $this->iterableData();
        $response = $this->response();
        if (is_object($response) && isset($response->data) && is_array($response->data)) {
            reset($response->data);
        }
    }

    public function current(): mixed
    {
        $this->iterableData();
        $response = $this->response();

        return is_object($response) ? current($response->data) : false;
    }

    public function key(): mixed
    {
        $this->iterableData();
        $response = $this->response();

        return is_object($response) ? key($response->data) : null;
    }

    public function next(): void
    {
        $this->iterableData();
        $response = $this->response();
        if (is_object($response) && isset($response->data) && is_array($response->data)) {
            next($response->data);
        }
    }

    public function valid(): bool
    {
        $this->iterableData();
        $response = $this->response();
        if (! is_object($response)) {
            return false;
        }
        $key = key($response->data);

        return $key !== null && $key !== false;
    }

    /**************** END Iterator methods ****************/

    private function logRequest(string $message): void
    {
        $serialisedRequest = $this->serialise();

        // Remove the authentication header
        unset($serialisedRequest['requestOptions']['headers']['Authorization']);

        // Some parts of the request can be massive so let's truncate to 500 characters
        array_walk_recursive($serialisedRequest, function (&$v) {
            if (is_string($v) && strlen($v) > 500) {
                $v = substr($v, 0, 500).'<TRUNCATED '.strlen($v).'>';
            }
        });

        call_user_func(AccessToken::getCallableLogFunction(), "{$message} ".json_encode($serialisedRequest));
    }

    /**
     * Get the first frame of a back trace outside this file
     */
    public static function getCallerFromBacktrace(array $backtrace, string $file = __FILE__, string $class = __CLASS__): ?array
    {
        if (count($backtrace) === 0) {
            return null;
        }

        // Filter out frames in vendor or in this class/file
        $filteredBacktrace = array_filter($backtrace, function ($frame) use ($class, $file) {
            return ! isset($frame['file']) ||
                (
                    ! str_contains($frame['file'], 'vendor') &&
                    ! str_contains($frame['file'], 'Middleware') &&
                    ! str_ends_with($frame['file'], '/public/index.php') &&
                    ! str_contains($frame['file'], $file) &&
                    ! str_contains($frame['class'] ?? '', $class)
                );
        });
        if (count($filteredBacktrace) > 0) {
            $backtrace = $filteredBacktrace;
        }

        $arrayBacktrace = array_map(function ($frame) {
            if (isset($frame['file']) && isset($frame['line'])) {
                $file = isset($_SERVER['DOCUMENT_ROOT']) ?
                    preg_replace('/^'.preg_quote(realpath($_SERVER['DOCUMENT_ROOT'].'/..'), '/').'/', '', $frame['file']) :
                    $frame['file'];

                return $file.'::'.$frame['line'];
            } elseif (isset($frame['class']) && isset($frame['function'])) {
                return $frame['class'].
                    (! empty($frame['type']) ? $frame['type'] : '::').
                    $frame['function'];
            } else {
                return null;
            }
        }, $backtrace);

        $arrayBacktrace = array_filter($arrayBacktrace);

        return array_slice($arrayBacktrace, 0, 3);
    }

    /**
     * Add an event listener
     */
    public static function addEventListener(string $eventName, \Closure $callback): void
    {
        self::$events[$eventName][] = $callback;
    }

    /**
     * Trigger an event loaded by addEventListener
     */
    public static function fireEvent(string $eventName, array $callbackParameters): void
    {
        if (isset(self::$events[$eventName])) {
            foreach (self::$events[$eventName] as $event) {
                call_user_func_array($event, $callbackParameters);
            }
        }
    }
}
