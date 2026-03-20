<?php
/*
 * DDLess - PHP Debug Engine
 *
 * @author    Jefferson T.S
 * @copyright 2025-2026 DDLess
 * @license   Proprietary
 *
 * WordPress HTTP request handler. Bootstraps WordPress via wp-blog-header.php,
 * configures superglobals, and captures the full HTTP response with snapshot.
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

// Setup
$projectRoot = !empty($GLOBALS['__DDLESS_PLAYGROUND__']) && defined('DDLESS_PROJECT_ROOT')
    ? DDLESS_PROJECT_ROOT
    : dirname(__DIR__, 3);
$ddlessDirectory = dirname(__DIR__, 2);

if (!defined('DDLESS_PROJECT_ROOT')) {
    define('DDLESS_PROJECT_ROOT', $projectRoot);
}

// WordPress detection — find wp-blog-header.php (the HTTP entry point)
$wpBlogHeader = $projectRoot . '/wp-blog-header.php';
if (!is_file($wpBlogHeader)) {
    fwrite(STDERR, "[ddless] wp-blog-header.php not found in project root. Is this a WordPress project?\n");
    echo "HTTP/1.1 500 Internal Server Error\r\nContent-Type: text/plain\r\n\r\nDDLess: wp-blog-header.php not found. Make sure the project root points to the WordPress installation directory.";
    exit(1);
}

fwrite(STDERR, "[ddless] WordPress project detected\n");

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

// Change to project root so WordPress relative includes work
chdir($projectRoot);

// WordPress (WooCommerce, etc.) often calls die()/exit() after REST API responses.
// PHP shutdown order: shutdown functions → destructors → output buffer flush.
// We use register_shutdown_function() so the HTTP response is always properly
// formatted even if die()/exit() is called during wp-blog-header.php execution.

$__ddless_wp_error = null;
$__ddless_wp_responded = false;
$__ddless_wp_headers = [];

// This shutdown function runs BEFORE output buffers are flushed, so ob_get_clean() works.
register_shutdown_function(function () use (
    &$__ddless_wp_error,
    &$__ddless_wp_responded,
    &$__ddless_wp_headers,
    $executionStartedAt,
    $ddlessDirectory,
    $breakpoints
) {
    if ($__ddless_wp_responded) {
        return; // Already handled by normal flow
    }
    $__ddless_wp_responded = true;

    // Drain all output buffers (WordPress may nest multiple levels)
    $capturedOutput = '';
    while (ob_get_level() > 0) {
        $capturedOutput = ob_get_clean() . $capturedOutput;
    }

    $statusCode = http_response_code() ?: 200;

    // Use headers captured via WordPress hooks (rest_pre_serve_request / wp_headers).
    // headers_list() is always empty in CLI mode, so we rely entirely on hook capture.
    $responseHeaders = $__ddless_wp_headers;

    $responseContentType = $responseHeaders['Content-Type'] ?? $responseHeaders['content-type'] ?? null;

    // Strip encoding/length headers — ob_get_clean() captures raw (uncompressed) output,
    // so forwarding Content-Encoding: gzip etc. causes the browser to fail parsing.
    $stripHeadersLower = ['content-encoding', 'transfer-encoding', 'content-length'];
    foreach (array_keys($responseHeaders) as $hKey) {
        if (in_array(strtolower($hKey), $stripHeadersLower, true)) {
            unset($responseHeaders[$hKey]);
        }
    }

    $statusText = ddless_response_status_text($statusCode) ?? 'OK';

    // Write the HTTP response to STDOUT using fwrite to bypass any remaining output buffering
    fwrite(STDOUT, sprintf("HTTP/1.1 %d %s\r\n", $statusCode, $statusText));

    foreach ($responseHeaders as $name => $value) {
        fwrite(STDOUT, sprintf("%s: %s\r\n", $name, $value));
    }

    fwrite(STDOUT, sprintf("Content-Length: %d\r\n", strlen($capturedOutput)));
    fwrite(STDOUT, "\r\n");
    fwrite(STDOUT, $capturedOutput);

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
    if ($__ddless_wp_error !== null) {
        $errorInfo = [
            'message' => $__ddless_wp_error->getMessage(),
            'file' => ddless_normalize_relative_path($__ddless_wp_error->getFile()),
            'line' => $__ddless_wp_error->getLine(),
            'trace' => array_slice(array_map(function ($frame) {
                return [
                    'label' => ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? 'main'),
                    'file' => isset($frame['file']) ? ddless_normalize_relative_path($frame['file']) : null,
                    'line' => $frame['line'] ?? null,
                ];
            }, $__ddless_wp_error->getTrace()), 0, 30),
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
            'route' => [
                'uri' => $_SERVER['REQUEST_URI'] ?? '/',
                'name' => null,
                'methods' => [$_SERVER['REQUEST_METHOD'] ?? 'GET'],
                'action' => 'wp-blog-header.php',
                'parameters' => [],
            ],
            'controller' => [
                'file' => 'wp-blog-header.php',
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

    $rootSnapshotPath = $ddlessDirectory . DIRECTORY_SEPARATOR . 'last_execution.json';
    @file_put_contents($rootSnapshotPath, $encodedSnapshot);

    fwrite(STDERR, "[ddless] WordPress execution complete\n");
});

// Capture output — WordPress outputs HTML directly via wp-blog-header.php.
ob_start();

// ── WordPress Hook Pre-Registration ──────────────────────────────────────────
// PHP CLI mode: header()/setcookie()/headers_list() are all no-ops.
// We pre-populate $GLOBALS['wp_filter'] so WordPress converts these to WP_Hook
// objects during initialization (build_preinitialized_hooks in wp-settings.php).
// This lets us capture response headers from the WP_REST_Response object.

$GLOBALS['wp_filter'] = $GLOBALS['wp_filter'] ?? [];

// Capture REST API response headers from WP_REST_Response (generic — works for any plugin)
$GLOBALS['wp_filter']['rest_pre_serve_request'] = [
    9999 => [
        'ddless_header_capture' => [
            'function' => function ($served, $result, $request, $server) use (&$__ddless_wp_headers) {
                if ($result instanceof \WP_REST_Response) {
                    // 1) All plugin/endpoint headers from the response object (generic)
                    foreach ($result->get_headers() as $name => $value) {
                        $__ddless_wp_headers[$name] = (string)$value;
                    }

                    // 2) Standard WP REST Server headers (set via header(), lost in CLI mode)
                    $charset = function_exists('get_option') ? (get_option('blog_charset') ?: 'UTF-8') : 'UTF-8';
                    if (!isset($__ddless_wp_headers['Content-Type'])) {
                        $__ddless_wp_headers['Content-Type'] = 'application/json; charset=' . $charset;
                    }
                    $__ddless_wp_headers['X-Robots-Tag'] = 'noindex';
                    $__ddless_wp_headers['X-Content-Type-Options'] = 'nosniff';

                    if (function_exists('rest_url')) {
                        $apiRoot = rest_url();
                        if ($apiRoot) {
                            $__ddless_wp_headers['Link'] = '<' . esc_url($apiRoot) . '>; rel="https://api.w.org/"';
                        }
                    }

                    // No-cache headers (WordPress sends these for authenticated requests)
                    if (function_exists('is_user_logged_in') && is_user_logged_in()) {
                        if (function_exists('wp_get_nocache_headers')) {
                            foreach (wp_get_nocache_headers() as $name => $value) {
                                // wp_get_nocache_headers returns e.g. 'Cache-Control' => 'no-cache, ...'
                                $__ddless_wp_headers[$name] = (string)$value;
                            }
                        }
                    }

                    // Vary: Origin (set by rest_send_cors_headers, lost in CLI)
                    if (!isset($__ddless_wp_headers['Vary'])) {
                        $__ddless_wp_headers['Vary'] = 'Origin';
                    }
                }
                return $served;
            },
            'accepted_args' => 4,
        ],
    ],
];

// Capture non-REST WordPress page headers (wp_headers filter — generic)
$GLOBALS['wp_filter']['wp_headers'] = [
    9999 => [
        'ddless_header_capture' => [
            'function' => function ($headers) use (&$__ddless_wp_headers) {
                foreach ($headers as $name => $value) {
                    $__ddless_wp_headers[$name] = (string)$value;
                }
                return $headers;
            },
            'accepted_args' => 1,
        ],
    ],
];

try {
    fwrite(STDERR, "[ddless] Booting WordPress via wp-blog-header.php...\n");
    require $wpBlogHeader;
    fwrite(STDERR, "[ddless] WordPress request handling completed.\n");
} catch (\Throwable $e) {
    $__ddless_wp_error = $e;
    fwrite(STDERR, "[ddless] WordPress threw: " . $e->getMessage() . "\n");
}

// If we reach here, WordPress didn't call die()/exit().
// The shutdown function will handle the response formatting and snapshot.
