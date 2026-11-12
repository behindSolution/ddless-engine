<?php
/**
 * Tests for ddless_step_gate(), the cheap pre-check injected in front of
 * every ddless_step_check() call.
 * Run: php tests/php/StepGateTest.php
 */
require_once __DIR__ . '/bootstrap.php';

function reset_gate_state(): void
{
    $GLOBALS['__DDLESS_HIT_COUNTS__'] = [];
    $GLOBALS['__DDLESS_GATE_COUNT__'] = null;
    $GLOBALS['__DDLESS_WATCHES__'] = [];
    $GLOBALS['__DDLESS_STEP_IN_MODE__'] = false;
    $GLOBALS['__DDLESS_STEP_OVER_FUNCTION__'] = null;
    $GLOBALS['__DDLESS_STEP_OVER_FILE__'] = null;
    $GLOBALS['__DDLESS_STEP_OUT_TARGET__'] = null;
    $GLOBALS['__DDLESS_STEP_OUT_DEPTH__'] = null;
    $GLOBALS['__DDLESS_REPLAY_TARGET__'] = null;
    $GLOBALS['__DDLESS_IS_REPLAY__'] = false;
    $GLOBALS['__DDLESS_TIMEFRAME_MODE__'] = false;
}

section('ddless_step_gate() — when a line has nothing to do');

test('an idle line is skipped but still counted', function () {
    reset_gate_state();
    assert_false(ddless_step_gate('/app/A.php', 10, 'A.php', false));
    assert_false(ddless_step_gate('/app/A.php', 10, 'A.php', false));
    assert_eq(2, $GLOBALS['__DDLESS_HIT_COUNTS__']['/app/A.php:10'], 'position counting must not depend on the gate');
    reset_gate_state();
});

section('ddless_step_gate() — when step_check must run');

test('a user breakpoint opens the gate', function () {
    reset_gate_state();
    assert_true(ddless_step_gate('/app/A.php', 10, 'A.php', true));
    reset_gate_state();
});

test('step in, step over and step out open the gate', function () {
    reset_gate_state();
    $GLOBALS['__DDLESS_STEP_IN_MODE__'] = true;
    assert_true(ddless_step_gate('/app/A.php', 10, 'A.php', false), 'step in');

    reset_gate_state();
    $GLOBALS['__DDLESS_STEP_OVER_FUNCTION__'] = 'App\\A->run';
    assert_true(ddless_step_gate('/app/A.php', 10, 'A.php', false), 'step over');

    reset_gate_state();
    $GLOBALS['__DDLESS_STEP_OUT_TARGET__'] = 'App\\A->caller';
    assert_true(ddless_step_gate('/app/A.php', 10, 'A.php', false), 'step out');
    reset_gate_state();
});

test('watch expressions open the gate', function () {
    reset_gate_state();
    $GLOBALS['__DDLESS_WATCHES__'] = ['$total'];
    assert_true(ddless_step_gate('/app/A.php', 10, 'A.php', false));
    reset_gate_state();
});

test('a timeframe recording opens the gate, a replay does not record', function () {
    reset_gate_state();
    $GLOBALS['__DDLESS_TIMEFRAME_MODE__'] = true;
    assert_true(ddless_step_gate('/app/A.php', 10, 'A.php', false), 'recording');

    $GLOBALS['__DDLESS_IS_REPLAY__'] = true;
    assert_false(ddless_step_gate('/app/A.php', 11, 'A.php', false), 'replay run past its target');
    reset_gate_state();
});

section('ddless_step_gate() — replay');

test('during a replay only the target position opens the gate', function () {
    reset_gate_state();
    $GLOBALS['__DDLESS_IS_REPLAY__'] = true;
    $GLOBALS['__DDLESS_REPLAY_TARGET__'] = ['file' => 'app/A.php', 'line' => 10, 'nth' => 2];

    assert_false(ddless_step_gate('/app/A.php', 10, 'app\\A.php', true), 'first hit of the line, even with a breakpoint');
    assert_false(ddless_step_gate('/app/A.php', 11, 'app/A.php', true), 'another line');
    assert_true(ddless_step_gate('/app/A.php', 10, 'app\\A.php', false), 'second hit is the target');
    reset_gate_state();
});

section('ddless_step_gate() + ddless_step_check()');

test('a hit counted by the gate is not counted again by step_check', function () {
    reset_gate_state();
    with_debug_mode(function () {
        $seen = [];
        $GLOBALS['__DDLESS_TERMINAL_HANDLER__'] = function () use (&$seen) {
            $seen[] = $GLOBALS['__DDLESS_HIT_COUNTS__']['/app/A.php:10'];
            return 'continue';
        };
        for ($i = 0; $i < 3; $i++) {
            ddless_step_gate('/app/A.php', 10, 'A.php', true)
                && ddless_step_check('/app/A.php', 10, 'A.php', true, '', [], []);
        }
        assert_eq([1, 2, 3], $seen);
    });
    reset_gate_state();
});

test('step_check called on its own still counts the hit', function () {
    reset_gate_state();
    ddless_step_check('/app/A.php', 10, 'A.php', false, '', [], []);
    ddless_step_check('/app/A.php', 10, 'A.php', false, '', [], []);
    assert_eq(2, $GLOBALS['__DDLESS_HIT_COUNTS__']['/app/A.php:10']);
    reset_gate_state();
});

test('instrumented code runs idle lines without calling step_check', function () {
    reset_gate_state();
    $code = <<<'PHP'
<?php
function gate_probe($n) {
    $sum = 0;
    for ($i = 0; $i < $n; $i++) {
        $sum += $i;
    }
    return $sum;
}
PHP;
    $instrumented = ddless_instrument_code_with_ast($code, '/app/GateProbe.php', 'GateProbe.php', []);
    assert_contains($instrumented, '\\ddless_step_gate(', 'gate injected');
    eval(preg_replace('/<\?php/', '', $instrumented, 1));

    $pauses = 0;
    with_debug_mode(function () use (&$pauses) {
        $GLOBALS['__DDLESS_TERMINAL_HANDLER__'] = function () use (&$pauses) {
            $pauses++;
            return 'continue';
        };
        assert_eq(10, gate_probe(5));
        assert_eq(5, $GLOBALS['__DDLESS_HIT_COUNTS__']['/app/GateProbe.php:5'] ?? 0, 'loop body counted once per iteration');
    });
    assert_eq(0, $pauses);
    reset_gate_state();
});

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    exit(print_test_results());
}
