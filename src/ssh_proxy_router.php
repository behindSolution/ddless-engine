<?php
/*
 * DDLess - SSH Browser Debug Server Router
 *
 * @author    Jefferson T.S
 * @copyright 2025-2026 DDLess
 * @license   Proprietary
 *
 * Router script for `php -S` built-in server. Used when debugging via SSH
 * with a real browser (sessions, cookies persist naturally).
 *
 * Responsibilities:
 * - Serve static files directly (return false)
 * - Capture php://input BEFORE stream wrapper registration
 * - Load debug.php for instrumentation
 * - Inject custom server variables from DDLESS_SERVER_VARIABLES env
 * - Dispatch to the appropriate framework handler
 */

// Static files: let php -S serve them directly
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$publicDir = $_SERVER['DOCUMENT_ROOT'] ?? getcwd();

// Check common static file extensions
$staticExtensions = [
    'css', 'js', 'jpg', 'jpeg', 'png', 'gif', 'svg', 'ico', 'webp', 'avif',
    'woff', 'woff2', 'ttf', 'eot', 'otf', 'map', 'json', 'xml',
    'mp4', 'webm', 'ogg', 'mp3', 'wav', 'pdf', 'zip', 'gz',
];
$ext = strtolower(pathinfo($uri, PATHINFO_EXTENSION));
if ($ext !== '' && in_array($ext, $staticExtensions, true)) {
    $staticPath = $publicDir . $uri;
    if (is_file($staticPath)) {
        return false; // php -S serves the file directly
    }
}

// Capture raw input BEFORE stream wrapper registration (debug.php defers wrapper)
$GLOBALS['__DDLESS_RAW_INPUT__'] = file_get_contents('php://input');

// Project root detection
$projectRoot = getenv('DDLESS_PROJECT_ROOT') ?: dirname(__DIR__);
define('DDLESS_PROJECT_ROOT', $projectRoot);

// Debug session
$sessionId = getenv('DDLESS_DEBUG_SESSION') ?: 'default';

// Load debug engine (instrumentation, breakpoints)
if (getenv('DDLESS_DEBUG_MODE') === 'true') {
    $GLOBALS['__DDLESS_DEFER_WRAPPER__'] = true;
    require_once __DIR__ . '/debug.php';
}

// Inject custom server variables from environment
$serverVarsJson = getenv('DDLESS_SERVER_VARIABLES');
if ($serverVarsJson !== false && $serverVarsJson !== '') {
    $customVars = json_decode($serverVarsJson, true);
    if (is_array($customVars)) {
        foreach ($customVars as $key => $value) {
            if (is_string($key) && $key !== '' && is_string($value)) {
                $_SERVER[$key] = $value;
            }
        }
    }
}

// Framework handler dispatch
// The framework handler expects superglobals to already be populated,
// which they are naturally with php -S.
$framework = getenv('DDLESS_FRAMEWORK') ?: 'php';
$frameworkHandler = __DIR__ . '/frameworks/' . $framework . '/http_request.php';

if (!is_file($frameworkHandler)) {
    http_response_code(500);
    echo "DDLess: Framework handler not found for: {$framework}";
    exit(1);
}

require_once $frameworkHandler;
