<?php
/*
 * DDLess - PHP Debug Engine
 *
 * @author    Jefferson T.S
 * @copyright 2025-2026 DDLess
 * @license   Proprietary
 *
 * CodeIgniter 4 task runner. Boots the CI4 application and executes
 * user-provided PHP code with access to models, services, and helpers.
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

define('DDLESS_PROJECT_ROOT', realpath(dirname(__DIR__, 3)));

$taskStartTime = microtime(true);

function ddless_task_emit(string $type, array $data): void {
    $data['type'] = $type;
    $data['timestamp'] = microtime(true);
    echo "__DDLESS_TASK_OUTPUT__:" . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    @fflush(STDOUT);
}

function ddless_task_done(bool $ok, float $startTime, ?string $error = null): void {
    $data = [
        'ok' => $ok,
        'durationMs' => round((microtime(true) - $startTime) * 1000, 2),
    ];
    if ($error !== null) {
        $data['error'] = $error;
    }
    echo "__DDLESS_TASK_DONE__:" . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
}

function ddless_clean_php_code(string $code): string {
    $code = trim($code);
    if (str_starts_with($code, '<?php')) {
        $code = substr($code, 5);
    } elseif (str_starts_with($code, '<?')) {
        $code = substr($code, 2);
    }
    $code = rtrim($code);
    if (str_ends_with($code, '?>')) {
        $code = substr($code, 0, -2);
    }
    return trim($code);
}

function ddless_prompt_with_rule(string $promptType, string $message, array $extra, ?callable $rule): string {
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

        $validationError = is_string($result) ? $result : 'Invalid value.';
    }
}

class DdlessChart {
    private string $chartType = 'line';
    private string $chartTitle = '';
    private array $labels = [];
    private array $datasets = [];

    public function title(string $title): self { $this->chartTitle = $title; return $this; }
    public function type(string $type): self { $this->chartType = $type; return $this; }
    public function line(): self { $this->chartType = 'line'; return $this; }
    public function bar(): self { $this->chartType = 'bar'; return $this; }
    public function pie(): self { $this->chartType = 'pie'; return $this; }
    public function doughnut(): self { $this->chartType = 'doughnut'; return $this; }
    public function area(): self { $this->chartType = 'area'; return $this; }

    public function labels(array $labels): self { $this->labels = $labels; return $this; }
    public function dataset(string $label, array $data): self {
        $this->datasets[] = ['label' => $label, 'data' => $data];
        return $this;
    }

    public function render(): void {
        ddless_task_emit('chart', [
            'title' => $this->chartTitle,
            'chartType' => $this->chartType,
            'labels' => $this->labels,
            'datasets' => $this->datasets,
        ]);
    }
}

// Read input
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

$composerAutoload = DDLESS_PROJECT_ROOT . '/vendor/autoload.php';
if (!file_exists($composerAutoload)) {
    ddless_task_emit('error', ['message' => 'Composer autoload not found at: ' . $composerAutoload]);
    ddless_task_done(false, $taskStartTime, 'Composer autoload not found.');
    exit(1);
}

require $composerAutoload;

// Load .env
$envFile = DDLESS_PROJECT_ROOT . '/.env';
if (is_file($envFile) && class_exists('CodeIgniter\Config\DotEnv')) {
    (new \CodeIgniter\Config\DotEnv(DDLESS_PROJECT_ROOT))->load();
}

// CI4 path constants
if (!defined('FCPATH')) {
    define('FCPATH', DDLESS_PROJECT_ROOT . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR);
}
if (!defined('ROOTPATH')) {
    define('ROOTPATH', DDLESS_PROJECT_ROOT . DIRECTORY_SEPARATOR);
}

$pathsConfig = DDLESS_PROJECT_ROOT . '/app/Config/Paths.php';
if (is_file($pathsConfig)) {
    require_once $pathsConfig;
}

if (class_exists('Config\Paths')) {
    $paths = new \Config\Paths();
    if (!defined('APPPATH')) {
        define('APPPATH', realpath($paths->appDirectory) . DIRECTORY_SEPARATOR);
    }
    if (!defined('WRITEPATH')) {
        define('WRITEPATH', realpath($paths->writableDirectory) . DIRECTORY_SEPARATOR);
    }
    if (!defined('SYSTEMPATH')) {
        $systemPath = realpath($paths->systemDirectory ?? DDLESS_PROJECT_ROOT . '/vendor/codeigniter4/framework/system');
        if ($systemPath) {
            define('SYSTEMPATH', $systemPath . DIRECTORY_SEPARATOR);
        }
    }
}

if (!defined('TESTPATH')) {
    define('TESTPATH', DDLESS_PROJECT_ROOT . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR);
}

if (!defined('CI_ENVIRONMENT')) {
    define('CI_ENVIRONMENT', $_SERVER['CI_ENVIRONMENT'] ?? $_ENV['CI_ENVIRONMENT'] ?? 'development');
}

// Boot CI4
$bootFile = defined('SYSTEMPATH') ? SYSTEMPATH . 'Boot.php' : null;
if ($bootFile && is_file($bootFile)) {
    require_once $bootFile;
}

try {
    if (class_exists('CodeIgniter\Boot') && class_exists('Config\Paths')) {
        \CodeIgniter\Boot::bootWeb(new \Config\Paths());
    }
} catch (\Throwable $e) {
    ddless_task_emit('error', ['message' => 'Failed to boot CodeIgniter: ' . $e->getMessage()]);
    ddless_task_done(false, $taskStartTime, 'Failed to boot CodeIgniter: ' . $e->getMessage());
    exit(1);
}

// Task Runner Command class
class DdlessCodeIgniterTaskRunnerCommand {
    private $__progressId = null;
    private $__progressCurrent = 0;
    private $__progressTotal = 0;

    // CI4 service helper
    public function service(string $name) {
        return \Config\Services::$name();
    }

    public function model(string $name) {
        if (function_exists('model')) {
            return model($name);
        }
        if (class_exists($name)) {
            return new $name();
        }
        $fqcn = 'App\\Models\\' . $name;
        if (class_exists($fqcn)) {
            return new $fqcn();
        }
        throw new \RuntimeException("Model not found: {$name}");
    }

    // Output methods
    public function info($string) { ddless_task_emit('info', ['message' => (string)$string]); }
    public function error($string) { ddless_task_emit('error', ['message' => (string)$string]); }
    public function warn($string) { ddless_task_emit('warn', ['message' => (string)$string]); }
    public function line($string) { ddless_task_emit('line', ['message' => (string)$string]); }
    public function comment($string) { ddless_task_emit('comment', ['message' => (string)$string]); }
    public function newLine($count = 1) { ddless_task_emit('newline', ['count' => $count]); return $this; }
    public function alert($string) { ddless_task_emit('alert', ['message' => (string)$string]); }
    public function listing(array $items) { ddless_task_emit('listing', ['items' => array_values(array_map('strval', $items))]); }
    public function hr() { ddless_task_emit('hr', []); }
    public function clipboard($text) { ddless_task_emit('clipboard', ['text' => (string)$text]); }
    public function link($url, $label = '') { ddless_task_emit('link', ['url' => (string)$url, 'label' => $label ?: (string)$url]); }

    public function table($headers, $rows) {
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
        $plainHeaders = array_values(array_map('strval', (array)$headers));
        ddless_task_emit('table', [
            'headers' => $plainHeaders,
            'rows' => $plainRows,
        ]);
    }

    public function json($data) { ddless_task_emit('json', ['data' => $data]); }

    public function chart(): DdlessChart { return new DdlessChart(); }

    public function spin(callable $callback, string $label = 'Processing...') {
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

    public function time(string $label, callable $callback) {
        $start = microtime(true);
        $result = $callback();
        $elapsed = round((microtime(true) - $start) * 1000, 2);
        ddless_task_emit('time', ['label' => $label, 'durationMs' => $elapsed]);
        return $result;
    }

    public function progress(int $total, string $label = ''): self {
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

    public function advance(int $step = 1, ?string $label = null): self {
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

    // Interactive prompts
    public function ask($question, $default = null, ?callable $rule = null) {
        $input = ddless_prompt_with_rule('ask', (string)$question, [
            'promptDefault' => (string)($default ?? ''),
        ], $rule);
        return $input !== '' ? $input : ($default ?? '');
    }

    public function secret($question, $fallback = true, ?callable $rule = null) {
        return ddless_prompt_with_rule('secret', (string)$question, [], $rule);
    }

    public function confirm($question, $default = false, ?callable $rule = null) {
        $input = ddless_prompt_with_rule('confirm', (string)$question, [
            'promptDefault' => $default ? 'true' : 'false',
        ], $rule);
        return $input === 'true';
    }

    public function choice($question, $choices, $default = null, $attempts = null, $multiple = false, ?callable $rule = null) {
        if ($choices instanceof \Traversable) {
            $choices = iterator_to_array($choices);
        }
        $input = ddless_prompt_with_rule('choice', (string)$question, [
            'promptOptions' => array_values((array)$choices),
            'promptDefault' => $default ?? $choices[0] ?? '',
        ], $rule);
        return $input;
    }

    public function anticipate($question, $suggestions, $default = null, ?callable $rule = null) {
        if ($suggestions instanceof \Traversable) {
            $suggestions = iterator_to_array($suggestions);
        }
        return ddless_prompt_with_rule('anticipate', (string)$question, [
            'promptOptions' => array_values((array)$suggestions),
            'promptDefault' => (string)($default ?? ''),
        ], $rule);
    }

    public function date($question, ?string $default = null, ?callable $rule = null) {
        return ddless_prompt_with_rule('date', (string)$question, [
            'promptDefault' => $default ?? date('Y-m-d'),
        ], $rule);
    }

    public function datetime($question, ?string $default = null, ?callable $rule = null) {
        return ddless_prompt_with_rule('datetime', (string)$question, [
            'promptDefault' => $default ?? date('Y-m-d H:i'),
        ], $rule);
    }

    public function import(string $filename, string $delimiter = ',', bool $headers = true, string $encoding = 'auto'): \Generator {
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
}

// Execute user code
try {
    $command = new DdlessCodeIgniterTaskRunnerCommand();

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
    }, $command, DdlessCodeIgniterTaskRunnerCommand::class);

    $closure();

    ddless_task_done(true, $taskStartTime);
} catch (\Throwable $e) {
    ddless_task_emit('error', [
        'message' => $e->getMessage(),
    ]);
    ddless_task_emit('line', [
        'message' => 'at ' . $e->getFile() . ':' . $e->getLine(),
    ]);
    ddless_task_done(false, $taskStartTime, $e->getMessage());
    exit(1);
}
