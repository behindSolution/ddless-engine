<?php
/*
 * DDLess - PHP Debug Engine
 *
 * @author    Jefferson T.S
 * @copyright 2025-2026 DDLess
 * @license   Proprietary
 *
 * Drupal 9/10/11 task runner. Boots the DrupalKernel and executes
 * user-provided PHP code with access to services, entities, and database.
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
    $data['type'] = $type; $data['timestamp'] = microtime(true);
    $encoded = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($encoded === false || strlen($encoded) > 5242880) {
        $reason = $encoded === false
            ? 'serialization failed (likely circular reference or unserializable value)'
            : 'payload exceeded 5 MB (' . strlen($encoded) . ' bytes)';
        $encoded = json_encode([
            'type' => 'alert',
            'timestamp' => $data['timestamp'],
            'message' => '[' . strtoupper($type) . '] output truncated — ' . $reason . '. Pass only the fields you need (e.g. $model->only([...])).',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    echo "__DDLESS_TASK_OUTPUT__:" . $encoded . "\n";
    @fflush(STDOUT);
}

function ddless_csv_encode_row(array $fields, string $delimiter = ','): string {
    $safe = array_map(function ($v) {
        if (is_array($v) || is_object($v)) return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($v === null) return ''; if ($v === true) return '1'; if ($v === false) return '0'; return (string) $v;
    }, $fields);
    $handle = fopen('php://temp', 'r+'); fputcsv($handle, $safe, $delimiter); rewind($handle);
    $csv = stream_get_contents($handle); fclose($handle); return rtrim($csv, "\n\r");
}

function ddless_task_done(bool $ok, float $startTime, ?string $error = null): void {
    $data = ['ok' => $ok, 'durationMs' => round((microtime(true) - $startTime) * 1000, 2)];
    if ($error !== null) $data['error'] = $error;
    echo "__DDLESS_TASK_DONE__:" . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
}

function ddless_clean_php_code(string $code): string {
    $code = trim($code);
    if (str_starts_with($code, '<?php')) $code = substr($code, 5);
    elseif (str_starts_with($code, '<?')) $code = substr($code, 2);
    $code = rtrim($code);
    if (str_ends_with($code, '?>')) $code = substr($code, 0, -2);
    return trim($code);
}

function ddless_prompt_with_rule(string $promptType, string $message, array $extra, ?callable $rule): string {
    $validationError = null;
    while (true) {
        $data = array_merge(['promptType' => $promptType, 'message' => $message], $extra);
        if ($validationError !== null) $data['validationError'] = $validationError;
        ddless_task_emit('prompt', $data);
        $input = trim((string)fgets(STDIN));
        if ($rule === null) return $input;
        $result = $rule($input);
        if ($result === true || $result === null) return $input;
        $validationError = is_string($result) ? $result : 'Invalid value.';
    }
}

class DdlessChart {
    private string $chartType = 'line'; private string $chartTitle = '';
    private array $labels = []; private array $datasets = [];
    public function title(string $title): self { $this->chartTitle = $title; return $this; }
    public function line(): self { $this->chartType = 'line'; return $this; }
    public function bar(): self { $this->chartType = 'bar'; return $this; }
    public function pie(): self { $this->chartType = 'pie'; return $this; }
    public function doughnut(): self { $this->chartType = 'doughnut'; return $this; }
    public function area(): self { $this->chartType = 'area'; return $this; }
    public function labels(array $labels): self { $this->labels = $labels; return $this; }
    public function dataset(string $label, array $data): self { $this->datasets[] = ['label' => $label, 'data' => $data]; return $this; }
    public function render(): void { ddless_task_emit('chart', ['title' => $this->chartTitle, 'chartType' => $this->chartType, 'labels' => $this->labels, 'datasets' => $this->datasets]); }
}

$inputJson = $GLOBALS['__DDLESS_TASK_INPUT__'] ?? null;
if (!$inputJson) { ddless_task_emit('error', ['message' => 'Task input not found.']); ddless_task_done(false, $taskStartTime, 'Task input not found.'); exit(1); }

$input = json_decode($inputJson, true);
if (!is_array($input)) { ddless_task_emit('error', ['message' => 'Invalid task input JSON.']); ddless_task_done(false, $taskStartTime, 'Invalid task input JSON.'); exit(1); }

$userCode = $input['code'] ?? '';
$imports = $input['imports'] ?? [];
$GLOBALS['__DDLESS_IMPORTS_PATH__'] = $input['importsPath'] ?? null;

if (trim($userCode) === '') { ddless_task_emit('warn', ['message' => 'No code to execute.']); ddless_task_done(true, $taskStartTime); exit(0); }

$composerAutoload = DDLESS_PROJECT_ROOT . '/autoload.php';
if (!file_exists($composerAutoload)) $composerAutoload = DDLESS_PROJECT_ROOT . '/vendor/autoload.php';
if (!file_exists($composerAutoload)) { ddless_task_emit('error', ['message' => 'Autoload not found.']); ddless_task_done(false, $taskStartTime, 'Autoload not found.'); exit(1); }

$webDir = is_dir(DDLESS_PROJECT_ROOT . '/web') ? DDLESS_PROJECT_ROOT . '/web' : DDLESS_PROJECT_ROOT;
chdir($webDir);
$autoloader = require $composerAutoload;

$container = null;
try {
    $request = \Symfony\Component\HttpFoundation\Request::createFromGlobals();
    $kernel = \Drupal\Core\DrupalKernel::createFromRequest($request, $autoloader, 'prod');
    $kernel->boot();
    $container = $kernel->getContainer();
} catch (\Throwable $e) {
    ddless_task_emit('error', ['message' => 'Failed to boot Drupal: ' . $e->getMessage()]);
    ddless_task_done(false, $taskStartTime, 'Failed to boot Drupal: ' . $e->getMessage());
    exit(1);
}

class DdlessDrupalTaskRunnerCommand {
    private $__container;
    private $__progressId = null; private $__progressCurrent = 0; private $__progressTotal = 0;

    public function setContainer($container): void { $this->__container = $container; }
    public function getContainer() { return $this->__container; }

    public function service(string $id) { return \Drupal::service($id); }
    public function entityTypeManager() { return \Drupal::entityTypeManager(); }
    public function database() { return \Drupal::database(); }
    public function config(string $name) { return \Drupal::config($name); }
    public function state() { return \Drupal::state(); }

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
        if ($rows instanceof \Traversable) $rows = iterator_to_array($rows);
        foreach ($rows as $row) {
            if ($row instanceof \Traversable) $row = iterator_to_array($row);
            if (is_object($row)) $row = (array) $row;
            $plainRows[] = array_values(array_map('strval', $row));
        }
        ddless_task_emit('table', ['headers' => array_values(array_map('strval', (array)$headers)), 'rows' => $plainRows]);
    }

    public function json($data) { ddless_task_emit('json', ['data' => $data]); }
    public function chart(): DdlessChart { return new DdlessChart(); }

    public function spin(callable $callback, string $label = 'Processing...') {
        ddless_task_emit('spin', ['label' => $label, 'state' => 'start']);
        try { $result = $callback(); ddless_task_emit('spin', ['label' => $label, 'state' => 'done']); return $result; }
        catch (\Throwable $e) { ddless_task_emit('spin', ['label' => $label, 'state' => 'error', 'message' => $e->getMessage()]); throw $e; }
    }

    public function time(string $label, callable $callback) {
        $start = microtime(true); $result = $callback();
        ddless_task_emit('time', ['label' => $label, 'durationMs' => round((microtime(true) - $start) * 1000, 2)]); return $result;
    }

    public function progress(int $total, string $label = ''): self {
        $this->__progressId = 'prog_' . uniqid(); $this->__progressCurrent = 0; $this->__progressTotal = $total;
        ddless_task_emit('progress', ['progressId' => $this->__progressId, 'current' => 0, 'total' => $total, 'percent' => 0, 'label' => $label]);
        return $this;
    }

    public function advance(int $step = 1, ?string $label = null): self {
        if (!$this->__progressId) return $this;
        $this->__progressCurrent = min($this->__progressCurrent + $step, $this->__progressTotal);
        $percent = $this->__progressTotal > 0 ? round(($this->__progressCurrent / $this->__progressTotal) * 100, 1) : 0;
        ddless_task_emit('progress', ['progressId' => $this->__progressId, 'current' => $this->__progressCurrent, 'total' => $this->__progressTotal, 'percent' => $percent, 'label' => $label]);
        return $this;
    }

    public function ask($question, $default = null, ?callable $rule = null) { $input = ddless_prompt_with_rule('ask', (string)$question, ['promptDefault' => (string)($default ?? '')], $rule); return $input !== '' ? $input : ($default ?? ''); }
    public function secret($question, $fallback = true, ?callable $rule = null) { return ddless_prompt_with_rule('secret', (string)$question, [], $rule); }
    public function confirm($question, $default = false, ?callable $rule = null) { return ddless_prompt_with_rule('confirm', (string)$question, ['promptDefault' => $default ? 'true' : 'false'], $rule) === 'true'; }
    public function choice($question, $choices, $default = null, $attempts = null, $multiple = false, ?callable $rule = null) { if ($choices instanceof \Traversable) $choices = iterator_to_array($choices); return ddless_prompt_with_rule('choice', (string)$question, ['promptOptions' => array_values((array)$choices), 'promptDefault' => $default ?? $choices[0] ?? ''], $rule); }
    public function anticipate($question, $suggestions, $default = null, ?callable $rule = null) { if ($suggestions instanceof \Traversable) $suggestions = iterator_to_array($suggestions); return ddless_prompt_with_rule('anticipate', (string)$question, ['promptOptions' => array_values((array)$suggestions), 'promptDefault' => (string)($default ?? '')], $rule); }
    public function date($question, ?string $default = null, ?callable $rule = null) { return ddless_prompt_with_rule('date', (string)$question, ['promptDefault' => $default ?? date('Y-m-d')], $rule); }
    public function datetime($question, ?string $default = null, ?callable $rule = null) { return ddless_prompt_with_rule('datetime', (string)$question, ['promptDefault' => $default ?? date('Y-m-d H:i')], $rule); }

    public function import(string $filename, string $delimiter = ',', bool $headers = true, string $encoding = 'auto'): \Generator {
        $importsPath = $GLOBALS['__DDLESS_IMPORTS_PATH__'] ?? null;
        if (!$importsPath) throw new \RuntimeException("No imports directory configured.");
        $filePath = rtrim($importsPath, '/\\') . DIRECTORY_SEPARATOR . basename($filename);
        if (!is_file($filePath)) throw new \RuntimeException("Import file not found: {$filename}");
        $handle = fopen($filePath, 'r');
        if ($handle === false) throw new \RuntimeException("Failed to open import file: {$filename}");
        try {
            $encodingDetected = null;
            if ($encoding === 'auto') {
                $sample = fread($handle, 8192); if ($sample === false || $sample === '') return;
                if (str_starts_with($sample, "\xEF\xBB\xBF")) { $encodingDetected = 'UTF-8'; } else { $encodingDetected = mb_detect_encoding($sample, ['UTF-8', 'Windows-1252', 'ISO-8859-1'], true) ?: 'UTF-8'; }
                rewind($handle); $bom = fread($handle, 3); if ($bom !== "\xEF\xBB\xBF") rewind($handle);
            } else { $encodingDetected = $encoding; }
            $needsConversion = $encodingDetected !== null && $encodingDetected !== 'UTF-8' && $encodingDetected !== 'utf-8';
            $headerRow = null;
            if ($headers) { $firstLine = fgetcsv($handle, 0, $delimiter); if ($firstLine === false || $firstLine === null) return; if ($needsConversion) $firstLine = array_map(fn($v) => mb_convert_encoding((string)$v, 'UTF-8', $encodingDetected), $firstLine); $headerRow = $firstLine; }
            while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
                if ($row === null) continue; if ($needsConversion) $row = array_map(fn($v) => mb_convert_encoding((string)$v, 'UTF-8', $encodingDetected), $row);
                if ($headerRow !== null) { $row = array_pad($row, count($headerRow), ''); yield array_combine($headerRow, array_slice($row, 0, count($headerRow))); } else { yield $row; }
            }
        } finally { fclose($handle); }
    }

    public function export(string $filename, callable $callback, string $delimiter = ';'): void {
        if (!str_ends_with(strtolower($filename), '.csv')) $filename .= '.csv';
        $page = 0; $totalRows = 0; $headersSent = false; $exportId = 'exp_' . uniqid();
        ddless_task_emit('export_begin', ['exportId' => $exportId, 'filename' => $filename]);
        try {
            while (true) {
                $rows = $callback($page); if ($rows === null || $rows === [] || $rows === false) break;
                if (!is_array($rows)) throw new \RuntimeException('Export callback must return an array.');
                $csvLines = [];
                if (!$headersSent) { $firstRow = reset($rows); if (is_array($firstRow) || is_object($firstRow)) $csvLines[] = ddless_csv_encode_row(array_keys((array)$firstRow), $delimiter); $headersSent = true; }
                foreach ($rows as $row) { $csvLines[] = ddless_csv_encode_row(array_values((array)$row), $delimiter); $totalRows++; }
                ddless_task_emit('export_chunk', ['exportId' => $exportId, 'csv' => implode("\n", $csvLines) . "\n", 'totalRows' => $totalRows]);
                $page++;
            }
            ddless_task_emit('export_end', ['exportId' => $exportId, 'filename' => $filename, 'totalRows' => $totalRows]);
        } catch (\Throwable $e) { ddless_task_emit('export_error', ['exportId' => $exportId, 'error' => $e->getMessage(), 'totalRows' => $totalRows]); throw $e; }
    }
}

try {
    $command = new DdlessDrupalTaskRunnerCommand();
    $command->setContainer($container);

    $useStatements = '';
    foreach ($imports as $import) { $import = trim($import); if ($import !== '') $useStatements .= "use {$import};\n"; }

    $cleanedCode = ddless_clean_php_code($userCode);
    $evalCode = $useStatements . "\n" . $cleanedCode;

    $closure = \Closure::bind(function () use ($evalCode) { return eval($evalCode); }, $command, DdlessDrupalTaskRunnerCommand::class);
    $closure();
    ddless_task_done(true, $taskStartTime);
} catch (\Throwable $e) {
    if (str_contains($e->getFile(), "eval()'d code")) {
        ddless_task_emit('error', ['message' => $e->getMessage()]);
        ddless_task_done(false, $taskStartTime, $e->getMessage());
        exit(1);
    }
    ddless_task_emit('exception', ['exceptionClass' => get_class($e), 'message' => $e->getMessage(), 'exceptionCode' => $e->getCode(), 'exceptionFile' => $e->getFile(), 'exceptionLine' => $e->getLine()]);
    ddless_task_done(true, $taskStartTime);
}
