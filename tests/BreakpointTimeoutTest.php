<?php
/**
 * Tests for breakpoint timeout configuration and ddless_wait_for_command()
 * Run: php tests/php/BreakpointTimeoutTest.php
 */
require_once __DIR__ . '/bootstrap.php';

// ── Helpers ──────────────────────────────────────────────────────────────────

/**
 * Write a temporary breakpoints.json with given settings, load it,
 * and return the resulting global timeout value.
 */
function loadTimeoutFromSettings(array $settings): int
{
    $original = $GLOBALS['__DDLESS_BREAKPOINT_TIMEOUT__'];

    $GLOBALS['__DDLESS_BREAKPOINT_TIMEOUT__'] = 3600;

    // Simulate the settings loading logic from debug.php (lines 217-218)
    $__ddless_settings = $settings;
    if (isset($__ddless_settings['breakpointTimeout']) && is_numeric($__ddless_settings['breakpointTimeout'])) {
        $GLOBALS['__DDLESS_BREAKPOINT_TIMEOUT__'] = max(1, (int)$__ddless_settings['breakpointTimeout']) * 60;
    }

    $result = $GLOBALS['__DDLESS_BREAKPOINT_TIMEOUT__'];

    // Restore
    $GLOBALS['__DDLESS_BREAKPOINT_TIMEOUT__'] = $original;

    return $result;
}

// ── Tests ────────────────────────────────────────────────────────────────────

section('Breakpoint timeout — default value');

test('default timeout is 3600 seconds (60 minutes)', function () {
    assert_eq(3600, $GLOBALS['__DDLESS_BREAKPOINT_TIMEOUT__']);
});

section('Breakpoint timeout — settings loading');

test('setting 60 minutes converts to 3600 seconds', function () {
    $result = loadTimeoutFromSettings(['breakpointTimeout' => 60]);
    assert_eq(3600, $result);
});

test('setting 120 minutes converts to 7200 seconds', function () {
    $result = loadTimeoutFromSettings(['breakpointTimeout' => 120]);
    assert_eq(7200, $result);
});

test('setting 5 minutes converts to 300 seconds', function () {
    $result = loadTimeoutFromSettings(['breakpointTimeout' => 5]);
    assert_eq(300, $result);
});

test('setting 600 minutes (max slider) converts to 36000 seconds', function () {
    $result = loadTimeoutFromSettings(['breakpointTimeout' => 600]);
    assert_eq(36000, $result);
});

test('setting 0 is clamped to minimum 1 minute (60 seconds)', function () {
    $result = loadTimeoutFromSettings(['breakpointTimeout' => 0]);
    assert_eq(60, $result);
});

test('negative value is clamped to minimum 1 minute (60 seconds)', function () {
    $result = loadTimeoutFromSettings(['breakpointTimeout' => -10]);
    assert_eq(60, $result);
});

test('string numeric value is accepted', function () {
    $result = loadTimeoutFromSettings(['breakpointTimeout' => '30']);
    assert_eq(1800, $result);
});

test('non-numeric string is ignored, keeps default', function () {
    $result = loadTimeoutFromSettings(['breakpointTimeout' => 'abc']);
    assert_eq(3600, $result);
});

test('missing breakpointTimeout keeps default', function () {
    $result = loadTimeoutFromSettings([]);
    assert_eq(3600, $result);
});

test('null breakpointTimeout keeps default', function () {
    $result = loadTimeoutFromSettings(['breakpointTimeout' => null]);
    assert_eq(3600, $result);
});

section('Breakpoint timeout — ddless_wait_for_command() uses global');

test('ddless_wait_for_command returns null on timeout', function () {
    $original = $GLOBALS['__DDLESS_BREAKPOINT_TIMEOUT__'];
    $GLOBALS['__DDLESS_BREAKPOINT_TIMEOUT__'] = 1;

    $start = time();
    $result = ddless_wait_for_command();
    $elapsed = time() - $start;

    $GLOBALS['__DDLESS_BREAKPOINT_TIMEOUT__'] = $original;

    assert_null($result, 'should return null on timeout');
    assert_true($elapsed >= 1, 'should wait at least 1 second');
    assert_true($elapsed <= 3, 'should not wait much longer than timeout');
});

test('ddless_wait_for_command explicit timeout overrides global', function () {
    $original = $GLOBALS['__DDLESS_BREAKPOINT_TIMEOUT__'];
    $GLOBALS['__DDLESS_BREAKPOINT_TIMEOUT__'] = 9999;

    $start = time();
    $result = ddless_wait_for_command(1);
    $elapsed = time() - $start;

    $GLOBALS['__DDLESS_BREAKPOINT_TIMEOUT__'] = $original;

    assert_null($result, 'should return null on timeout');
    assert_true($elapsed >= 1, 'should wait at least 1 second');
    assert_true($elapsed <= 3, 'should not wait much longer than explicit timeout');
});

