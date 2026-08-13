<?php
/**
 * Tests for the Timeframe (time travel) engine: position identity
 * (file:line#nth), the NDJSON recorder with delta-encoded scope, and the
 * replay gate that races to a recorded position and pauses there.
 * Run: php tests/php/TimeframeTest.php
 */
require_once __DIR__ . '/bootstrap.php';

section('Timeframe — position identity');

$tfSessionId = 'ddless-test-timeframe';
$__tfPrevSession = getenv('DDLESS_DEBUG_SESSION');
putenv("DDLESS_DEBUG_SESSION={$tfSessionId}");
$tfSessionDir = ddless_get_session_dir();
putenv($__tfPrevSession === false ? 'DDLESS_DEBUG_SESSION' : "DDLESS_DEBUG_SESSION={$__tfPrevSession}");

// Full reset of every global the timeframe engine touches, so tests don't leak
// into each other (or into the shared run-all process).
$resetTf = function () {
    $GLOBALS['__DDLESS_TIMEFRAME_MODE__'] = false;
    $GLOBALS['__DDLESS_IS_REPLAY__'] = false;
    $GLOBALS['__DDLESS_REPLAY_TARGET__'] = null;
    $GLOBALS['__DDLESS_TF_DETAIL__'] = 'normal';
    $GLOBALS['__DDLESS_TF_SEQ__'] = 0;
    $GLOBALS['__DDLESS_TF_HANDLE__'] = null;
    $GLOBALS['__DDLESS_TF_SCOPE_STACK__'] = [];
    $GLOBALS['__DDLESS_TF_FRAMES_WRITTEN__'] = 0;
    $GLOBALS['__DDLESS_TF_TRUNCATED__'] = false;
    $GLOBALS['__DDLESS_TF_MAX_FRAMES__'] = 200000;
    $GLOBALS['__DDLESS_HIT_COUNTS__'] = [];
    $GLOBALS['__DDLESS_HIT_USER_BPS__'] = [];
    $GLOBALS['__DDLESS_WATCHES__'] = [];
    unset($GLOBALS['__DDLESS_TERMINAL_HANDLER__']);
};

// Read the timeline back as decoded lines. The recorder writes meta first,
// then one frame per step_check, then an end record.
$readTimeline = function () use ($tfSessionDir) {
    $files = glob($tfSessionDir . '/timeframe/*.ndjson');
    $path = $files ? $files[0] : $tfSessionDir . '/timeframe/none.ndjson';
    if (!is_file($path)) {
        return [];
    }
    $lines = array_filter(explode("\n", (string) file_get_contents($path)), fn($l) => trim($l) !== '');
    return array_map(fn($l) => json_decode($l, true), array_values($lines));
};

$cleanupTimeline = function () use ($tfSessionDir) {
    foreach (glob($tfSessionDir . '/timeframe/*.ndjson') ?: [] as $f) @unlink($f);
    @rmdir($tfSessionDir . '/timeframe');
    @rmdir($tfSessionDir);
};

test('ddless_tf_parse_position() parses file:line#nth', function () {
    $parsed = ddless_tf_parse_position('app/Services/UserService.php:42#3');
    assert_not_null($parsed);
    assert_eq('app/Services/UserService.php', $parsed['file']);
    assert_eq(42, $parsed['line']);
    assert_eq(3, $parsed['nth']);
});

test('ddless_tf_parse_position() normalizes backslashes', function () {
    $parsed = ddless_tf_parse_position('app\\Models\\User.php:10#1');
    assert_not_null($parsed);
    assert_eq('app/Models/User.php', $parsed['file'], 'separators normalized to forward slashes');
});

test('ddless_tf_parse_position() rejects malformed positions', function () {
    assert_null(ddless_tf_parse_position('app/User.php:42'), 'missing #nth');
    assert_null(ddless_tf_parse_position('app/User.php#3'), 'missing :line');
    assert_null(ddless_tf_parse_position(''), 'empty');
});

test('ddless_tf_format_position() round-trips through the parser', function () {
    $formatted = ddless_tf_format_position('app/User.php', 42, 3);
    assert_eq('app/User.php:42#3', $formatted);
    $parsed = ddless_tf_parse_position($formatted);
    assert_eq(42, $parsed['line']);
    assert_eq(3, $parsed['nth']);
});

