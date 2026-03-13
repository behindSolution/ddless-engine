<?php
/*
 * DDLess - PHP Debug Engine
 *
 * @author    Jefferson T.S
 * @copyright 2025-2026 DDLess
 * @license   Proprietary
 *
 * Method execution entry point. Bootstraps the debug engine and delegates
 * to the appropriate framework method executor for direct function/method
 * invocation with optional debugging support.
 */

// Defer stream wrapper registration to prevent issues with Laravel bootstrap
$GLOBALS['__DDLESS_DEFER_WRAPPER__'] = true;

if (getenv('DDLESS_DEBUG_MODE') === 'true') {
    require_once __DIR__ . '/debug.php';
}

$inputFile = __DIR__ . '/method_input_temp.json';
if (!is_file($inputFile)) {
    echo json_encode([
        'ok' => false,
        'error' => 'Method input file not found: ' . $inputFile,
    ]);
    exit(1);
}

$inputJson = file_get_contents($inputFile);
@unlink($inputFile);

// Signal method_executor to skip stdin reading
$_ENV['DDLESS_METHOD_INPUT_FILE'] = '__ALREADY_LOADED__';
putenv('DDLESS_METHOD_INPUT_FILE=__ALREADY_LOADED__');

$input = json_decode($inputJson, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    echo json_encode([
        'ok' => false,
        'error' => 'Invalid JSON input: ' . json_last_error_msg(),
    ]);
    exit(1);
}

$GLOBALS['__DDLESS_METHOD_INPUT__'] = $inputJson;

$framework = $input['framework'] ?? 'laravel';
$executorPath = __DIR__ . '/frameworks/' . $framework . '/method_executor.php';

if (!is_file($executorPath)) {
    echo json_encode([
        'ok' => false,
        'error' => 'Method executor not found for framework: ' . $framework,
        'path' => $executorPath,
    ]);
    exit(1);
}

require_once $executorPath;
