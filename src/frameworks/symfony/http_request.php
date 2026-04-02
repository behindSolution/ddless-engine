<?php
/*
 * DDLess - PHP Debug Engine
 *
 * @author    Jefferson T.S
 * @copyright 2025-2026 DDLess
 * @license   Proprietary
 *
 * Symfony HTTP request handler. Bootstraps the Symfony application kernel,
 * processes the captured HTTP request through the full middleware/event pipeline,
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

function ddless_collect_logs(string $logDir): array
{
    // Symfony uses var/log/dev.log (or prod.log depending on env)
    $candidates = ['dev.log', 'prod.log', 'test.log'];
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

// Symfony Bootstrap
// Path from .ddless/frameworks/symfony/ to project root
$projectRoot = !empty($GLOBALS['__DDLESS_PLAYGROUND__']) && defined('DDLESS_PROJECT_ROOT')
    ? DDLESS_PROJECT_ROOT
    : dirname(__DIR__, 3);
$ddlessDirectory = dirname(__DIR__, 2);

require_once $projectRoot . '/vendor/autoload.php';

if (!class_exists('Symfony\Component\HttpKernel\Kernel')) {
    fwrite(STDERR, "Symfony HttpKernel not found. Run composer install inside the project." . PHP_EOL);
    exit(1);
}

// Load .env variables (Symfony normally does this via autoload_runtime.php)
if (class_exists('Symfony\Component\Dotenv\Dotenv')) {
    $envFile = $projectRoot . '/.env';
    if (file_exists($envFile)) {
        (new \Symfony\Component\Dotenv\Dotenv())->bootEnv($envFile);
    }
}

// Request Processing
$breakpoints = ddless_read_breakpoints($ddlessDirectory);
$executionStartedAt = microtime(true);
$logDir = $projectRoot . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'log';

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

// Resolve the Kernel class
$kernelClass = null;
$kernelCandidates = [
    'App\\Kernel',
    'App\\HttpKernel',
];

foreach ($kernelCandidates as $candidate) {
    if (class_exists($candidate)) {
        $kernelClass = $candidate;
        break;
    }
}

if ($kernelClass === null) {
    fwrite(STDERR, "[ddless] Symfony Kernel class not found. Tried: " . implode(', ', $kernelCandidates) . "\n");
    exit(1);
}

$env = $_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? 'dev';
$debug = (bool)($_SERVER['APP_DEBUG'] ?? $_ENV['APP_DEBUG'] ?? true);

$kernel = new $kernelClass($env, $debug);

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
    $request = \Symfony\Component\HttpFoundation\Request::createFromGlobals();

    // Override uploaded files with test-mode UploadedFile instances
    if (!empty($_FILES) && class_exists('Symfony\Component\HttpFoundation\File\UploadedFile')) {
        $convertedFiles = [];
        foreach ($_FILES as $key => $file) {
            if (is_array($file['tmp_name'] ?? null)) {
                $convertedFiles[$key] = [];
                foreach ($file['tmp_name'] as $i => $tmpName) {
                    if (is_string($tmpName) && is_file($tmpName)) {
                        $convertedFiles[$key][$i] = new \Symfony\Component\HttpFoundation\File\UploadedFile(
                            $tmpName,
                            $file['name'][$i] ?? basename($tmpName),
                            $file['type'][$i] ?? 'application/octet-stream',
                            $file['error'][$i] ?? UPLOAD_ERR_OK,
                            true
                        );
                    }
                }
            } else {
                $tmpName = $file['tmp_name'] ?? null;
                if (is_string($tmpName) && is_file($tmpName)) {
                    $convertedFiles[$key] = new \Symfony\Component\HttpFoundation\File\UploadedFile(
                        $tmpName,
                        $file['name'] ?? basename($tmpName),
                        $file['type'] ?? 'application/octet-stream',
                        $file['error'] ?? UPLOAD_ERR_OK,
                        true
                    );
                }
            }
        }
        if (!empty($convertedFiles)) {
            $request->files->replace($convertedFiles);
        }
    }

    fwrite(STDERR, "[ddless] Starting Symfony request handling...\n");
    $response = $kernel->handle($request);
    fwrite(STDERR, "[ddless] Request handling completed, status: " . $response->getStatusCode() . "\n");

    $responseContentType = $response->headers->get('Content-Type');
    $responseBodyContent = $response->getContent();
    $capturedBodyContent = null;

    $isDdlessMode = php_sapi_name() === 'cli';

    if ($isDdlessMode) {
        $statusCode = $response->getStatusCode();
        $statusText = ddless_response_status_text($statusCode) ?? 'OK';

        echo sprintf("HTTP/1.1 %d %s\r\n", $statusCode, $statusText);

        foreach ($response->headers->allPreserveCase() as $name => $values) {
            if (!is_array($values)) {
                $values = [$values];
            }
            foreach ($values as $value) {
                echo sprintf("%s: %s\r\n", $name, $value);
            }
        }

        echo "\r\n";

        ob_start();
        $response->sendContent();
        $bodyOutput = ob_get_clean();

        if ($bodyOutput === '' || $bodyOutput === null) {
            $bodyOutput = $responseBodyContent;
        }

        if (is_string($bodyOutput)) {
            $capturedBodyContent = $bodyOutput;
            echo $bodyOutput;
        } elseif ($bodyOutput !== null) {
            $capturedBodyContent = (string)$bodyOutput;
            echo $capturedBodyContent;
        }
    } else {
        ddless_safe_send_response($response);
        if (is_string($responseBodyContent)) {
            $capturedBodyContent = $responseBodyContent;
        }
    }

    if ($capturedBodyContent === null && is_string($responseBodyContent)) {
        $capturedBodyContent = $responseBodyContent;
    }

    // Snapshot Generation
    $executionFinishedAt = microtime(true);
    $durationMs = ($executionFinishedAt - $executionStartedAt) * 1000;
    $memoryPeakBytes = memory_get_peak_usage(true);

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

    // Extract route and controller info from Symfony's request attributes
    try {
        $routeName = $request->attributes->get('_route');
        $controllerAttr = $request->attributes->get('_controller');
        $routeParams = $request->attributes->get('_route_params', []);

        if ($controllerAttr !== null) {
            $routeInfo = [
                'uri' => $request->getPathInfo(),
                'name' => $routeName,
                'methods' => [$request->getMethod()],
                'action' => is_string($controllerAttr) ? $controllerAttr : (is_array($controllerAttr) ? implode('::', $controllerAttr) : get_class($controllerAttr)),
                'parameters' => ddless_normalize_value($routeParams),
            ];

            // Resolve controller class and method for reflection
            $controllerClass = null;
            $controllerMethod = null;
            $controllerFile = null;
            $startLine = null;
            $endLine = null;

            if (is_string($controllerAttr)) {
                if (strpos($controllerAttr, '::') !== false) {
                    [$controllerClass, $controllerMethod] = explode('::', $controllerAttr, 2);
                } elseif (class_exists($controllerAttr)) {
                    $controllerClass = $controllerAttr;
                    $controllerMethod = '__invoke';
                }
            } elseif (is_array($controllerAttr) && count($controllerAttr) === 2) {
                $controllerClass = is_object($controllerAttr[0]) ? get_class($controllerAttr[0]) : $controllerAttr[0];
                $controllerMethod = $controllerAttr[1];
            }

            if ($controllerClass && $controllerMethod && class_exists($controllerClass) && method_exists($controllerClass, $controllerMethod)) {
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

            $actionLabel = $routeInfo['action'];
            $callStack[] = [
                'label' => $actionLabel,
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

    // Extract user from Symfony Security
    try {
        $container = $kernel->getContainer();
        if ($container->has('security.token_storage')) {
            $tokenStorage = $container->get('security.token_storage');
            $token = $tokenStorage->getToken();
            if ($token !== null) {
                $user = $token->getUser();
                if ($user !== null) {
                    if (is_object($user) && method_exists($user, 'getUserIdentifier')) {
                        $variables['user'] = ddless_normalize_value([
                            'identifier' => $user->getUserIdentifier(),
                            'roles' => $token->getRoleNames(),
                            'class' => get_class($user),
                        ]);
                    } elseif (is_string($user)) {
                        $variables['user'] = $user;
                    } else {
                        $variables['user'] = ddless_normalize_value($user);
                    }
                }
            }
        }
    } catch (\Throwable $exception) {
        // security component may not be installed
    }

    // Extract session
    try {
        if ($request->hasSession()) {
            $session = $request->getSession();
            $variables['session'] = ddless_normalize_value($session->all());
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
            'status' => $response->getStatusCode(),
            'statusText' => ddless_response_status_text($response->getStatusCode()),
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

    $rootSnapshotPath = $ddlessDirectory . DIRECTORY_SEPARATOR . 'last_execution.json';
    @file_put_contents($rootSnapshotPath, $encodedSnapshot);

    $kernel->terminate($request, $response);
    fwrite(STDERR, "[ddless] Request terminated successfully\n");
} finally {
    if ($phpWrapperReplaced) {
        @stream_wrapper_restore('php');
    }
    fwrite(STDERR, "[ddless] PHP execution complete\n");
}