test('hit counts are tracked per line, independently', function () use ($resetTf) {
    $resetTf();
    for ($i = 0; $i < 3; $i++) {
        ddless_step_check('/proj/a.php', 10, 'a.php', false, '', [], []);
    }
    ddless_step_check('/proj/a.php', 11, 'a.php', false, '', [], []);
    ddless_step_check('/proj/b.php', 10, 'b.php', false, '', [], []);

    assert_eq(3, $GLOBALS['__DDLESS_HIT_COUNTS__']['/proj/a.php:10'], 'line 10 hit three times');
    assert_eq(1, $GLOBALS['__DDLESS_HIT_COUNTS__']['/proj/a.php:11'], 'line 11 counted separately');
    assert_eq(1, $GLOBALS['__DDLESS_HIT_COUNTS__']['/proj/b.php:10'], 'same line in another file is a distinct position');
    $resetTf();
});

section('Timeframe — replay gate');

test('replay pauses only at the target position', function () use ($resetTf) {
    $resetTf();
    $previousMode = getenv('DDLESS_DEBUG_MODE');
    putenv('DDLESS_DEBUG_MODE=true');

    $pauses = [];
    $GLOBALS['__DDLESS_TERMINAL_HANDLER__'] = function ($payload) use (&$pauses) {
        $pauses[] = $payload;
        return 'continue';
    };
    $GLOBALS['__DDLESS_REPLAY_TARGET__'] = ['file' => 'a.php', 'line' => 10, 'nth' => 3];
    $GLOBALS['__DDLESS_IS_REPLAY__'] = true;

    // Scope keys come from get_defined_vars() — no leading '$'.
    for ($i = 0; $i < 5; $i++) {
        ddless_step_check('/proj/a.php', 10, 'a.php', false, '', ['i' => $i], []);
    }

    putenv($previousMode === false ? 'DDLESS_DEBUG_MODE' : "DDLESS_DEBUG_MODE={$previousMode}");

    assert_count(1, $pauses, 'exactly one pause during a replay run');
    assert_eq(10, $pauses[0]['line']);
    assert_eq(2, $pauses[0]['variables']['i'], 'paused on the 3rd hit, where $i === 2');
    assert_null($GLOBALS['__DDLESS_REPLAY_TARGET__'], 'target cleared after arrival');
    $resetTf();
});

test('replay does not pause on user breakpoints before the target', function () use ($resetTf) {
    $resetTf();
    $previousMode = getenv('DDLESS_DEBUG_MODE');
    putenv('DDLESS_DEBUG_MODE=true');

    $pauses = 0;
    $GLOBALS['__DDLESS_TERMINAL_HANDLER__'] = function () use (&$pauses) {
        $pauses++;
        return 'continue';
    };
    $GLOBALS['__DDLESS_REPLAY_TARGET__'] = ['file' => 'a.php', 'line' => 20, 'nth' => 1];
    $GLOBALS['__DDLESS_IS_REPLAY__'] = true;

    // A real user breakpoint sitting on an earlier line must be flown past.
    ddless_step_check('/proj/a.php', 10, 'a.php', true, '', [], []);
    assert_eq(0, $pauses, 'earlier user breakpoint ignored during replay');

    ddless_step_check('/proj/a.php', 20, 'a.php', false, '', [], []);
    assert_eq(1, $pauses, 'paused at the target');

    putenv($previousMode === false ? 'DDLESS_DEBUG_MODE' : "DDLESS_DEBUG_MODE={$previousMode}");
    $resetTf();
});

test('replay does not fire dumppoints before the target (would exit the run)', function () use ($resetTf) {
    $resetTf();
    $GLOBALS['__DDLESS_REPLAY_TARGET__'] = ['file' => 'a.php', 'line' => 99, 'nth' => 1];
    $GLOBALS['__DDLESS_IS_REPLAY__'] = true;

    // A dumppoint calls exit(0) when it fires. Reaching the line after this one
    // proves the gate returned before the dumppoint branch.
    ddless_step_check('/proj/a.php', 10, 'a.php', true, '', [], [], '', '$x');

    assert_eq(1, $GLOBALS['__DDLESS_HIT_COUNTS__']['/proj/a.php:10'], 'run survived a pre-target dumppoint');
    $resetTf();
});

section('Timeframe — recorder');

