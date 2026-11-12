<?php
/**
 * DDLess Test Bootstrap
 *
 * Shared test runner, assertions, and debug.php loading.
 * Included by each individual test file.
 */

// Prevent double-loading
if (defined('DDLESS_TEST_BOOTSTRAP_LOADED')) return;
define('DDLESS_TEST_BOOTSTRAP_LOADED', true);

// ============================================================================
// Mini test runner (zero dependencies)
// ============================================================================

$GLOBALS['__test_results'] = ['pass' => 0, 'fail' => 0, 'errors' => []];

function test(string $name, callable $fn): void {
    try {
        $fn();
        $GLOBALS['__test_results']['pass']++;
        echo "  \033[32m✓\033[0m {$name}\n";
    } catch (\Throwable $e) {
        $GLOBALS['__test_results']['fail']++;
        $GLOBALS['__test_results']['errors'][] = [$name, $e];
        echo "  \033[31m✗\033[0m {$name}\n";
        echo "    \033[31m{$e->getMessage()}\033[0m\n";
        $trace = $e->getTrace();
        if (!empty($trace)) {
            $frame = $trace[0];
            echo "    at {$frame['file']}:{$frame['line']}\n";
        }
    }
}

function assert_eq($expected, $actual, string $msg = ''): void {
    if ($expected !== $actual) {
        $exp = var_export($expected, true);
        $act = var_export($actual, true);
        throw new \RuntimeException(
            ($msg ? "{$msg}: " : '') . "Expected {$exp}, got {$act}"
        );
    }
}

function assert_true(bool $value, string $msg = ''): void {
    if (!$value) {
        throw new \RuntimeException($msg ?: 'Expected true, got false');
    }
}

function assert_false(bool $value, string $msg = ''): void {
    if ($value) {
        throw new \RuntimeException($msg ?: 'Expected false, got true');
    }
}

function assert_null($value, string $msg = ''): void {
    if ($value !== null) {
        $act = var_export($value, true);
        throw new \RuntimeException(($msg ? "{$msg}: " : '') . "Expected null, got {$act}");
    }
}

function assert_not_null($value, string $msg = ''): void {
    if ($value === null) {
        throw new \RuntimeException(($msg ? "{$msg}: " : '') . 'Expected non-null, got null');
    }
}

function assert_contains(string $haystack, string $needle, string $msg = ''): void {
    if (strpos($haystack, $needle) === false) {
        throw new \RuntimeException(
            ($msg ? "{$msg}: " : '') . "Expected string to contain '{$needle}'"
        );
    }
}

function assert_not_contains(string $haystack, string $needle, string $msg = ''): void {
    if (strpos($haystack, $needle) !== false) {
        throw new \RuntimeException(
            ($msg ? "{$msg}: " : '') . "Expected string NOT to contain '{$needle}'"
        );
    }
}

function assert_array_has_key($key, array $arr, string $msg = ''): void {
    if (!array_key_exists($key, $arr)) {
        throw new \RuntimeException(
            ($msg ? "{$msg}: " : '') . "Expected array to have key '{$key}'"
        );
    }
}

function assert_array_not_has_key($key, array $arr, string $msg = ''): void {
    if (array_key_exists($key, $arr)) {
        throw new \RuntimeException(
            ($msg ? "{$msg}: " : '') . "Expected array NOT to have key '{$key}'"
        );
    }
}

function assert_count(int $expected, $countable, string $msg = ''): void {
    $actual = is_array($countable) ? count($countable) : $countable;
    if ($expected !== $actual) {
        throw new \RuntimeException(
            ($msg ? "{$msg}: " : '') . "Expected count {$expected}, got {$actual}"
        );
    }
}

function section(string $title): void {
    echo "\n\033[1;36m{$title}\033[0m\n";
}

function print_test_results(): int {
    $results = $GLOBALS['__test_results'];
    echo "\n" . str_repeat('-', 50) . "\n";
    if ($results['fail'] === 0) {
        echo "\033[32m  {$results['pass']} tests passed\033[0m\n";
    } else {
        $total = $results['pass'] + $results['fail'];
        echo "\033[1;31m  {$results['fail']}/{$total} tests failed\033[0m\n";
        foreach ($results['errors'] as [$name, $e]) {
            echo "  - {$name}: {$e->getMessage()}\n";
        }
    }
    echo str_repeat('-', 50) . "\n";
    return $results['fail'];
}

// ============================================================================
// Bootstrap: load debug.php
// ============================================================================

putenv('DDLESS_DEBUG_MODE=false');
putenv('DDLESS_CLI_MODE=true');

if (!defined('DDLESS_PROJECT_ROOT')) {
    define('DDLESS_PROJECT_ROOT', realpath(__DIR__ . '/../../'));
}

// Support both repo layouts:
//   Private repo: tests/php/bootstrap.php → ../../.ddless/debug.php
//   Public repo:  tests/bootstrap.php     → ../src/debug.php
$debugPhpPath = is_file(__DIR__ . '/../../.ddless/debug.php')
    ? __DIR__ . '/../../.ddless/debug.php'
    : __DIR__ . '/../src/debug.php';

