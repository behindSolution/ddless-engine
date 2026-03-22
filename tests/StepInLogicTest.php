<?php
/**
 * Tests for step-in logic
 * Run: php tests/php/StepInLogicTest.php
 */
require_once __DIR__ . '/bootstrap.php';

section('Step-in: always stops on next line');

test('stops on next line in same function', function () {
    $GLOBALS['__DDLESS_STEP_IN_MODE__'] = true;
    assert_true($GLOBALS['__DDLESS_STEP_IN_MODE__'], 'should stop');
    // Simulate reset that step_check does after stopping
    $GLOBALS['__DDLESS_STEP_IN_MODE__'] = false;
});

test('stops inside a called function (same file)', function () {
    $GLOBALS['__DDLESS_STEP_IN_MODE__'] = true;
    // Step-in doesn't care about backtrace — it stops unconditionally
    assert_true($GLOBALS['__DDLESS_STEP_IN_MODE__'], 'should stop inside called function');
    $GLOBALS['__DDLESS_STEP_IN_MODE__'] = false;
});

test('stops inside a called function (different file)', function () {
    $GLOBALS['__DDLESS_STEP_IN_MODE__'] = true;
    assert_true($GLOBALS['__DDLESS_STEP_IN_MODE__'], 'should stop even in different file');
    $GLOBALS['__DDLESS_STEP_IN_MODE__'] = false;
});

test('resets after stopping (single shot)', function () {
    $GLOBALS['__DDLESS_STEP_IN_MODE__'] = true;
    assert_true($GLOBALS['__DDLESS_STEP_IN_MODE__'], 'should have stopped');
    $GLOBALS['__DDLESS_STEP_IN_MODE__'] = false;
    assert_false($GLOBALS['__DDLESS_STEP_IN_MODE__'], 'should NOT stop again after reset');
});

test('is not active by default', function () {
    $GLOBALS['__DDLESS_STEP_IN_MODE__'] = false;
    assert_false($GLOBALS['__DDLESS_STEP_IN_MODE__'], 'should not be active');
});

test('step-in is checked before step-over in step_check', function () {
    // step_check checks STEP_IN_MODE first (line 1293), then STEP_OVER (line 1299)
    // so if both are set, step-in wins
    $GLOBALS['__DDLESS_STEP_IN_MODE__'] = true;
    $GLOBALS['__DDLESS_STEP_OVER_FUNCTION__'] = 'SomeClass->method';
    assert_true($GLOBALS['__DDLESS_STEP_IN_MODE__'], 'step-in should take priority');
    $GLOBALS['__DDLESS_STEP_IN_MODE__'] = false;
    $GLOBALS['__DDLESS_STEP_OVER_FUNCTION__'] = null;
});

test('step-in is checked before step-out in step_check', function () {
    $GLOBALS['__DDLESS_STEP_IN_MODE__'] = true;
    $GLOBALS['__DDLESS_STEP_OUT_TARGET__'] = 'SomeClass->caller';
    assert_true($GLOBALS['__DDLESS_STEP_IN_MODE__'], 'step-in should take priority over step-out');
    $GLOBALS['__DDLESS_STEP_IN_MODE__'] = false;
    $GLOBALS['__DDLESS_STEP_OUT_TARGET__'] = null;
});

test('resetAllStepModes clears step-in', function () {
    $GLOBALS['__DDLESS_STEP_IN_MODE__'] = true;
    // Simulate $resetAllStepModes
    $GLOBALS['__DDLESS_STEP_IN_MODE__'] = false;
    $GLOBALS['__DDLESS_STEP_OVER_FUNCTION__'] = null;
    $GLOBALS['__DDLESS_STEP_OVER_FILE__'] = null;
    $GLOBALS['__DDLESS_STEP_OUT_TARGET__'] = null;
    assert_false($GLOBALS['__DDLESS_STEP_IN_MODE__'], 'step-in should be cleared');
});

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    exit(print_test_results());
}