test('records meta, one frame per step, and an end record', function () use ($resetTf, $readTimeline, $cleanupTimeline, $tfSessionId) {
    $resetTf();
    $previousSession = getenv('DDLESS_DEBUG_SESSION');
    putenv("DDLESS_DEBUG_SESSION={$tfSessionId}");
    $previousStream = $GLOBALS['__DDLESS_IPC_STREAM__'];
    $GLOBALS['__DDLESS_IPC_STREAM__'] = fopen('php://memory', 'w+');
    $GLOBALS['__DDLESS_TIMEFRAME_MODE__'] = true;

    ddless_step_check('/proj/a.php', 10, 'a.php', false, '', ['$x' => 1], []);
    ddless_step_check('/proj/a.php', 11, 'a.php', false, '', ['$x' => 1], []);
    ddless_tf_close();

    $lines = $readTimeline();
    assert_eq('meta', $lines[0]['type'], 'first line is the meta header');
    assert_eq(DDLESS_TIMEFRAME_VERSION, $lines[0]['version']);
    assert_eq('normal', $lines[0]['detail']);
    assert_eq(10, $lines[1]['line']);
    assert_eq(11, $lines[2]['line']);
    assert_eq('end', $lines[3]['type']);
    assert_eq(2, $lines[3]['frames'], 'end record counts the frames');

    fclose($GLOBALS['__DDLESS_IPC_STREAM__']);
    $GLOBALS['__DDLESS_IPC_STREAM__'] = $previousStream;
    putenv($previousSession === false ? 'DDLESS_DEBUG_SESSION' : "DDLESS_DEBUG_SESSION={$previousSession}");
    $cleanupTimeline();
    $resetTf();
});

test('frames carry the position (line + nth) that replay targets', function () use ($resetTf, $readTimeline, $cleanupTimeline, $tfSessionId) {
    $resetTf();
    $previousSession = getenv('DDLESS_DEBUG_SESSION');
    putenv("DDLESS_DEBUG_SESSION={$tfSessionId}");
    $previousStream = $GLOBALS['__DDLESS_IPC_STREAM__'];
    $GLOBALS['__DDLESS_IPC_STREAM__'] = fopen('php://memory', 'w+');
    $GLOBALS['__DDLESS_TIMEFRAME_MODE__'] = true;

    for ($i = 0; $i < 3; $i++) {
        ddless_step_check('/proj/a.php', 7, 'a.php', false, '', ['i' => $i], []);
    }
    ddless_tf_close();

    $frames = array_values(array_filter($readTimeline(), fn($l) => isset($l['nth'])));
    assert_count(3, $frames);
    assert_eq(1, $frames[0]['nth']);
    assert_eq(3, $frames[2]['nth'], 'third pass through the same line');
    assert_eq('a.php:7#3', ddless_tf_format_position($frames[2]['file'], $frames[2]['line'], $frames[2]['nth']));

    fclose($GLOBALS['__DDLESS_IPC_STREAM__']);
    $GLOBALS['__DDLESS_IPC_STREAM__'] = $previousStream;
    putenv($previousSession === false ? 'DDLESS_DEBUG_SESSION' : "DDLESS_DEBUG_SESSION={$previousSession}");
    $cleanupTimeline();
    $resetTf();
});

test('scope is delta-encoded — only changed variables are stored', function () use ($resetTf, $readTimeline, $cleanupTimeline, $tfSessionId) {
    $resetTf();
    $previousSession = getenv('DDLESS_DEBUG_SESSION');
    putenv("DDLESS_DEBUG_SESSION={$tfSessionId}");
    $previousStream = $GLOBALS['__DDLESS_IPC_STREAM__'];
    $GLOBALS['__DDLESS_IPC_STREAM__'] = fopen('php://memory', 'w+');
    $GLOBALS['__DDLESS_TIMEFRAME_MODE__'] = true;

    // $stable never changes; $counter does; $late appears midway; $gone leaves.
    ddless_step_check('/proj/a.php', 5, 'a.php', false, '', ['stable' => 'x', 'counter' => 1, 'gone' => true], []);
    ddless_step_check('/proj/a.php', 6, 'a.php', false, '', ['stable' => 'x', 'counter' => 2, 'gone' => true], []);
    ddless_step_check('/proj/a.php', 7, 'a.php', false, '', ['stable' => 'x', 'counter' => 2, 'late' => 'new'], []);
    ddless_tf_close();

    $frames = array_values(array_filter($readTimeline(), fn($l) => isset($l['nth'])));

    assert_true($frames[0]['scopeStart'] ?? false, 'first frame of a scope is a full snapshot');
    assert_count(3, $frames[0]['vars'], 'full snapshot holds every variable');

    assert_eq(['counter' => 2], $frames[1]['vars'] ?? [], 'only the changed variable is recorded');
    assert_array_not_has_key('del', $frames[1], 'nothing left scope yet');

    assert_eq(['late' => 'new'], $frames[2]['vars'] ?? [], 'new variable recorded, unchanged ones skipped');
    assert_eq(['gone'], $frames[2]['del'] ?? [], 'removed variable reported so readers can drop it');

    fclose($GLOBALS['__DDLESS_IPC_STREAM__']);
    $GLOBALS['__DDLESS_IPC_STREAM__'] = $previousStream;
    putenv($previousSession === false ? 'DDLESS_DEBUG_SESSION' : "DDLESS_DEBUG_SESSION={$previousSession}");
    $cleanupTimeline();
    $resetTf();
});

