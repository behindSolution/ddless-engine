<?php
/*
 * DDLess - PHP Debug Engine
 *
 * @author    Jefferson T.S
 * @copyright 2025-2026 DDLess
 * @license   Proprietary
 *
 * Slim 4 HTTP request handler.
 *
 * Slim is a micro-framework: there is no bootstrap file that hands back a
 * configured app. Container, middleware and routes are all built inside the
 * front controller, which ends in $app->run(). So this includes that file and
 * lets Slim run, then reads the matched route back out of the $app it leaves
 * in scope. Rebuilding the bootstrap by hand would mean guessing at code that
 * only exists in the user's project.
 */

// PHP 7.4 compatibility polyfills
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

// Undefined under php -S, and debug.php only defines them in debug mode.
if (!defined('STDOUT')) { define('STDOUT', fopen('php://stdout', 'w')); }
if (!defined('STDERR')) { define('STDERR', fopen('php://stderr', 'w')); }

// PHP Input Stream Wrapper
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

// Helper Functions
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
        200 => 'OK', 201 => 'Created', 204 => 'No Content',
        301 => 'Moved Permanently', 302 => 'Found', 304 => 'Not Modified',
        307 => 'Temporary Redirect', 308 => 'Permanent Redirect',
        400 => 'Bad Request', 401 => 'Unauthorized', 403 => 'Forbidden',
        404 => 'Not Found', 405 => 'Method Not Allowed', 422 => 'Unprocessable Entity',
        429 => 'Too Many Requests', 500 => 'Internal Server Error',
        502 => 'Bad Gateway', 503 => 'Service Unavailable',
    ];

    return $map[$statusCode] ?? null;
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
        return [];
    }
}

/**
 * Describe a callable the way a person would recognise it in the editor.
 * Slim accepts several shapes: [$controller, 'method'], 'Class:method',
 * a closure, or an invokable object.
 */
function ddless_slim_describe_callable($callable): array
{
    $class = null;
    $method = null;
    $file = null;
    $startLine = null;
    $endLine = null;

    try {
        $reflection = null;

        if (is_array($callable) && count($callable) === 2) {
            $class = is_object($callable[0]) ? get_class($callable[0]) : (string) $callable[0];
            $method = (string) $callable[1];
            if (method_exists($callable[0], $method)) {
                $reflection = new ReflectionMethod($callable[0], $method);
            }
        } elseif ($callable instanceof Closure) {
            $class = 'Closure';
            $reflection = new ReflectionFunction($callable);
        } elseif (is_string($callable)) {
            if (strpos($callable, ':') !== false) {
                [$class, $method] = explode(':', $callable, 2);
                if ($class !== '' && $method !== '' && method_exists($class, $method)) {
                    $reflection = new ReflectionMethod($class, $method);
                }
            } elseif (class_exists($callable) && method_exists($callable, '__invoke')) {
                $class = $callable;
                $method = '__invoke';
                $reflection = new ReflectionMethod($callable, '__invoke');
            } elseif (function_exists($callable)) {
                $method = $callable;
                $reflection = new ReflectionFunction($callable);
            }
        } elseif (is_object($callable) && method_exists($callable, '__invoke')) {
            $class = get_class($callable);
            $method = '__invoke';
            $reflection = new ReflectionMethod($callable, '__invoke');
        }

        if ($reflection !== null) {
            $file = $reflection->getFileName() ?: null;
            $startLine = $reflection->getStartLine() ?: null;
            $endLine = $reflection->getEndLine() ?: null;
        }
    } catch (Throwable $e) {
        // A callable we cannot reflect still gets its class/method reported.
    }

    return [
        'class' => $class,
        'method' => $method,
        'file' => $file !== null ? ddless_normalize_relative_path($file) : null,
        'startLine' => $startLine,
        'endLine' => $endLine,
    ];
}

/**
 * Ask Slim which route the request matched.
 *
 * Runs after the app handled the request, so the PSR-7 request and its route
 * attribute are gone. computeRoutingResults() is the same lookup
 * RoutingMiddleware performs, so it reports the route that actually ran rather
 * than one guessed by re-matching patterns here.
 */
