<?php
/**
 * Tests for watch history tracking in ddless_step_check.
 * Run: php tests/php/WatchHistoryTest.php
 */
require_once __DIR__ . '/bootstrap.php';

/**
 * Capture watch history markers emitted during step_check calls.
 * Returns an array of decoded history entries in order.
 */
function capture_watch_history(callable $fn): array
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

    $entries = [];
    foreach (preg_split("/\r?\n/", $output) as $line) {
        if (strpos($line, '__DDLESS_WATCH_HISTORY__:') === 0) {
            $json = substr($line, strlen('__DDLESS_WATCH_HISTORY__:'));
            $decoded = json_decode($json, true);
            if (is_array($decoded)) $entries[] = $decoded;
        }
    }
    return $entries;
}

/**
 * Reset watch-related globals to a clean state.
 */
function reset_watch_state(array $watches = []): void
{
    $GLOBALS['__DDLESS_WATCHES__'] = $watches;
    $GLOBALS['__DDLESS_WATCH_LAST__'] = [];
    $GLOBALS['__DDLESS_WATCH_PREV_LOC__'] = null;
    $GLOBALS['__DDLESS_HIT_USER_BPS__'] = [];
    $GLOBALS['__DDLESS_STEP_IN_MODE__'] = false;
    $GLOBALS['__DDLESS_STEP_OVER_FUNCTION__'] = null;
    $GLOBALS['__DDLESS_STEP_OUT_TARGET__'] = null;
}

function fake_backtrace(): array
{
    return [
        ['function' => 'test', 'file' => '/app/TestFile.php', 'line' => 10],
    ];
}

// ============================================================================

section('Watch history: basic value change detection');

test('records the initial value on first capture', function () {
    reset_watch_state(['$pointOfSaleId']);
    $entries = capture_watch_history(function () {
        ddless_step_check(
            '/app/TestFile.php', 20, 'app/TestFile.php', false,
            '', ['pointOfSaleId' => 6252], fake_backtrace(),
            '', '', '', ''
        );
    });

    assert_count(1, $entries, 'should emit one entry for first capture');
    assert_eq(6252, $entries[0]['value'], 'value should be 6252');
    assert_eq(20, $entries[0]['line'], 'first capture uses current line');
});

test('does not emit when value is unchanged', function () {
    reset_watch_state(['$pointOfSaleId']);
    $entries = capture_watch_history(function () {
        ddless_step_check('/app/TestFile.php', 20, 'app/TestFile.php', false, '', ['pointOfSaleId' => 6252], fake_backtrace(), '', '', '', '');
        ddless_step_check('/app/TestFile.php', 21, 'app/TestFile.php', false, '', ['pointOfSaleId' => 6252], fake_backtrace(), '', '', '', '');
        ddless_step_check('/app/TestFile.php', 22, 'app/TestFile.php', false, '', ['pointOfSaleId' => 6252], fake_backtrace(), '', '', '', '');
    });

    assert_count(1, $entries, 'should only emit once — value never changed');
});

test('emits a new entry when value changes', function () {
    reset_watch_state(['$status']);
    $entries = capture_watch_history(function () {
        ddless_step_check('/app/Order.php', 10, 'app/Order.php', false, '', ['status' => 'pending'], fake_backtrace(), '', '', '', '');
        ddless_step_check('/app/Order.php', 15, 'app/Order.php', false, '', ['status' => 'approved'], fake_backtrace(), '', '', '', '');
    });

    assert_count(2, $entries);
    assert_eq('pending', $entries[0]['value']);
    assert_eq('approved', $entries[1]['value']);
});

// ============================================================================

section('Watch history: step_check is injected BEFORE line, so changes map to previous location');

test('change is attributed to the previous step_check location', function () {
    reset_watch_state(['$x']);
    $entries = capture_watch_history(function () {
        ddless_step_check('/app/A.php', 10, 'app/A.php', false, '', ['x' => 5], fake_backtrace(), '', '', '', '');
        ddless_step_check('/app/A.php', 11, 'app/A.php', false, '', ['x' => 10], fake_backtrace(), '', '', '', '');
    });

    assert_count(2, $entries);
    assert_eq(10, $entries[0]['line'], 'first capture uses its own line');
    assert_eq(10, $entries[1]['line'], 'subsequent change uses the PREVIOUS step_check line (where assignment happened)');
    assert_eq(10, $entries[1]['value'], 'value captured at line 11 reflects new value');
});