test("'light' detail records positions without any scope", function () use ($resetTf, $readTimeline, $cleanupTimeline, $tfSessionId) {
    $resetTf();
    $previousSession = getenv('DDLESS_DEBUG_SESSION');
    putenv("DDLESS_DEBUG_SESSION={$tfSessionId}");
    $previousStream = $GLOBALS['__DDLESS_IPC_STREAM__'];
    $GLOBALS['__DDLESS_IPC_STREAM__'] = fopen('php://memory', 'w+');
    $GLOBALS['__DDLESS_TIMEFRAME_MODE__'] = true;
    $GLOBALS['__DDLESS_TF_DETAIL__'] = 'light';

    ddless_step_check('/proj/a.php', 5, 'a.php', false, '', ['heavy' => range(1, 100)], []);
    ddless_tf_close();

    $frames = array_values(array_filter($readTimeline(), fn($l) => isset($l['nth'])));
    assert_count(1, $frames);
    assert_array_not_has_key('vars', $frames[0], 'light mode skips serialization entirely');
    assert_eq(5, $frames[0]['line'], 'position is still recorded');

    fclose($GLOBALS['__DDLESS_IPC_STREAM__']);
    $GLOBALS['__DDLESS_IPC_STREAM__'] = $previousStream;
    putenv($previousSession === false ? 'DDLESS_DEBUG_SESSION' : "DDLESS_DEBUG_SESSION={$previousSession}");
    $cleanupTimeline();
    $resetTf();
});

test('frames record which kind of breakpoint sits on the line', function () use ($resetTf, $readTimeline, $cleanupTimeline, $tfSessionId) {
    $resetTf();
    $previousSession = getenv('DDLESS_DEBUG_SESSION');
    putenv("DDLESS_DEBUG_SESSION={$tfSessionId}");
    $previousStream = $GLOBALS['__DDLESS_IPC_STREAM__'];
    $GLOBALS['__DDLESS_IPC_STREAM__'] = fopen('php://memory', 'w+');
    $GLOBALS['__DDLESS_TIMEFRAME_MODE__'] = true;

    // Args: file, line, rel, isUserBp, condition, vars, backtrace, logpoint,
    //       dumppoint, condDumpCondition, condDumpExpressions
    ddless_step_check('/proj/a.php', 1, 'a.php', false, '', [], []);
    ddless_step_check('/proj/a.php', 2, 'a.php', true, '', [], []);
    ddless_step_check('/proj/a.php', 3, 'a.php', true, '$x > 1', [], []);
    ddless_step_check('/proj/a.php', 4, 'a.php', true, '', [], [], 'hello {$x}');
    // A plain dumppoint is not exercised here: it calls exit(0) as soon as it
    // fires, which would take the test process with it.
    ddless_step_check('/proj/a.php', 6, 'a.php', true, '', [], [], '', '', '$x > 1', '$x');
    ddless_tf_close();

    $byLine = [];
    foreach ($readTimeline() as $entry) {
        if (isset($entry['nth'])) $byLine[$entry['line']] = $entry;
    }

    assert_array_not_has_key('bp', $byLine[1], 'a plain line carries no breakpoint kind');
    assert_eq('line', $byLine[2]['bp'], 'regular breakpoint');
    assert_eq('condition', $byLine[3]['bp'], 'conditional breakpoint');
    assert_eq('log', $byLine[4]['bp'], 'logpoint');
    assert_eq('conddump', $byLine[6]['bp'], 'conditional dumppoint');

    fclose($GLOBALS['__DDLESS_IPC_STREAM__']);
    $GLOBALS['__DDLESS_IPC_STREAM__'] = $previousStream;
    putenv($previousSession === false ? 'DDLESS_DEBUG_SESSION' : "DDLESS_DEBUG_SESSION={$previousSession}");
    $cleanupTimeline();
    $resetTf();
});