ob_start();
require_once $debugPhpPath;
ob_end_clean();

$GLOBALS['__DDLESS_ALLOW_GLOBAL_SCOPE__'] = false;
$GLOBALS['__DDLESS_SERIALIZE_DEPTH__'] = 4;

// ============================================================================
// Shared helpers: debug mode and the file-based pause protocol
// ============================================================================

/** Run $fn with debug mode on, IPC stream silenced, and engine state restored after. */
function with_debug_mode(callable $fn)
{
    $previousMode = getenv('DDLESS_DEBUG_MODE');
    $previousStream = $GLOBALS['__DDLESS_IPC_STREAM__'] ?? null;
    $previousHandler = $GLOBALS['__DDLESS_TERMINAL_HANDLER__'] ?? null;
    $mem = fopen('php://memory', 'w+');

    putenv('DDLESS_DEBUG_MODE=true');
    $GLOBALS['__DDLESS_IPC_STREAM__'] = $mem;
    $GLOBALS['__DDLESS_HIT_USER_BPS__'] = [];
    $GLOBALS['__DDLESS_HIT_COUNTS__'] = [];
    $GLOBALS['__DDLESS_MODIFIED_VARS__'] = null;
    $GLOBALS['__DDLESS_STEP_IN_MODE__'] = false;
    $GLOBALS['__DDLESS_STEP_OVER_FUNCTION__'] = null;
    $GLOBALS['__DDLESS_STEP_OVER_FILE__'] = null;
    $GLOBALS['__DDLESS_STEP_OUT_TARGET__'] = null;

    try {
        return $fn();
    } finally {
        putenv($previousMode === false ? 'DDLESS_DEBUG_MODE' : "DDLESS_DEBUG_MODE={$previousMode}");
        $GLOBALS['__DDLESS_IPC_STREAM__'] = $previousStream;
        if ($previousHandler === null) {
            unset($GLOBALS['__DDLESS_TERMINAL_HANDLER__']);
        } else {
            $GLOBALS['__DDLESS_TERMINAL_HANDLER__'] = $previousHandler;
        }
        $GLOBALS['__DDLESS_HIT_USER_BPS__'] = [];
        $GLOBALS['__DDLESS_HIT_COUNTS__'] = [];
        $GLOBALS['__DDLESS_MODIFIED_VARS__'] = null;
        $GLOBALS['__DDLESS_STEP_IN_MODE__'] = false;
        $GLOBALS['__DDLESS_STEP_OVER_FUNCTION__'] = null;
        $GLOBALS['__DDLESS_STEP_OVER_FILE__'] = null;
        $GLOBALS['__DDLESS_STEP_OUT_TARGET__'] = null;
        fclose($mem);
    }
}

/**
 * Drive the real file-based pause protocol: a child PHP process plays the
 * Electron side, answering the breakpoint with the given command steps while
 * $run executes in this process and pauses for real.
 *
 * A step is ['command' => 'evaluate', 'expression' => '...'] or
 * ['command' => 'continue']. 'raceContinue' => true on an evaluate step sends
 * a Continue while that evaluate is still running.
 *
 * @return array{results: array, elapsed: float, return: mixed}
 */
