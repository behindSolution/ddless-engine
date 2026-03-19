<?php
/*
 * DDLess - PHP Debug Engine
 *
 * @author    Jefferson T.S
 * @copyright 2025-2026 DDLess
 * @license   Proprietary
 *
 * Method executor test runner. Boots a framework, resolves a class from the
 * container, and calls a method — same flow as the DDLess Test Method feature.
 * Used to validate framework integrations (method_executor.php).
 *
 * Usage (via test_trigger.php):
 *   php test_trigger.php --test method --framework laravel --class "App\Services\OrderService" --method calculate
 *   php test_trigger.php --test method --framework laravel --class "App\Services\OrderService" --method calculate --bp 32
 *   php test_trigger.php --test method --framework laravel --class "App\Services\OrderService" --method calculate --params-file params.php
 */

$paths = ddless_resolve_paths();
$ddlessDir = $paths['ddlessDir'];
$projectRoot = $paths['projectRoot'];

// ─── Argument parsing ────────────────────────────────────────────────────────

$opts = [
    'framework'    => null,
    'class'        => '',
    'method'       => null,
    'paramsFile'   => null, // PHP file that returns the method arguments array
    'ctorFile'     => null, // PHP file that returns constructor arguments array
    'bp'           => [],
    'depth'        => 4,
];

$args = array_slice($GLOBALS['argv'], 1);
$i = 0;

while ($i < count($args)) {
    $arg = $args[$i];
    switch ($arg) {
        case '--framework': case '-fw':
            $opts['framework'] = $args[++$i] ?? null;
            break;
        case '--class':
            $opts['class'] = $args[++$i] ?? '';
            break;
        case '--method': case '-m':
            $opts['method'] = $args[++$i] ?? null;
            break;
        case '--params-file': case '-pf':
            $opts['paramsFile'] = $args[++$i] ?? null;
            break;
        case '--ctor-file': case '-cf':
            $opts['ctorFile'] = $args[++$i] ?? null;
            break;
        case '--bp': case '-b':
            $opts['bp'][] = $args[++$i] ?? null;
            break;
        case '--depth': case '-d':
            $opts['depth'] = (int) ($args[++$i] ?? 4);
            break;
        case '--help': case '-h':
            fwrite(STDERR, <<<HELP

  DDLess Method Test — Framework Method Executor

  Usage:
    php test_trigger.php --test method --framework <name> --class <FQCN> --method <name> [options]

  Options:
    --framework, -fw <name>    Framework: laravel, symfony, codeigniter, tempest, wordpress, php
    --class <FQCN>             Fully qualified class name (empty for functions)
    --method, -m <name>        Method or function name
    --params-file, -pf <file>  PHP file returning method arguments (return [...])
    --ctor-file, -cf <file>    PHP file returning constructor arguments (return [...])
    --bp, -b <spec>            Breakpoint: file.php:line (repeatable)
    --depth, -d <n>            Variable serialization depth (default: 4)
    --help, -h                 Show this help

  Examples:
    php test_trigger.php --test method -fw laravel --class "App\\Services\\OrderService" -m calculate
    php test_trigger.php --test method -fw laravel --class "App\\Services\\OrderService" -m calculate --bp OrderService.php:45
    php test_trigger.php --test method -fw symfony --class "App\\Service\\Mailer" -m send --params-file test_params.php

  Params file example (test_params.php):
    <?php return ['order_id' => 42, 'include_tax' => true];

HELP);
            exit(0);
        default:
            fwrite(STDERR, CLR_RED . "Unknown option: {$arg}" . CLR_RESET . "\n");
            exit(1);
    }
    $i++;
}

if ($opts['framework'] === null) {
    fwrite(STDERR, CLR_RED . "Error: --framework is required." . CLR_RESET . "\n");
    exit(1);
}
if ($opts['method'] === null) {
    fwrite(STDERR, CLR_RED . "Error: --method is required." . CLR_RESET . "\n");
    exit(1);
}

// ─── Resolve executor ───────────────────────────────────────────────────────

$framework = $opts['framework'];
$executorPath = $ddlessDir . '/frameworks/' . $framework . '/method_executor.php';

if (!is_file($executorPath)) {
    fwrite(STDERR, CLR_RED . "Error: Method executor not found for framework: {$framework}" . CLR_RESET . "\n");
    fwrite(STDERR, CLR_DIM . "  Expected at: {$executorPath}" . CLR_RESET . "\n");

    // List available frameworks
    $available = [];
    $fwDir = $ddlessDir . '/frameworks';
    if (is_dir($fwDir)) {
        foreach (scandir($fwDir) as $dir) {
            if ($dir === '.' || $dir === '..') continue;
            if (is_file($fwDir . '/' . $dir . '/method_executor.php')) {
                $available[] = $dir;
            }
        }
    }
    if (!empty($available)) {
        fwrite(STDERR, CLR_DIM . "  Available: " . implode(', ', $available) . CLR_RESET . "\n");
    }
    exit(1);
}

// ─── Build method input ─────────────────────────────────────────────────────

$parameterCode = 'return [];';
if ($opts['paramsFile'] !== null) {
    $paramsPath = $opts['paramsFile'];
    if (!str_starts_with($paramsPath, '/') && !preg_match('/^[A-Za-z]:/', $paramsPath)) {
        $paramsPath = $projectRoot . DIRECTORY_SEPARATOR . $paramsPath;
    }
    if (!is_file($paramsPath)) {
        fwrite(STDERR, CLR_RED . "Error: Params file not found: {$paramsPath}" . CLR_RESET . "\n");
        exit(1);
    }
    $parameterCode = 'return include ' . var_export(realpath($paramsPath), true) . ';';
}

