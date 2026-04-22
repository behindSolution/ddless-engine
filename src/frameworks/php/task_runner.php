<?php
/*
 * DDLess - PHP Debug Engine
 *
 * @author    Jefferson T.S
 * @copyright 2025-2026 DDLess
 * @license   Proprietary
 *
 * Generic PHP task runner. Provides a DdlessTask base class with output
 * methods and interactive prompts for executing user-written PHP code
 * without a framework dependency.
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
    $encoded = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($encoded === false || strlen($encoded) > 262144) {
        $reason = $encoded === false
            ? 'serialization failed (likely circular reference or unserializable value)'
            : 'payload exceeded 256 KB (' . strlen($encoded) . ' bytes)';
        $encoded = json_encode([
            'type' => 'alert',
            'timestamp' => $data['timestamp'],
            'message' => '[' . strtoupper($type) . '] output truncated — ' . $reason . '. Pass only the fields you need (e.g. $model->only([...])).',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    echo "__DDLESS_TASK_OUTPUT__:" . $encoded . "\n";
    @fflush(STDOUT);
}

function ddless_csv_encode_row(array $fields, string $delimiter = ','): string
{
    $safe = array_map(function ($v) {
        if (is_array($v) || is_object($v)) return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($v === null) return '';
        if ($v === true) return '1';
        if ($v === false) return '0';
        return (string) $v;
    }, $fields);
    $handle = fopen('php://temp', 'r+');
    fputcsv($handle, $safe, $delimiter);
    rewind($handle);
    $csv = stream_get_contents($handle);
    fclose($handle);
    return rtrim($csv, "\n\r");
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

$composerAutoload = DDLESS_PROJECT_ROOT . '/vendor/autoload.php';
if (file_exists($composerAutoload)) {
    require $composerAutoload;
}

$entryPoint = $input['entryPoint'] ?? null;
if ($entryPoint) {
    $entryPointPath = DDLESS_PROJECT_ROOT . '/' . ltrim(str_replace('\\', '/', $entryPoint), '/');
    if (is_file($entryPointPath)) {
        ob_start();
        require_once $entryPointPath;
        ob_end_clean();
    }
}

class DdlessTask
{
    private $__progressId = null;
    private $__progressCurrent = 0;
    private $__progressTotal = 0;

    public function info(string $message): void
    {
        ddless_task_emit('info', ['message' => $message]);
    }

    public function error(string $message): void
    {
        ddless_task_emit('error', ['message' => $message]);
    }

    public function warn(string $message): void
    {
        ddless_task_emit('warn', ['message' => $message]);
    }

    public function line(string $message): void
    {
        ddless_task_emit('line', ['message' => $message]);
    }

    public function comment(string $message): void
    {
        ddless_task_emit('comment', ['message' => $message]);
    }

    public function newLine(int $count = 1): void
    {
        ddless_task_emit('newline', ['count' => $count]);
    }

    public function alert(string $message): void
    {
        ddless_task_emit('alert', ['message' => $message]);
    }

    public function listing(array $items): void
    {
        ddless_task_emit('listing', ['items' => array_values(array_map('strval', $items))]);
    }

    public function hr(): void
    {
        ddless_task_emit('hr', []);
    }

    public function clipboard(string $text): void
    {
        ddless_task_emit('clipboard', ['text' => $text]);
    }

    public function link(string $url, string $label = ''): void
    {
        ddless_task_emit('link', ['url' => $url, 'label' => $label ?: $url]);
    }

    public function json($data): void
    {
        ddless_task_emit('json', ['data' => $data]);
    }

    public function table(array $headers, array $rows): void
    {
        $plainRows = [];
        foreach ($rows as $row) {
            if (is_object($row)) {
                $row = (array) $row;
            }
            $plainRows[] = array_values(array_map('strval', $row));
        }

        ddless_task_emit('table', [
            'headers' => array_values(array_map('strval', $headers)),
            'rows' => $plainRows,
        ]);
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

    public function ask(string $question, string $default = '', ?callable $rule = null): string
    {
        $input = ddless_prompt_with_rule('ask', $question, [
            'promptDefault' => $default,
        ], $rule);
        return $input !== '' ? $input : $default;
    }

    public function secret(string $question, ?callable $rule = null): string
    {
        $input = ddless_prompt_with_rule('secret', $question, [], $rule);
        return $input;
    }

    public function confirm(string $question, bool $default = false, ?callable $rule = null): bool
    {
        $input = ddless_prompt_with_rule('confirm', $question, [
            'promptDefault' => $default ? 'true' : 'false',
        ], $rule);
        return $input === 'true';
    }

    public function choice(string $question, array $options, ?string $default = null, ?callable $rule = null): string
    {
        $input = ddless_prompt_with_rule('choice', $question, [
            'promptOptions' => array_values($options),
            'promptDefault' => $default ?? $options[0] ?? '',
        ], $rule);
        return $input;
    }

    public function anticipate(string $question, array $suggestions, ?string $default = null, ?callable $rule = null): string
    {
        $input = ddless_prompt_with_rule('anticipate', $question, [
            'promptOptions' => array_values(array_map('strval', $suggestions)),
            'promptDefault' => $default ?? '',
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

    public function export(string $filename, callable $callback, string $delimiter = ';'): void
    {
        if (!str_ends_with(strtolower($filename), '.csv')) {
            $filename .= '.csv';
        }

        $page = 0;
        $totalRows = 0;
        $headersSent = false;
        $exportId = 'exp_' . uniqid();

        ddless_task_emit('export_begin', [
            'exportId' => $exportId,
            'filename' => $filename,
        ]);

        try {
            while (true) {
                $rows = $callback($page);

                if ($rows === null || $rows === [] || $rows === false) {
                    break;
                }

                if (!is_array($rows)) {
                    throw new \RuntimeException('Export callback must return an array of rows, null, or an empty array.');
                }

                $csvLines = [];

                if (!$headersSent) {
                    $firstRow = reset($rows);
                    if (is_array($firstRow) || is_object($firstRow)) {
                        $headers = array_keys((array) $firstRow);
                        $csvLines[] = ddless_csv_encode_row($headers, $delimiter);
                    }
                    $headersSent = true;
                }

                foreach ($rows as $row) {
                    $csvLines[] = ddless_csv_encode_row(array_values((array) $row), $delimiter);
                    $totalRows++;
                }

                ddless_task_emit('export_chunk', [
                    'exportId' => $exportId,
                    'csv' => implode("\n", $csvLines) . "\n",
                    'totalRows' => $totalRows,
                ]);

                $page++;
            }

            ddless_task_emit('export_end', [
                'exportId' => $exportId,
                'filename' => $filename,
                'totalRows' => $totalRows,
            ]);
        } catch (\Throwable $e) {
            ddless_task_emit('export_error', [
                'exportId' => $exportId,
                'error' => $e->getMessage(),
                'totalRows' => $totalRows,
            ]);
            throw $e;
        }
    }
}

try {
    $task = new DdlessTask();

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
    }, $task, DdlessTask::class);

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
