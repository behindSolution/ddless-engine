<?php
/*
 * DDLess - PHP Debug Engine
 *
 * @author    Jefferson T.S
 * @copyright 2025-2026 DDLess
 * @license   Proprietary
 *
 * Laravel task runner. Bootstraps the Laravel application and executes
 * user-written code as an Artisan command with output methods (info, warn,
 * error, json, table) and interactive prompts (ask, confirm, choice).
 */

declare(strict_types=1);

if (!defined('DDLESS_PROJECT_ROOT')) {
    define('DDLESS_PROJECT_ROOT', realpath(dirname(__DIR__, 3)));
}

define('DDLESS_TASK_RUNNER', true);

ini_set('display_errors', 'stderr');
ini_set('log_errors', '0');
error_reporting(E_ALL);

$taskStartTime = microtime(true);


function ddless_task_emit(string $type, array $data): void
{
    $data['type'] = $type;
    $data['timestamp'] = microtime(true);
    echo "__DDLESS_TASK_OUTPUT__:" . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    @fflush(STDOUT);
}

function ddless_task_done(bool $ok, float $startTime, ?string $error = null): void
{
    $data = [
        'ok' => $ok,
        'durationMs' => round((microtime(true) - $startTime) * 1000, 2),
    ];
    if ($error !== null) {
        $data['error'] = $error;
    }
    echo "__DDLESS_TASK_DONE__:" . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
}

function ddless_clean_php_code(string $code): string
{
    $code = preg_replace('/^<\?php\s*/i', '', trim($code));
    $code = preg_replace('/^<\?=?\s*/', '', $code);
    $code = preg_replace('/\s*\?>\s*$/', '', $code);
    return trim($code);
}

function ddless_prompt_with_rule(string $promptType, string $message, array $extra, ?callable $rule): string
{
    $validationError = null;

    while (true) {
        $data = array_merge([
            'promptType' => $promptType,
            'message' => $message,
        ], $extra);

        if ($validationError !== null) {
            $data['validationError'] = $validationError;
        }

        ddless_task_emit('prompt', $data);
        $input = trim((string)fgets(STDIN));

        if ($rule === null) {
            return $input;
        }

        $result = $rule($input);
        if ($result === true || $result === null) {
            return $input;
        }

        // Rule returned error message string
        $validationError = is_string($result) ? $result : 'Invalid value.';
    }
}

class DdlessChart
{
    private string $chartType = 'line';
    private array $labels = [];
    private array $datasets = [];

    public function line(): self
    {
        $this->chartType = 'line';
        return $this;
    }

    public function bar(): self
    {
        $this->chartType = 'bar';
        return $this;
    }

    public function pie(): self
    {
        $this->chartType = 'pie';
        return $this;
    }

    public function doughnut(): self
    {
        $this->chartType = 'doughnut';
        return $this;
    }

    public function area(): self
    {
        $this->chartType = 'area';
        return $this;
    }

    public function labels(array $labels): self
    {
        $this->labels = $labels;
        return $this;
    }

    public function dataset(string $label, array $data): self
    {
        $this->datasets[] = ['label' => $label, 'data' => $data];
        return $this;
    }

    public function render(): void
    {
        ddless_task_emit('chart', [
            'chartType' => $this->chartType,
            'labels' => $this->labels,
            'datasets' => $this->datasets,
        ]);
    }
}

$inputJson = $GLOBALS['__DDLESS_TASK_INPUT__'] ?? null;
if (!$inputJson) {
    ddless_task_emit('error', ['message' => 'Task input not found.']);
    ddless_task_done(false, $taskStartTime, 'Task input not found.');
    exit(1);
}

$input = json_decode($inputJson, true);
if (!is_array($input)) {
    ddless_task_emit('error', ['message' => 'Invalid task input JSON.']);
    ddless_task_done(false, $taskStartTime, 'Invalid task input JSON.');
    exit(1);
}

$userCode = $input['code'] ?? '';
$imports = $input['imports'] ?? [];
$GLOBALS['__DDLESS_IMPORTS_PATH__'] = $input['importsPath'] ?? null;

if (trim($userCode) === '') {
    ddless_task_emit('warn', ['message' => 'No code to execute.']);
    ddless_task_done(true, $taskStartTime);
    exit(0);
}

// WORKAROUND: FORCE FILE-BASED SERVICES
// The task runner may execute outside the Docker network (e.g. WSL host running
// Sail). Docker service hostnames like "redis", "mysql" are unreachable from
// outside the container, causing infinite connection timeouts during bootstrap.
// Force file/sync drivers so Laravel never attempts external service connections
// during the bootstrap phase. User code can still override these if needed.