$constructorCode = 'return [];';
if ($opts['ctorFile'] !== null) {
    $ctorPath = $opts['ctorFile'];
    if (!str_starts_with($ctorPath, '/') && !preg_match('/^[A-Za-z]:/', $ctorPath)) {
        $ctorPath = $projectRoot . DIRECTORY_SEPARATOR . $ctorPath;
    }
    if (!is_file($ctorPath)) {
        fwrite(STDERR, CLR_RED . "Error: Constructor file not found: {$ctorPath}" . CLR_RESET . "\n");
        exit(1);
    }
    $constructorCode = 'return include ' . var_export(realpath($ctorPath), true) . ';';
}

$methodInput = [
    'class'           => $opts['class'],
    'method'          => $opts['method'],
    'parameterCode'   => $parameterCode,
    'constructorCode' => $constructorCode,
    'framework'       => $framework,
];

// ─── Parse breakpoints ──────────────────────────────────────────────────────

$breakpointMap = [];
foreach ($opts['bp'] as $bpSpec) {
    if ($bpSpec === null) continue;
    if (str_contains($bpSpec, ':')) {
        [$bpFile, $bpLine] = explode(':', $bpSpec, 2);
        $breakpointMap[str_replace('\\', '/', $bpFile)][] = (int) $bpLine;
    }
}

// ─── Setup ───────────────────────────────────────────────────────────────────

if (!defined('DDLESS_PROJECT_ROOT')) {
    define('DDLESS_PROJECT_ROOT', $projectRoot);
}

$hasBreakpoints = !empty($breakpointMap);
if ($hasBreakpoints) {
    $session = ddless_setup_session($ddlessDir, $breakpointMap, $opts['depth']);
    ddless_register_terminal_handler();
}

// Banner
fwrite(STDERR, "\n" . CLR_BOLD . "  DDLess Debug" . CLR_RESET . CLR_DIM . " — Method Test" . CLR_RESET . "\n");
fwrite(STDERR, CLR_DIM . "  Framework: " . CLR_RESET . $framework . "\n");
if ($opts['class'] !== '') {
    fwrite(STDERR, CLR_DIM . "  Class:     " . CLR_RESET . $opts['class'] . "\n");
}
fwrite(STDERR, CLR_DIM . "  Method:    " . CLR_RESET . $opts['method'] . "\n");
if (!empty($breakpointMap)) {
    foreach ($breakpointMap as $bpFile => $bpLines) {
        fwrite(STDERR, CLR_DIM . "  Breakpoints: " . CLR_RESET . $bpFile . " → lines " . implode(', ', $bpLines) . "\n");
    }
}
fwrite(STDERR, CLR_DIM . "  ──────────────────────────────────────────" . CLR_RESET . "\n\n");

// ─── Load engine & execute ──────────────────────────────────────────────────

if ($hasBreakpoints) {
    ddless_load_engine($ddlessDir, $projectRoot);
} else {
    // No breakpoints — just load autoload without debug engine
    $composerAutoload = $projectRoot . '/vendor/autoload.php';
    if (is_file($composerAutoload)) {
        require_once $composerAutoload;
    }
}

// Set up input for method_executor
$GLOBALS['__DDLESS_METHOD_INPUT__'] = json_encode($methodInput, JSON_UNESCAPED_SLASHES);
$_ENV['DDLESS_METHOD_INPUT_FILE'] = '__ALREADY_LOADED__';
putenv('DDLESS_METHOD_INPUT_FILE=__ALREADY_LOADED__');
putenv('DDLESS_FRAMEWORK=' . $framework);

fwrite(STDERR, CLR_DIM . "  Executing..." . CLR_RESET . "\n");

// Capture stdout to parse the result JSON
ob_start();

try {
    require $executorPath;
} catch (\SystemExit $e) {
    // method_executor calls exit() — we can't prevent that in same process
} catch (\Throwable $e) {
    // If the executor throws instead of using ddless_method_error
    if (ob_get_level() > 0) {
        $output = ob_get_clean();
    }
    ddless_display_exception($e);
    if ($hasBreakpoints) {
        ddless_cleanup_session($session['sessionDir']);
    }
    fwrite(STDERR, "\n" . CLR_RED . "  ✖ Method execution failed." . CLR_RESET . "\n\n");
    exit(1);
}

// Note: method_executor.php calls exit() after ddless_method_success/error,
// so we won't normally reach here. The result is captured via a shutdown function.

// ─── Shutdown handler to capture result ─────────────────────────────────────
// Since method_executor calls exit(), we register a shutdown function
// to parse and display the result.

$sessionForCleanup = $session ?? null;
register_shutdown_function(function () use ($hasBreakpoints, $sessionForCleanup) {
    $output = ob_get_clean();

    if ($output === false || trim($output) === '') {
        fwrite(STDERR, CLR_DIM . "  (no output captured)" . CLR_RESET . "\n");
    } else {
        // Filter DDLess markers from output
        $lines = explode("\n", $output);
        $jsonOutput = '';
        foreach ($lines as $line) {
            if (str_starts_with($line, '__DDLESS_')) continue;
            $jsonOutput .= $line . "\n";
        }

        $result = json_decode(trim($jsonOutput), true);
        if (is_array($result)) {
            ddless_display_result($result);
        } else {
            // Raw output — just print it
            fwrite(STDERR, "\n" . CLR_DIM . "  Output:" . CLR_RESET . "\n");
            fwrite(STDERR, "    " . trim($jsonOutput) . "\n");
        }
    }

    if ($hasBreakpoints && $sessionForCleanup !== null) {
        ddless_cleanup_session($sessionForCleanup['sessionDir']);
    }

    fwrite(STDERR, "\n");
});
