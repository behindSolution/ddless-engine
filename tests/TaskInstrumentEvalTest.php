<?php
/**
 * Tests for ddless_task_instrument_eval_code() — the Task Runner "Enable
 * Debugging" engine: instruments the scratchpad (virtual file __taskrunner__.php)
 * so breakpoints pause execution, preserving editor line numbers.
 * Run: php tests/php/TaskInstrumentEvalTest.php
 */
require_once __DIR__ . '/bootstrap.php';

section('ddless_task_instrument_eval_code()');

$virtualAbs = DDLESS_PROJECT_ROOT . '/__taskrunner__.php';

// Reset the breakpoint globals between tests (closure, not a global function,
// to avoid polluting the shared run-all process).
$resetTaskBp = function () {
    $GLOBALS['__DDLESS_BREAKPOINT_FILES__'] = [];
    $GLOBALS['__DDLESS_USER_BP_LINES__'] = [];
    $GLOBALS['__DDLESS_ALLOW_GLOBAL_SCOPE__'] = false;
};

$setBp = function (array $lines, array $extra = []) use ($virtualAbs) {
    $GLOBALS['__DDLESS_BREAKPOINT_FILES__'] = [];
    $GLOBALS['__DDLESS_BREAKPOINT_FILES__'][$virtualAbs] = array_merge(
        ['relativePath' => '__taskrunner__.php', 'lines' => $lines],
        $extra,
    );
};

test('returns null when there are no breakpoints for the scratchpad', function () use ($resetTaskBp) {
    $resetTaskBp();
    assert_null(ddless_task_instrument_eval_code("<?php\n\$x = 1;\n"));
});

test('instruments the breakpointed line and neutralizes <?php', function () use ($resetTaskBp, $setBp) {
    $setBp([3]);
    $code = "<?php\n\n\$x = 1 + 1;\n\$this->info(\$x);\n";
    $result = ddless_task_instrument_eval_code($code);
    assert_not_null($result, 'should instrument when a breakpoint exists');
    assert_contains($result, 'ddless_step_check', 'step_check injected');
    assert_not_contains($result, '<?php', 'open tag neutralized for eval()');
    $resetTaskBp();
});

test('step_check reports the original editor line number', function () use ($resetTaskBp, $setBp) {
    $setBp([3]);
    $code = "<?php\n\n\$x = 1 + 1;\n\$this->info(\$x);\n";
    $result = ddless_task_instrument_eval_code($code);
    assert_not_null($result);
    // Instrumentation adds physical lines, but each step_check embeds the
    // ORIGINAL line number — so the pause highlights the right editor line
    // (3) regardless of the shift. The original statement text is preserved too.
    assert_contains($result, ", 3, '__taskrunner__.php'", 'step_check reports line 3 for the virtual file');
    assert_contains($result, '$x = 1 + 1', 'breakpoint statement preserved');
    $resetTaskBp();
});

test('enables global scope (scratchpad is top-level code)', function () use ($resetTaskBp, $setBp) {
    $resetTaskBp();
    $setBp([3]);
    assert_false($GLOBALS['__DDLESS_ALLOW_GLOBAL_SCOPE__'], 'precondition: global scope off');
    ddless_task_instrument_eval_code("<?php\n\n\$x = 1;\n");
    assert_true($GLOBALS['__DDLESS_ALLOW_GLOBAL_SCOPE__'], 'global scope enabled after instrumenting');
    $resetTaskBp();
});

test('registers the breakpoint line globally', function () use ($resetTaskBp, $setBp, $virtualAbs) {
    $setBp([3]);
    ddless_task_instrument_eval_code("<?php\n\n\$x = 1;\n");
    assert_true(!empty($GLOBALS['__DDLESS_USER_BP_LINES__'][$virtualAbs . ':3']), 'user bp line registered');
    $resetTaskBp();
});

test('instrumented eval code is syntactically valid PHP', function () use ($resetTaskBp, $setBp) {
    $setBp([3, 4]);
    $code = "<?php\n\n\$total = 0;\nforeach ([1, 2, 3] as \$n) { \$total += \$n; }\n\$this->info((string) \$total);\n";
    $result = ddless_task_instrument_eval_code($code);
    assert_not_null($result);

    // The eval body has no open tag — wrap it for the linter.
    $tmp = tempnam(sys_get_temp_dir(), 'ddless_taskdbg_');
    file_put_contents($tmp, "<?php\n" . $result);
    $out = []; $rc = 0;
    exec('php -l ' . escapeshellarg($tmp) . ' 2>&1', $out, $rc);
    @unlink($tmp);
    assert_eq(0, $rc, 'instrumented code must be valid PHP: ' . implode("\n", $out));
    $resetTaskBp();
});

test('returns null on parse error', function () use ($resetTaskBp, $setBp) {
    $setBp([3]);
    assert_null(ddless_task_instrument_eval_code("<?php\n\nfunction broken( {\n"));
    $resetTaskBp();
});

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    exit(print_test_results());
}