if (!extension_loaded('redis')) {
    if (!class_exists('Redis', false)) {
        class Redis {
            const REDIS_NOT_FOUND = 0;
            const REDIS_STRING = 1;
            const REDIS_SET = 2;
            const REDIS_LIST = 3;
            const REDIS_ZSET = 4;
            const REDIS_HASH = 5;
            const ATOMIC = 0;
            const MULTI = 1;
            const PIPELINE = 2;
            const OPT_SERIALIZER = 1;
            const OPT_PREFIX = 2;
            const SERIALIZER_NONE = 0;
            const SERIALIZER_PHP = 1;
            const OPT_READ_TIMEOUT = 3;
            const SERIALIZER_IGBINARY = 2;
            const SERIALIZER_MSGPACK = 3;
            const SERIALIZER_JSON = 4;
            const OPT_SCAN = 4;
            const SCAN_RETRY = 1;
            const SCAN_NORETRY = 0;

            public function connect($host, $port = 6379, $timeout = 0) {
                throw new \RuntimeException('[ddless] Cannot connect to Redis: extension not available');
            }
            public function pconnect($host, $port = 6379, $timeout = 0) {
                throw new \RuntimeException('[ddless] Cannot connect to Redis: extension not available');
            }
            public function auth($password) { return false; }
            public function select($db) { return false; }
            public function ping() { return false; }
            public function get($key) { return false; }
            public function set($key, $value, $timeout = null) { return false; }
            public function setex($key, $ttl, $value) { return false; }
            public function del($key) { return 0; }
            public function exists($key) { return false; }
            public function __call($method, $args) { return false; }
            public static function __callStatic($method, $args) { return false; }
        }

        if (!class_exists('RedisException', false)) {
            class RedisException extends \Exception {}
        }
    }
}

// Always force file-based cache/session/queue — task runner doesn't need Redis
putenv('CACHE_DRIVER=file');
putenv('CACHE_STORE=file');
putenv('SESSION_DRIVER=file');
putenv('QUEUE_CONNECTION=sync');
putenv('REDIS_CLIENT=predis');

$_ENV['CACHE_DRIVER'] = 'file';
$_ENV['CACHE_STORE'] = 'file';
$_ENV['SESSION_DRIVER'] = 'file';
$_ENV['QUEUE_CONNECTION'] = 'sync';
$_ENV['REDIS_CLIENT'] = 'predis';

$_SERVER['CACHE_DRIVER'] = 'file';
$_SERVER['CACHE_STORE'] = 'file';
$_SERVER['SESSION_DRIVER'] = 'file';
$_SERVER['QUEUE_CONNECTION'] = 'sync';
$_SERVER['REDIS_CLIENT'] = 'predis';

$composerAutoload = DDLESS_PROJECT_ROOT . '/vendor/autoload.php';
if (!file_exists($composerAutoload)) {
    ddless_task_emit('error', ['message' => 'Composer autoload not found at: ' . $composerAutoload]);
    ddless_task_done(false, $taskStartTime, 'Composer autoload not found.');
    exit(1);
}

require $composerAutoload;

$laravelBootstrap = DDLESS_PROJECT_ROOT . '/bootstrap/app.php';
if (!file_exists($laravelBootstrap)) {
    ddless_task_emit('error', ['message' => 'Laravel bootstrap not found at: ' . $laravelBootstrap]);
    ddless_task_done(false, $taskStartTime, 'Laravel bootstrap not found.');
    exit(1);
}

$noRedis = !extension_loaded('redis');

try {
    $app = require $laravelBootstrap;

    if ($noRedis && isset($app['config'])) {
        $app['config']->set('cache.default', 'file');
        $app['config']->set('session.driver', 'file');
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('database.redis.client', 'predis');
    }

    $kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();

    if ($noRedis) {
        $app['config']->set('cache.default', 'file');
        $app['config']->set('session.driver', 'file');
        $app['config']->set('queue.default', 'sync');
    }
} catch (Throwable $e) {
    ddless_task_emit('error', ['message' => 'Failed to bootstrap Laravel: ' . $e->getMessage()]);
    ddless_task_done(false, $taskStartTime, 'Failed to bootstrap Laravel: ' . $e->getMessage());
    exit(1);
}

class DdlessTaskRunnerCommand extends \Illuminate\Console\Command
{
    protected $signature = 'ddless:task-runner';
    protected $description = 'DDLess Task Runner';

    private $__progressId = null;
    private $__progressCurrent = 0;
    private $__progressTotal = 0;

    public function info($string, $verbosity = null)
    {
        ddless_task_emit('info', ['message' => $string]);
    }

    public function error($string, $verbosity = null)
    {
        ddless_task_emit('error', ['message' => $string]);
    }

    public function warn($string, $verbosity = null)
    {
        ddless_task_emit('warn', ['message' => $string]);
    }

    public function line($string, $style = null, $verbosity = null)
    {
        ddless_task_emit('line', ['message' => $string]);
    }