function ddless_slim_route_info($app, string $method, string $path): ?array
{
    if (!is_object($app) || !method_exists($app, 'getRouteResolver')) {
        return null;
    }

    try {
        $resolver = $app->getRouteResolver();
        $results = $resolver->computeRoutingResults($path, $method);

        // 1 = FOUND. A 404/405 was handled by Slim's error middleware.
        if ($results->getRouteStatus() !== 1) {
            return null;
        }

        $identifier = $results->getRouteIdentifier();
        if ($identifier === null) {
            return null;
        }

        $route = $resolver->resolveRoute($identifier);
        $callable = ddless_slim_describe_callable($route->getCallable());

        $action = $callable['class'] !== null && $callable['method'] !== null
            ? $callable['class'] . '::' . $callable['method']
            : ($callable['method'] ?? 'Closure');

        return [
            'route' => [
                'uri' => $route->getPattern(),
                'name' => $route->getName(),
                'methods' => $route->getMethods(),
                'action' => $action,
                'parameters' => $results->getRouteArguments(),
            ],
            'controller' => [
                'file' => $callable['file'],
                'class' => $callable['class'],
                'method' => $callable['method'],
                'startLine' => $callable['startLine'],
                'endLine' => $callable['endLine'],
            ],
        ];
    } catch (Throwable $e) {
        fwrite(STDERR, "[ddless] Slim route lookup failed: " . $e->getMessage() . "\n");
        return null;
    }
}

// Setup
// Path from .ddless/frameworks/php/ to project root
$projectRoot = !empty($GLOBALS['__DDLESS_PLAYGROUND__']) && defined('DDLESS_PROJECT_ROOT')
    ? DDLESS_PROJECT_ROOT
    : dirname(__DIR__, 3);
$ddlessDirectory = dirname(__DIR__, 2);

$composerAutoload = $projectRoot . '/vendor/autoload.php';
if (file_exists($composerAutoload)) {
    require_once $composerAutoload;
    fwrite(STDERR, "[ddless] Composer autoload loaded.\n");
}

// CLI loses every response header (see response_emitter_shim.php).
// class_exists(..., false) so the check does not autoload the real class first.
if (php_sapi_name() === 'cli' && !class_exists('Slim\\ResponseEmitter', false)) {
    require_once __DIR__ . '/response_emitter_shim.php';
    fwrite(STDERR, "[ddless] Slim response emitter shim active (CLI mode).\n");
}

// Entry Point Resolution
// One front controller for every request: /api/users is a route, not a file,
// so there is no URL-to-file mapping to attempt.
$entryPoint = getenv('DDLESS_ENTRY_POINT') ?: null;
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$requestPath = parse_url($requestUri, PHP_URL_PATH) ?: '/';

if (!$entryPoint) {
    foreach (['public/index.php', 'index.php'] as $candidate) {
        if (is_file($projectRoot . '/' . $candidate)) {
            $entryPoint = $candidate;
            break;
        }
    }
}

$__ddlessNoEntry = "DDLess: No Slim front controller found (looked for public/index.php).";
if (!$entryPoint) {
    fwrite(STDERR, "[ddless] {$__ddlessNoEntry}\n");
    if (php_sapi_name() === 'cli-server') {
        http_response_code(500);
        header('Content-Type: text/plain');
        echo $__ddlessNoEntry;
    } else {
        echo "HTTP/1.1 500 Internal Server Error\r\nContent-Type: text/plain\r\n\r\n" . $__ddlessNoEntry;
    }
    exit(1);
}

$entryPointAbsolute = $projectRoot . '/' . ltrim(str_replace('\\', '/', $entryPoint), '/');
if (!is_file($entryPointAbsolute)) {
    fwrite(STDERR, "[ddless] Front controller not found: {$entryPointAbsolute}\n");
    if (php_sapi_name() === 'cli-server') {
        http_response_code(500);
        header('Content-Type: text/plain');
        echo "DDLess: Front controller not found: {$entryPoint}";
    } else {
        echo "HTTP/1.1 500 Internal Server Error\r\nContent-Type: text/plain\r\n\r\nDDLess: Front controller not found: {$entryPoint}";
    }
    exit(1);
}

fwrite(STDERR, "[ddless] Slim front controller: {$entryPoint}\n");

// Request Processing
$breakpoints = ddless_read_breakpoints($ddlessDirectory);
$executionStartedAt = microtime(true);

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

if (getenv('DDLESS_DEBUG_MODE') === 'true') {
    $debugModule = dirname(__DIR__, 2) . '/debug.php';
    if (file_exists($debugModule)) {
        require_once $debugModule;
        if (function_exists('ddless_register_stream_wrapper')) {
            ddless_register_stream_wrapper();
        }
    }
}

