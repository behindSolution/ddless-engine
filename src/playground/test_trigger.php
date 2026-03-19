#!/usr/bin/env php
<?php
/*
 * DDLess - PHP Debug Engine
 *
 * @author    Jefferson T.S
 * @copyright 2025-2026 DDLess
 * @license   Proprietary
 *
 * Playground entry point. Routes to the appropriate test runner
 * based on --test flag. Designed for the open-source ddless-engine
 * so contributors can validate debug engine and framework integrations
 * without the full DDLess application.
 *
 * Usage:
 *   php test_trigger.php --file script.php --bp 10               (engine debug)
 *   php test_trigger.php --test method --framework laravel ...    (method executor)
 *   php test_trigger.php --test task --framework laravel ...      (task runner)
 *
 * HTTP server mode (built-in PHP server):
 *   DDLESS_FRAMEWORK=laravel php -S localhost:8080 playground/test_trigger.php
 *   DDLESS_FRAMEWORK=laravel DDLESS_BP="Controller.php:45" php -S localhost:8080 playground/test_trigger.php
 */

ini_set('display_errors', 'stderr');
ini_set('log_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/terminal_ui.php';

// ─── HTTP server mode ───────────────────────────────────────────────────────
// When used as a built-in server router: php -S localhost:8080 playground/test_trigger.php

if (php_sapi_name() === 'cli-server') {
    // Serve static files from public/ directory
    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $paths = ddless_resolve_paths();
    $staticFile = $paths['projectRoot'] . '/public' . $requestPath;
    if ($requestPath !== '/' && is_file($staticFile)) {
        return false; // let built-in server handle static assets
    }

    require __DIR__ . '/http_test.php';
    return;
}

// ─── Detect test mode ────────────────────────────────────────────────────────

$testMode = null;
$passArgs = [];

foreach ($argv as $idx => $arg) {
    if ($idx === 0) continue; // skip script name

    if ($arg === '--test' && isset($argv[$idx + 1])) {
        $testMode = strtolower($argv[$idx + 1]);
        // Collect remaining args after --test <mode>, skipping the mode value
        $passArgs = array_slice($argv, $idx + 2);
        break;
    }

    // No --test found yet, keep going
}

// If no --test flag, default to engine test (pass all original args)
if ($testMode === null) {
    $testMode = 'engine';
    $passArgs = array_slice($argv, 1);
}

// Rebuild $argv for the delegated script
array_unshift($passArgs, $argv[0]);
$GLOBALS['argv'] = $passArgs;
$GLOBALS['argc'] = count($passArgs);

// ─── Route to runner ─────────────────────────────────────────────────────────

$runners = [
    'engine' => __DIR__ . '/engine_test.php',
    'method' => __DIR__ . '/method_test.php',
    'task'   => __DIR__ . '/task_test.php',
    'http'   => __DIR__ . '/http_test.php',
];

if (!isset($runners[$testMode])) {
    fwrite(STDERR, CLR_RED . "Unknown test mode: {$testMode}" . CLR_RESET . "\n");
    fwrite(STDERR, CLR_DIM . "Available: " . implode(', ', array_keys($runners)) . CLR_RESET . "\n");
    exit(1);
}

$runnerFile = $runners[$testMode];
if (!is_file($runnerFile)) {
    fwrite(STDERR, CLR_RED . "Runner not found: {$runnerFile}" . CLR_RESET . "\n");
    exit(1);
}

require $runnerFile;
