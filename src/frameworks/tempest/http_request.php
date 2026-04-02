<?php
/*
 * DDLess - PHP Debug Engine
 *
 * @author    Jefferson T.S
 * @copyright 2025-2026 DDLess
 * @license   Proprietary
 *
 * Tempest HTTP request handler. Bootstraps the Tempest application container,
 * dispatches the captured HTTP request through the router pipeline,
 * and returns the response with headers and status code.
 */

if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool {
        return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool {
        return $needle === '' || ($haystack !== '' && substr_compare($haystack, $needle, -strlen($needle)) === 0);
    }
}

if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}

if (!class_exists('DDLessPhpInputStream')) {
    class DDLessPhpInputStream
    {
        public $context;
        private int $position = 0;
        private bool $isInput = false;
        private string $buffer = '';

        public function stream_open($path, $mode, $options, &$opened_path)
        {
            $this->position = 0;
            $this->isInput = ($path === 'php://input');
            $this->buffer = '';
            return true;
        }

        public function stream_read($count)
        {
            $dataSource = $this->isInput ? ($GLOBALS['__DDLESS_RAW_INPUT__'] ?? '') : $this->buffer;
            $data = substr($dataSource, $this->position, $count);
            $this->position += strlen($data);
            return $data;
        }

        public function stream_eof()
        {
            $dataSource = $this->isInput ? ($GLOBALS['__DDLESS_RAW_INPUT__'] ?? '') : $this->buffer;
            return $this->position >= strlen($dataSource);
        }

        public function stream_tell()
        {
            return $this->position;
        }

        public function stream_seek($offset, $whence = SEEK_SET)
        {
            $dataSource = $this->isInput ? ($GLOBALS['__DDLESS_RAW_INPUT__'] ?? '') : $this->buffer;
            $length = strlen($dataSource);

            switch ($whence) {
                case SEEK_SET:
                    $target = $offset;
                    break;
                case SEEK_CUR:
                    $target = $this->position + $offset;
                    break;
                case SEEK_END:
                    $target = $length + $offset;
                    break;
                default:
                    return false;
            }

            if ($target < 0) {
                return false;
            }

            $this->position = $target;
            return true;
        }

        public function stream_stat()
        {
            $size = $this->isInput ? strlen($GLOBALS['__DDLESS_RAW_INPUT__'] ?? '') : strlen($this->buffer);
            return ['size' => $size];
        }

        public function stream_write($data)
        {
            if ($this->isInput) {
                return strlen($data);
            }

            // For php://temp, php://memory, etc. — real read/write buffer
            $len = strlen($data);
            $before = substr($this->buffer, 0, $this->position);
            $after = substr($this->buffer, $this->position + $len);
            $this->buffer = $before . $data . $after;
            $this->position += $len;
            return $len;
        }

        public function stream_truncate(int $newSize)
        {
            if ($this->isInput) {
                return false;
            }
            if ($newSize < strlen($this->buffer)) {
                $this->buffer = substr($this->buffer, 0, $newSize);
            } else {
                $this->buffer = str_pad($this->buffer, $newSize, "\0");
            }
            if ($this->position > $newSize) {
                $this->position = $newSize;
            }
            return true;
        }
    }
}

function ddless_normalize_relative_path(string $path): string
{
    $normalized = str_replace('\\', '/', $path);
    $root = defined('DDLESS_PROJECT_ROOT') ? str_replace('\\', '/', (string)DDLESS_PROJECT_ROOT) : null;

    if ($root && str_starts_with($normalized, $root)) {
        $trimmed = substr($normalized, strlen($root));
        return ltrim($trimmed, '/');
    }

    return ltrim($normalized, '/');
}

function ddless_normalize_headers(array $headers): array
{
    $normalized = [];
    foreach ($headers as $key => $value) {
        if (is_array($value)) {
            $normalized[$key] = array_map(
                static fn($entry) => is_scalar($entry) ? (string)$entry : json_encode($entry),
                $value,
            );
        } elseif ($value !== null) {
            $normalized[$key] = (string)$value;
        }
    }
    return $normalized;
}

