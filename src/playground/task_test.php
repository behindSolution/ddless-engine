<?php
/*
 * DDLess - PHP Debug Engine
 *
 * @author    Jefferson T.S
 * @copyright 2025-2026 DDLess
 * @license   Proprietary
 *
 * Task runner test. Boots a framework and executes arbitrary PHP code
 * with access to models, services, and the container — same flow as
 * the DDLess Task Runner feature. Used to validate framework integrations
 * (task_runner.php).
 *
 * Usage (via test_trigger.php):
 *   php test_trigger.php --test task --framework laravel --code '$this->info("hello");'
 *   php test_trigger.php --test task --framework laravel --file my_task.php
 *   php test_trigger.php --test task --framework laravel --code '$users = User::all();' --bp 1
 */

$paths = ddless_resolve_paths();
$ddlessDir = $paths['ddlessDir'];
$projectRoot = $paths['projectRoot'];

// ─── Argument parsing ────────────────────────────────────────────────────────

$opts = [
    'framework' => null,
    'code'      => null,
    'file'      => null,
    'imports'   => [],
    'bp'        => [],
    'depth'     => 4,
];

$args = array_slice($GLOBALS['argv'], 1);
$i = 0;

while ($i < count($args)) {
    $arg = $args[$i];
    switch ($arg) {
        case '--framework': case '-fw':
            $opts['framework'] = $args[++$i] ?? null;
            break;
        case '--code': case '-c':
            $opts['code'] = $args[++$i] ?? null;
            break;
        case '--file': case '-f':
            $opts['file'] = $args[++$i] ?? null;
            break;
        case '--import': case '-u':
            $opts['imports'][] = $args[++$i] ?? null;
            break;
        case '--bp': case '-b':
            $opts['bp'][] = $args[++$i] ?? null;
            break;
        case '--depth': case '-d':
            $opts['depth'] = (int) ($args[++$i] ?? 4);
            break;
        case '--help': case '-h':
            fwrite(STDERR, <<<HELP

  DDLess Task Test — Framework Task Runner

  Usage:
    php test_trigger.php --test task --framework <name> --code '<php code>' [options]
    php test_trigger.php --test task --framework <name> --file <task.php> [options]

  Options:
    --framework, -fw <name>   Framework: laravel, symfony, codeigniter, tempest, wordpress, php
    --code, -c <code>         PHP code to execute (without <?php tag)
    --file, -f <file>         PHP file with task code to execute
    --import, -u <namespace>  Use statement to add (repeatable, e.g. "App\\Models\\User")
    --bp, -b <spec>           Breakpoint: file.php:line (repeatable)
    --depth, -d <n>           Variable serialization depth (default: 4)
    --help, -h                Show this help

  Examples:
    php test_trigger.php --test task -fw laravel -c '\$this->info("Users: " . User::count());' -u "App\\Models\\User"
    php test_trigger.php --test task -fw laravel --file my_task.php
    php test_trigger.php --test task -fw symfony -c '\$em = \$this->get(EntityManagerInterface::class);'

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
if ($opts['code'] === null && $opts['file'] === null) {
    fwrite(STDERR, CLR_RED . "Error: --code or --file is required." . CLR_RESET . "\n");
    exit(1);
}

// ─── Resolve code ───────────────────────────────────────────────────────────

$taskCode = $opts['code'];

if ($opts['file'] !== null) {
    $filePath = $opts['file'];
    if (!str_starts_with($filePath, '/') && !preg_match('/^[A-Za-z]:/', $filePath)) {
        $filePath = $projectRoot . DIRECTORY_SEPARATOR . $filePath;
    }
    if (!is_file($filePath)) {
        fwrite(STDERR, CLR_RED . "Error: Task file not found: {$filePath}" . CLR_RESET . "\n");
        exit(1);
    }
    $taskCode = file_get_contents($filePath);
}

// ─── Resolve runner ─────────────────────────────────────────────────────────

$framework = $opts['framework'];
$runnerPath = $ddlessDir . '/frameworks/' . $framework . '/task_runner.php';

if (!is_file($runnerPath)) {
    fwrite(STDERR, CLR_RED . "Error: Task runner not found for framework: {$framework}" . CLR_RESET . "\n");
    fwrite(STDERR, CLR_DIM . "  Expected at: {$runnerPath}" . CLR_RESET . "\n");

    $available = [];
    $fwDir = $ddlessDir . '/frameworks';
    if (is_dir($fwDir)) {
        foreach (scandir($fwDir) as $dir) {
            if ($dir === '.' || $dir === '..') continue;
            if (is_file($fwDir . '/' . $dir . '/task_runner.php')) {
                $available[] = $dir;
            }
        }
    }
    if (!empty($available)) {
        fwrite(STDERR, CLR_DIM . "  Available: " . implode(', ', $available) . CLR_RESET . "\n");
    }
    exit(1);
}

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
fwrite(STDERR, "\n" . CLR_BOLD . "  DDLess Debug" . CLR_RESET . CLR_DIM . " — Task Test" . CLR_RESET . "\n");
fwrite(STDERR, CLR_DIM . "  Framework: " . CLR_RESET . $framework . "\n");
if ($opts['file'] !== null) {
    fwrite(STDERR, CLR_DIM . "  File:      " . CLR_RESET . $opts['file'] . "\n");
} else {
    $preview = substr($taskCode, 0, 60) . (strlen($taskCode) > 60 ? '...' : '');
    fwrite(STDERR, CLR_DIM . "  Code:      " . CLR_RESET . $preview . "\n");
}
if (!empty($opts['imports'])) {
    fwrite(STDERR, CLR_DIM . "  Imports:   " . CLR_RESET . implode(', ', $opts['imports']) . "\n");
}
if (!empty($breakpointMap)) {
    foreach ($breakpointMap as $bpFile => $bpLines) {
        fwrite(STDERR, CLR_DIM . "  Breakpoints: " . CLR_RESET . $bpFile . " → lines " . implode(', ', $bpLines) . "\n");
    }
}
fwrite(STDERR, CLR_DIM . "  ──────────────────────────────────────────" . CLR_RESET . "\n\n");

// ─── Build task input ───────────────────────────────────────────────────────

$taskInput = [
    'code'      => $taskCode,
    'imports'   => array_filter($opts['imports']),
    'framework' => $framework,
];

$GLOBALS['__DDLESS_TASK_INPUT__'] = json_encode($taskInput, JSON_UNESCAPED_SLASHES);

putenv('DDLESS_FRAMEWORK=' . $framework);

// ─── Execute ─────────────────────────────────────────────────────────────────

fwrite(STDERR, CLR_DIM . "  Executing..." . CLR_RESET . "\n");

// Capture stdout to parse task output markers
ob_start();

$exitCode = 0;

try {
    require $runnerPath;
} catch (\Throwable $e) {
    $exitCode = 1;
}

$output = ob_get_clean() ?: '';

// ─── Parse task output ──────────────────────────────────────────────────────

$taskOutputs = [];
$taskDone = null;

foreach (explode("\n", $output) as $line) {
    if (str_starts_with($line, '__DDLESS_TASK_OUTPUT__:')) {
        $json = substr($line, strlen('__DDLESS_TASK_OUTPUT__:'));
        $data = json_decode($json, true);
        if (is_array($data)) {
            $taskOutputs[] = $data;
        }
    } elseif (str_starts_with($line, '__DDLESS_TASK_DONE__:')) {
        $json = substr($line, strlen('__DDLESS_TASK_DONE__:'));
        $taskDone = json_decode($json, true);
    }
}

// Display task outputs
foreach ($taskOutputs as $entry) {
    $type = $entry['type'] ?? 'line';
    $message = $entry['message'] ?? '';

    switch ($type) {
        case 'info':
            fwrite(STDERR, CLR_CYAN . "  [info] " . CLR_RESET . $message . "\n");
            break;
        case 'error':
            fwrite(STDERR, CLR_RED . "  [error] " . CLR_RESET . $message . "\n");
            $exitCode = 1;
            break;
        case 'warn':
            fwrite(STDERR, CLR_YELLOW . "  [warn] " . CLR_RESET . $message . "\n");
            break;
        case 'line':
            fwrite(STDERR, "  " . $message . "\n");
            break;
        case 'comment':
            fwrite(STDERR, CLR_DIM . "  " . $message . CLR_RESET . "\n");
            break;
        case 'table':
            $headers = $entry['headers'] ?? [];
            $rows = $entry['rows'] ?? [];
            if (!empty($headers)) {
                fwrite(STDERR, "  " . CLR_BOLD . implode("\t", $headers) . CLR_RESET . "\n");
                foreach ($rows as $row) {
                    fwrite(STDERR, "  " . implode("\t", $row) . "\n");
                }
            }
            break;
        case 'json':
            $formatted = ddless_terminal_format_value($entry['data'] ?? null);
            fwrite(STDERR, "  " . $formatted . "\n");
            break;
        case 'exception':
            $exClass = $entry['exceptionClass'] ?? 'Exception';
            fwrite(STDERR, "\n" . CLR_BOLD . CLR_YELLOW . "  ⚠ {$exClass}" . CLR_RESET . "\n");
            fwrite(STDERR, "    " . $message . "\n");
            if (isset($entry['statusCode'])) {
                fwrite(STDERR, CLR_DIM . "    Status: " . CLR_RESET . $entry['statusCode'] . "\n");
            }
            if (isset($entry['errors']) && is_array($entry['errors'])) {
                foreach ($entry['errors'] as $field => $msgs) {
                    foreach ((array)$msgs as $msg) {
                        fwrite(STDERR, CLR_RED . "    • {$field}: {$msg}" . CLR_RESET . "\n");
                    }
                }
            }
            break;
        default:
            if ($message !== '') {
                fwrite(STDERR, CLR_DIM . "  [{$type}] " . CLR_RESET . $message . "\n");
            }
            break;
    }
}

// Task done summary
if ($taskDone !== null) {
    $ok = $taskDone['ok'] ?? false;
    $duration = $taskDone['durationMs'] ?? null;

    if ($ok) {
        $msg = CLR_GREEN . "  ✔ Task completed.";
        if ($duration !== null) {
            $msg .= CLR_DIM . " ({$duration}ms)";
        }
        fwrite(STDERR, "\n" . $msg . CLR_RESET . "\n\n");
    } else {
        $error = $taskDone['error'] ?? 'Unknown error';
        fwrite(STDERR, "\n" . CLR_RED . "  ✖ Task failed: {$error}" . CLR_RESET . "\n\n");
        $exitCode = 1;
    }
} elseif ($exitCode !== 0) {
    fwrite(STDERR, "\n" . CLR_RED . "  ✖ Task execution failed." . CLR_RESET . "\n\n");
}

// Cleanup
if ($hasBreakpoints && isset($session)) {
    ddless_cleanup_session($session['sessionDir']);
}

exit($exitCode);