// Change CWD to the entry point's directory so relative includes work.
// Traditional PHP apps (Dolibarr, etc.) use require('../file.php') which
// resolves relative to CWD. Apache/nginx set CWD to the script's directory.
chdir(dirname($entryPointAbsolute));

if (php_sapi_name() === 'cli-server') {
    $_SERVER['SCRIPT_FILENAME'] = $entryPointAbsolute;
    $entryPointUrl = '/' . ltrim(str_replace('\\', '/', $entryPoint), '/');
    // Only fix SCRIPT_NAME/PHP_SELF if they don't match the resolved entry point
    // (e.g. when URL maps to a different PHP file than what was requested)
    if (isset($_SERVER['SCRIPT_NAME']) && $_SERVER['SCRIPT_NAME'] === '/.ddless/ssh_proxy_router.php') {
        $_SERVER['SCRIPT_NAME'] = $entryPointUrl;
    }
    if (isset($_SERVER['PHP_SELF']) && $_SERVER['PHP_SELF'] === '/.ddless/ssh_proxy_router.php') {
        $_SERVER['PHP_SELF'] = $entryPointUrl;
    }
}

ini_set('display_errors', 'stderr');
ini_set('log_errors', '1');
error_reporting(E_ALL & ~E_DEPRECATED);

// Capture output
ob_start();
$phpError = null;

try {
    fwrite(STDERR, "[ddless] Including front controller: {$entryPointAbsolute}\n");
    // Top level on purpose: the front controller's variables land in this
    // scope, which is how the Slim app becomes reachable below.
    require $entryPointAbsolute;
    fwrite(STDERR, "[ddless] Front controller execution completed.\n");
} catch (\Throwable $e) {
    $phpError = $e;
    fwrite(STDERR, "[ddless] Entry point threw: " . $e->getMessage() . "\n");
}

$capturedOutput = ob_get_clean() ?: '';

// Found by type: the front controller names the variable itself.
$slimApp = null;
if (class_exists('Slim\App')) {
    foreach (get_defined_vars() as $__ddlessVarName => $__ddlessVarValue) {
        if ($__ddlessVarValue instanceof Slim\App) {
            $slimApp = $__ddlessVarValue;
            break;
        }
    }
}
unset($__ddlessVarName, $__ddlessVarValue);

$slimRouteInfo = $slimApp !== null
    ? ddless_slim_route_info($slimApp, $_SERVER['REQUEST_METHOD'] ?? 'GET', $requestPath)
    : null;

if ($slimApp === null) {
    fwrite(STDERR, "[ddless] No Slim app found in scope; route details unavailable.\n");
}

$statusCode = http_response_code() ?: 200;
$slimReasonPhrase = null;
$responseHeaders = [];

if (function_exists('headers_list')) {
    foreach (headers_list() as $header) {
        $parts = explode(':', $header, 2);
        if (count($parts) === 2) {
            $name = trim($parts[0]);
            $value = trim($parts[1]);
            $responseHeaders[$name] = $value;
        }
    }
}

// headers_list() is empty under CLI, so read them off the response the emitter
// shim captured. Without this the client gets no Content-Type and shows JSON
// as plain text.
$slimResponse = $GLOBALS['__DDLESS_SLIM_RESPONSE__'] ?? null;
if ($slimResponse !== null && $slimResponse instanceof \Psr\Http\Message\ResponseInterface) {
    foreach ($slimResponse->getHeaders() as $name => $values) {
        $responseHeaders[$name] = implode(', ', $values);
    }
    $statusCode = $slimResponse->getStatusCode();
    $slimReasonPhrase = $slimResponse->getReasonPhrase();
}

$responseContentType = $responseHeaders['Content-Type'] ?? $responseHeaders['content-type'] ?? null;

if (php_sapi_name() === 'cli-server') {
    // Running under php -S (browser debug server) — headers are managed natively.
    // No http_response_code() here. $statusCode came from it a few lines up, so
    // setting it back is a no-op — and after Slim's emitter sent its status line
    // via header('HTTP/..'), that no-op writes a PHP warning into the buffered
    // body. headers_sent() misses it: buffering means nothing was flushed yet.
    echo $capturedOutput;
} else {
    // CLI trigger mode — output raw HTTP format for parsePhpdbgHttpResponse() in Electron.
    $statusText = $slimReasonPhrase ?? ddless_response_status_text($statusCode) ?? 'OK';
    echo sprintf("HTTP/1.1 %d %s\r\n", $statusCode, $statusText);

    foreach ($responseHeaders as $name => $value) {
        echo sprintf("%s: %s\r\n", $name, $value);
    }

    echo "\r\n";
    echo $capturedOutput;
}