if (!function_exists('ddless_normalize_value')) {
    function ddless_normalize_value($value, int $depth = 0)
    {
        $maxDepth = $GLOBALS['__DDLESS_SERIALIZE_DEPTH__'] ?? 4;
        if ($depth > $maxDepth) {
            if (is_object($value)) {
                return '[object ' . get_class($value) . ']';
            }
            return is_scalar($value) ? $value : '[max-depth]';
        }

        if (is_null($value) || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_string($value)) {
            if (strlen($value) > 10000) {
                return substr($value, 0, 10000) . '…';
            }
            return $value;
        }

        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $item) {
                $result[$key] = ddless_normalize_value($item, $depth + 1);
            }
            return $result;
        }

        if ($value instanceof \JsonSerializable) {
            try {
                return ddless_normalize_value($value->jsonSerialize(), $depth + 1);
            } catch (\Throwable $exception) {
                return '[object ' . get_class($value) . ']';
            }
        }

        if (method_exists($value, 'toArray')) {
            try {
                return ddless_normalize_value($value->toArray(), $depth + 1);
            } catch (\Throwable $exception) {
                // ignore fallthrough
            }
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }

        if (is_object($value)) {
            return '[object ' . get_class($value) . ']';
        }

        if (is_resource($value)) {
            return '[resource ' . (get_resource_type($value) ?: 'resource') . ']';
        }

        return (string)$value;
    }
}

if (!function_exists('ddless_build_call_stack')) {
    function ddless_build_call_stack(array $backtrace): array
    {
        $result = [];
        $limit = 30;

        foreach ($backtrace as $index => $frame) {
            if ($index >= $limit) {
                $result[] = [
                    'label' => null,
                    'file' => null,
                    'line' => null,
                    'truncated' => true,
                ];
                break;
            }

            if (!is_array($frame)) {
                continue;
            }

            $class = isset($frame['class']) && is_string($frame['class']) ? $frame['class'] : null;
            $type = isset($frame['type']) && is_string($frame['type']) ? $frame['type'] : '';
            $function = isset($frame['function']) && is_string($frame['function']) ? $frame['function'] : null;
            $file = isset($frame['file']) && is_string($frame['file']) ? ddless_normalize_relative_path($frame['file']) : null;
            $line = isset($frame['line']) && (is_int($frame['line']) || ctype_digit((string)$frame['line']))
                ? (int)$frame['line']
                : null;

            $label = $function;
            if ($class !== null && $function !== null) {
                $label = $class . $type . $function;
            } elseif ($function === null) {
                $label = 'main';
            }

            $result[] = [
                'label' => $label,
                'file' => $file,
                'line' => $line,
            ];
        }

        return $result;
    }
}

function ddless_read_breakpoints(string $ddlessDir): array
{
    $path = $ddlessDir . DIRECTORY_SEPARATOR . 'breakpoints.json';
    if (!is_file($path)) {
        return [];
    }

    try {
        $raw = file_get_contents($path);
        if ($raw === false) {
            return [];
        }
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            return [];
        }
        $result = [];
        foreach ($decoded as $file => $lines) {
            if (!is_array($lines)) {
                continue;
            }
            $normalizedFile = ddless_normalize_relative_path((string)$file);
            $normalizedLines = [];
            foreach ($lines as $line) {
                if (is_numeric($line)) {
                    $normalizedLines[] = (int)$line;
                }
            }
            if ($normalizedFile !== '' && $normalizedLines) {
                $result[$normalizedFile] = array_values(array_unique($normalizedLines));
            }
        }
        return $result;
    } catch (\Throwable $exception) {
        fwrite(STDERR, "[ddless] Failed to read breakpoints: {$exception->getMessage()}\n");
        return [];
    }
}

function ddless_collect_logs(string $logFilePath, ?int $offsetBefore): array
{
    if (!is_file($logFilePath) || !is_readable($logFilePath)) {
        return [];
    }

    $handle = fopen($logFilePath, 'rb');
    if ($handle === false) {
        return [];
    }

    $newSize = filesize($logFilePath);
    $start = 0;
    if ($offsetBefore !== null && $offsetBefore >= 0 && $offsetBefore <= $newSize) {
        $start = $offsetBefore;
    } elseif ($newSize > 32768) {
        $start = $newSize - 32768;
    }

    if ($start > 0) {
        fseek($handle, $start);
        if ($start !== 0) {
            fgets($handle);
        }
    }

    $data = stream_get_contents($handle) ?: '';
    fclose($handle);

    $data = trim($data);
    if ($data === '') {
        return [];
    }

    $lines = preg_split("/\r?\n/", $data);
    if (!$lines) {
        return [];
    }

    return array_slice(array_map('strval', $lines), -200);
}

