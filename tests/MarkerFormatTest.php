<?php
/**
 * Contract tests for stdout marker lines consumed by electron/debug/cli-handler.ts.
 * If these fail, the Node-side NDJSON parsing will silently stop seeing events.
 * Run: php tests/php/MarkerFormatTest.php
 */
require_once __DIR__ . '/bootstrap.php';

function capture_markers(callable $fn): array
{
    $memStream = fopen('php://memory', 'w+');
    $originalStream = $GLOBALS['__DDLESS_IPC_STREAM__'] ?? null;
    $GLOBALS['__DDLESS_IPC_STREAM__'] = $memStream;
    try {
        $fn();
    } finally {
        $GLOBALS['__DDLESS_IPC_STREAM__'] = $originalStream;
    }
    rewind($memStream);
    $output = stream_get_contents($memStream);
    fclose($memStream);

    $lines = [];
    foreach (preg_split("/\r?\n/", $output) as $line) {
        if ($line === '') continue;
        $lines[] = $line;
    }
    return $lines;
}

function parse_marker(string $line, string $prefix): array
{
    assert_true(
        strpos($line, $prefix) === 0,
        "line must start with '{$prefix}' but got: " . substr($line, 0, 40)
    );
    $json = substr($line, strlen($prefix));
    $decoded = json_decode($json, true);
    assert_true(is_array($decoded), "payload after '{$prefix}' must be valid JSON object");
    return $decoded;
}

function assert_has_keys(array $payload, array $keys, string $marker): void
{
    foreach ($keys as $k) {
        assert_array_has_key($k, $payload, "{$marker} payload missing key '{$k}'");
    }
}

// ============================================================================

section('Marker format: __DDLESS_PROGRESS__');

test('emits prefix + valid JSON with expected keys', function () {
    unset($GLOBALS['__DDLESS_TERMINAL_HANDLER__']);
    $lines = capture_markers(function () {
        ddless_emit_progress(3, 10, 'app/Models/User.php');
    });
    assert_count(1, $lines, 'exactly one progress line emitted');
    $payload = parse_marker($lines[0], '__DDLESS_PROGRESS__:');
    assert_has_keys($payload, ['type', 'current', 'total', 'percent', 'currentFile'], 'PROGRESS');
    assert_eq(3, $payload['current']);
    assert_eq(10, $payload['total']);
    assert_eq(30, $payload['percent']);
    assert_eq('app/Models/User.php', $payload['currentFile']);
});

section('Marker format: __DDLESS_TRACE__ / __DDLESS_TRACE_EXIT__');

test('trace_fn emits prefix + required keys', function () {
    $GLOBALS['__DDLESS_TRACE_REQUEST_START__'] = microtime(true);
    $GLOBALS['__DDLESS_TRACE_STARTS__'] = [];

    $seq = 0;
    $lines = capture_markers(function () use (&$seq) {
        $seq = ddless_trace_fn('/app/Foo.php', 42);
    });

    assert_count(1, $lines);
    $payload = parse_marker($lines[0], '__DDLESS_TRACE__:');
    assert_has_keys($payload, ['seq', 'label', 'file', 'line', 'depth', 'start_ms'], 'TRACE');
    assert_eq('/app/Foo.php', $payload['file']);
    assert_eq(42, $payload['line']);
    assert_true(is_int($payload['seq']) && $payload['seq'] > 0, 'seq must be positive int');
    assert_true(is_numeric($payload['start_ms']), 'start_ms must be numeric');
});

test('trace_exit emits prefix + seq and duration_ms', function () {
    $GLOBALS['__DDLESS_TRACE_REQUEST_START__'] = microtime(true);
    $GLOBALS['__DDLESS_TRACE_STARTS__'] = [];

    $seq = 0;
    capture_markers(function () use (&$seq) {
        $seq = ddless_trace_fn('/app/Foo.php', 42);
    });

    $lines = capture_markers(function () use ($seq) {
        ddless_trace_exit($seq);
    });

    assert_count(1, $lines);
    $payload = parse_marker($lines[0], '__DDLESS_TRACE_EXIT__:');
    assert_has_keys($payload, ['seq', 'duration_ms'], 'TRACE_EXIT');
    assert_eq($seq, $payload['seq']);
    assert_true(is_numeric($payload['duration_ms']), 'duration_ms must be numeric');
});

section('Marker format: __DDLESS_LOGPOINT__');

test('step_check emits logpoint line with expected keys', function () {
    $GLOBALS['__DDLESS_WATCHES__'] = [];
    $GLOBALS['__DDLESS_WATCH_LAST__'] = [];
    $GLOBALS['__DDLESS_WATCH_PREV_LOC__'] = null;
    $GLOBALS['__DDLESS_HIT_USER_BPS__'] = [];
    $GLOBALS['__DDLESS_LOGPOINT_DEDUP__'] = [];
    $GLOBALS['__DDLESS_STEP_IN_MODE__'] = false;
    $GLOBALS['__DDLESS_STEP_OVER_FUNCTION__'] = null;
    $GLOBALS['__DDLESS_STEP_OUT_TARGET__'] = null;

    $scopeVariables = ['user' => 'alice'];
    $backtrace = [['function' => 'test', 'file' => '/app/TestFile.php', 'line' => 10]];

    $lines = capture_markers(function () use ($scopeVariables, $backtrace) {
        ddless_step_check(
            '/app/TestFile.php', 20, 'app/TestFile.php', true,
            '', $scopeVariables, $backtrace,
            'user is {$user}', '', '', ''
        );
    });

    $logLines = array_values(array_filter($lines, fn($l) => strpos($l, '__DDLESS_LOGPOINT__:') === 0));
    assert_true(count($logLines) >= 1, 'at least one logpoint line emitted');
    $payload = parse_marker($logLines[0], '__DDLESS_LOGPOINT__:');
    assert_has_keys($payload, ['file', 'line', 'message', 'timestamp'], 'LOGPOINT');
    assert_eq('app/TestFile.php', $payload['file']);
    assert_eq(20, $payload['line']);
    assert_contains($payload['message'], 'alice', 'message must interpolate variable');
});

// ============================================================================

if (realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    exit(print_test_results());
}
