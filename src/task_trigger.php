<?php
/*
 * DDLess - PHP Debug Engine
 *
 * @author    Jefferson T.S
 * @copyright 2025-2026 DDLess
 * @license   Proprietary
 *
 * Task runner entry point. Bootstraps the debug engine and delegates to
 * the appropriate framework task runner for executing user-written PHP code
 * with streaming output and interactive prompts.
 */

ini_set('display_errors', 'stderr');
ini_set('log_errors', '0');
error_reporting(E_ALL);

// Defer stream wrapper registration to prevent issues with Laravel bootstrap
$GLOBALS['__DDLESS_DEFER_WRAPPER__'] = true;

/**
 * Emit a marker line that the Electron IPC handler can parse.
 * Used before the framework runner is loaded (which defines ddless_task_emit).
 */
function __ddless_trigger_emit(string $type, array $data): void
{
    $data['type'] = $type;
    $data['timestamp'] = microtime(true);
    echo "__DDLESS_TASK_OUTPUT__:" . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    @fflush(STDOUT);
}

function __ddless_trigger_done(bool $ok, ?string $error = null): void
{
    $data = ['ok' => $ok, 'durationMs' => 0];
    if ($error !== null) {
        $data['error'] = $error;
    }
    echo "__DDLESS_TASK_DONE__:" . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    @fflush(STDOUT);
}

$inputFile = __DIR__ . '/task_input_temp.json';
if (!is_file($inputFile)) {
    __ddless_trigger_emit('error', ['message' => 'Task input file not found: ' . $inputFile]);
    __ddless_trigger_done(false, 'Task input file not found.');
    exit(1);
}

$inputJson = file_get_contents($inputFile);
@unlink($inputFile);

// Signal task_runner that input is already loaded
$_ENV['DDLESS_TASK_INPUT_FILE'] = '__ALREADY_LOADED__';
putenv('DDLESS_TASK_INPUT_FILE=__ALREADY_LOADED__');

$input = json_decode($inputJson, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    __ddless_trigger_emit('error', ['message' => 'Invalid JSON input: ' . json_last_error_msg()]);
    __ddless_trigger_done(false, 'Invalid JSON input.');
    exit(1);
}

$GLOBALS['__DDLESS_TASK_INPUT__'] = $inputJson;

$framework = $input['framework'] ?? 'laravel';
$runnerPath = __DIR__ . '/frameworks/' . $framework . '/task_runner.php';

if (!is_file($runnerPath)) {
    __ddless_trigger_emit('error', ['message' => 'Task runner not found for framework: ' . $framework . ' at ' . $runnerPath]);
    __ddless_trigger_done(false, 'Task runner not found.');
    exit(1);
}

require_once $runnerPath;
