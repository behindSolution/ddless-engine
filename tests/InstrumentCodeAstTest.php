<?php
/**
 * Tests for ddless_instrument_code_with_ast()
 * Run: php tests/php/InstrumentCodeAstTest.php
 */
require_once __DIR__ . '/bootstrap.php';

/** The original source that follows an injected step_check on the same line. */
function instrumented_rest(string $line): string
{
    return explode('/* DDLESS_BP */', $line, 2)[1] ?? '';
}

/** True when the instrumented code carries a step_check for this source line. */
function instrumented_has_step_check(?string $instrumented, int $line): bool
{
    if ($instrumented === null) {
        return false;
    }
    return (bool) preg_match('/ddless_step_check\(\'[^\']*\', ' . $line . ', /', $instrumented);
}

section('ddless_instrument_code_with_ast()');

test('injects step_check BEFORE statement lines', function () {
    $code = '<?php
function foo() {
    $a = 1;
    return $a;
}';
    $result = ddless_instrument_code_with_ast($code, '/test/file.php', 'file.php');
    assert_not_null($result);
    $resultLines = explode("\n", $result);

    $hasCheckBeforeAssign = false;
    $hasCheckBeforeReturn = false;
    for ($i = 0; $i < count($resultLines); $i++) {
        if (str_contains($resultLines[$i], 'ddless_step_check') && str_contains($resultLines[$i], '/* DDLESS_BP */')) {
            $rest = instrumented_rest($resultLines[$i]);
            if (str_contains($rest, '$a = 1')) {
                $hasCheckBeforeAssign = true;
            }
            if (str_contains($rest, 'return $a')) {
                $hasCheckBeforeReturn = true;
            }
        }
    }
    assert_true($hasCheckBeforeAssign, 'step_check before $a = 1');
    assert_true($hasCheckBeforeReturn, 'step_check before return');
});

test('elseif injection into condition', function () {
    $code = '<?php
function foo($a, $b) {
    if ($a) {
        echo "a";
    } elseif ($b) {
        echo "b";
    }
}';
    $result = ddless_instrument_code_with_ast($code, '/test/file.php', 'file.php');
    assert_not_null($result);
    assert_contains($result, '!(\\ddless_step_gate(', 'elseif condition injection');
    assert_contains($result, '&& ($b)', 'original condition preserved');
});

test('else with user BP injects INSIDE the block', function () {
    $code = '<?php
function foo($a) {
    if ($a) {
        echo "yes";
    } else {
        echo "no";
    }
}';
    $result = ddless_instrument_code_with_ast($code, '/test/file.php', 'file.php', [5]);
    assert_not_null($result);

    $resultLines = explode("\n", $result);
    $elseIdx = null;
    foreach ($resultLines as $i => $line) {
        if (str_contains($line, '} else {')) {
            $elseIdx = $i;
            break;
        }
    }
    assert_not_null($elseIdx, 'else line found');
    assert_contains($resultLines[$elseIdx + 1], 'ddless_step_check', 'step_check inside else block');
});

test('else without user BP does NOT inject extra step_check inside block', function () {
    $code = '<?php
function foo($a) {
    if ($a) {
        echo "yes";
    } else {
        echo "no";
    }
}';
    $resultNoBp = ddless_instrument_code_with_ast($code, '/test/file.php', 'file.php');
    $resultWithBp = ddless_instrument_code_with_ast($code, '/test/file.php', 'file.php', [5]);

    assert_not_null($resultNoBp);
    assert_not_null($resultWithBp);

    $countNoBp = substr_count($resultNoBp, 'ddless_step_check');
    $countWithBp = substr_count($resultWithBp, 'ddless_step_check');
    assert_true($countWithBp > $countNoBp,
        "with BP should have more step_checks ({$countWithBp}) than without ({$countNoBp})");
});

test('catch with user BP injects inside block', function () {
    $code = '<?php
function foo() {
    try {
        echo "try";
    } catch (\Exception $e) {
        echo "catch";
    }
}';
    $result = ddless_instrument_code_with_ast($code, '/test/file.php', 'file.php', [5]);
    assert_not_null($result);

    $resultLines = explode("\n", $result);
    $catchIdx = null;
    foreach ($resultLines as $i => $line) {
        if (str_contains($line, '} catch (')) {
            $catchIdx = $i;
            break;
        }
    }
    assert_not_null($catchIdx, 'catch line found');
    assert_contains($resultLines[$catchIdx + 1], 'ddless_step_check', 'step_check inside catch block');
});

