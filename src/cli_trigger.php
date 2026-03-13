<?php
/*
 * DDLess - PHP Debug Engine
 *
 * @author    Jefferson T.S
 * @copyright 2025-2026 DDLess
 * @license   Proprietary
 *
 * CLI debug entry point. Loaded via auto_prepend_file to enable debugging
 * of PHP scripts executed from the terminal. Reconstructs $argv/$argc,
 * registers the stream wrapper, and propagates debug to child processes.
 */

ini_set('display_errors', 'stderr');
ini_set('log_errors', '0');
error_reporting(E_ALL);

// Defer stream wrapper registration to prevent issues with Laravel bootstrap
$GLOBALS['__DDLESS_DEFER_WRAPPER__'] = true;

if (getenv('DDLESS_DEBUG_MODE') === 'true') {
    require_once __DIR__ . '/debug.php';
}

$inputFile = __DIR__ . '/cli_input_temp.json';
if (!is_file($inputFile)) {
    fwrite(STDERR, "[ddless] CLI input file not found: {$inputFile}\n");
    exit(1);
}

$inputJson = file_get_contents($inputFile);
@unlink($inputFile);

$input = json_decode($inputJson, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    fwrite(STDERR, "[ddless] Invalid JSON input: " . json_last_error_msg() . "\n");
    exit(1);
}

$args = $input['args'] ?? [];
if (empty($args)) {
    fwrite(STDERR, "[ddless] No script/command specified in args\n");
    exit(1);
}

$projectRoot = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$script = $args[0];
$scriptPath = $projectRoot . DIRECTORY_SEPARATOR . $script;

if (!is_file($scriptPath)) {
    fwrite(STDERR, "[ddless] Script not found: {$scriptPath}\n");
    exit(1);
}

$GLOBALS['argv'] = $args;
$GLOBALS['argc'] = count($args);
$_SERVER['argv'] = $args;
$_SERVER['argc'] = count($args);

chdir($projectRoot);

// Idempotent — artisan/phpunit will skip it via require_once.
$autoloadPath = $projectRoot . '/vendor/autoload.php';
if (is_file($autoloadPath)) {
    require_once $autoloadPath;
}

// Without this, files won't be instrumented and breakpoints won't fire
if (function_exists('ddless_register_stream_wrapper')) {
    ddless_register_stream_wrapper();
}

// Propagate debug instrumentation to child PHP processes (e.g., `artisan test`
// spawns PHPUnit as a separate process). An ini file with auto_prepend_file ensures
// every child process also loads debug.php. The child inherits env vars
// (DDLESS_DEBUG_MODE, DDLESS_DEBUG_SESSION, etc.) so debug.php activates correctly.
if (getenv('DDLESS_DEBUG_MODE') === 'true') {
    $debugPhpPath = __DIR__ . DIRECTORY_SEPARATOR . 'debug.php';
    $iniPath = __DIR__ . DIRECTORY_SEPARATOR . 'ddless_prepend.ini';
    @file_put_contents($iniPath, 'auto_prepend_file=' . $debugPhpPath . "\n");

    // Leading separator preserves the default PHP ini scan directory
    // so existing extensions/configs continue to load normally.
    $sep = PHP_OS_FAMILY === 'Windows' ? ';' : ':';
    putenv('PHP_INI_SCAN_DIR=' . $sep . __DIR__);
    $_ENV['PHP_INI_SCAN_DIR'] = $sep . __DIR__;
}

try {
    include $scriptPath;
} catch (\Throwable $e) {
    fwrite(STDERR, "[ddless] " . get_class($e) . ": " . $e->getMessage() . "\n");
    fwrite(STDERR, $e->getTraceAsString() . "\n");
    exit(1);
}
