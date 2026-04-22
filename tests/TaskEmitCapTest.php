<?php
/**
 * Contract tests for ddless_task_emit size cap across every framework task runner.
 * Any payload whose JSON encoding exceeds 256 KB (or fails) must be replaced
 * with an `alert` marker so the Electron renderer does not crash.
 *
 * Run: php tests/php/TaskEmitCapTest.php
 */
require_once __DIR__ . '/bootstrap.php';

/**
 * Extract the `ddless_task_emit` function from a framework task_runner.php
 * and eval it under a unique name so we can call it without bootstrapping
 * the whole framework.
 */
function load_framework_emit(string $frameworkFile, string $newFnName): void
{
    $source = file_get_contents($frameworkFile);
    if ($source === false) {
        throw new RuntimeException("Cannot read {$frameworkFile}");
    }
    // Match from `function ddless_task_emit` up to (but not including) the next top-level `function `.
    if (!preg_match('/function\s+ddless_task_emit\b.*?(?=\nfunction\s+\w)/s', $source, $m)) {
        throw new RuntimeException("Could not extract ddless_task_emit from {$frameworkFile}");
    }
    $code = preg_replace('/function\s+ddless_task_emit/', "function {$newFnName}", $m[0], 1);
    eval($code);
}

/**
 * Capture the marker line emitted by a task_emit call and decode its JSON payload.
 */
function capture_emitted_marker(callable $fn): array
{
    ob_start();
    try {
        $fn();
    } finally {
        $output = ob_get_clean();
    }
    $line = trim($output);
    $prefix = '__DDLESS_TASK_OUTPUT__:';
    if (strpos($line, $prefix) !== 0) {
        throw new RuntimeException("Missing expected prefix. Got: " . substr($line, 0, 100));
    }
    $decoded = json_decode(substr($line, strlen($prefix)), true);
    if (!is_array($decoded)) {
        throw new RuntimeException("Emitted payload is not valid JSON: " . substr($line, 0, 200));
    }
    return $decoded;
}

$FRAMEWORKS = [
    'cakephp', 'codeigniter', 'drupal', 'laravel', 'php',
    'symfony', 'tempest', 'wordpress', 'yii2',
];

// Load each framework's emit function under a unique name.
$emitFns = [];
foreach ($FRAMEWORKS as $fw) {
    $fn = 'test_emit_' . $fw;
    load_framework_emit(DDLESS_PROJECT_ROOT . "/.ddless/frameworks/{$fw}/task_runner.php", $fn);
    $emitFns[$fw] = $fn;
}

// ============================================================================

section('Task emit cap: small payloads pass through unchanged');

foreach ($emitFns as $fw => $fn) {
    test("{$fw}: small json payload keeps original type and data", function () use ($fn) {
        $payload = capture_emitted_marker(function () use ($fn) {
            $fn('json', ['data' => ['name' => 'alice', 'age' => 30]]);
        });
        assert_eq('json', $payload['type'], 'type preserved');
        assert_true(isset($payload['data']), 'data key preserved');
        assert_eq('alice', $payload['data']['name']);
        assert_eq(30, $payload['data']['age']);
    });
}

section('Task emit cap: oversized payloads become alerts');

foreach ($emitFns as $fw => $fn) {
    test("{$fw}: 500 KB payload is replaced with alert", function () use ($fn) {
        $big = str_repeat('x', 500 * 1024);
        $payload = capture_emitted_marker(function () use ($fn, $big) {
            $fn('json', ['data' => $big]);
        });
        assert_eq('alert', $payload['type'], 'oversized payload must convert to alert');
        assert_array_has_key('message', $payload, 'alert must carry message');
        assert_contains($payload['message'], '[JSON]', 'alert mentions original type');
        assert_contains($payload['message'], '256 KB', 'alert mentions the threshold');
    });
}

section('Task emit cap: exactly-at-threshold payloads pass, just-over are capped');

foreach ($emitFns as $fw => $fn) {
    test("{$fw}: payload slightly under 256 KB is not capped", function () use ($fn) {
        // Leave plenty of room for the envelope overhead (type, timestamp, keys).
        $underLimit = str_repeat('y', 260000);
        $payload = capture_emitted_marker(function () use ($fn, $underLimit) {
            $fn('json', ['data' => $underLimit]);
        });
        assert_eq('json', $payload['type'], 'under-limit payload must not be capped');
    });

    test("{$fw}: payload comfortably over 256 KB is capped", function () use ($fn) {
        $overLimit = str_repeat('z', 300 * 1024);
        $payload = capture_emitted_marker(function () use ($fn, $overLimit) {
            $fn('json', ['data' => $overLimit]);
        });
        assert_eq('alert', $payload['type'], 'over-limit payload must be capped');
    });
}

section('Task emit cap: unserializable payloads become alerts');

foreach ($emitFns as $fw => $fn) {
    test("{$fw}: invalid UTF-8 triggers serialization-failure alert", function () use ($fn) {
        // json_encode returns false for invalid UTF-8 without JSON_INVALID_UTF8_SUBSTITUTE.
        $invalidUtf8 = "\xB1\x31\xFF";
        $payload = capture_emitted_marker(function () use ($fn, $invalidUtf8) {
            $fn('json', ['data' => $invalidUtf8]);
        });
        assert_eq('alert', $payload['type'], 'unserializable payload must become alert');
        assert_contains($payload['message'], 'serialization failed', 'alert explains the failure');
    });
}

// ============================================================================

if (realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    exit(print_test_results());
}