    public function comment($string, $verbosity = null)
    {
        ddless_task_emit('comment', ['message' => $string]);
    }

    public function newLine($count = 1)
    {
        ddless_task_emit('newline', ['count' => $count]);
        return $this;
    }

    public function alert($string, $verbosity = null)
    {
        ddless_task_emit('alert', ['message' => (string) $string]);
    }

    public function listing(array $items)
    {
        ddless_task_emit('listing', ['items' => array_values(array_map('strval', $items))]);
    }

    public function hr()
    {
        ddless_task_emit('hr', []);
    }

    public function clipboard($text)
    {
        ddless_task_emit('clipboard', ['text' => (string) $text]);
    }

    public function link($url, $label = '')
    {
        ddless_task_emit('link', ['url' => (string) $url, 'label' => $label ?: (string) $url]);
    }

    public function table($headers, $rows, $tableStyle = 'default', array $columnStyles = [])
    {
        $plainRows = [];
        if ($rows instanceof \Traversable) {
            $rows = iterator_to_array($rows);
        }
        foreach ($rows as $row) {
            if ($row instanceof \Traversable) {
                $row = iterator_to_array($row);
            }
            if (is_object($row)) {
                $row = (array) $row;
            }
            $plainRows[] = array_values(array_map('strval', $row));
        }

        $plainHeaders = array_values(array_map('strval', $headers));

        ddless_task_emit('table', [
            'headers' => $plainHeaders,
            'rows' => $plainRows,
        ]);
    }

    public function json($data)
    {
        ddless_task_emit('json', ['data' => $data]);
    }

    public function chart(): DdlessChart
    {
        return new DdlessChart();
    }

    public function spin(callable $callback, string $label = 'Processing...')
    {
        ddless_task_emit('spin', ['label' => $label, 'state' => 'start']);
        try {
            $result = $callback();
            ddless_task_emit('spin', ['label' => $label, 'state' => 'done']);
            return $result;
        } catch (\Throwable $e) {
            ddless_task_emit('spin', ['label' => $label, 'state' => 'error', 'message' => $e->getMessage()]);
            throw $e;
        }
    }

    public function time(string $label, callable $callback)
    {
        $start = microtime(true);
        $result = $callback();
        $elapsed = round((microtime(true) - $start) * 1000, 2);
        ddless_task_emit('time', ['label' => $label, 'durationMs' => $elapsed]);
        return $result;
    }

    public function progress(int $total, string $label = ''): self
    {
        $this->__progressId = 'prog_' . uniqid();
        $this->__progressCurrent = 0;
        $this->__progressTotal = $total;

        ddless_task_emit('progress', [
            'progressId' => $this->__progressId,
            'current' => 0,
            'total' => $total,
            'percent' => 0,
            'label' => $label,
        ]);

        return $this;
    }

    public function advance(int $step = 1, ?string $label = null): self
    {
        if (!$this->__progressId) return $this;

        $this->__progressCurrent = min($this->__progressCurrent + $step, $this->__progressTotal);
        $percent = $this->__progressTotal > 0
            ? round(($this->__progressCurrent / $this->__progressTotal) * 100, 1)
            : 0;

        ddless_task_emit('progress', [
            'progressId' => $this->__progressId,
            'current' => $this->__progressCurrent,
            'total' => $this->__progressTotal,
            'percent' => $percent,
            'label' => $label,
        ]);

        return $this;
    }

    public function ask($question, $default = null, ?callable $rule = null)
    {
        $input = ddless_prompt_with_rule('ask', (string) $question, [
            'promptDefault' => (string) ($default ?? ''),
        ], $rule);
        return $input !== '' ? $input : ($default ?? '');
    }

    public function secret($question, $fallback = true, ?callable $rule = null)
    {
        $input = ddless_prompt_with_rule('secret', (string) $question, [], $rule);
        return $input;
    }

    public function confirm($question, $default = false, ?callable $rule = null)
    {
        $input = ddless_prompt_with_rule('confirm', (string) $question, [
            'promptDefault' => $default ? 'true' : 'false',
        ], $rule);
        return $input === 'true';
    }

    public function choice($question, $choices, $default = null, $attempts = null, $multiple = false, ?callable $rule = null)
    {
        if ($choices instanceof \Traversable) {
            $choices = iterator_to_array($choices);
        }
        $input = ddless_prompt_with_rule('choice', (string) $question, [
            'promptOptions' => array_values((array) $choices),
            'promptDefault' => $default ?? $choices[0] ?? '',
        ], $rule);
        return $input;
    }

    public function anticipate($question, $choices, $default = null, ?callable $rule = null)
    {
        if ($choices instanceof \Traversable) {
            $choices = iterator_to_array($choices);
        }
        $input = ddless_prompt_with_rule('anticipate', (string) $question, [
            'promptOptions' => array_values(array_map('strval', (array) $choices)),
            'promptDefault' => (string) ($default ?? ''),
        ], $rule);
        return $input !== '' ? $input : ($default ?? '');
    }

