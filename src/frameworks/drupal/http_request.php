<?php
/*
 * DDLess - PHP Debug Engine
 *
 * @author    Jefferson T.S
 * @copyright 2025-2026 DDLess
 * @license   Proprietary
 *
 * Drupal 9/10/11 HTTP request handler. Bootstraps the DrupalKernel,
 * processes the captured HTTP request through the full middleware pipeline,
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

        public function stream_tell() { return $this->position; }

        public function stream_seek($offset, $whence = SEEK_SET)
        {
            $dataSource = $this->isInput ? ($GLOBALS['__DDLESS_RAW_INPUT__'] ?? '') : $this->buffer;
            $length = strlen($dataSource);
            switch ($whence) {
                case SEEK_SET: $target = $offset; break;
                case SEEK_CUR: $target = $this->position + $offset; break;
                case SEEK_END: $target = $length + $offset; break;
                default: return false;
            }
            if ($target < 0) return false;
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
            if ($this->isInput) return strlen($data);
            $len = strlen($data);
            $before = substr($this->buffer, 0, $this->position);
            $after = substr($this->buffer, $this->position + $len);
            $this->buffer = $before . $data . $after;
            $this->position += $len;
            return $len;
        }

        public function stream_truncate(int $newSize)
        {
            if ($this->isInput) return false;
            if ($newSize < strlen($this->buffer)) {
                $this->buffer = substr($this->buffer, 0, $newSize);
            } else {
                $this->buffer = str_pad($this->buffer, $newSize, "\0");
            }
            if ($this->position > $newSize) $this->position = $newSize;
            return true;
        }
    }
}

function ddless_normalize_relative_path(string $path): string
{
    $normalized = str_replace('\\', '/', $path);
    $root = defined('DDLESS_PROJECT_ROOT') ? str_replace('\\', '/', (string)DDLESS_PROJECT_ROOT) : null;
    if ($root && str_starts_with($normalized, $root)) {
        return ltrim(substr($normalized, strlen($root)), '/');
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
            if (is_object($value)) return '[object ' . get_class($value) . ']';
            return is_scalar($value) ? $value : '[max-depth]';
        }
        if (is_null($value) || is_bool($value) || is_int($value) || is_float($value)) return $value;
        if (is_string($value)) {
            return strlen($value) > 10000 ? substr($value, 0, 10000) . "\xe2\x80\xa6" : $value;
        }
        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $item) { $result[$key] = ddless_normalize_value($item, $depth + 1); }
            return $result;
        }
        if ($value instanceof \JsonSerializable) {
            try { return ddless_normalize_value($value->jsonSerialize(), $depth + 1); }
            catch (\Throwable $e) { return '[object ' . get_class($value) . ']'; }
        }
        if (method_exists($value, 'toArray')) {
            try { return ddless_normalize_value($value->toArray(), $depth + 1); }
            catch (\Throwable $e) {}
        }
        if ($value instanceof \DateTimeInterface) return $value->format(\DateTimeInterface::ATOM);
        if (is_object($value)) return '[object ' . get_class($value) . ']';
        if (is_resource($value)) return '[resource ' . (get_resource_type($value) ?: 'resource') . ']';
        return (string)$value;
    }
}

function ddless_read_breakpoints(string $ddlessDir): array
{
    $path = $ddlessDir . DIRECTORY_SEPARATOR . 'breakpoints.json';
    if (!is_file($path)) return [];
    try {
        $raw = file_get_contents($path);
        if ($raw === false) return [];
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) return [];
        $result = [];
        foreach ($decoded as $file => $lines) {
            if (!is_array($lines)) continue;
            $normalizedFile = ddless_normalize_relative_path((string)$file);
            $normalizedLines = [];
            foreach ($lines as $line) { if (is_numeric($line)) $normalizedLines[] = (int)$line; }
            if ($normalizedFile !== '' && $normalizedLines) {
                $result[$normalizedFile] = array_values(array_unique($normalizedLines));
            }
        }
        return $result;
    } catch (\Throwable $e) {
        fwrite(STDERR, "[ddless] Failed to read breakpoints: {$e->getMessage()}\n");
        return [];
    }
}

function ddless_collect_logs(string $logDir): array
{
    // Drupal uses dblog by default (database), but syslog module writes to system log.
    // Check for any file-based logs in the configured directory.
    $candidates = ['drupal.log', 'error.log', 'php-error.log'];
    foreach ($candidates as $candidate) {
        $path = $logDir . DIRECTORY_SEPARATOR . $candidate;
        if (is_file($path) && is_readable($path)) {
            $handle = fopen($path, 'rb');
            if ($handle === false) continue;
            $newSize = filesize($path);
            if ($newSize > 32768) { fseek($handle, $newSize - 32768); fgets($handle); }
            $data = trim(stream_get_contents($handle) ?: '');
            fclose($handle);
            if ($data !== '') {
                $lines = preg_split("/\r?\n/", $data);
                return $lines ? array_slice(array_map('strval', $lines), -200) : [];
            }
        }
    }
    return [];
}

function ddless_encode_json($value): string
{
    $encoded = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return $encoded === false ? "{}" : $encoded;
}

function ddless_prepare_body_payload(?string $content, ?string $contentType): array
{
    $raw = $content ?? '';
    $encoding = 'utf8';
    $truncated = false;
    $isTextual = $contentType === null || preg_match('/json|text|xml|javascript|css|html|csv|urlencoded/i', $contentType);
    if (!$isTextual) {
        $encoding = 'base64';
        $prepared = base64_encode($raw);
    } else {
        if ($raw !== '' && function_exists('mb_check_encoding') && !mb_check_encoding($raw, 'UTF-8')) {
            $encoding = 'base64';
            $prepared = base64_encode($raw);
        } else {
            if (strlen($raw) > 131072) { $prepared = substr($raw, 0, 131072); $truncated = true; }
            else { $prepared = $raw; }
        }
    }
    return ['content' => $prepared, 'encoding' => $encoding, 'truncated' => $truncated];
}

function ddless_response_status_text(int $statusCode): ?string
{
    static $map = [
        100 => 'Continue', 101 => 'Switching Protocols',
        200 => 'OK', 201 => 'Created', 202 => 'Accepted', 204 => 'No Content',
        301 => 'Moved Permanently', 302 => 'Found', 303 => 'See Other', 304 => 'Not Modified',
        307 => 'Temporary Redirect', 308 => 'Permanent Redirect',
        400 => 'Bad Request', 401 => 'Unauthorized', 403 => 'Forbidden', 404 => 'Not Found',
        405 => 'Method Not Allowed', 409 => 'Conflict', 422 => 'Unprocessable Entity',
        429 => 'Too Many Requests',
        500 => 'Internal Server Error', 502 => 'Bad Gateway', 503 => 'Service Unavailable',
    ];
    return $map[$statusCode] ?? null;
}

// Drupal Bootstrap
$projectRoot = !empty($GLOBALS['__DDLESS_PLAYGROUND__']) && defined('DDLESS_PROJECT_ROOT')
    ? DDLESS_PROJECT_ROOT
    : dirname(__DIR__, 3);
$ddlessDirectory = dirname(__DIR__, 2);

if (!defined('DDLESS_PROJECT_ROOT')) {
    define('DDLESS_PROJECT_ROOT', $projectRoot);
}

// ═══════════════════════════════════════════════════════════════════════════════
// Proxy mode (php -S / cli-server): include index.php directly.
// Drupal's entry point handles bootstrap, routing, and response natively.
// ═══════════════════════════════════════════════════════════════════════════════

if (php_sapi_name() === 'cli-server') {
    register_shutdown_function(
        function () use ($projectRoot, $ddlessDirectory) {
            $statusCode = http_response_code() ?: 200;
            $responseHeaders = [];
            foreach (headers_list() as $headerLine) {
                $colonPos = strpos($headerLine, ':');
                if ($colonPos !== false) {
                    $responseHeaders[trim(substr($headerLine, 0, $colonPos))] = trim(substr($headerLine, $colonPos + 1));
                }
            }
            $snapshot = [
                'timestamp' => date('c'),
                'sessionId' => null,
                'request' => [
                    'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
                    'path' => parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/',
                    'fullUrl' => ($_SERVER['REQUEST_SCHEME'] ?? 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . ($_SERVER['REQUEST_URI'] ?? '/'),
                    'headers' => [], 'query' => $_GET ?? [], 'input' => $_POST ?? [], 'cookies' => $_COOKIE ?? [],
                ],
                'response' => [
                    'status' => $statusCode, 'statusText' => null,
                    'headers' => $responseHeaders,
                    'body' => ['content' => '', 'encoding' => 'utf8', 'truncated' => false],
                ],
                'context' => [
                    'route' => null, 'controller' => null,
                    'breakpoints' => [], 'hitBreakpoints' => [],
                    'variables' => [], 'callStack' => [],
                ],
                'metrics' => ['durationMs' => 0, 'memoryPeakBytes' => memory_get_peak_usage(true)],
                'logs' => [],
            ];
            $encoded = json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
            if (function_exists('ddless_get_session_dir')) {
                @file_put_contents(ddless_get_session_dir() . DIRECTORY_SEPARATOR . 'last_execution.json', $encoded);
            }
            @file_put_contents($ddlessDirectory . DIRECTORY_SEPARATOR . 'last_execution.json', $encoded);
            fwrite(STDERR, "__DDLESS_REQUEST_COMPLETE__\n");
        }
    );

    if (getenv('DDLESS_DEBUG_MODE') === 'true') {
        $debugModule = dirname(__DIR__, 2) . '/debug.php';
        if (file_exists($debugModule)) {
            require_once $debugModule;
            if (function_exists('ddless_register_stream_wrapper')) {
                ddless_register_stream_wrapper();
            }
        }
    }

    fwrite(STDERR, "[ddless] Starting Drupal request handling...\n");

    // Composer-based Drupal: web/index.php. Legacy: index.php at root.
    $webDir = $projectRoot . '/web';
    if (is_file($webDir . '/index.php')) {
        $indexFile = $webDir . '/index.php';
        chdir($webDir);
    } elseif (is_file($projectRoot . '/index.php')) {
        $indexFile = $projectRoot . '/index.php';
        chdir($projectRoot);
    } else {
        http_response_code(500);
        echo "DDLess: Drupal index.php not found (checked web/index.php and index.php).";
        exit(1);
    }

    require $indexFile;
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════════
// CLI mode (DDLess direct request): DrupalKernel bootstrap, handle, snapshot.
// ═══════════════════════════════════════════════════════════════════════════════

$breakpoints = ddless_read_breakpoints($ddlessDirectory);
$executionStartedAt = microtime(true);

// Composer-based Drupal: web/ is the docroot, autoload.php is at root.
$webDir = is_dir($projectRoot . '/web') ? $projectRoot . '/web' : $projectRoot;
chdir($webDir);

$autoloadFile = $projectRoot . '/autoload.php';
if (!is_file($autoloadFile)) {
    $autoloadFile = $projectRoot . '/vendor/autoload.php';
}
$autoloader = require_once $autoloadFile;

if (!class_exists('Drupal\Core\DrupalKernel')) {
    fwrite(STDERR, "[ddless] DrupalKernel not found. Run composer install.\n");
    exit(1);
}

$logDir = $projectRoot . DIRECTORY_SEPARATOR . 'sites' . DIRECTORY_SEPARATOR . 'default' . DIRECTORY_SEPARATOR . 'files' . DIRECTORY_SEPARATOR . 'logs';

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
    if (function_exists('ddless_register_stream_wrapper')) {
        ddless_register_stream_wrapper();
    }
}

// Pre-load instrumented files through the wrapper
if (getenv('DDLESS_DEBUG_MODE') === 'true' && !empty($GLOBALS['__DDLESS_INSTRUMENTED_CODE__'])) {
    foreach (array_keys($GLOBALS['__DDLESS_INSTRUMENTED_CODE__']) as $instrumentedPath) {
        if (is_file($instrumentedPath) && str_ends_with($instrumentedPath, '.php')) {
            include_once $instrumentedPath;
        }
    }
}

try {
    fwrite(STDERR, "[ddless] Starting Drupal request handling...\n");

    $request = \Symfony\Component\HttpFoundation\Request::createFromGlobals();
    $kernel = \Drupal\Core\DrupalKernel::createFromRequest($request, $autoloader, 'prod');
    $kernel->boot();

    $response = $kernel->handle($request);

    $statusCode = $response->getStatusCode();
    $responseContentType = $response->headers->get('Content-Type');

    ob_start();
    $response->sendContent();
    $capturedBodyContent = ob_get_clean();

    if ($capturedBodyContent === '' || $capturedBodyContent === null) {
        $capturedBodyContent = $response->getContent() ?: '';
    }

    $statusText = ddless_response_status_text($statusCode) ?? 'OK';

    echo sprintf("HTTP/1.1 %d %s\r\n", $statusCode, $statusText);
    foreach ($response->headers->allPreserveCase() as $name => $values) {
        if (!is_array($values)) $values = [$values];
        foreach ($values as $value) {
            echo sprintf("%s: %s\r\n", $name, $value);
        }
    }
    echo "\r\n";
    echo $capturedBodyContent;

    fwrite(STDERR, "[ddless] Request handling completed, status: " . $statusCode . "\n");

    // Snapshot Generation
    $executionFinishedAt = microtime(true);
    $durationMs = ($executionFinishedAt - $executionStartedAt) * 1000;

    $rawInput = $GLOBALS['__DDLESS_RAW_INPUT__'] ?? '';
    $contentType = $_SERVER['CONTENT_TYPE'] ?? null;

    $normalizedRequestHeaders = ddless_normalize_headers($request->headers->all() ?? []);
    $requestSummary = [
        'method' => $request->getMethod(),
        'path' => $request->getPathInfo(),
        'fullUrl' => $request->getUri(),
        'headers' => $normalizedRequestHeaders,
        'query' => ddless_normalize_value($request->query->all()),
        'input' => ddless_normalize_value($request->request->all()),
        'cookies' => ddless_normalize_value($request->cookies->all()),
        'rawBody' => ddless_prepare_body_payload($rawInput, $contentType),
    ];

    $snapshotResponseHeaders = ddless_normalize_headers($response->headers->allPreserveCase());
    $responseBodyPayload = ddless_prepare_body_payload(
        is_string($capturedBodyContent) ? $capturedBodyContent : '',
        is_string($responseContentType) ? $responseContentType : null,
    );

    $routeInfo = null;
    $controllerInfo = null;
    $hitBreakpoints = [];
    $callStack = [];

    // Extract route/controller from Drupal's routing system
    try {
        $routeMatch = $request->attributes->get('_route_object');
        $controllerAttr = $request->attributes->get('_controller');
        $routeName = $request->attributes->get('_route');

        if ($controllerAttr !== null) {
            $routeInfo = [
                'uri' => $requestSummary['path'],
                'name' => $routeName,
                'methods' => [$request->getMethod()],
                'action' => is_string($controllerAttr) ? $controllerAttr : (is_array($controllerAttr) ? implode('::', $controllerAttr) : ''),
                'parameters' => ddless_normalize_value($request->attributes->get('_raw_variables', [])),
            ];

            $controllerClass = null;
            $controllerMethod = null;

            if (is_string($controllerAttr)) {
                if (strpos($controllerAttr, '::') !== false) {
                    [$controllerClass, $controllerMethod] = explode('::', $controllerAttr, 2);
                } elseif (strpos($controllerAttr, ':') !== false) {
                    [$service, $controllerMethod] = explode(':', $controllerAttr, 2);
                    try {
                        $serviceObj = \Drupal::service($service);
                        $controllerClass = get_class($serviceObj);
                    } catch (\Throwable $e) {}
                }
            }

            if ($controllerClass && $controllerMethod && class_exists($controllerClass) && method_exists($controllerClass, $controllerMethod)) {
                $reflection = new \ReflectionMethod($controllerClass, $controllerMethod);
                $controllerFile = $reflection->getFileName() ?: null;
                $startLine = $reflection->getStartLine() ?: null;
                $endLine = $reflection->getEndLine() ?: null;

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
                    'label' => $routeInfo['action'],
                    'file' => $controllerInfo['file'] ?? null,
                    'line' => $controllerInfo['startLine'] ?? null,
                ];
            }
        }
    } catch (\Throwable $e) {}

    $variables = [
        'routeParameters' => $routeInfo['parameters'] ?? [],
        'requestInput' => $requestSummary['input'],
        'query' => $requestSummary['query'],
    ];

    // Extract current user
    try {
        $currentUser = \Drupal::currentUser();
        if ($currentUser && $currentUser->isAuthenticated()) {
            $variables['user'] = ddless_normalize_value([
                'uid' => $currentUser->id(),
                'name' => $currentUser->getAccountName(),
                'email' => $currentUser->getEmail(),
                'roles' => $currentUser->getRoles(),
            ]);
        }
    } catch (\Throwable $e) {}

    // Extract session
    try {
        if ($request->hasSession()) {
            $session = $request->getSession();
            $variables['session'] = ddless_normalize_value($session->all());
        }
    } catch (\Throwable $e) {}

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
            'memoryPeakBytes' => memory_get_peak_usage(true),
        ],
        'logs' => $logs,
    ];

    $encodedSnapshot = ddless_encode_json($snapshot) . "\n";
    if (function_exists('ddless_get_session_dir')) {
        @file_put_contents(ddless_get_session_dir() . DIRECTORY_SEPARATOR . 'last_execution.json', $encodedSnapshot);
    }
    @file_put_contents($ddlessDirectory . DIRECTORY_SEPARATOR . 'last_execution.json', $encodedSnapshot);

    $kernel->terminate($request, $response);
    fwrite(STDERR, "[ddless] Request completed successfully\n");
} finally {
    if ($phpWrapperReplaced) {
        @stream_wrapper_restore('php');
    }
    fwrite(STDERR, "__DDLESS_REQUEST_COMPLETE__\n");
}