test('recording is disabled during a replay run', function () use ($resetTf) {
    $resetTf();
    $GLOBALS['__DDLESS_TIMEFRAME_MODE__'] = true;
    assert_true(ddless_tf_is_recording(), 'recording while running normally');

    $GLOBALS['__DDLESS_IS_REPLAY__'] = true;
    assert_false(ddless_tf_is_recording(), 'a replay must not overwrite the original timeline');
    $resetTf();
});

test('runaway guard stops recording and flags truncation', function () use ($resetTf, $readTimeline, $cleanupTimeline, $tfSessionId) {
    $resetTf();
    $previousSession = getenv('DDLESS_DEBUG_SESSION');
    putenv("DDLESS_DEBUG_SESSION={$tfSessionId}");
    $previousStream = $GLOBALS['__DDLESS_IPC_STREAM__'];
    $GLOBALS['__DDLESS_IPC_STREAM__'] = fopen('php://memory', 'w+');
    $GLOBALS['__DDLESS_TIMEFRAME_MODE__'] = true;
    $GLOBALS['__DDLESS_TF_MAX_FRAMES__'] = 2;

    for ($i = 0; $i < 6; $i++) {
        ddless_step_check('/proj/a.php', 5, 'a.php', false, '', ['i' => $i], []);
    }
    ddless_tf_close();

    $lines = $readTimeline();
    $frames = array_values(array_filter($lines, fn($l) => isset($l['nth'])));
    assert_count(2, $frames, 'stopped at the cap');
    $end = $lines[count($lines) - 1];
    assert_true($end['truncated'], 'truncation is reported, not silent');

    fclose($GLOBALS['__DDLESS_IPC_STREAM__']);
    $GLOBALS['__DDLESS_IPC_STREAM__'] = $previousStream;
    putenv($previousSession === false ? 'DDLESS_DEBUG_SESSION' : "DDLESS_DEBUG_SESSION={$previousSession}");
    $cleanupTimeline();
    $resetTf();
});

section('Timeframe — start-at trigger');

test('recording stays idle until execution enters the start file', function () use ($resetTf, $readTimeline, $cleanupTimeline, $tfSessionId) {
    $resetTf();
    $cleanupTimeline();
    $previousSession = getenv('DDLESS_DEBUG_SESSION');
    putenv("DDLESS_DEBUG_SESSION={$tfSessionId}");
    $previousStream = $GLOBALS['__DDLESS_IPC_STREAM__'];
    $GLOBALS['__DDLESS_IPC_STREAM__'] = fopen('php://memory', 'w+');
    $GLOBALS['__DDLESS_TIMEFRAME_MODE__'] = true;
    $GLOBALS['__DDLESS_TF_START_FILE__'] = 'app/Controller.php';
    $GLOBALS['__DDLESS_TF_ARMED__'] = false;

    // Bootstrap first: instrumented, counted, but not recorded.
    ddless_step_check('/p/boot.php', 1, 'vendor/boot.php', false, '', ['a' => 1], []);
    ddless_step_check('/p/boot.php', 2, 'vendor/boot.php', false, '', ['a' => 2], []);
    // Entering the trigger arms it, from here on everything is recorded.
    ddless_step_check('/p/c.php', 10, 'app/Controller.php', false, '', ['b' => 1], []);
    ddless_step_check('/p/svc.php', 5, 'app/Service.php', false, '', ['c' => 1], []);
    ddless_tf_close();

    $frames = array_values(array_filter($readTimeline(), fn($l) => isset($l['nth'])));
    assert_count(2, $frames, 'only the frames from the trigger onward');
    assert_eq('app/Controller.php', $frames[0]['file'], 'recording starts at the trigger');
    assert_eq('app/Service.php', $frames[1]['file'], 'and continues past it into other files');

    // Positions must be unaffected: the counter runs even while idle.
    assert_eq(1, $GLOBALS['__DDLESS_HIT_COUNTS__']['/p/boot.php:1'], 'skipped lines are still counted');

    fclose($GLOBALS['__DDLESS_IPC_STREAM__']);
    $GLOBALS['__DDLESS_IPC_STREAM__'] = $previousStream;
    putenv($previousSession === false ? 'DDLESS_DEBUG_SESSION' : "DDLESS_DEBUG_SESSION={$previousSession}");
    $cleanupTimeline();
    $GLOBALS['__DDLESS_TF_START_FILE__'] = '';
    $resetTf();
});