    public function date(string $question, ?string $default = null, ?callable $rule = null): string
    {
        $input = ddless_prompt_with_rule('date', $question, [
            'promptDefault' => $default ?? '',
        ], $rule);
        return $input !== '' ? $input : ($default ?? '');
    }

    public function datetime(string $question, ?string $default = null, ?callable $rule = null): string
    {
        $input = ddless_prompt_with_rule('datetime', $question, [
            'promptDefault' => $default ?? '',
        ], $rule);
        return $input !== '' ? $input : ($default ?? '');
    }

    public function import(string $filename, string $delimiter = ',', bool $headers = true, string $encoding = 'auto'): \Generator
    {
        $importsPath = $GLOBALS['__DDLESS_IMPORTS_PATH__'] ?? null;
        if (!$importsPath) {
            throw new \RuntimeException("No imports directory configured.");
        }

        $filePath = rtrim($importsPath, '/\\') . DIRECTORY_SEPARATOR . basename($filename);
        if (!is_file($filePath)) {
            throw new \RuntimeException("Import file not found: {$filename}");
        }

        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            throw new \RuntimeException("Failed to open import file: {$filename}");
        }

        try {
            $encodingDetected = null;
            if ($encoding === 'auto') {
                $sample = fread($handle, 8192);
                if ($sample === false || $sample === '') {
                    return;
                }
                if (str_starts_with($sample, "\xEF\xBB\xBF")) {
                    $sample = substr($sample, 3);
                    $encodingDetected = 'UTF-8';
                } else {
                    $detected = mb_detect_encoding($sample, ['UTF-8', 'Windows-1252', 'ISO-8859-1'], true);
                    $encodingDetected = $detected ?: 'UTF-8';
                }
                rewind($handle);
                $bom = fread($handle, 3);
                if ($bom !== "\xEF\xBB\xBF") {
                    rewind($handle);
                }
            } else {
                $encodingDetected = $encoding;
            }

            $needsConversion = $encodingDetected !== null
                && $encodingDetected !== 'UTF-8'
                && $encodingDetected !== 'utf-8';

            $headerRow = null;
            if ($headers) {
                $firstLine = fgetcsv($handle, 0, $delimiter);
                if ($firstLine === false || $firstLine === null) {
                    return;
                }
                if ($needsConversion) {
                    $firstLine = array_map(fn($v) => mb_convert_encoding((string) $v, 'UTF-8', $encodingDetected), $firstLine);
                }
                $headerRow = $firstLine;
            }

            while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
                if ($row === null) continue;
                if ($needsConversion) {
                    $row = array_map(fn($v) => mb_convert_encoding((string) $v, 'UTF-8', $encodingDetected), $row);
                }
                if ($headerRow !== null) {
                    // Pad row to match header count or trim excess
                    $row = array_pad($row, count($headerRow), '');
                    yield array_combine($headerRow, array_slice($row, 0, count($headerRow)));
                } else {
                    yield $row;
                }
            }
        } finally {
            fclose($handle);
        }
    }

    public function handle()
    {
        // This method is dynamically replaced at runtime
    }
}

try {
    $command = new DdlessTaskRunnerCommand();
    $command->setLaravel($app);

    $useStatements = '';
    foreach ($imports as $import) {
        $import = trim($import);
        if ($import !== '') {
            $useStatements .= "use {$import};\n";
        }
    }

    $cleanedCode = ddless_clean_php_code($userCode);

    $evalCode = $useStatements . "\n" . $cleanedCode;

    $closure = \Closure::bind(function () use ($evalCode) {
        return eval($evalCode);
    }, $command, DdlessTaskRunnerCommand::class);

    $closure();

    ddless_task_done(true, $taskStartTime);
} catch (Throwable $e) {
    $isEvalError = str_contains($e->getFile(), "eval()'d code");

    if ($isEvalError) {
        ddless_task_emit('error', [
            'message' => $e->getMessage(),
        ]);
        ddless_task_done(false, $taskStartTime, $e->getMessage());
        exit(1);
    }

    // Application exception — the code ran but the app threw
    $exceptionData = [
        'exceptionClass' => get_class($e),
        'message' => $e->getMessage(),
        'exceptionCode' => $e->getCode(),
        'exceptionFile' => $e->getFile(),
        'exceptionLine' => $e->getLine(),
    ];

    if (method_exists($e, 'errors')) {
        $exceptionData['errors'] = $e->errors();
    }
    if (method_exists($e, 'getStatusCode')) {
        $exceptionData['statusCode'] = $e->getStatusCode();
    }

    ddless_task_emit('exception', $exceptionData);
    ddless_task_done(true, $taskStartTime);
}
