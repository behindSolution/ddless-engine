<?php
/*
 * DDLess - PHP Debug Engine
 *
 * @author    Jefferson T.S
 * @copyright 2025-2026 DDLess
 * @license   Proprietary
 *
 * CodeIgniter 4 HTTP request handler. Bootstraps the CI4 application,
 * processes the captured HTTP request through the full routing/filter pipeline,
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

function ddless_collect_logs(string $logDir): array
{
    // CI4 uses writable/logs/log-YYYY-MM-DD.php
    $today = date('Y-m-d');
    $candidates = ["log-{$today}.php", "log-{$today}.log"];
    $logFilePath = null;

    foreach ($candidates as $candidate) {
        $path = $logDir . DIRECTORY_SEPARATOR . $candidate;
        if (is_file($path)) {
            $logFilePath = $path;
            break;
        }
    }

    if ($logFilePath === null || !is_readable($logFilePath)) {
        return [];
    }

    $handle = fopen($logFilePath, 'rb');
    if ($handle === false) {
        return [];
    }

    $newSize = filesize($logFilePath);
    $start = 0;
    if ($newSize > 32768) {
        $start = $newSize - 32768;
    }

    if ($start > 0) {
        fseek($handle, $start);
        fgets($handle);
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
        100 => 'Continue', 101 => 'Switching Protocols', 102 => 'Processing',
        200 => 'OK', 201 => 'Created', 202 => 'Accepted',
        203 => 'Non-Authoritative Information', 204 => 'No Content',
        205 => 'Reset Content', 206 => 'Partial Content', 207 => 'Multi-Status',
        300 => 'Multiple Choices', 301 => 'Moved Permanently', 302 => 'Found',
        303 => 'See Other', 304 => 'Not Modified',
        307 => 'Temporary Redirect', 308 => 'Permanent Redirect',
        400 => 'Bad Request', 401 => 'Unauthorized', 402 => 'Payment Required',
        403 => 'Forbidden', 404 => 'Not Found', 405 => 'Method Not Allowed',
        406 => 'Not Acceptable', 407 => 'Proxy Authentication Required',
        408 => 'Request Timeout', 409 => 'Conflict', 410 => 'Gone',
        411 => 'Length Required', 412 => 'Precondition Failed',
        413 => 'Payload Too Large', 414 => 'URI Too Long',
        415 => 'Unsupported Media Type', 422 => 'Unprocessable Entity',
        429 => 'Too Many Requests',
        500 => 'Internal Server Error', 501 => 'Not Implemented',
        502 => 'Bad Gateway', 503 => 'Service Unavailable',
        504 => 'Gateway Timeout', 505 => 'HTTP Version Not Supported',
    ];

    return $map[$statusCode] ?? null;
}

// Override CI4's is_cli() BEFORE autoload/bootstrap so CI4 treats this as a web request.
// CI4's system/Common.php uses `if (! function_exists('is_cli'))` — our definition wins.
if (!function_exists('is_cli')) {
    function is_cli(): bool
    {
        return false;
    }
}

// CodeIgniter 4 Bootstrap
$projectRoot = !empty($GLOBALS['__DDLESS_PLAYGROUND__']) && defined('DDLESS_PROJECT_ROOT')
    ? DDLESS_PROJECT_ROOT
    : dirname(__DIR__, 3);
$ddlessDirectory = dirname(__DIR__, 2);

if (!defined('DDLESS_PROJECT_ROOT')) {
    define('DDLESS_PROJECT_ROOT', $projectRoot);
}

// CI4 requires FCPATH to be defined (normally set by public/index.php)
if (!defined('FCPATH')) {
    $publicDir = $projectRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR;
    define('FCPATH', $publicDir);
}

// CI4 needs these path constants
if (!defined('ROOTPATH')) {
    define('ROOTPATH', $projectRoot . DIRECTORY_SEPARATOR);
}

require_once $projectRoot . '/vendor/autoload.php';

// Load .env via CI4's Dotenv if available
$envFile = $projectRoot . '/.env';
if (is_file($envFile) && class_exists('CodeIgniter\Config\DotEnv')) {
    (new \CodeIgniter\Config\DotEnv($projectRoot))->load();
}

// CI4 needs Paths config
$pathsConfig = $projectRoot . '/app/Config/Paths.php';
if (!is_file($pathsConfig)) {
    fwrite(STDERR, "[ddless] CodeIgniter Paths config not found at: {$pathsConfig}\n");
    exit(1);
}
require_once $pathsConfig;

if (!class_exists('Config\Paths')) {
    fwrite(STDERR, "[ddless] Config\\Paths class not found after requiring {$pathsConfig}\n");
    exit(1);
}

$paths = new \Config\Paths();

// Define path constants that CI4 expects
if (!defined('APPPATH')) {
    define('APPPATH', realpath($paths->appDirectory) . DIRECTORY_SEPARATOR);
}
if (!defined('WRITEPATH')) {
    define('WRITEPATH', realpath($paths->writableDirectory) . DIRECTORY_SEPARATOR);
}
if (!defined('SYSTEMPATH')) {
    $systemPath = realpath($paths->systemDirectory ?? $projectRoot . '/vendor/codeigniter4/framework/system');
    if ($systemPath) {
        define('SYSTEMPATH', $systemPath . DIRECTORY_SEPARATOR);
    } else {
        // Try common locations
        $candidates = [
            $projectRoot . '/vendor/codeigniter4/framework/system',
            $projectRoot . '/system',
        ];
        foreach ($candidates as $candidate) {
            if (is_dir($candidate)) {
                define('SYSTEMPATH', realpath($candidate) . DIRECTORY_SEPARATOR);
                break;
            }
        }
        if (!defined('SYSTEMPATH')) {
            fwrite(STDERR, "[ddless] CodeIgniter system directory not found.\n");
            exit(1);
        }
    }
}
if (!defined('TESTPATH')) {
    define('TESTPATH', $projectRoot . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR);
}

// Boot file (CI4 4.x)
$bootFile = SYSTEMPATH . 'Boot.php';
if (is_file($bootFile)) {
    require_once $bootFile;
}

// CI4 expects CI_ENVIRONMENT
if (!defined('CI_ENVIRONMENT')) {
    define('CI_ENVIRONMENT', $_SERVER['CI_ENVIRONMENT'] ?? $_ENV['CI_ENVIRONMENT'] ?? 'development');
}

// Request Processing
$breakpoints = ddless_read_breakpoints($ddlessDirectory);
$executionStartedAt = microtime(true);
$logDir = (defined('WRITEPATH') ? WRITEPATH : $projectRoot . '/writable/') . 'logs';

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
    fwrite(STDERR, "[ddless] Starting CodeIgniter request handling...\n");

    // Boot CI4 app
    if (class_exists('CodeIgniter\Boot')) {
        // CI4 4.5+ uses Boot class
        \CodeIgniter\Boot::bootWeb($paths);
        $app = \Config\Services::codeigniter();
    } elseif (class_exists('Config\Services')) {
        // CI4 4.x - manual bootstrap
        $app = \Config\Services::codeigniter();
        $app->initialize();
        $app->setContext('web');
    } else {
        fwrite(STDERR, "[ddless] CodeIgniter application class not found.\n");
        exit(1);
    }

    // Capture output instead of sending directly
    ob_start();
    $app->run();
    $bodyOutput = ob_get_clean();

    // Get the response object
    $response = \Config\Services::response();
    $request = \Config\Services::request();

    $statusCode = $response->getStatusCode();
    $responseContentType = $response->getHeaderLine('Content-Type');
    $capturedBodyContent = null;

    $isDdlessMode = php_sapi_name() === 'cli';

    if ($isDdlessMode) {
        $statusText = ddless_response_status_text($statusCode) ?? $response->getReasonPhrase();

        echo sprintf("HTTP/1.1 %d %s\r\n", $statusCode, $statusText);

        foreach ($response->headers() as $name => $header) {
            echo sprintf("%s: %s\r\n", $header->getName(), $header->getValueLine());
        }

        echo "\r\n";

        // Use body from response object, fallback to ob output
        $responseBody = $response->getBody();
        if (empty($responseBody) && !empty($bodyOutput)) {
            $responseBody = $bodyOutput;
        }

        if (is_string($responseBody)) {
            $capturedBodyContent = $responseBody;
            echo $responseBody;
        }
    } else {
        if (is_string($bodyOutput)) {
            echo $bodyOutput;
            $capturedBodyContent = $bodyOutput;
        }
    }

    fwrite(STDERR, "[ddless] Request handling completed, status: " . $statusCode . "\n");

    if ($capturedBodyContent === null) {
        $capturedBodyContent = $response->getBody() ?? '';
    }

    // Snapshot Generation
    $executionFinishedAt = microtime(true);
    $durationMs = ($executionFinishedAt - $executionStartedAt) * 1000;
    $memoryPeakBytes = memory_get_peak_usage(true);

    $rawInput = $GLOBALS['__DDLESS_RAW_INPUT__'] ?? '';
    $contentType = $_SERVER['CONTENT_TYPE'] ?? null;

    // Build request headers from CI4 request
    $requestHeaders = [];
    foreach ($request->headers() as $name => $header) {
        $requestHeaders[$header->getName()] = $header->getValueLine();
    }

    $requestSummary = [
        'method' => $request->getMethod(),
        'path' => '/' . ltrim($request->getUri()->getPath(), '/'),
        'fullUrl' => (string)$request->getUri(),
        'headers' => ddless_normalize_headers($requestHeaders),
        'query' => ddless_normalize_value($request->getGet()),
        'input' => ddless_normalize_value($request->getPost()),
        'cookies' => ddless_normalize_value($request->getCookie()),
        'rawBody' => ddless_prepare_body_payload($rawInput, $contentType),
    ];

    // Build response headers
    $snapshotResponseHeaders = [];
    foreach ($response->headers() as $name => $header) {
        $snapshotResponseHeaders[$header->getName()] = $header->getValueLine();
    }

    $responseBodyPayload = ddless_prepare_body_payload(
        is_string($capturedBodyContent) ? $capturedBodyContent : '',
        $responseContentType ?: null,
    );

    $routeInfo = null;
    $controllerInfo = null;
    $hitBreakpoints = [];
    $callStack = [];

    // Extract route and controller info from CI4 router
    try {
        $router = \Config\Services::router();
        $controllerName = $router->controllerName();
        $methodName = $router->methodName();
        $matchedRoute = $router->getMatchedRoute();

        if ($controllerName) {
            $routeInfo = [
                'uri' => $requestSummary['path'],
                'name' => is_array($matchedRoute) ? ($matchedRoute[0] ?? null) : null,
                'methods' => [$request->getMethod()],
                'action' => $controllerName . '::' . $methodName,
                'parameters' => ddless_normalize_value($router->params() ?? []),
            ];

            $controllerClass = ltrim($controllerName, '\\');
            $controllerFile = null;
            $startLine = null;
            $endLine = null;

            if (class_exists($controllerClass) && method_exists($controllerClass, $methodName)) {
                $reflection = new \ReflectionMethod($controllerClass, $methodName);
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
                    'method' => $methodName,
                    'startLine' => $startLine,
                    'endLine' => $endLine,
                ];

                if (!empty($hitBreakpoints[$relativeControllerPath])) {
                    sort($hitBreakpoints[$relativeControllerPath]);
                }
            }

            $callStack[] = [
                'label' => $routeInfo['action'],
                'file' => $controllerInfo['file'] ?? null,
                'line' => $controllerInfo['startLine'] ?? null,
            ];
        }
    } catch (\Throwable $exception) {
        // ignore route inspection errors
    }

    $variables = [
        'routeParameters' => $routeInfo['parameters'] ?? [],
        'requestInput' => $requestSummary['input'],
        'query' => $requestSummary['query'],
    ];

    // Extract session
    try {
        $session = \Config\Services::session();
        if ($session) {
            $variables['session'] = ddless_normalize_value($_SESSION ?? []);
        }
    } catch (\Throwable $exception) {
        // session may not be configured
    }

    $logs = ddless_collect_logs($logDir);

    $snapshot = [
        'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        'sessionId' => null,
        'request' => $requestSummary,
        'response' => [
            'status' => $statusCode,
            'statusText' => ddless_response_status_text($statusCode),
            'headers' => ddless_normalize_headers($snapshotResponseHeaders),
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

    $rootSnapshotPath = $ddlessDirectory . DIRECTORY_SEPARATOR . 'last_execution.json';
    @file_put_contents($rootSnapshotPath, $encodedSnapshot);

    fwrite(STDERR, "[ddless] Request completed successfully\n");
} finally {
    if ($phpWrapperReplaced) {
        @stream_wrapper_restore('php');
    }
    fwrite(STDERR, "[ddless] PHP execution complete\n");
}