test('a bare file name matches as a path suffix', function () use ($resetTf) {
    $resetTf();
    $GLOBALS['__DDLESS_TIMEFRAME_MODE__'] = true;
    $GLOBALS['__DDLESS_TF_START_FILE__'] = 'Controller.php';
    $GLOBALS['__DDLESS_TF_ARMED__'] = false;

    assert_false(ddless_tf_should_record('app/Service.php'), 'unrelated file does not arm it');
    assert_true(ddless_tf_should_record('app/Http/Controller.php'), 'suffix match arms it');
    assert_true(ddless_tf_should_record('app/Service.php'), 'once armed, everything records');

    $GLOBALS['__DDLESS_TF_START_FILE__'] = '';
    $resetTf();
});

test('without a start file everything records from the first line', function () use ($resetTf) {
    $resetTf();
    $GLOBALS['__DDLESS_TIMEFRAME_MODE__'] = true;
    $GLOBALS['__DDLESS_TF_START_FILE__'] = '';
    assert_true(ddless_tf_should_record('anything.php'));
    $resetTf();
});

test("scope 'only' records the file itself and nothing it calls", function () use ($resetTf) {
    $resetTf();
    $GLOBALS['__DDLESS_TIMEFRAME_MODE__'] = true;
    $GLOBALS['__DDLESS_TF_START_FILE__'] = 'app/Controller.php';
    $GLOBALS['__DDLESS_TF_SCOPE_MODE__'] = 'only';
    $GLOBALS['__DDLESS_TF_ARMED__'] = false;

    assert_false(ddless_tf_should_record('vendor/boot.php'), 'before it: skipped');
    assert_true(ddless_tf_should_record('app/Controller.php'), 'the file itself: recorded');
    assert_false(ddless_tf_should_record('app/Service.php'), 'what it calls: still skipped');
    assert_true(ddless_tf_should_record('app/Controller.php'), 'back in the file: recorded again');

    $GLOBALS['__DDLESS_TF_START_FILE__'] = '';
    $GLOBALS['__DDLESS_TF_SCOPE_MODE__'] = 'from';
    $resetTf();
});

test("scope 'from' keeps recording past the start file", function () use ($resetTf) {
    $resetTf();
    $GLOBALS['__DDLESS_TIMEFRAME_MODE__'] = true;
    $GLOBALS['__DDLESS_TF_START_FILE__'] = 'app/Controller.php';
    $GLOBALS['__DDLESS_TF_SCOPE_MODE__'] = 'from';
    $GLOBALS['__DDLESS_TF_ARMED__'] = false;

    assert_false(ddless_tf_should_record('vendor/boot.php'));
    assert_true(ddless_tf_should_record('app/Controller.php'));
    assert_true(ddless_tf_should_record('app/Service.php'), 'callees are recorded too');

    $GLOBALS['__DDLESS_TF_START_FILE__'] = '';
    $resetTf();
});

section('Timeframe — per-execution identity');

