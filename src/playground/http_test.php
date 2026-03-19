<?php
/*
 * DDLess - PHP Debug Engine
 *
 * @author    Jefferson T.S
 * @copyright 2025-2026 DDLess
 * @license   Proprietary
 *
 * HTTP request test runner. Integrates with PHP's built-in server to
 * route real HTTP requests through a framework's http_request.php handler.
 * Allows contributors to validate new framework HTTP integrations by
 * sending actual requests (via curl, browser, etc.) with interactive
 * breakpoint debugging in the server terminal.
 *
 * Usage (via test_trigger.php as built-in server router):
 *   DDLESS_FRAMEWORK=laravel php -S localhost:8080 playground/test_trigger.php
 *   DDLESS_FRAMEWORK=laravel DDLESS_BP="OrderController.php:45" php -S localhost:8080 playground/test_trigger.php
 *
 * Then send requests:
 *   curl http://localhost:8080/api/orders
 *   curl -X POST http://localhost:8080/api/orders -H "Content-Type: application/json" -d '{"item":"test"}'
 *
 * Environment variables:
 *   DDLESS_FRAMEWORK   Framework name (default: php). E.g.: laravel, symfony, codeigniter, tempest, wordpress
 *   DDLESS_BP          Breakpoints, comma-separated. E.g.: "OrderController.php:45,OrderService.php:32"
 *   DDLESS_DEPTH       Variable serialization depth (default: 4)
 */

$paths = ddless_resolve_paths();
$ddlessDir = $paths['ddlessDir'];
$projectRoot = $paths['projectRoot'];

// ─── Configuration from environment ─────────────────────────────────────────

$framework = getenv('DDLESS_FRAMEWORK') ?: 'php';
$depth = (int) (getenv('DDLESS_DEPTH') ?: 4);

// Parse breakpoints from env: "file:line,file:line"
$breakpointMap = [];
$bpEnv = getenv('DDLESS_BP') ?: '';
if ($bpEnv !== '') {
    foreach (explode(',', $bpEnv) as $bpSpec) {
        $bpSpec = trim($bpSpec);
        if ($bpSpec === '' || !str_contains($bpSpec, ':')) continue;
        [$bpFile, $bpLine] = explode(':', $bpSpec, 2);
        $breakpointMap[str_replace('\\', '/', $bpFile)][] = (int) $bpLine;
    }
}

// ─── Resolve handler ────────────────────────────────────────────────────────

$handlerPath = $ddlessDir . '/frameworks/' . $framework . '/http_request.php';

if (!is_file($handlerPath)) {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    fwrite(STDERR, CLR_RED . "  Error: HTTP handler not found for framework: {$framework}" . CLR_RESET . "\n");
    fwrite(STDERR, CLR_DIM . "  Expected at: {$handlerPath}" . CLR_RESET . "\n");

    $available = [];
    $fwDir = $ddlessDir . '/frameworks';
    if (is_dir($fwDir)) {
        foreach (scandir($fwDir) as $dir) {
            if ($dir === '.' || $dir === '..') continue;
            if (is_file($fwDir . '/' . $dir . '/http_request.php')) {
                $available[] = $dir;
            }
        }
    }
    if (!empty($available)) {
        fwrite(STDERR, CLR_DIM . "  Available: " . implode(', ', $available) . CLR_RESET . "\n");
    }

    http_response_code(500);
    echo "DDLess: HTTP handler not found for framework: {$framework}";
    return;
}

// ─── Capture raw input ──────────────────────────────────────────────────────
// Must be done BEFORE http_request.php replaces the php:// stream wrapper

$GLOBALS['__DDLESS_RAW_INPUT__'] = file_get_contents('php://input');

// ─── Setup ──────────────────────────────────────────────────────────────────

if (!defined('DDLESS_PROJECT_ROOT')) {
    define('DDLESS_PROJECT_ROOT', $projectRoot);
}

$hasBreakpoints = !empty($breakpointMap);
$session = null;

if ($hasBreakpoints) {
    $session = ddless_setup_session($ddlessDir, $breakpointMap, $depth);
    ddless_register_terminal_handler();
}

// ─── Request log ────────────────────────────────────────────────────────────

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri = $_SERVER['REQUEST_URI'] ?? '/';
$startTime = microtime(true);

fwrite(STDERR, "\n" . CLR_DIM . "  DDLess" . CLR_RESET . " " . $framework
    . "  " . CLR_BOLD . $method . CLR_RESET . " " . CLR_CYAN . $uri . CLR_RESET . "\n");

// ─── Set environment for http_request.php ───────────────────────────────────

putenv('DDLESS_FRAMEWORK=' . $framework);

if ($hasBreakpoints) {
    putenv('DDLESS_DEBUG_MODE=true');
    $_ENV['DDLESS_DEBUG_MODE'] = 'true';
}

// ─── Register shutdown for response summary & cleanup ───────────────────────

$sessionForCleanup = $session;

register_shutdown_function(function () use ($startTime, $hasBreakpoints, $sessionForCleanup) {
    $durationMs = round((microtime(true) - $startTime) * 1000, 1);
    $statusCode = http_response_code();

    if ($statusCode >= 500) {
        $color = CLR_RED;
    } elseif ($statusCode >= 400) {
        $color = CLR_YELLOW;
    } elseif ($statusCode >= 300) {
        $color = CLR_BLUE;
    } else {
        $color = CLR_GREEN;
    }

    fwrite(STDERR, $color . "  < " . CLR_RESET . $statusCode
        . CLR_DIM . " ({$durationMs}ms)" . CLR_RESET . "\n");

    if ($hasBreakpoints && $sessionForCleanup !== null) {
        ddless_cleanup_session($sessionForCleanup['sessionDir']);
    }
});

// ─── Execute handler ────────────────────────────────────────────────────────

try {
    require $handlerPath;
} catch (\Throwable $e) {
    ddless_display_exception($e);
    http_response_code(500);
    echo "DDLess: Handler error: " . $e->getMessage();
}