test('conditional breakpoint passes condition to step_check', function () {
    $code = '<?php
function foo($id) {
    $user = find($id);
    return $user;
}';
    $result = ddless_instrument_code_with_ast(
        $code, '/test/file.php', 'file.php',
        [3],
        [3 => '$id > 5'],
    );
    assert_not_null($result);
    assert_contains($result, '$id > 5', 'condition in step_check call');
});

test('logpoint passes expression to step_check', function () {
    $code = '<?php
function foo($user) {
    $name = $user->name;
    return $name;
}';
    $result = ddless_instrument_code_with_ast(
        $code, '/test/file.php', 'file.php',
        [3],
        [],
        [3 => 'User: {$user->name}'],
    );
    assert_not_null($result);
    assert_contains($result, 'User: {$user->name}', 'logpoint expression in step_check');
});

test('delimiter safety: lines starting with ) or } are skipped', function () {
    $code = '<?php
function foo(
    $a,
    $b
) {
    return $a + $b;
}';
    $result = ddless_instrument_code_with_ast($code, '/test/file.php', 'file.php');
    assert_not_null($result);

    $resultLines = explode("\n", $result);
    foreach ($resultLines as $i => $line) {
        if (str_contains($line, 'ddless_step_check') && str_contains($line, '/* DDLESS_BP */')) {
            $nextTrimmed = trim(instrumented_rest($line));
            if ($nextTrimmed !== '') {
                $firstChar = $nextTrimmed[0];
                assert_true(
                    strpos(')]}>{', $firstChar) === false,
                    "step_check should not be before a delimiter line: {$nextTrimmed}"
                );
            }
        }
    }
});

test('preserves indentation of injected step_check', function () {
    $code = '<?php
function foo() {
        $a = 1;
}';
    $result = ddless_instrument_code_with_ast($code, '/test/file.php', 'file.php');
    assert_not_null($result);

    $resultLines = explode("\n", $result);
    foreach ($resultLines as $line) {
        if (str_contains($line, 'ddless_step_check') && str_contains($line, '/* DDLESS_BP */')) {
            preg_match('/^(\s*)/', $line, $m);
            assert_eq('        ', $m[1], 'indentation should match original line');
            break;
        }
    }
});

test('returns null when no instrumentable lines', function () {
    $code = '<?php
class Foo {
    const X = 1;
}';
    $result = ddless_instrument_code_with_ast($code, '/test/file.php', 'file.php');
    assert_null($result, 'no instrumentable lines');
});

test('HTML lines are skipped in global scope', function () {
    $GLOBALS['__DDLESS_ALLOW_GLOBAL_SCOPE__'] = true;
    $code = '<?php $x = 1; ?>
<div>Hello</div>
<?php echo $x; ?>';
    $result = ddless_instrument_code_with_ast($code, '/test/file.php', 'file.php');
    $GLOBALS['__DDLESS_ALLOW_GLOBAL_SCOPE__'] = false;

    if ($result !== null) {
        assert_not_contains($result, '<div>Hello</div>' . "\n" . '\\ddless_step_check',
            'should not inject before HTML');
    }
});

test('short echo tags are skipped', function () {
    $GLOBALS['__DDLESS_ALLOW_GLOBAL_SCOPE__'] = true;
    $code = '<?php $x = 1; ?>
<?= $x ?>';
    $result = ddless_instrument_code_with_ast($code, '/test/file.php', 'file.php');
    $GLOBALS['__DDLESS_ALLOW_GLOBAL_SCOPE__'] = false;

    if ($result !== null) {
        $resultLines = explode("\n", $result);
        foreach ($resultLines as $line) {
            if (str_contains($line, '<?=')) {
                assert_not_contains($line, 'ddless_step_check',
                    'short echo tag line should not have injection');
                break;
            }
        }
    }
});

test('multiple elseif chains work correctly', function () {
    $code = '<?php
function foo($x) {
    if ($x === 1) {
        echo "one";
    } elseif ($x === 2) {
        echo "two";
    } elseif ($x === 3) {
        echo "three";
    } else {
        echo "other";
    }
}';
    $result = ddless_instrument_code_with_ast($code, '/test/file.php', 'file.php');
    assert_not_null($result);

    $elseifCount = substr_count($result, '!(\\ddless_step_gate(');
    assert_eq(2, $elseifCount, 'two elseif conditions should be injected');
});

