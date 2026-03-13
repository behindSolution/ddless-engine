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
