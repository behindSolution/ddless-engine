<?php
/**
 * Tests for ddless_eval_in_context()
 * Run: php tests/php/EvalInContextTest.php
 */
require_once __DIR__ . '/bootstrap.php';

// ── Helper class for $this binding tests ────────────────────────────────────

class EvalTestDummy {
    public string $name = 'Alice';
    private int $secret = 42;

    public function getSecret(): int {
        return $this->secret;
    }
}

section('ddless_eval_in_context() — returnValue=true (default)');

test('evaluates simple expression', function () {
    $vars = [];
    $result = ddless_eval_in_context('1 + 2', $vars, []);
    assert_eq(3, $result);
});

test('evaluates string expression', function () {
    $vars = [];
    $result = ddless_eval_in_context('"hello " . "world"', $vars, []);
    assert_eq('hello world', $result);
});

test('accesses scope variables', function () {
    $vars = ['x' => 10, 'y' => 20];
    $result = ddless_eval_in_context('$x + $y', $vars, []);
    assert_eq(30, $result);
});

test('accesses array scope variable', function () {
    $vars = ['items' => ['a', 'b', 'c']];
    $result = ddless_eval_in_context('count($items)', $vars, []);
    assert_eq(3, $result);
});

test('scope variables do not leak between calls', function () {
    $vars1 = ['leak' => 999];
    ddless_eval_in_context('$leak = 999', $vars1, []);
    // $leak should not exist in the next call
    $vars2 = [];
    $result = ddless_eval_in_context('isset($leak) ? $leak : null', $vars2, []);
    assert_null($result);
});

section('ddless_eval_in_context() — returnValue=false (playground mode)');

test('executes raw code and returns result', function () {
    $vars = [];
    $result = ddless_eval_in_context('$a = 5; $b = 10; return $a * $b;', $vars, [], false);
    assert_eq(50, $result);
});

test('multi-line code with return', function () {
    $code = <<<'PHP'
$items = [1, 2, 3, 4, 5];
$sum = 0;
foreach ($items as $i) {
    $sum += $i;
}
return $sum;
PHP;
    $vars = [];
    $result = ddless_eval_in_context($code, $vars, [], false);
    assert_eq(15, $result);
});

test('returns null when no return statement', function () {
    $vars = [];
    $result = ddless_eval_in_context('$x = 42;', $vars, [], false);
    assert_null($result);
});

test('accesses scope variables in raw mode', function () {
    $vars = ['user' => ['name' => 'Bob', 'age' => 30]];
    $result = ddless_eval_in_context('return $user["name"] . " is " . $user["age"];', $vars, [], false);
    assert_eq('Bob is 30', $result);
});

test('can use built-in functions', function () {
    $vars = [];
    $result = ddless_eval_in_context('return array_map(fn($v) => $v * 2, [1,2,3]);', $vars, [], false);
    assert_eq([2, 4, 6], $result);
});

test('can use anonymous functions', function () {
    $code = <<<'PHP'
$fn = function($a, $b) { return $a + $b; };
return $fn(3, 7);
PHP;
    $vars = [];
    $result = ddless_eval_in_context($code, $vars, [], false);
    assert_eq(10, $result);
});

section('ddless_eval_in_context() — $this binding');

test('binds $this when backtrace has object', function () {
    $obj = new EvalTestDummy();
    $backtrace = [
        ['function' => 'someMethod', 'object' => $obj],
    ];
    $vars = [];
    $result = ddless_eval_in_context('$this->name', $vars, $backtrace);
    assert_eq('Alice', $result);
});

test('$this can access private members via closure binding', function () {
    $obj = new EvalTestDummy();
    $backtrace = [
        ['function' => 'someMethod', 'object' => $obj],
    ];
    $vars = [];
    $result = ddless_eval_in_context('$this->getSecret()', $vars, $backtrace);
    assert_eq(42, $result);
});

test('skips ddless_ frames in backtrace', function () {
    $obj = new EvalTestDummy();
    $backtrace = [
        ['function' => 'ddless_step_check'],
        ['function' => 'ddless_handle_breakpoint'],
        ['function' => 'someMethod', 'object' => $obj],
    ];
    $vars = [];
    $result = ddless_eval_in_context('$this->name', $vars, $backtrace);
    assert_eq('Alice', $result);
});

test('no $this when backtrace has no object', function () {
    $backtrace = [
        ['function' => 'plainFunction'],
    ];
    $vars = [];
    $result = ddless_eval_in_context('isset($this) ? "has this" : "no this"', $vars, $backtrace);
    assert_eq('no this', $result);
});

test('combines scope variables with $this binding', function () {
    $obj = new EvalTestDummy();
    $backtrace = [
        ['function' => 'someMethod', 'object' => $obj],
    ];
    $vars = ['suffix' => '!'];
    $result = ddless_eval_in_context('$this->name . $suffix', $vars, $backtrace);
    assert_eq('Alice!', $result);
});

section('ddless_eval_in_context() — error handling');

test('syntax error throws ParseError', function () {
    $threw = false;
    try {
        $vars = [];
        ddless_eval_in_context('invalid syntax !!!', $vars, []);
    } catch (\ParseError $e) {
        $threw = true;
    }
    assert_true($threw, 'eval with syntax error should throw ParseError');
});

test('undefined variable returns null (suppressed by @)', function () {
    $vars = [];
    $result = ddless_eval_in_context('$nonExistentVar', $vars, []);
    assert_null($result);
});

section('ddless_eval_in_context() — variable modification (playground)');

test('modifies scope variables in playground mode', function () {
    $vars = ['x' => 10, 'y' => 20];
    ddless_eval_in_context('$x = 99;', $vars, [], false);
    assert_eq(99, $vars['x'], 'x should be updated to 99');
    assert_eq(20, $vars['y'], 'y should remain unchanged');
});

test('adds new variables in playground mode', function () {
    $vars = ['x' => 10];
    ddless_eval_in_context('$newVar = "hello";', $vars, [], false);
    assert_true(array_key_exists('newVar', $vars), 'newVar should exist in scope');
    assert_eq('hello', $vars['newVar']);
});

test('does not modify scope in expression mode', function () {
    $vars = ['x' => 10];
    ddless_eval_in_context('$x + 5', $vars, []);
    assert_eq(10, $vars['x'], 'x should remain unchanged in expression mode');
});

test('modified variables persist across multiple evals', function () {
    $vars = ['counter' => 0];
    ddless_eval_in_context('$counter++;', $vars, [], false);
    assert_eq(1, $vars['counter']);
    ddless_eval_in_context('$counter += 10;', $vars, [], false);
    assert_eq(11, $vars['counter']);
});

test('variable modification works with $this binding', function () {
    $obj = new EvalTestDummy();
    $backtrace = [
        ['function' => 'someMethod', 'object' => $obj],
    ];
    $vars = ['x' => 1];
    ddless_eval_in_context('$x = $this->name;', $vars, $backtrace, false);
    assert_eq('Alice', $vars['x']);
});

// Print results if run standalone
if (basename($argv[0] ?? '') === basename(__FILE__)) {
    exit(print_test_results());
}
