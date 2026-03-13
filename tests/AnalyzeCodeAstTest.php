<?php
/**
 * Tests for ddless_analyze_code_ast()
 * Run: php tests/php/AnalyzeCodeAstTest.php
 */
require_once __DIR__ . '/bootstrap.php';

section('ddless_analyze_code_ast()');

test('basic statements inside function', function () {
    $code = '<?php
function foo() {
    $a = 1;
    echo $a;
    return $a;
}';
    $lines = ddless_analyze_code_ast($code);
    assert_array_has_key(3, $lines, '$a = 1');
    assert_array_has_key(4, $lines, 'echo');
    assert_array_has_key(5, $lines, 'return');
    assert_eq('statement', $lines[3]['type']);
    assert_eq('statement', $lines[4]['type']);
    assert_eq('statement', $lines[5]['type']);
});

test('control structures', function () {
    $code = '<?php
function foo($x, $arr) {
    if ($x) {
        echo "if";
    }
    for ($i = 0; $i < 10; $i++) {
        echo $i;
    }
    foreach ($arr as $item) {
        echo $item;
    }
    while ($x > 0) {
        $x--;
    }
}';
    $lines = ddless_analyze_code_ast($code);
    assert_eq('control', $lines[3]['type'], 'if');
    assert_eq('control', $lines[6]['type'], 'for');
    assert_eq('control', $lines[9]['type'], 'foreach');
    assert_eq('control', $lines[12]['type'], 'while');
});

test('switch is control, case is NOT instrumentable', function () {
    $code = '<?php
function foo($x) {
    switch ($x) {
        case 1:
            echo "one";
            break;
        case 2:
            echo "two";
            break;
        default:
            echo "other";
    }
}';
    $lines = ddless_analyze_code_ast($code);
    assert_eq('control', $lines[3]['type'], 'switch');
    assert_array_not_has_key(4, $lines, 'case 1 should not be instrumentable');
    assert_array_not_has_key(7, $lines, 'case 2 should not be instrumentable');
    assert_array_not_has_key(10, $lines, 'default should not be instrumentable');
    assert_eq('statement', $lines[5]['type'], 'echo one');
    assert_eq('statement', $lines[6]['type'], 'break');
});

test('elseif classified as elseif type', function () {
    $code = '<?php
function foo($a, $b) {
    if ($a) {
        echo "a";
    } elseif ($b) {
        echo "b";
    }
}';
    $lines = ddless_analyze_code_ast($code);
    assert_eq('elseif', $lines[5]['type']);
});

test('else classified as else type', function () {
    $code = '<?php
function foo($a) {
    if ($a) {
        echo "yes";
    } else {
        echo "no";
    }
}';
    $lines = ddless_analyze_code_ast($code);
    assert_eq('else', $lines[5]['type']);
});

test('catch and finally classified correctly', function () {
    $code = '<?php
function foo() {
    try {
        echo "try";
    } catch (\Exception $e) {
        echo "catch";
    } finally {
        echo "finally";
    }
}';
    $lines = ddless_analyze_code_ast($code);
    assert_eq('control', $lines[3]['type'], 'try');
    assert_eq('catch', $lines[5]['type']);
    assert_eq('finally', $lines[7]['type']);
});

test('global scope lines are ignored by default', function () {
    $code = '<?php
$a = 1;
echo $a;
';
    $lines = ddless_analyze_code_ast($code);
    assert_count(0, $lines, 'no lines in global scope');
});

test('global scope lines included when allowGlobalScope is true', function () {
    $GLOBALS['__DDLESS_ALLOW_GLOBAL_SCOPE__'] = true;
    $code = '<?php
$a = 1;
echo $a;
';
    $lines = ddless_analyze_code_ast($code);
    $GLOBALS['__DDLESS_ALLOW_GLOBAL_SCOPE__'] = false;

    assert_array_has_key(2, $lines, '$a = 1');
    assert_array_has_key(3, $lines, 'echo');
});

test('single-line method body not instrumentable (overlap with declaration)', function () {
    $code = '<?php
class Foo {
    public function bar() { return 42; }
}';
    $lines = ddless_analyze_code_ast($code);
    assert_count(0, $lines, 'single-line method overlaps with declaration');
});

test('closure body is instrumentable', function () {
    $code = '<?php
function foo() {
    $fn = function () {
        return 1;
    };
}';
    $lines = ddless_analyze_code_ast($code);
    assert_array_has_key(3, $lines, '$fn assignment');
    assert_array_has_key(4, $lines, 'return inside closure');
    assert_eq('statement', $lines[3]['type']);
    assert_eq('statement', $lines[4]['type']);
});