function ddless_encode_json($value): string
{
    $encoded = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($encoded === false) {
        return "{}";
    }
    return $encoded;
}

function ddless_prepare_body_payload(?string $content, ?string $contentType): array
{
    $raw = $content ?? '';
    $encoding = 'utf8';
    $truncated = false;

    $isTextual = $contentType === null
        || preg_match('/json|text|xml|javascript|css|html|csv|urlencoded/i', $contentType);

    if (!$isTextual) {
        $encoding = 'base64';
        $prepared = base64_encode($raw);
    } else {
        if ($raw !== '' && function_exists('mb_check_encoding') && !mb_check_encoding($raw, 'UTF-8')) {
            $encoding = 'base64';
            $prepared = base64_encode($raw);
        } else {
            if (strlen($raw) > 131072) {
                $prepared = substr($raw, 0, 131072);
                $truncated = true;
            } else {
                $prepared = $raw;
            }
        }
    }

    return [
        'content' => $prepared,
        'encoding' => $encoding,
        'truncated' => $truncated,
    ];
}

function ddless_response_status_text(int $statusCode): ?string
{
    static $map = [
        100 => 'Continue',
        101 => 'Switching Protocols',
        102 => 'Processing',
        200 => 'OK',
        201 => 'Created',
        202 => 'Accepted',
        203 => 'Non-Authoritative Information',
        204 => 'No Content',
        205 => 'Reset Content',
        206 => 'Partial Content',
        207 => 'Multi-Status',
        300 => 'Multiple Choices',
        301 => 'Moved Permanently',
        302 => 'Found',
        303 => 'See Other',
        304 => 'Not Modified',
        307 => 'Temporary Redirect',
        308 => 'Permanent Redirect',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        402 => 'Payment Required',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        406 => 'Not Acceptable',
        407 => 'Proxy Authentication Required',
        408 => 'Request Timeout',
        409 => 'Conflict',
        410 => 'Gone',
        411 => 'Length Required',
        412 => 'Precondition Failed',
        413 => 'Payload Too Large',
        414 => 'URI Too Long',
        415 => 'Unsupported Media Type',
        422 => 'Unprocessable Entity',
        429 => 'Too Many Requests',
        500 => 'Internal Server Error',
        501 => 'Not Implemented',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        504 => 'Gateway Timeout',
        505 => 'HTTP Version Not Supported',
    ];

    return $map[$statusCode] ?? null;
}

// Tempest Bootstrap
// Path from .ddless/frameworks/tempest/ to project root
$projectRoot = !empty($GLOBALS['__DDLESS_PLAYGROUND__']) && defined('DDLESS_PROJECT_ROOT')
    ? DDLESS_PROJECT_ROOT
    : dirname(__DIR__, 3);
$ddlessDirectory = dirname(__DIR__, 2);

if (!defined('DDLESS_PROJECT_ROOT')) {
    define('DDLESS_PROJECT_ROOT', $projectRoot);
}

require_once $projectRoot . '/vendor/autoload.php';

if (!class_exists('Tempest\Core\Tempest')) {
    fwrite(STDERR, "[ddless] Tempest not found. Run composer install inside the project.\n");
    exit(1);
}

// Request Processing
$breakpoints = ddless_read_breakpoints($ddlessDirectory);
$executionStartedAt = microtime(true);

// Tempest doesn't have a fixed log location — check common paths
$logFilePath = null;
$logCandidates = [
    $projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'tempest.log',
    $projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'app.log',
    $projectRoot . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'log' . DIRECTORY_SEPARATOR . 'tempest.log',
    $projectRoot . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'log' . DIRECTORY_SEPARATOR . 'app.log',
];
foreach ($logCandidates as $candidate) {
    if (is_file($candidate)) {
        $logFilePath = $candidate;
        break;
    }
}
// If no log file found yet, default to storage/logs/tempest.log (may be created during request)
if ($logFilePath === null) {
    $logFilePath = $projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'tempest.log';
}
$logOffset = is_file($logFilePath) ? filesize($logFilePath) : null;

