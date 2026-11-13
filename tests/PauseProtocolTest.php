<?php
/**
 * Tests for pausing at a breakpoint and the file-based command protocol
 * (breakpoint_state.json / breakpoint_command.json), driven end to end.
 * Run: php tests/php/PauseProtocolTest.php
 */
require_once __DIR__ . '/bootstrap.php';

class PlaygroundResponse
{
    public function header(string $name, string $value): string
    {
        return "{$name}: {$value}";
    }

    public function dump(): string
    {
        return 'dumped';
    }
}

section('Pause — user breakpoints');

test('a breakpoint inside a loop pauses on every iteration', function () {
    $pauses = 0;
    with_debug_mode(function () use (&$pauses) {
        $GLOBALS['__DDLESS_TERMINAL_HANDLER__'] = function () use (&$pauses) {
            $pauses++;
            return 'continue';
        };
        for ($i = 0; $i < 3; $i++) {
            ddless_step_check('/app/Loop.php', 5, 'Loop.php', true, '', ['i' => $i], []);
        }
    });
    assert_eq(3, $pauses, 'breakpoint was reached 3 times');
});

section('Pause — playground evaluate');

test('playground allows method calls named like blocked functions', function () {
    $vars = ['response' => new PlaygroundResponse()];
    $session = run_paused_session(
        [
            ['command' => 'evaluate', 'expression' => 'return $response->header("X-Debug", "1");'],
            ['command' => 'evaluate', 'expression' => 'return $response->dump();'],
            ['command' => 'continue'],
        ],
        function () use (&$vars) {
            ddless_handle_breakpoint('/app/Play.php', 10, 'Play.php', $vars, []);
        }
    );
    assert_count(2, $session['results'], 'evaluate results received');
    foreach ($session['results'] as $result) {
        assert_true(($result['success'] ?? false) === true, 'evaluate rejected: ' . ($result['error'] ?? 'no result'));
    }
    assert_eq('X-Debug: 1', $session['results'][0]['result']);
    assert_eq('dumped', $session['results'][1]['result']);
});

test('playground edits at an elseif do not leak into another function scope', function () {
    $code = <<<'PHP'
<?php
function scope_other() {
    $leaked = isset($a);
    return $leaked;
}
function scope_main($a) {
    if ($a === 0) {
        return 'zero';
    } elseif (scope_other()) {
        return 'leaked';
    }
    return 'clean';
}
PHP;
    $instrumented = ddless_instrument_code_with_ast($code, '/app/Scope.php', 'Scope.php', [9]);
    assert_contains((string) $instrumented, "', 9, ", 'elseif line must be instrumented for this test');
    eval(preg_replace('/<\?php/', '', $instrumented, 1));

    $session = run_paused_session(
        [
            ['command' => 'evaluate', 'expression' => '$a = 99;'],
            ['command' => 'continue'],
        ],
        function () {
            return scope_main(1);
        }
    );
    assert_eq('clean', $session['return'], 'scope_other() saw $a from scope_main()');
});

section('Pause — command file');

test('a Continue sent while an evaluate is running is not lost', function () {
    $vars = [];
    $session = run_paused_session(
        [
            ['command' => 'evaluate', 'expression' => 'usleep(400000); return 1;', 'raceContinue' => true],
        ],
        function () use (&$vars) {
            ddless_handle_breakpoint('/app/Race.php', 7, 'Race.php', $vars, []);
        },
        3
    );
    assert_true(
        $session['elapsed'] < 2.0,
        sprintf('resumed only after %.1fs: the Continue was deleted and the pause ran into its timeout', $session['elapsed'])
    );
});

section('Pause — state file');

test('state file is replaced in one step and leaves no temp file behind', function () {
    $previousSession = getenv('DDLESS_DEBUG_SESSION');
    putenv('DDLESS_DEBUG_SESSION=ddless-test-state-file');
    $stateFile = ddless_get_breakpoint_state_file();
    try {
        assert_true(ddless_write_breakpoint_state(['type' => 'breakpoint', 'line' => 1]));
        assert_true(ddless_write_breakpoint_state(['type' => 'breakpoint', 'line' => 2]));
        $decoded = json_decode((string) file_get_contents($stateFile), true);
        assert_eq(2, $decoded['line'] ?? null, 'the second write replaced the first');
        assert_false(is_file($stateFile . '.tmp'), 'temp file must not linger');
    } finally {
        @unlink($stateFile);
        @unlink($stateFile . '.tmp');
        @rmdir(dirname($stateFile));
        putenv($previousSession === false ? 'DDLESS_DEBUG_SESSION' : "DDLESS_DEBUG_SESSION={$previousSession}");
    }
});

test('a payload with invalid UTF-8 or NAN is still written, so the pause is not skipped', function () {
    $previousSession = getenv('DDLESS_DEBUG_SESSION');
    putenv('DDLESS_DEBUG_SESSION=ddless-test-state-file');
    $stateFile = ddless_get_breakpoint_state_file();
    try {
        $written = ddless_write_breakpoint_state([
            'type' => 'breakpoint',
            'line' => 7,
            'label' => 'caf' . chr(233),
            'ratio' => NAN,
        ]);
        assert_true($written, 'state must be written');
        $decoded = json_decode((string) file_get_contents($stateFile), true);
        assert_eq(7, $decoded['line'] ?? null, 'the readable part of the payload survives');
    } finally {
        @unlink($stateFile);
        @unlink($stateFile . '.tmp');
        @rmdir(dirname($stateFile));
        putenv($previousSession === false ? 'DDLESS_DEBUG_SESSION' : "DDLESS_DEBUG_SESSION={$previousSession}");
    }
});

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    exit(print_test_results());
}
