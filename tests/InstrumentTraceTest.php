<?php
/**
 * Tests for ddless_instrument_trace_only() and ddless_is_vendor_path()
 * Run: php tests/php/InstrumentTraceTest.php
 */
require_once __DIR__ . '/bootstrap.php';

section('ddless_instrument_trace_only()');

test('injects try/finally wrapper in named function', function () {
    $code = '<?php
function processUser($id) {
    $user = find($id);
    return $user;
}';
    $result = ddless_instrument_trace_only($code, '/test/file.php', 'file.php');
    assert_not_null($result);
    assert_contains($result, '/* DDLESS_TRACE */');
    assert_contains($result, '/* DDLESS_TRACE_END */');
    assert_contains($result, '\\ddless_trace_fn(');
    assert_contains($result, '\\ddless_trace_exit(');
    assert_contains($result, 'try {');
    assert_contains($result, 'finally {');
});

test('does not instrument closures', function () {
    $code = '<?php
function main() {
    $fn = function() {
        return 1;
    };
    return $fn();
}';
    $result = ddless_instrument_trace_only($code, '/test/file.php', 'file.php');
    assert_not_null($result);
    assert_eq(1, substr_count($result, '/* DDLESS_TRACE */'), 'only named function traced');
});

test('multiple functions get sequential var names', function () {
    $code = '<?php
function foo() { return 1; }
function bar() { return 2; }
function baz() { return 3; }';
    $result = ddless_instrument_trace_only($code, '/test/file.php', 'file.php');
    assert_not_null($result);
    assert_contains($result, '$__ddless_seq =', 'first function');
    assert_contains($result, '$__ddless_seq2 =', 'second function');
    assert_contains($result, '$__ddless_seq3 =', 'third function');
});

test('already traced code returns null (guard)', function () {
    $code = '<?php /* DDLESS_TRACE */ function foo() { return 1; }';
    $result = ddless_instrument_trace_only($code, '/test/file.php', 'file.php');
    assert_null($result, 'should not re-instrument');
});

test('zero line shift: line count preserved', function () {
    $code = '<?php
function foo() {
    $a = 1;
    $b = 2;
    return $a + $b;
}';
    $originalLineCount = count(explode("\n", $code));
    $result = ddless_instrument_trace_only($code, '/test/file.php', 'file.php');
    assert_not_null($result);
    $resultLineCount = count(explode("\n", $result));
    assert_eq($originalLineCount, $resultLineCount, 'line count should not change');
});

test('abstract methods are not instrumented', function () {
    $code = '<?php
abstract class Foo {
    abstract public function bar();
    public function baz() { return 1; }
}';
    $result = ddless_instrument_trace_only($code, '/test/file.php', 'file.php');
    assert_not_null($result);
    assert_eq(1, substr_count($result, '/* DDLESS_TRACE */'), 'only baz should be traced');
});

section('ddless_is_vendor_path()');

test('vendor path detected', function () {
    assert_true(ddless_is_vendor_path('/project/vendor/laravel/framework/src/Router.php'));
});

test('node_modules path detected', function () {
    assert_true(ddless_is_vendor_path('/project/node_modules/something/index.js'));
});

test('.ddless path detected', function () {
    assert_true(ddless_is_vendor_path('/project/.ddless/debug.php'));
});

test('project path is not vendor', function () {
    assert_false(ddless_is_vendor_path('/project/app/Controllers/UserController.php'));
});

test('backslash paths normalized', function () {
    assert_true(ddless_is_vendor_path('C:\\project\\vendor\\autoload.php'));
});

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    exit(print_test_results());
}