// Replace php://input stream
$phpWrapperReplaced = false;
if (in_array('php', stream_get_wrappers(), true)) {
    $phpWrapperReplaced = @stream_wrapper_unregister('php');
    if ($phpWrapperReplaced) {
        if (!stream_wrapper_register('php', 'DDLessPhpInputStream')) {
            stream_wrapper_restore('php');
            $phpWrapperReplaced = false;
        }
    }
}

// Register debug module after autoload but before handling
if (getenv('DDLESS_DEBUG_MODE') === 'true') {
    $debugModule = dirname(__DIR__, 2) . '/debug.php';
    if (file_exists($debugModule)) {
        require_once $debugModule;
        if (function_exists('ddless_register_stream_wrapper')) {
            ddless_register_stream_wrapper();
        }
    }
}

try {
    fwrite(STDERR, "[ddless] Starting Tempest request handling...\n");

    // Boot Tempest via the Container — do NOT call ->run() as it sends the response directly
    $container = \Tempest\Core\Tempest::boot($projectRoot);
    $router = $container->get(\Tempest\Router\Router::class);

    // Build a PSR-7 request from the already-configured $_SERVER superglobals.
    // Tempest internally uses Laminas Diactoros for PSR-7 — the Router accepts
    // both native Tempest\Http\Request and Psr\Http\Message\ServerRequestInterface.
    $request = null;

    // 1) Preferred: use Tempest's own RequestFactory (creates PSR-7 from globals)
    if (class_exists('Tempest\Http\RequestFactory')) {
        try {
            $factory = $container->get(\Tempest\Http\RequestFactory::class);
            $request = $factory->make();
        } catch (\Throwable $e) {
            fwrite(STDERR, "[ddless] Tempest RequestFactory failed: {$e->getMessage()}\n");
        }
    }

    // 2) Fallback: Laminas ServerRequestFactory (Tempest ships it as a dependency)
    if ($request === null && class_exists('Laminas\Diactoros\ServerRequestFactory')) {
        try {
            $request = \Laminas\Diactoros\ServerRequestFactory::fromGlobals();
        } catch (\Throwable $e) {
            fwrite(STDERR, "[ddless] Laminas ServerRequestFactory failed: {$e->getMessage()}\n");
        }
    }

    // 3) Last resort: resolve Request from the container
    if ($request === null) {
        foreach ([
            'Tempest\Http\Request',
            'Tempest\Router\Request',
            'Psr\Http\Message\ServerRequestInterface',
        ] as $requestClass) {
            if (!class_exists($requestClass) && !interface_exists($requestClass)) continue;
            try {
                $request = $container->get($requestClass);
                if ($request !== null) break;
            } catch (\Throwable $e) {
                fwrite(STDERR, "[ddless] Container {$requestClass} resolution failed: {$e->getMessage()}\n");
            }
        }
    }

    if ($request === null) {
        fwrite(STDERR, "[ddless] Failed to create HTTP request object — none of the Tempest request factories are available\n");
        exit(1);
    }

    // Dispatch the request through the router
    $response = $router->dispatch($request);

    fwrite(STDERR, "[ddless] Request handling completed\n");

    // Extract response data
    $statusCode = 200;
    $responseHeaders = [];
    $responseBodyContent = '';
    $responseContentType = null;

    if (is_object($response)) {
        // Tempest Response uses public readonly properties: $status (enum), $body, $headers (Header[])

        // Status — Tempest\Http\Status enum with ->value (int)
        if (isset($response->status)) {
            $status = $response->status;
            if (is_object($status) && property_exists($status, 'value')) {
                $statusCode = (int)$status->value;
            } elseif (is_int($status)) {
                $statusCode = $status;
            }
        } elseif (method_exists($response, 'getStatusCode')) {
            $statusCode = (int)$response->getStatusCode();
        }

        // Headers — Tempest uses Header objects with ->name and ->value properties
        if (isset($response->headers) && is_array($response->headers)) {
            foreach ($response->headers as $header) {
                if (is_object($header)) {
                    $name = $header->name ?? ($header->key ?? null);
                    $value = $header->value ?? null;
                    // Header name might be an enum (HeaderName) with ->value
                    if (is_object($name) && property_exists($name, 'value')) {
                        $name = $name->value;
                    }
                    if ($name !== null && $value !== null) {
                        $responseHeaders[(string)$name] = (string)$value;
                    }
                }
            }
        } elseif (method_exists($response, 'getHeaders')) {
            $rawHeaders = $response->getHeaders();
            if (is_array($rawHeaders)) {
                $responseHeaders = $rawHeaders;
            }
        }

        // Body — Tempest body can be string, array, View, Generator, JsonSerializable, or null
        if (property_exists($response, 'body')) {
            $body = $response->body;
            if (is_string($body)) {
                $responseBodyContent = $body;
            } elseif (is_array($body) || $body instanceof \JsonSerializable) {
                $responseBodyContent = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if (!isset($responseHeaders['Content-Type']) && !isset($responseHeaders['content-type'])) {
                    $responseHeaders['Content-Type'] = 'application/json';
                }
            } elseif ($body instanceof \Generator) {
                $responseBodyContent = '';
                foreach ($body as $chunk) {
                    $responseBodyContent .= (string)$chunk;
                }
            } elseif ($body !== null) {
                // View or other object — cast to string
                $responseBodyContent = (string)$body;
            }
        } elseif (method_exists($response, 'getBody')) {
            $body = $response->getBody();
            if (is_string($body)) {
                $responseBodyContent = $body;
            } elseif ($body !== null) {
                $responseBodyContent = (string)$body;
            }
        }

        // Extract Content-Type from response headers
        foreach ($responseHeaders as $headerName => $headerValue) {
            if (strtolower((string)$headerName) === 'content-type') {
                $responseContentType = is_array($headerValue) ? ($headerValue[0] ?? null) : (string)$headerValue;
                break;
            }
        }
    }

    $capturedBodyContent = null;

    // DDLess mode: output HTTP response with headers so Electron can parse it
    $isDdlessMode = php_sapi_name() === 'cli';

    if ($isDdlessMode) {
        $statusText = ddless_response_status_text($statusCode) ?? 'OK';

        echo sprintf("HTTP/1.1 %d %s\r\n", $statusCode, $statusText);

        foreach ($responseHeaders as $name => $value) {
            if (is_array($value)) {
                foreach ($value as $v) {
                    echo sprintf("%s: %s\r\n", $name, $v);
                }
            } else {
                echo sprintf("%s: %s\r\n", $name, $value);
            }
        }

        echo "\r\n";

        if (is_string($responseBodyContent)) {
            $capturedBodyContent = $responseBodyContent;
            echo $responseBodyContent;
        }
    } else {
        if (is_object($response) && method_exists($response, 'send')) {
            if (function_exists('ddless_safe_send_response')) {
                ddless_safe_send_response($response);
            } else {
                $response->send();
            }
            $capturedBodyContent = $responseBodyContent;
        } else {
            if (is_string($responseBodyContent)) {
                echo $responseBodyContent;
                $capturedBodyContent = $responseBodyContent;
            }
        }
    }

    if ($capturedBodyContent === null) {
        $capturedBodyContent = $responseBodyContent;
    }

    fwrite(STDERR, "[ddless] Request handling completed, status: " . $statusCode . "\n");

    // Snapshot Generation
    $executionFinishedAt = microtime(true);
    $durationMs = ($executionFinishedAt - $executionStartedAt) * 1000;
    $memoryPeakBytes = memory_get_peak_usage(true);

    $rawInput = $GLOBALS['__DDLESS_RAW_INPUT__'] ?? '';
    $contentType = $_SERVER['CONTENT_TYPE'] ?? null;

    // Build request headers from $_SERVER
    $requestHeaders = [];
    foreach ($_SERVER as $key => $value) {
        if (str_starts_with($key, 'HTTP_')) {
            $headerName = str_replace('_', '-', substr($key, 5));
            $headerName = ucwords(strtolower($headerName), '-');
            $requestHeaders[$headerName] = $value;
        }
    }
    if (isset($_SERVER['CONTENT_TYPE'])) {
        $requestHeaders['Content-Type'] = $_SERVER['CONTENT_TYPE'];
    }
    if (isset($_SERVER['CONTENT_LENGTH'])) {
        $requestHeaders['Content-Length'] = $_SERVER['CONTENT_LENGTH'];
    }

    $requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
    $requestPath = parse_url($requestUri, PHP_URL_PATH) ?: '/';
    $queryString = $_SERVER['QUERY_STRING'] ?? '';
    $fullUrl = ($_SERVER['REQUEST_SCHEME'] ?? 'http') . '://'
        . ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost')
        . $requestUri;

    $requestSummary = [
        'method' => $requestMethod,
        'path' => $requestPath,
        'fullUrl' => $fullUrl,
        'headers' => ddless_normalize_headers($requestHeaders),
        'query' => ddless_normalize_value($_GET),
        'input' => ddless_normalize_value($_POST),
        'cookies' => ddless_normalize_value($_COOKIE),
        'rawBody' => ddless_prepare_body_payload($rawInput, $contentType),
    ];

    $snapshotResponseHeaders = ddless_normalize_headers($responseHeaders);
    $responseBodyPayload = ddless_prepare_body_payload(
        is_string($capturedBodyContent) ? $capturedBodyContent : '',
        is_string($responseContentType) ? $responseContentType : null,
    );

    $routeInfo = null;
    $controllerInfo = null;
    $hitBreakpoints = [];
    $callStack = [];

    // Extract route and controller info from the router after dispatch
    // Tempest routes are attribute-based (#[Get], #[Post], etc.)
    try {
        $matchedRoute = null;

        // Try to get the matched route from the router
        if (method_exists($router, 'getMatchedRoute')) {
            $matchedRoute = $router->getMatchedRoute();
        } elseif (method_exists($router, 'getCurrentRoute')) {
            $matchedRoute = $router->getCurrentRoute();
        }

        // Try to extract controller info from the matched route
        $controllerClass = null;
        $controllerMethod = null;

        if ($matchedRoute !== null && is_object($matchedRoute)) {
            // Tempest Route object may have handler property or method
            if (method_exists($matchedRoute, 'getHandler')) {
                $handler = $matchedRoute->getHandler();
                if (is_object($handler)) {
                    if (property_exists($handler, 'className') || method_exists($handler, 'getClassName')) {
                        $controllerClass = method_exists($handler, 'getClassName')
                            ? $handler->getClassName()
                            : $handler->className;
                    }
                    if (property_exists($handler, 'methodName') || method_exists($handler, 'getMethodName')) {
                        $controllerMethod = method_exists($handler, 'getMethodName')
                            ? $handler->getMethodName()
                            : $handler->methodName;
                    }
                }
            }

            // Tempest Route may store handler info directly
            if ($controllerClass === null) {
                if (property_exists($matchedRoute, 'handler')) {
                    $handler = $matchedRoute->handler;
                    if (is_object($handler)) {
                        $controllerClass = property_exists($handler, 'className') ? $handler->className : null;
                        $controllerMethod = property_exists($handler, 'methodName') ? $handler->methodName : null;
                    } elseif (is_array($handler) && count($handler) === 2) {
                        $controllerClass = is_string($handler[0]) ? $handler[0] : (is_object($handler[0]) ? get_class($handler[0]) : null);
                        $controllerMethod = $handler[1] ?? null;
                    }
                }
            }

            // Extract route URI pattern
            $routeUri = null;
            if (method_exists($matchedRoute, 'getUri')) {
                $routeUri = $matchedRoute->getUri();
            } elseif (property_exists($matchedRoute, 'uri')) {
                $routeUri = $matchedRoute->uri;
            }

            $routeName = null;
            if (method_exists($matchedRoute, 'getName')) {
                $routeName = $matchedRoute->getName();
            } elseif (property_exists($matchedRoute, 'name')) {
                $routeName = $matchedRoute->name;
            }

            $routeMethods = null;
            if (method_exists($matchedRoute, 'getMethod')) {
                $m = $matchedRoute->getMethod();
                $routeMethods = is_array($m) ? $m : [$m];
            } elseif (property_exists($matchedRoute, 'method')) {
                $m = $matchedRoute->method;
                // May be an enum (Tempest\Router\Method)
                if (is_object($m) && property_exists($m, 'value')) {
                    $routeMethods = [$m->value];
                } elseif (is_string($m)) {
                    $routeMethods = [$m];
                } elseif (is_array($m)) {
                    $routeMethods = $m;
                }
            }

            $routeParameters = [];
            if (method_exists($matchedRoute, 'getParameters')) {
                $routeParameters = $matchedRoute->getParameters();
            } elseif (property_exists($matchedRoute, 'params')) {
                $routeParameters = $matchedRoute->params;
            }

            $actionLabel = null;
            if ($controllerClass && $controllerMethod) {
                $actionLabel = $controllerClass . '::' . $controllerMethod;
            }

            $routeInfo = [
                'uri' => $routeUri ?? $requestPath,
                'name' => $routeName,
                'methods' => $routeMethods ?? [$requestMethod],
                'action' => $actionLabel,
                'parameters' => ddless_normalize_value(is_array($routeParameters) ? $routeParameters : []),
            ];
        }

        // Use reflection to get controller file and line info
        if ($controllerClass && $controllerMethod) {
            $controllerFile = null;
            $startLine = null;
            $endLine = null;

            if (class_exists($controllerClass) && method_exists($controllerClass, $controllerMethod)) {
                $reflection = new \ReflectionMethod($controllerClass, $controllerMethod);
                $controllerFile = $reflection->getFileName() ?: null;
                $startLine = $reflection->getStartLine() ?: null;
                $endLine = $reflection->getEndLine() ?: null;
            }

            if ($controllerFile) {
                $relativeControllerPath = ddless_normalize_relative_path(
                    str_starts_with($controllerFile, $projectRoot)
                        ? substr($controllerFile, strlen($projectRoot) + 1)
                        : $controllerFile
                );

                if ($relativeControllerPath && isset($breakpoints[$relativeControllerPath])) {
                    foreach ($breakpoints[$relativeControllerPath] as $line) {
                        if ($startLine !== null && $endLine !== null && $line >= $startLine && $line <= $endLine) {
                            $hitBreakpoints[$relativeControllerPath][] = $line;
                        }
                    }
                }

                $controllerInfo = [
                    'file' => $relativeControllerPath,
                    'class' => $controllerClass,
                    'method' => $controllerMethod,
                    'startLine' => $startLine,
                    'endLine' => $endLine,
                ];

                if (!empty($hitBreakpoints[$relativeControllerPath])) {
                    sort($hitBreakpoints[$relativeControllerPath]);
                }
            }

            $callStack[] = [
                'label' => $routeInfo['action'] ?? ($controllerClass . '::' . $controllerMethod),
                'file' => $controllerInfo['file'] ?? null,
                'line' => $controllerInfo['startLine'] ?? null,
            ];
        }
    } catch (\Throwable $exception) {
        // ignore route inspection errors
        fwrite(STDERR, "[ddless] Route inspection warning: {$exception->getMessage()}\n");
    }

    $variables = [
        'routeParameters' => $routeInfo['parameters'] ?? [],
        'requestInput' => $requestSummary['input'],
        'query' => $requestSummary['query'],
    ];

    // Extract session data if available
    try {
        if (isset($_SESSION) && is_array($_SESSION)) {
            $variables['session'] = ddless_normalize_value($_SESSION);
        }
    } catch (\Throwable $exception) {
        // session may not be configured
    }

    $logs = ddless_collect_logs($logFilePath, $logOffset);

    $snapshot = [
        'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        'sessionId' => null,
        'request' => $requestSummary,
        'response' => [
            'status' => $statusCode,
            'statusText' => ddless_response_status_text($statusCode),
            'headers' => $snapshotResponseHeaders,
            'body' => $responseBodyPayload,
        ],
        'context' => [
            'route' => $routeInfo,
            'controller' => $controllerInfo,
            'breakpoints' => $breakpoints,
            'hitBreakpoints' => $hitBreakpoints,
            'variables' => $variables,
            'callStack' => $callStack,
        ],
        'metrics' => [
            'durationMs' => round($durationMs, 4),
            'memoryPeakBytes' => $memoryPeakBytes,
        ],
        'logs' => $logs,
    ];

    $encodedSnapshot = ddless_encode_json($snapshot) . "\n";

    if (function_exists('ddless_get_session_dir')) {
        $sessionSnapshotPath = ddless_get_session_dir() . DIRECTORY_SEPARATOR . 'last_execution.json';
        @file_put_contents($sessionSnapshotPath, $encodedSnapshot);
    }

    // Also write to root for backwards compatibility (non-session tools)
    $rootSnapshotPath = $ddlessDirectory . DIRECTORY_SEPARATOR . 'last_execution.json';
    @file_put_contents($rootSnapshotPath, $encodedSnapshot);

    fwrite(STDERR, "[ddless] Request completed successfully\n");
} finally {
    if ($phpWrapperReplaced) {
        @stream_wrapper_restore('php');
    }
    fwrite(STDERR, "[ddless] PHP execution complete\n");
}