// Snapshot Generation
$executionFinishedAt = microtime(true);
$durationMs = ($executionFinishedAt - $executionStartedAt) * 1000;
$memoryPeakBytes = memory_get_peak_usage(true);

$rawInput = $GLOBALS['__DDLESS_RAW_INPUT__'] ?? '';
$contentType = $_SERVER['CONTENT_TYPE'] ?? null;

$requestSummary = [
    'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
    'path' => $_SERVER['REQUEST_URI'] ?? '/',
    'fullUrl' => ($_SERVER['REQUEST_SCHEME'] ?? 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . ($_SERVER['REQUEST_URI'] ?? '/'),
    'headers' => [],
    'query' => ddless_normalize_value($_GET),
    'input' => ddless_normalize_value($_POST),
    'cookies' => ddless_normalize_value($_COOKIE),
    'rawBody' => ddless_prepare_body_payload($rawInput, $contentType),
];

$reqHeaders = [];
foreach ($_SERVER as $key => $value) {
    if (str_starts_with($key, 'HTTP_')) {
        $headerName = str_replace('_', '-', substr($key, 5));
        $reqHeaders[$headerName] = (string)$value;
    }
}
if (isset($_SERVER['CONTENT_TYPE'])) {
    $reqHeaders['Content-Type'] = $_SERVER['CONTENT_TYPE'];
}
if (isset($_SERVER['CONTENT_LENGTH'])) {
    $reqHeaders['Content-Length'] = $_SERVER['CONTENT_LENGTH'];
}
$requestSummary['headers'] = $reqHeaders;

$responseBodyPayload = ddless_prepare_body_payload(
    $capturedOutput,
    is_string($responseContentType) ? $responseContentType : null,
);

$errorInfo = null;
if ($phpError !== null) {
    $errorInfo = [
        'message' => $phpError->getMessage(),
        'file' => ddless_normalize_relative_path($phpError->getFile()),
        'line' => $phpError->getLine(),
        'trace' => array_slice(array_map(function ($frame) {
            return [
                'label' => ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? 'main'),
                'file' => isset($frame['file']) ? ddless_normalize_relative_path($frame['file']) : null,
                'line' => $frame['line'] ?? null,
            ];
        }, $phpError->getTrace()), 0, 30),
    ];
}

$variables = [
    'query' => $requestSummary['query'],
    'post' => $requestSummary['input'],
];

$snapshot = [
    'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
    'sessionId' => null,
    'request' => $requestSummary,
    'response' => [
        'status' => $statusCode,
        'statusText' => ddless_response_status_text($statusCode),
        'headers' => ddless_normalize_headers($responseHeaders),
        'body' => $responseBodyPayload,
    ],
    'context' => [
        // Falls back to the front controller on 404/405.
        'route' => $slimRouteInfo['route'] ?? [
            'uri' => $_SERVER['REQUEST_URI'] ?? '/',
            'name' => null,
            'methods' => [$_SERVER['REQUEST_METHOD'] ?? 'GET'],
            'action' => $entryPoint,
            'parameters' => [],
        ],
        'controller' => $slimRouteInfo['controller'] ?? [
            'file' => ddless_normalize_relative_path($entryPointAbsolute),
            'class' => null,
            'method' => null,
            'startLine' => 1,
            'endLine' => null,
        ],
        'breakpoints' => $breakpoints,
        'hitBreakpoints' => [],
        'variables' => $variables,
        'callStack' => [],
        'error' => $errorInfo,
    ],
    'metrics' => [
        'durationMs' => round($durationMs, 4),
        'memoryPeakBytes' => $memoryPeakBytes,
    ],
    'logs' => [],
];

$encodedSnapshot = ddless_encode_json($snapshot) . "\n";

if (function_exists('ddless_get_session_dir')) {
    $sessionSnapshotPath = ddless_get_session_dir() . DIRECTORY_SEPARATOR . 'last_execution.json';
    @file_put_contents($sessionSnapshotPath, $encodedSnapshot);
}

// Also write to root for backwards compatibility
$rootSnapshotPath = $ddlessDirectory . DIRECTORY_SEPARATOR . 'last_execution.json';
@file_put_contents($rootSnapshotPath, $encodedSnapshot);

if ($phpWrapperReplaced) {
    @stream_wrapper_restore('php');
}

fwrite(STDERR, "__DDLESS_REQUEST_COMPLETE__\n");