test('arrow function is tracked (assignment line)', function () {
    $code = '<?php
function foo() {
    $fn = fn($x) => $x + 1;
}';
    $lines = ddless_analyze_code_ast($code);
    assert_array_has_key(3, $lines, '$fn assignment');
});

test('multi-line chain only has start line', function () {
    $code = '<?php
function foo($obj) {
    $result = $obj
        ->method1()
        ->method2();
}';
    $lines = ddless_analyze_code_ast($code);
    assert_array_has_key(3, $lines, 'chain start line');
    assert_array_not_has_key(4, $lines, 'continuation line 1');
    assert_array_not_has_key(5, $lines, 'continuation line 2');
});

test('syntax error returns empty array without crash', function () {
    $code = '<?php function foo() { $x = ';
    $lines = ddless_analyze_code_ast($code);
    assert_true(is_array($lines));
});

test('empty PHP file returns empty array', function () {
    $code = '<?php';
    $lines = ddless_analyze_code_ast($code);
    assert_count(0, $lines);
});

test('nested functions have separate scopes', function () {
    $code = '<?php
function outer() {
    $a = 1;
    function inner() {
        return 2;
    }
    return $a;
}';
    $lines = ddless_analyze_code_ast($code);
    assert_array_has_key(3, $lines, '$a = 1 in outer');
    assert_array_has_key(5, $lines, 'return in inner');
    assert_array_has_key(7, $lines, 'return in outer');
});

test('class method statements are instrumentable', function () {
    $code = '<?php
class UserController {
    public function index($id) {
        $user = User::find($id);
        $posts = $user->posts;
        return view("user", compact("user", "posts"));
    }
}';
    $lines = ddless_analyze_code_ast($code);
    assert_array_has_key(4, $lines, '$user assignment');
    assert_array_has_key(5, $lines, '$posts assignment');
    assert_array_has_key(6, $lines, 'return');
});

test('class/property declarations are NOT instrumentable', function () {
    $code = '<?php
class Foo {
    public $bar = 1;
    private $baz;
    const X = 10;
    public function test() {
        return $this->bar;
    }
}';
    $lines = ddless_analyze_code_ast($code);
    assert_array_not_has_key(2, $lines, 'class declaration');
    assert_array_not_has_key(3, $lines, 'property declaration');
    assert_array_not_has_key(4, $lines, 'private property');
    assert_array_not_has_key(5, $lines, 'const');
    assert_array_has_key(7, $lines, 'return in method');
});

test('user breakpoint lines are marked with isUserBp', function () {
    $code = '<?php
function foo() {
    $a = 1;
    $b = 2;
    return $a + $b;
}';
    $lines = ddless_analyze_code_ast($code, [3, 5]);
    assert_true($lines[3]['isUserBp'], 'line 3 should be user BP');
    assert_false($lines[4]['isUserBp'], 'line 4 should not be user BP');
    assert_true($lines[5]['isUserBp'], 'line 5 should be user BP');
});

test('do-while is instrumentable', function () {
    $code = '<?php
function foo() {
    do {
        $x = 1;
    } while ($x > 0);
}';
    $lines = ddless_analyze_code_ast($code);
    assert_eq('control', $lines[3]['type'], 'do');
});

test('try-catch is control type', function () {
    $code = '<?php
function foo() {
    try {
        throw new \Exception("test");
    } catch (\Exception $e) {
        echo $e->getMessage();
    }
}';
    $lines = ddless_analyze_code_ast($code);
    assert_eq('control', $lines[3]['type'], 'try');
});

test('unset and global statements', function () {
    $code = '<?php
function foo() {
    global $config;
    $x = 1;
    unset($x);
}';
    $lines = ddless_analyze_code_ast($code);
    assert_eq('statement', $lines[3]['type'], 'global');
    assert_eq('statement', $lines[4]['type'], '$x');
    assert_eq('statement', $lines[5]['type'], 'unset');
});

test('break and continue statements', function () {
    $code = '<?php
function foo($arr) {
    foreach ($arr as $item) {
        if ($item === null) {
            continue;
        }
        if ($item === false) {
            break;
        }
        echo $item;
    }
}';
    $lines = ddless_analyze_code_ast($code);
    assert_eq('statement', $lines[5]['type'], 'continue');
    assert_eq('statement', $lines[8]['type'], 'break');
});

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    exit(print_test_results());
}
