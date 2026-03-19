<?php
/*
 * DDLess - PHP Debug Engine
 *
 * @author    Jefferson T.S
 * @copyright 2025-2026 DDLess
 * @license   Proprietary
 *
 * Engine test runner. Runs a PHP script or inline code with breakpoints
 * and interactive stepping. Validates the core debug engine (instrumentation,
 * breakpoints, step-over/in/out, variable inspection) without any framework.
 *
 * Usage (via test_trigger.php):
 *   php test_trigger.php --file script.php --bp 10 --bp 25
 *   php test_trigger.php --code '$x = 1; echo $x;' --bp 1
 *   php test_trigger.php --file script.php --bp Service.php:45 --depth 5
 */

$paths = ddless_resolve_paths();
$ddlessDir = $paths['ddlessDir'];
$projectRoot = $paths['projectRoot'];

// ─── Argument parsing ────────────────────────────────────────────────────────

$opts = [
    'file'  => null,
    'code'  => null,
    'bp'    => [],
    'depth' => 4,
];

$args = array_slice($GLOBALS['argv'], 1);
$i = 0;

while ($i < count($args)) {
    $arg = $args[$i];
    switch ($arg) {
        case '--file': case '-f':
            $opts['file'] = $args[++$i] ?? null;
            break;
        case '--code': case '-c':
            $opts['code'] = $args[++$i] ?? null;
            break;
        case '--bp': case '-b':
            $opts['bp'][] = $args[++$i] ?? null;
            break;
        case '--depth': case '-d':
            $opts['depth'] = (int) ($args[++$i] ?? 4);
            break;
        case '--help': case '-h':
            fwrite(STDERR, <<<HELP

  DDLess Engine Test — Terminal PHP Debugger

  Usage:
    php test_trigger.php --file <script.php> [--bp <line>] [--depth <n>]
    php test_trigger.php --code '<php code>' [--bp <line>] [--depth <n>]

  Options:
    --file, -f <path>    PHP file to debug (relative to project root)
    --code, -c <code>    Inline PHP code to debug (without <?php tag)
    --bp,   -b <spec>    Breakpoint: line number or file.php:line (repeatable)
    --depth, -d <n>      Variable serialization depth (default: 4)
    --help, -h           Show this help

  Commands at breakpoint:
    [c] continue     Run until next breakpoint
    [n] next         Step over (next line in same function)
    [s] step         Step into (enter function calls)
    [o] out          Step out (return to caller)
    [q] quit         Stop execution

HELP);
            exit(0);
        default:
            if ($opts['file'] === null && $opts['code'] === null && !str_starts_with($arg, '-')) {
                $opts['file'] = $arg;
            } else {
                fwrite(STDERR, CLR_RED . "Unknown option: {$arg}" . CLR_RESET . "\n");
                exit(1);
            }
            break;
    }
    $i++;
}

if ($opts['file'] === null && $opts['code'] === null) {
    fwrite(STDERR, CLR_RED . "Error: --file or --code is required. Use --help for usage." . CLR_RESET . "\n");
    exit(1);
}

// ─── Resolve script ─────────────────────────────────────────────────────────

if (!defined('DDLESS_PROJECT_ROOT')) {
    define('DDLESS_PROJECT_ROOT', $projectRoot);
}

$tempCodeFile = null;
$scriptPath = null;
$scriptRelative = null;

if ($opts['code'] !== null) {
    $tempCodeFile = $projectRoot . '/ddless_test_temp_' . getmypid() . '.php';
    file_put_contents($tempCodeFile, "<?php\n" . $opts['code'] . "\n");
    $scriptPath = $tempCodeFile;
    $scriptRelative = basename($tempCodeFile);
    $opts['bp'] = array_map(function ($bp) {
        if (is_numeric($bp)) return (string) ((int) $bp + 1);
        return $bp;
    }, $opts['bp']);
} else {
    $filePath = $opts['file'];
    if (!str_starts_with($filePath, '/') && !preg_match('/^[A-Za-z]:/', $filePath)) {
        $scriptPath = $projectRoot . DIRECTORY_SEPARATOR . $filePath;
    } else {
        $scriptPath = $filePath;
    }
    if (!is_file($scriptPath)) {
        fwrite(STDERR, CLR_RED . "Error: File not found: {$scriptPath}" . CLR_RESET . "\n");
        exit(1);
    }
    $scriptPath = realpath($scriptPath);
    $scriptRelative = str_replace('\\', '/', substr($scriptPath, strlen($projectRoot) + 1));
}

// ─── Parse breakpoints ──────────────────────────────────────────────────────

$breakpointMap = [];

foreach ($opts['bp'] as $bpSpec) {
    if ($bpSpec === null) continue;
    if (str_contains($bpSpec, ':')) {
        [$bpFile, $bpLine] = explode(':', $bpSpec, 2);
        $breakpointMap[str_replace('\\', '/', $bpFile)][] = (int) $bpLine;
    } else {
        $breakpointMap[$scriptRelative][] = (int) $bpSpec;
    }
}

// ─── Setup ───────────────────────────────────────────────────────────────────

$session = ddless_setup_session($ddlessDir, $breakpointMap, $opts['depth']);
ddless_register_terminal_handler();

// Banner
fwrite(STDERR, "\n" . CLR_BOLD . "  DDLess Debug" . CLR_RESET . CLR_DIM . " — Engine Test" . CLR_RESET . "\n");
if ($opts['file'] !== null) {
    fwrite(STDERR, CLR_DIM . "  File: " . CLR_RESET . $scriptRelative . "\n");
} else {
    fwrite(STDERR, CLR_DIM . "  Code: " . CLR_RESET . substr($opts['code'], 0, 60) . (strlen($opts['code']) > 60 ? '...' : '') . "\n");
}
if (!empty($breakpointMap)) {
    foreach ($breakpointMap as $bpFile => $bpLines) {
        fwrite(STDERR, CLR_DIM . "  Breakpoints: " . CLR_RESET . $bpFile . " → lines " . implode(', ', $bpLines) . "\n");
    }
} else {
    fwrite(STDERR, CLR_YELLOW . "  No breakpoints set. Use --bp <line> to set breakpoints." . CLR_RESET . "\n");
}
fwrite(STDERR, CLR_DIM . "  Depth: " . CLR_RESET . $opts['depth'] . "\n");
fwrite(STDERR, CLR_DIM . "  ──────────────────────────────────────────" . CLR_RESET . "\n\n");

// Load engine
ddless_load_engine($ddlessDir, $projectRoot);

// ─── Execute ─────────────────────────────────────────────────────────────────

function ddless_engine_run_isolated(string $__ddless_script_path__): void
{
    include $__ddless_script_path__;
}

$exitCode = 0;

try {
    fwrite(STDERR, CLR_DIM . "  Running..." . CLR_RESET . "\n");
    ob_start('ddless_terminal_output_filter');
    ddless_engine_run_isolated($scriptPath);
    ob_end_flush();
} catch (\Throwable $e) {
    if (ob_get_level() > 0) ob_end_clean();
    ddless_display_exception($e);
    $exitCode = 1;
}

// ─── Cleanup ─────────────────────────────────────────────────────────────────

ddless_cleanup_session($session['sessionDir'], $tempCodeFile);

if ($exitCode === 0) {
    fwrite(STDERR, "\n" . CLR_GREEN . "  ✔ Execution completed." . CLR_RESET . "\n\n");
} else {
    fwrite(STDERR, "\n" . CLR_RED . "  ✖ Execution failed." . CLR_RESET . "\n\n");
}

exit($exitCode);