test('isUserBreakpoint flag in step_check is true/false correctly', function () {
    $code = '<?php
function foo() {
    $a = 1;
    $b = 2;
}';
    $result = ddless_instrument_code_with_ast($code, '/test/file.php', 'file.php', [3]);
    assert_not_null($result);

    $resultLines = explode("\n", $result);
    foreach ($resultLines as $i => $line) {
        if (str_contains($line, 'ddless_step_check') && str_contains($line, '/* DDLESS_BP */')) {
            $nextLine = instrumented_rest($line);
            if (str_contains($nextLine, '$a = 1')) {
                assert_contains($line, ', true,', 'line 3 should be isUserBp=true');
            }
            if (str_contains($nextLine, '$b = 2')) {
                assert_contains($line, ', false,', 'line 4 should be isUserBp=false');
            }
        }
    }
});

test('braceless if injects step_check into condition', function () {
    $code = '<?php
function foo($a) {
    if ( empty($a) || $a === "no" )
        $a = false;
    else
        $a = true;
    return $a;
}';
    $result = ddless_instrument_code_with_ast($code, '/test/file.php', 'file.php');
    assert_not_null($result);

    // Must NOT produce a parse error — the instrumented code must be valid PHP
    $tokens = @token_get_all($result);
    assert_true(is_array($tokens), 'instrumented code should tokenize without error');

    // The if condition should have step_check injected (like elseif pattern)
    assert_contains($result, '!(\\ddless_step_gate(', 'step_check in braceless if condition');
    assert_contains($result, '&& (', 'original condition wrapped');

    // Body lines ($a = false / $a = true) should NOT have step_check
    $resultLines = explode("\n", $result);
    foreach ($resultLines as $line) {
        if (str_contains($line, '$a = false') || str_contains($line, '$a = true')) {
            assert_not_contains($line, 'ddless_step_check',
                'braceless body should not be instrumented: ' . trim($line));
        }
    }
});

test('braceless while injects step_check into condition', function () {
    $code = '<?php
function foo($items) {
    $i = 0;
    while ($i < count($items))
        $i++;
    return $i;
}';
    $result = ddless_instrument_code_with_ast($code, '/test/file.php', 'file.php');
    assert_not_null($result);

    assert_contains($result, '!(\\ddless_step_gate(', 'step_check in braceless while condition');

    $resultLines = explode("\n", $result);
    foreach ($resultLines as $line) {
        if (str_contains($line, '$i++')) {
            assert_not_contains($line, 'ddless_step_check',
                'braceless while body should not be instrumented');
        }
    }
});

test('braced if still uses normal injection before line', function () {
    $code = '<?php
function foo($a) {
    if ($a) {
        $b = 1;
    }
    return $a;
}';
    $result = ddless_instrument_code_with_ast($code, '/test/file.php', 'file.php');
    assert_not_null($result);

    $resultLines = explode("\n", $result);
    $hasCheckBeforeIf = false;
    for ($i = 0; $i < count($resultLines); $i++) {
        if (str_contains($resultLines[$i], 'ddless_step_check') && str_contains($resultLines[$i], '/* DDLESS_BP */')) {
            if (str_contains(instrumented_rest($resultLines[$i]), 'if ($a) {')) {
                $hasCheckBeforeIf = true;
            }
        }
    }
    assert_true($hasCheckBeforeIf, 'braced if should have step_check BEFORE (not in condition)');
});

test('braced if with trailing comment is NOT treated as braceless', function () {
    $code = '<?php
function foo($did_just_catch) {
    if ( $did_just_catch ) { // @phpstan-ignore if.alwaysFalse (The variable is set in the catch block below.)
        $level = E_USER_ERROR;
    }
    return $level;
}';
    $result = ddless_instrument_code_with_ast($code, '/test/template.php', 'template.php');
    assert_not_null($result);

    // Must NOT inject into condition — this is a braced if
    $resultLines = explode("\n", $result);
    foreach ($resultLines as $line) {
        if (str_contains($line, '$did_just_catch')) {
            assert_not_contains($line, '!(\\ddless_step_gate(',
                'braced if with comment should NOT get condition injection');
            break;
        }
    }

    // Must be valid PHP
    $tmpFile = tempnam(sys_get_temp_dir(), 'ddless_test_');
    file_put_contents($tmpFile, $result);
    $output = [];
    $exitCode = 0;
    exec('php -l ' . escapeshellarg($tmpFile) . ' 2>&1', $output, $exitCode);
    @unlink($tmpFile);
    assert_eq(0, $exitCode, 'instrumented code must be valid PHP: ' . implode("\n", $output));
});