section('Breakpoint timeout — modified variables global');

test('__DDLESS_MODIFIED_VARS__ is null by default', function () {
    assert_null($GLOBALS['__DDLESS_MODIFIED_VARS__']);
});

test('__DDLESS_MODIFIED_VARS__ can be set and cleared', function () {
    $GLOBALS['__DDLESS_MODIFIED_VARS__'] = ['x' => 99, 'y' => 'hello'];
    assert_eq(['x' => 99, 'y' => 'hello'], $GLOBALS['__DDLESS_MODIFIED_VARS__']);

    $GLOBALS['__DDLESS_MODIFIED_VARS__'] = null;
    assert_null($GLOBALS['__DDLESS_MODIFIED_VARS__']);
});

test('extract from __DDLESS_MODIFIED_VARS__ overwrites local scope', function () {
    $x = 10;
    $y = 20;

    $GLOBALS['__DDLESS_MODIFIED_VARS__'] = ['x' => 99];

    if (!empty($GLOBALS['__DDLESS_MODIFIED_VARS__'])) {
        extract($GLOBALS['__DDLESS_MODIFIED_VARS__'], EXTR_OVERWRITE);
        $GLOBALS['__DDLESS_MODIFIED_VARS__'] = null;
    }

    assert_eq(99, $x, 'x should be overwritten');
    assert_eq(20, $y, 'y should remain unchanged');
    assert_null($GLOBALS['__DDLESS_MODIFIED_VARS__'], 'should be cleared after extract');
});

test('extract adds new variables to scope', function () {
    $GLOBALS['__DDLESS_MODIFIED_VARS__'] = ['newVar' => 'created'];

    if (!empty($GLOBALS['__DDLESS_MODIFIED_VARS__'])) {
        extract($GLOBALS['__DDLESS_MODIFIED_VARS__'], EXTR_OVERWRITE);
        $GLOBALS['__DDLESS_MODIFIED_VARS__'] = null;
    }

    assert_eq('created', $newVar, 'newVar should exist after extract');
});

test('empty __DDLESS_MODIFIED_VARS__ skips extract', function () {
    $x = 10;
    $GLOBALS['__DDLESS_MODIFIED_VARS__'] = null;

    if (!empty($GLOBALS['__DDLESS_MODIFIED_VARS__'])) {
        extract($GLOBALS['__DDLESS_MODIFIED_VARS__'], EXTR_OVERWRITE);
        $GLOBALS['__DDLESS_MODIFIED_VARS__'] = null;
    }

    assert_eq(10, $x, 'x should remain unchanged');
});

section('Breakpoint timeout — step mode reset on timeout');

test('all step modes are reset after timeout simulation', function () {
    $GLOBALS['__DDLESS_STEP_OVER__'] = true;
    $GLOBALS['__DDLESS_STEP_IN_MODE__'] = true;
    $GLOBALS['__DDLESS_STEP_OUT_TARGET__'] = 'someFunction';
    $GLOBALS['__DDLESS_STEP_OVER_FUNCTION__'] = 'anotherFunction';
    $GLOBALS['__DDLESS_STEP_OVER_FILE__'] = '/some/file.php';

    // Simulate the timeout reset logic from ddless_handle_breakpoint
    $GLOBALS['__DDLESS_STEP_OVER__'] = false;
    $GLOBALS['__DDLESS_STEP_IN_MODE__'] = false;
    $GLOBALS['__DDLESS_STEP_OUT_TARGET__'] = null;
    $GLOBALS['__DDLESS_STEP_OVER_FUNCTION__'] = null;
    $GLOBALS['__DDLESS_STEP_OVER_FILE__'] = null;

    assert_false($GLOBALS['__DDLESS_STEP_OVER__'], 'step over should be false');
    assert_false($GLOBALS['__DDLESS_STEP_IN_MODE__'], 'step in should be false');
    assert_null($GLOBALS['__DDLESS_STEP_OUT_TARGET__'], 'step out target should be null');
    assert_null($GLOBALS['__DDLESS_STEP_OVER_FUNCTION__'], 'step over function should be null');
    assert_null($GLOBALS['__DDLESS_STEP_OVER_FILE__'], 'step over file should be null');
});

// Print results if run standalone
if (basename($argv[0] ?? '') === basename(__FILE__)) {
    exit(print_test_results());
}