test('each execution writes its own timeline file', function () use ($resetTf, $tfSessionDir, $tfSessionId, $cleanupTimeline) {
    $resetTf();
    $cleanupTimeline();
    $previousSession = getenv('DDLESS_DEBUG_SESSION');
    putenv("DDLESS_DEBUG_SESSION={$tfSessionId}");
    $previousStream = $GLOBALS['__DDLESS_IPC_STREAM__'];
    $GLOBALS['__DDLESS_IPC_STREAM__'] = fopen('php://memory', 'w+');
    $GLOBALS['__DDLESS_TIMEFRAME_MODE__'] = true;

    // The run id is memoised per process, so a second "run" is simulated by
    // pointing the handle at a fresh file the way a new process would.
    ddless_step_check('/proj/a.php', 1, 'a.php', false, '', [], []);
    $firstFile = ddless_tf_timeline_file();
    ddless_tf_close();

    assert_true(is_file($firstFile), 'first run wrote its own file');
    assert_true(str_contains($firstFile, '/timeframe/'), 'recordings live in the timeframe dir');
    assert_true(str_contains(basename($firstFile), (string) getmypid()), 'run id carries the pid');

    $meta = json_decode(explode("
", (string) file_get_contents($firstFile))[0], true);
    assert_array_has_key('runId', $meta, 'meta identifies the run');
    assert_array_has_key('label', $meta, 'meta carries a human label');

    fclose($GLOBALS['__DDLESS_IPC_STREAM__']);
    $GLOBALS['__DDLESS_IPC_STREAM__'] = $previousStream;
    putenv($previousSession === false ? 'DDLESS_DEBUG_SESSION' : "DDLESS_DEBUG_SESSION={$previousSession}");
    $cleanupTimeline();
    $resetTf();
});

test('old recordings are pruned so the directory cannot grow forever', function () use ($resetTf, $tfSessionDir, $tfSessionId, $cleanupTimeline) {
    $resetTf();
    $cleanupTimeline();
    $previousSession = getenv('DDLESS_DEBUG_SESSION');
    putenv("DDLESS_DEBUG_SESSION={$tfSessionId}");

    $dir = ddless_tf_timeline_dir();
    for ($i = 1; $i <= 25; $i++) {
        file_put_contents($dir . '/' . str_pad((string) $i, 4, '0', STR_PAD_LEFT) . '-old.ndjson', "{}
");
    }
    assert_count(25, glob($dir . '/*.ndjson'));

    ddless_tf_prune_old_runs(20);
    $left = glob($dir . '/*.ndjson');
    assert_count(20, $left, 'pruned down to the cap');
    assert_true(str_contains($left[0], '0006-'), 'the oldest went first');

    putenv($previousSession === false ? 'DDLESS_DEBUG_SESSION' : "DDLESS_DEBUG_SESSION={$previousSession}");
    $cleanupTimeline();
    $resetTf();
});

section('Timeframe — stdin journal');

$cleanupJournal = function () use ($tfSessionDir) {
    @unlink($tfSessionDir . '/stdin.ndjson');
};

test('answers read during a recording are journalled in order', function () use ($resetTf, $cleanupJournal, $tfSessionId, $tfSessionDir) {
    $resetTf();
    $cleanupJournal();
    $previousSession = getenv('DDLESS_DEBUG_SESSION');
    putenv("DDLESS_DEBUG_SESSION={$tfSessionId}");
    $GLOBALS['__DDLESS_TIMEFRAME_MODE__'] = true;

    ddless_tf_journal_input('1111');
    ddless_tf_journal_input('');
    ddless_tf_journal_input('2026-08');

    $lines = array_values(array_filter(
        explode("\n", (string) file_get_contents($tfSessionDir . '/stdin.ndjson')),
        fn($l) => trim($l) !== '',
    ));
    assert_count(3, $lines);
    assert_eq('1111', json_decode($lines[0], true));
    assert_eq('', json_decode($lines[1], true), 'an empty answer is still an answer');
    assert_eq('2026-08', json_decode($lines[2], true));

    putenv($previousSession === false ? 'DDLESS_DEBUG_SESSION' : "DDLESS_DEBUG_SESSION={$previousSession}");
    $cleanupJournal();
    $resetTf();
});

test('a replay reads the journalled answers back in order', function () use ($resetTf, $cleanupJournal, $tfSessionId) {
    $resetTf();
    $cleanupJournal();
    $previousSession = getenv('DDLESS_DEBUG_SESSION');
    putenv("DDLESS_DEBUG_SESSION={$tfSessionId}");

    $GLOBALS['__DDLESS_TIMEFRAME_MODE__'] = true;
    ddless_tf_journal_input('1111');
    ddless_tf_journal_input('2026-08');

    // Now replay: the queue is served in order, then runs dry so the caller
    // falls back to a real prompt instead of inventing an answer.
    $GLOBALS['__DDLESS_TIMEFRAME_MODE__'] = false;
    $GLOBALS['__DDLESS_IS_REPLAY__'] = true;
    unset($GLOBALS['__DDLESS_TF_INPUT_QUEUE__']);

    assert_eq('1111', ddless_tf_next_journaled_input());
    assert_eq('2026-08', ddless_tf_next_journaled_input());
    assert_null(ddless_tf_next_journaled_input(), 'exhausted journal falls back to prompting');

    putenv($previousSession === false ? 'DDLESS_DEBUG_SESSION' : "DDLESS_DEBUG_SESSION={$previousSession}");
    $cleanupJournal();
    $resetTf();
});

test('outside a replay nothing is served from the journal', function () use ($resetTf, $cleanupJournal, $tfSessionId) {
    $resetTf();
    $cleanupJournal();
    $previousSession = getenv('DDLESS_DEBUG_SESSION');
    putenv("DDLESS_DEBUG_SESSION={$tfSessionId}");

    $GLOBALS['__DDLESS_TIMEFRAME_MODE__'] = true;
    ddless_tf_journal_input('1111');
    unset($GLOBALS['__DDLESS_TF_INPUT_QUEUE__']);

    assert_null(ddless_tf_next_journaled_input(), 'a normal run always prompts for real');

    putenv($previousSession === false ? 'DDLESS_DEBUG_SESSION' : "DDLESS_DEBUG_SESSION={$previousSession}");
    $cleanupJournal();
    $resetTf();
});

test('journalling is a no-op when nothing is being recorded', function () use ($resetTf, $cleanupJournal, $tfSessionId, $tfSessionDir) {
    $resetTf();
    $cleanupJournal();
    $previousSession = getenv('DDLESS_DEBUG_SESSION');
    putenv("DDLESS_DEBUG_SESSION={$tfSessionId}");

    ddless_tf_journal_input('should not be written');
    assert_false(is_file($tfSessionDir . '/stdin.ndjson'), 'no journal without a recording');

    putenv($previousSession === false ? 'DDLESS_DEBUG_SESSION' : "DDLESS_DEBUG_SESSION={$previousSession}");
    $resetTf();
});

section('Timeframe — Task Runner integration');

test('scratchpad is instrumented without breakpoints when timeframe is on', function () use ($resetTf) {
    $resetTf();
    $GLOBALS['__DDLESS_BREAKPOINT_FILES__'] = [];
    assert_null(ddless_task_instrument_eval_code("<?php\n\$x = 1;\n"), 'no instrumentation without breakpoints');

    $GLOBALS['__DDLESS_TIMEFRAME_MODE__'] = true;
    $result = ddless_task_instrument_eval_code("<?php\n\$x = 1;\n");
    assert_not_null($result, 'timeframe instruments every line, breakpoints or not');
    assert_contains($result, 'ddless_step_check', 'step_check injected');

    $GLOBALS['__DDLESS_ALLOW_GLOBAL_SCOPE__'] = false;
    $resetTf();
});

test('a recorded loop produces one frame per iteration, end to end', function () use ($resetTf, $readTimeline, $cleanupTimeline, $tfSessionId) {
    $resetTf();
    $GLOBALS['__DDLESS_BREAKPOINT_FILES__'] = [];
    $previousSession = getenv('DDLESS_DEBUG_SESSION');
    putenv("DDLESS_DEBUG_SESSION={$tfSessionId}");
    $previousStream = $GLOBALS['__DDLESS_IPC_STREAM__'];
    $GLOBALS['__DDLESS_IPC_STREAM__'] = fopen('php://memory', 'w+');
    $GLOBALS['__DDLESS_TIMEFRAME_MODE__'] = true;

    $code = "<?php\n\$total = 0;\nforeach ([1, 2, 3] as \$n) {\n    \$total += \$n;\n}\n";
    $instrumented = ddless_task_instrument_eval_code($code);
    assert_not_null($instrumented);
    eval($instrumented);
    ddless_tf_close();

    $frames = array_values(array_filter($readTimeline(), fn($l) => isset($l['nth'])));
    $line4 = array_values(array_filter($frames, fn($f) => $f['line'] === 4));
    assert_count(3, $line4, 'the loop body recorded once per iteration');
    assert_eq(1, $line4[0]['nth']);
    assert_eq(3, $line4[2]['nth'], 'iterations are addressable as a.php:4#1..#3');

    // $total is the running sum BEFORE the line executes: 0, 1, 3.
    assert_eq(3, $line4[2]['vars']['total'] ?? null, 'scope captured at each iteration');
    assert_eq(3, $line4[2]['vars']['n'] ?? null, 'loop variable captured too');

    fclose($GLOBALS['__DDLESS_IPC_STREAM__']);
    $GLOBALS['__DDLESS_IPC_STREAM__'] = $previousStream;
    putenv($previousSession === false ? 'DDLESS_DEBUG_SESSION' : "DDLESS_DEBUG_SESSION={$previousSession}");
    $GLOBALS['__DDLESS_ALLOW_GLOBAL_SCOPE__'] = false;
    $cleanupTimeline();
    $resetTf();
});

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    exit(print_test_results());
}