test('braceless if/else with WordPress deprecated.php pattern', function () {
    $code = '<?php
function adjacent_post_link( $format, $link, $in_same_cat = false, $excluded_categories = \'\' ) {
    if ( empty($in_same_cat) || \'no\' == $in_same_cat )
        $in_same_cat = false;
    else
        $in_same_cat = true;

    if ( empty($excluded_categories) || \'no\' == $excluded_categories )
        $excluded_categories = \'\';

    return $in_same_cat;
}';
    $result = ddless_instrument_code_with_ast($code, '/test/deprecated.php', 'deprecated.php');
    assert_not_null($result);

    // Validate the instrumented code is parseable PHP
    $tmpFile = tempnam(sys_get_temp_dir(), 'ddless_test_');
    file_put_contents($tmpFile, $result);
    $output = [];
    $exitCode = 0;
    exec('php -l ' . escapeshellarg($tmpFile) . ' 2>&1', $output, $exitCode);
    @unlink($tmpFile);
    assert_eq(0, $exitCode, 'instrumented code must be valid PHP: ' . implode("\n", $output));
});

test('does not inject between PHP attribute and function declaration', function () {
    $code = '<?php
class ChildRepo extends BaseRepo
{
    #[\Override]
    public function findAll(): array { return [1, 2, 3]; }
}';
    $result = ddless_instrument_code_with_ast($code, '/test/file.php', 'file.php');
    // May be null (no instrumentable lines inside single-line body) or valid PHP
    if ($result !== null) {
        // Must NOT have step_check between #[\Override] and public function
        $lines = explode("\n", $result);
        for ($i = 0; $i < count($lines); $i++) {
            if (str_contains($lines[$i], '#[\\Override]') || str_contains($lines[$i], '#[\Override]')) {
                $next = $lines[$i + 1] ?? '';
                assert_true(
                    !str_contains($next, 'ddless_step_check'),
                    'step_check must not be injected between attribute and function'
                );
            }
        }

        // Validate the instrumented code is parseable PHP
        $tmpFile = tempnam(sys_get_temp_dir(), 'ddless_test_');
        file_put_contents($tmpFile, $result);
        $output = [];
        $exitCode = 0;
        exec('php -l ' . escapeshellarg($tmpFile) . ' 2>&1', $output, $exitCode);
        @unlink($tmpFile);
        assert_eq(0, $exitCode, 'code with #[Override] must be valid PHP: ' . implode("\n", $output));
    }
});

test('does not inject between multi-line attribute and declaration', function () {
    $code = '<?php
class Controller
{
    #[Route("/api/test", methods: ["POST"])]
    public function handle(): array
    {
        $data = request()->all();
        return $data;
    }
}';
    $result = ddless_instrument_code_with_ast($code, '/test/file.php', 'file.php');
    assert_not_null($result);

    // Validate the instrumented code is parseable PHP
    $tmpFile = tempnam(sys_get_temp_dir(), 'ddless_test_');
    file_put_contents($tmpFile, $result);
    $output = [];
    $exitCode = 0;
    exec('php -l ' . escapeshellarg($tmpFile) . ' 2>&1', $output, $exitCode);
    @unlink($tmpFile);
    assert_eq(0, $exitCode, 'code with #[Route] must be valid PHP: ' . implode("\n", $output));
});

test('does not inject between switch { and case label', function () {
    $code = '<?php
function bean_implements($interface) {
    switch ($interface) {
        case "ACL": return true;
    }
    return false;
}';
    $result = ddless_instrument_code_with_ast($code, '/test/file.php', 'file.php');
    assert_not_null($result);

    // Must NOT have step_check between switch { and case
    $resultLines = explode("\n", $result);
    for ($i = 0; $i < count($resultLines); $i++) {
        if (str_contains($resultLines[$i], 'switch (')) {
            $next = trim($resultLines[$i + 1] ?? '');
            assert_true(
                !str_contains($next, 'ddless_step_check'),
                'step_check must not be injected between switch { and case'
            );
        }
    }

    // Validate the instrumented code is parseable PHP
    $tmpFile = tempnam(sys_get_temp_dir(), 'ddless_test_');
    file_put_contents($tmpFile, $result);
    $output = [];
    $exitCode = 0;
    exec('php -l ' . escapeshellarg($tmpFile) . ' 2>&1', $output, $exitCode);
    @unlink($tmpFile);
    assert_eq(0, $exitCode, 'switch/case instrumented code must be valid PHP: ' . implode("\n", $output));
});

test('switch with separate case lines instruments correctly', function () {
    $code = '<?php
function getIcon($type) {
    $icon = "";
    switch ($type) {
        case "full":
            $icon = "Upgrade";
            break;
        case "patch":
            $icon = "Patch";
            break;
        default:
            $icon = "Unknown";
            break;
    }
    return $icon;
}';
    $result = ddless_instrument_code_with_ast($code, '/test/file.php', 'file.php');
    assert_not_null($result);

    // step_check should exist before $icon assignments inside case blocks
    assert_contains($result, 'ddless_step_check', 'should have instrumentation');

    // Validate the instrumented code is parseable PHP
    $tmpFile = tempnam(sys_get_temp_dir(), 'ddless_test_');
    file_put_contents($tmpFile, $result);
    $output = [];
    $exitCode = 0;
    exec('php -l ' . escapeshellarg($tmpFile) . ' 2>&1', $output, $exitCode);
    @unlink($tmpFile);
    assert_eq(0, $exitCode, 'normal switch/case must be valid PHP: ' . implode("\n", $output));
});

test('switch with inline case+statement on same line', function () {
    $code = '<?php
function translate($key) {
    switch ($key) {
        case "yes": return true;
        case "no": return false;
        default: return null;
    }
}';
    $result = ddless_instrument_code_with_ast($code, '/test/file.php', 'file.php');
    assert_not_null($result);

    // Lines starting with case should NOT have step_check before them
    $resultLines = explode("\n", $result);
    for ($i = 0; $i < count($resultLines); $i++) {
        if (str_contains($resultLines[$i], 'ddless_step_check') && str_contains($resultLines[$i], '/* DDLESS_BP */')) {
            $next = trim(instrumented_rest($resultLines[$i]));
            assert_true(
                !preg_match('/^case\s/', $next) && !preg_match('/^default\s*:/', $next),
                'step_check must not precede a case/default line: ' . $next
            );
        }
    }

    // Validate
    $tmpFile = tempnam(sys_get_temp_dir(), 'ddless_test_');
    file_put_contents($tmpFile, $result);
    $output = [];
    $exitCode = 0;
    exec('php -l ' . escapeshellarg($tmpFile) . ' 2>&1', $output, $exitCode);
    @unlink($tmpFile);
    assert_eq(0, $exitCode, 'inline case/return must be valid PHP: ' . implode("\n", $output));
});

section('ddless_instrument_code_with_ast() — comments and multi-line headers');

test('statement below a comment ending in ")" is instrumented', function () {
    $code = <<<'PHP'
<?php
function total_with_comment() {
    // Calculate total (with tax)
    $total = 1 + 2;
    return $total;
}
PHP;
    $result = ddless_instrument_code_with_ast($code, '/app/Total.php', 'Total.php', [4]);
    assert_true(instrumented_has_step_check($result, 4), 'line 4 has a user breakpoint but got no step_check');
});

test('multi-line "if (" condition (PSR-12) is instrumented', function () {
    $code = <<<'PHP'
<?php
function multiline_if($x) {
    if (
        $x > 1
    ) {
        $x++;
    }
    return $x;
}
PHP;
    $result = ddless_instrument_code_with_ast($code, '/app/Multi.php', 'Multi.php', [3]);
    assert_true(instrumented_has_step_check($result, 3), 'breakpoint on the "if (" line got no step_check');
});

test('multi-line "foreach (" header is instrumented', function () {
    $code = <<<'PHP'
<?php
function multiline_foreach($items) {
    foreach (
        $items as $item
    ) {
        echo $item;
    }
}
PHP;
    $result = ddless_instrument_code_with_ast($code, '/app/Each.php', 'Each.php', [3]);
    assert_true(instrumented_has_step_check($result, 3), 'breakpoint on the "foreach (" line got no step_check');
});

section('ddless_instrument_code_with_ast() — line numbers are preserved');

test('instrumentation keeps every source line on its original line number', function () {
    $code = <<<'PHP'
<?php
function lines_fixture() {
    $a = 1;
    $b = 2;
    return lines_helper($a + $b);
}
function lines_helper($v) {
    return $v * 2;
}
PHP;
    $result = ddless_instrument_code_with_ast($code, '/app/Lines.php', 'Lines.php', [3]);
    assert_not_null($result);
    $original = explode("\n", $code);
    $instrumented = explode("\n", $result);
    assert_eq(count($original), count($instrumented), 'line count changed (stack traces, exceptions and __LINE__ shift)');
});

test('an exception thrown in instrumented code reports the real line', function () {
    $code = <<<'PHP'
<?php
function instrumented_thrower() {
    $a = 1;
    $b = 2;
    throw new \RuntimeException('boom');
}
PHP;
    $result = ddless_instrument_code_with_ast($code, '/app/Thrower.php', 'Thrower.php', []);
    assert_not_null($result);
    eval(preg_replace('/<\?php/', '', $result, 1));
    try {
        instrumented_thrower();
        throw new \LogicException('instrumented_thrower() should have thrown');
    } catch (\RuntimeException $e) {
        assert_eq(5, $e->getLine(), 'exception line');
    }
});

section('ddless_instrument_code_with_ast() — breakpoints always land');

test('breakpoint on the body of a braceless if lands on the if', function () {
    $code = <<<'CODE'
<?php
function braceless_body($x) {
    $y = 1;
    if ($x)
        $y = 2;
    return $y;
}
CODE;
    $result = ddless_instrument_code_with_ast($code, '/app/Body.php', 'Body.php', [5]);
    $lines = explode("\n", $result);
    assert_not_contains($lines[4], 'ddless_step_check', 'the body itself cannot take a statement');
    assert_contains($lines[3], "'Body.php', true", 'the breakpoint moved to the if on line 4');
    assert_contains($lines[2], "'Body.php', false", 'unrelated lines are not breakpoints');
});

test('breakpoint on a "case x: statement" line lands on the line above', function () {
    $code = <<<'CODE'
<?php
function inline_case($key) {
    switch ($key) {
        case 'yes': return true;
    }
    return false;
}
CODE;
    $result = ddless_instrument_code_with_ast($code, '/app/Case.php', 'Case.php', [4]);
    $lines = explode("\n", $result);
    assert_not_contains($lines[3], 'ddless_step_check', 'nothing can be injected before a case label');
    assert_contains($lines[2], "'Case.php', true", 'the breakpoint moved to the switch on line 3');
});

test('condition and logpoint of a moved breakpoint follow it', function () {
    $code = <<<'CODE'
<?php
function moved_settings($x) {
    if ($x)
        return 1;
    return 0;
}
CODE;
    $result = ddless_instrument_code_with_ast(
        $code, '/app/Moved.php', 'Moved.php',
        [4],
        [4 => '$x > 10'],
        [4 => 'x is {$x}']
    );
    $lines = explode("\n", $result);
    assert_contains($lines[2], '$x > 10', 'condition applied where the breakpoint landed');
    assert_contains($lines[2], 'x is {$x}', 'logpoint applied where the breakpoint landed');
});

test('breakpoint on else is still injected inside the else block', function () {
    $code = <<<'CODE'
<?php
function else_block($x) {
    if ($x) {
        $y = 1;
    } else {
        $y = 2;
    }
    return $y;
}
CODE;
    $withBp = explode("\n", ddless_instrument_code_with_ast($code, '/app/Else.php', 'Else.php', [5]));
    assert_contains($withBp[4], "'Else.php', true", 'breakpoint on the else line');
    $withoutBp = explode("\n", ddless_instrument_code_with_ast($code, '/app/Else.php', 'Else.php', []));
    assert_not_contains($withoutBp[4], 'ddless_step_check', 'else is not a stepping stop on its own');
});

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    exit(print_test_results());
}