function run_paused_session(array $steps, callable $run, int $timeoutSeconds = 3): array
{
    $sessionId = 'ddless-test-pause-session';
    $previousSession = getenv('DDLESS_DEBUG_SESSION');
    $previousTimeout = $GLOBALS['__DDLESS_BREAKPOINT_TIMEOUT__'];
    putenv("DDLESS_DEBUG_SESSION={$sessionId}");
    $GLOBALS['__DDLESS_BREAKPOINT_TIMEOUT__'] = $timeoutSeconds;
    $sessionDir = ddless_get_session_dir();
    @unlink($sessionDir . '/breakpoint_state.json');
    @unlink($sessionDir . '/breakpoint_command.json');

    $tmp = sys_get_temp_dir() . '/ddless_pause_' . uniqid();
    mkdir($tmp, 0755, true);
    $planFile = $tmp . '/plan.json';
    $resultFile = $tmp . '/results.json';
    $responderFile = $tmp . '/responder.php';
    file_put_contents($planFile, json_encode($steps));
    file_put_contents($responderFile, <<<'PHP'
<?php
[$self, $dir, $planFile, $resultFile] = $argv;
$state = $dir . '/breakpoint_state.json';
$cmd = $dir . '/breakpoint_command.json';
$waitState = function (string $type, ?string $requestId = null) use ($state) {
    $start = microtime(true);
    while (microtime(true) - $start < 8) {
        clearstatcache();
        $data = @json_decode((string) @file_get_contents($state), true);
        if (is_array($data) && ($data['type'] ?? '') === $type
            && ($requestId === null || ($data['requestId'] ?? '') === $requestId)) {
            return $data;
        }
        usleep(20000);
    }
    return null;
};
$send = function (array $data) use ($cmd) {
    file_put_contents($cmd . '.tmp', json_encode($data));
    rename($cmd . '.tmp', $cmd);
};
$results = [];
$waitState('breakpoint');
foreach (json_decode(file_get_contents($planFile), true) as $i => $step) {
    if ($step['command'] !== 'evaluate') {
        $send($step);
        continue;
    }
    $requestId = 'r' . $i;
    $send(['command' => 'evaluate', 'expression' => $step['expression'], 'requestId' => $requestId]);
    if (!empty($step['raceContinue'])) {
        // Wait until PHP picked the evaluate up, then press Continue mid-evaluate.
        $start = microtime(true);
        while (microtime(true) - $start < 8) {
            clearstatcache();
            if (!is_file($cmd)) break;
            usleep(5000);
        }
        $send(['command' => 'continue']);
        break;
    }
    $results[] = $waitState('evaluate_result', $requestId);
}
file_put_contents($resultFile, json_encode($results));
PHP
    );

    $process = proc_open(
        [PHP_BINARY, $responderFile, $sessionDir, $planFile, $resultFile],
        [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
        $pipes
    );

    $started = microtime(true);
    try {
        $return = with_debug_mode(function () use ($run) {
            unset($GLOBALS['__DDLESS_TERMINAL_HANDLER__']);
            return $run();
        });
    } finally {
        $elapsed = microtime(true) - $started;
        foreach ($pipes as $pipe) {
            @fclose($pipe);
        }
        proc_close($process);

        $results = is_file($resultFile) ? (json_decode(file_get_contents($resultFile), true) ?: []) : [];
        @unlink($planFile);
        @unlink($resultFile);
        @unlink($responderFile);
        @rmdir($tmp);
        @unlink($sessionDir . '/breakpoint_state.json');
        @unlink($sessionDir . '/breakpoint_command.json');
        @unlink($sessionDir . '/breakpoint_command.json.tmp');
        @rmdir($sessionDir);
        putenv($previousSession === false ? 'DDLESS_DEBUG_SESSION' : "DDLESS_DEBUG_SESSION={$previousSession}");
        $GLOBALS['__DDLESS_BREAKPOINT_TIMEOUT__'] = $previousTimeout;
    }

    return ['results' => $results, 'elapsed' => $elapsed, 'return' => $return];
}

/**
 * Create a throwaway project with its own copy of debug.php, so a test can
 * exercise what only happens at load time (breakpoints.json parsing, cache,
 * manifest) without touching the real .ddless directory.
 *
 * @param array<string, string> $files relative path => contents
 */
function create_isolated_project(array $files = []): string
{
    $root = sys_get_temp_dir() . '/ddless_isolated_' . uniqid();
    mkdir($root . '/.ddless', 0755, true);
    copy($GLOBALS['debugPhpPath'], $root . '/.ddless/debug.php');
    foreach ($files as $relative => $contents) {
        $target = $root . '/' . $relative;
        if (!is_dir(dirname($target))) {
            mkdir(dirname($target), 0755, true);
        }
        file_put_contents($target, $contents);
    }
    return str_replace('\\', '/', realpath($root));
}

function remove_isolated_project(string $root): void
{
    if (!is_dir($root)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $entry) {
        $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
    }
    @rmdir($root);
}

/**
 * Load the isolated project's debug.php in a fresh PHP process and run $body
 * after it. $body is PHP code that must echo one JSON document on its last
 * line; the decoded value is returned.
 *
 * @param array<string, string> $env
 */
function run_isolated_engine(string $root, string $body, array $env = [])
{
    $parserDir = str_replace('\\', '/', dirname($GLOBALS['debugPhpPath'])) . '/vendor-internal/nikic/php-parser/lib/PhpParser/';
    $script = $root . '/__run_' . uniqid() . '.php';
    file_put_contents($script, "<?php\n"
        . "error_reporting(E_ALL & ~E_DEPRECATED);\n"
        . "spl_autoload_register(function (\$class) {\n"
        . "    if (strncmp(\$class, 'PhpParser\\\\', 10) !== 0) return;\n"
        . "    \$file = " . var_export($parserDir, true) . " . str_replace('\\\\', '/', substr(\$class, 10)) . '.php';\n"
        . "    if (is_file(\$file)) require \$file;\n"
        . "});\n"
        . "\$__before = array_keys(get_defined_vars());\n"
        . "require " . var_export($root . '/.ddless/debug.php', true) . ";\n"
        . "\$__leaked = array_values(array_diff(array_keys(get_defined_vars()), \$__before, ['__before']));\n"
        . $body . "\n");

    $environment = array_merge(getenv(), [
        'DDLESS_CLI_MODE' => '1',
        'DDLESS_DEBUG_MODE' => 'false',
        'DDLESS_DEBUG_SESSION' => 'isolated',
    ], $env);

    $process = proc_open([PHP_BINARY, $script], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, $root, $environment);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    @unlink($script);

    $lines = array_values(array_filter(preg_split("/\r?\n/", trim((string) $stdout)), fn($line) => $line !== ''));
    $decoded = $lines ? json_decode(end($lines), true) : null;
    if ($decoded === null) {
        throw new \RuntimeException("isolated engine produced no JSON.\nstdout: {$stdout}\nstderr: {$stderr}");
    }
    return $decoded;
}