test('change crossing files uses the previous file', function () {
    reset_watch_state(['$x']);
    $entries = capture_watch_history(function () {
        ddless_step_check('/app/A.php', 10, 'app/A.php', false, '', ['x' => 1], fake_backtrace(), '', '', '', '');
        ddless_step_check('/app/B.php', 5, 'app/B.php', false, '', ['x' => 2], fake_backtrace(), '', '', '', '');
    });

    assert_count(2, $entries);
    assert_eq('app/A.php', $entries[0]['file']);
    assert_eq('app/A.php', $entries[1]['file'], 'change at B.php:5 attributed to A.php (previous step_check)');
    assert_eq(10, $entries[1]['line']);
});

// ============================================================================

section('Watch history: skips when root variable not in scope');

test('silently skips when variable does not exist (closure without use)', function () {
    reset_watch_state(['$pointOfSaleId']);
    $entries = capture_watch_history(function () {
        ddless_step_check('/app/A.php', 10, 'app/A.php', false, '', ['pointOfSaleId' => 6252], fake_backtrace(), '', '', '', '');
        ddless_step_check('/app/A.php', 20, 'app/A.php', false, '', ['q' => 'closure_scope'], fake_backtrace(), '', '', '', '');
        ddless_step_check('/app/A.php', 30, 'app/A.php', false, '', ['pointOfSaleId' => 6252], fake_backtrace(), '', '', '', '');
    });

    assert_count(1, $entries, 'should emit only the first capture — scope without variable is skipped');
    assert_eq(6252, $entries[0]['value']);
});

test('re-emits when variable reappears with a different value', function () {
    reset_watch_state(['$x']);
    $entries = capture_watch_history(function () {
        ddless_step_check('/app/A.php', 10, 'app/A.php', false, '', ['x' => 100], fake_backtrace(), '', '', '', '');
        ddless_step_check('/app/A.php', 20, 'app/A.php', false, '', ['other' => 'nope'], fake_backtrace(), '', '', '', '');
        ddless_step_check('/app/A.php', 30, 'app/A.php', false, '', ['x' => 200], fake_backtrace(), '', '', '', '');
    });

    assert_count(2, $entries);
    assert_eq(100, $entries[0]['value']);
    assert_eq(200, $entries[1]['value']);
});

// ============================================================================

section('Watch history: nested property access');

test('tracks $user->id when $user exists', function () {
    reset_watch_state(['$user->id']);
    $user = new stdClass();
    $user->id = 42;
    $entries = capture_watch_history(function () use ($user) {
        ddless_step_check('/app/A.php', 10, 'app/A.php', false, '', ['user' => $user], fake_backtrace(), '', '', '', '');
    });

    assert_count(1, $entries);
    assert_eq(42, $entries[0]['value']);
});

test('skips $user->id when $user is not in scope', function () {
    reset_watch_state(['$user->id']);
    $entries = capture_watch_history(function () {
        ddless_step_check('/app/A.php', 10, 'app/A.php', false, '', ['other' => 'x'], fake_backtrace(), '', '', '', '');
    });

    assert_count(0, $entries, 'no $user in scope → skip');
});

// ============================================================================

section('Watch history: multiple watches');

test('tracks multiple watch expressions independently', function () {
    reset_watch_state(['$a', '$b']);
    $entries = capture_watch_history(function () {
        ddless_step_check('/app/A.php', 10, 'app/A.php', false, '', ['a' => 1, 'b' => 10], fake_backtrace(), '', '', '', '');
        ddless_step_check('/app/A.php', 11, 'app/A.php', false, '', ['a' => 2, 'b' => 10], fake_backtrace(), '', '', '', '');
        ddless_step_check('/app/A.php', 12, 'app/A.php', false, '', ['a' => 2, 'b' => 20], fake_backtrace(), '', '', '', '');
    });

    $aEntries = array_values(array_filter($entries, fn($e) => $e['expr'] === '$a'));
    $bEntries = array_values(array_filter($entries, fn($e) => $e['expr'] === '$b'));

    assert_count(2, $aEntries, '$a: initial + 1 change');
    assert_count(2, $bEntries, '$b: initial + 1 change');
    assert_eq(1, $aEntries[0]['value']);
    assert_eq(2, $aEntries[1]['value']);
    assert_eq(10, $bEntries[0]['value']);
    assert_eq(20, $bEntries[1]['value']);
});

// ============================================================================

section('Watch history: no watches configured');

test('does not emit anything when __DDLESS_WATCHES__ is empty', function () {
    reset_watch_state([]);
    $entries = capture_watch_history(function () {
        ddless_step_check('/app/A.php', 10, 'app/A.php', false, '', ['x' => 1], fake_backtrace(), '', '', '', '');
    });

    assert_count(0, $entries);
});

// ============================================================================

exit(print_test_results());
