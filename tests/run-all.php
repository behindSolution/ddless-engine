<?php
/**
 * DDLess Test Runner — executes all test files
 * Run: php tests/php/run-all.php
 */

echo "\033[1;37m╔══════════════════════════════════════════════════════════╗\033[0m\n";
echo "\033[1;37m║           DDLess Debug Engine — Test Suite              ║\033[0m\n";
echo "\033[1;37m╚══════════════════════════════════════════════════════════╝\033[0m\n";

require_once __DIR__ . '/bootstrap.php';

$testFiles = [
    'AnalyzeCodeAstTest.php',
    'ResolveBreakpointsTest.php',
    'InstrumentCodeAstTest.php',
    'CurrentFunctionInfoTest.php',
    'NormalizeValueTest.php',
    'InstrumentTraceTest.php',
    'HttpRequestTest.php',
    'EvalInContextTest.php',
    'BreakpointTimeoutTest.php',
];

foreach ($testFiles as $file) {
    require_once __DIR__ . '/' . $file;
}

// Final summary
$results = $GLOBALS['__test_results'];
echo "\n\033[1;37m" . str_repeat('=', 60) . "\033[0m\n";
if ($results['fail'] === 0) {
    echo "\033[1;32m  All {$results['pass']} tests passed!\033[0m\n";
} else {
    $total = $results['pass'] + $results['fail'];
    echo "\033[1;31m  {$results['fail']}/{$total} tests failed\033[0m\n\n";
    echo "\033[1;31mFailed tests:\033[0m\n";
    foreach ($results['errors'] as [$name, $e]) {
        echo "  - {$name}: {$e->getMessage()}\n";
    }
}
echo "\033[1;37m" . str_repeat('=', 60) . "\033[0m\n";

exit($results['fail'] > 0 ? 1 : 0);
