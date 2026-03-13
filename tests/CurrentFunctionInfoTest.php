<?php
/**
 * Tests for ddless_get_current_function_info()
 * Run: php tests/php/CurrentFunctionInfoTest.php
 */
require_once __DIR__ . '/bootstrap.php';

section('ddless_get_current_function_info()');

test('skips ddless internal functions', function () {
    $backtrace = [
        ['function' => 'ddless_step_check', 'file' => '/test/debug.php'],
        ['function' => 'ddless_handle_breakpoint', 'file' => '/test/debug.php'],
        ['function' => 'index', 'class' => 'UserController', 'type' => '->', 'file' => '/test/UserController.php'],
        ['function' => 'dispatch', 'class' => 'Router', 'type' => '->', 'file' => '/test/Router.php'],
    ];
    $info = ddless_get_current_function_info($backtrace);
    assert_eq('UserController->index', $info['function']);
    assert_eq('/test/UserController.php', $info['file']);
});

test('depth counts all non-ddless frames', function () {
    $backtrace = [
        ['function' => 'ddless_step_check'],
        ['function' => 'foo', 'file' => '/test/a.php'],
        ['function' => 'bar', 'file' => '/test/b.php'],
        ['function' => 'baz', 'file' => '/test/c.php'],
    ];
    $info = ddless_get_current_function_info($backtrace);
    assert_eq(3, $info['depth']);
});

test('all ddless frames returns null function and depth 0', function () {
    $backtrace = [
        ['function' => 'ddless_step_check'],
        ['function' => 'ddless_handle_breakpoint'],
    ];
    $info = ddless_get_current_function_info($backtrace);
    assert_null($info['function']);
    assert_eq(0, $info['depth']);
});

test('closure function name includes {closure}', function () {
    $backtrace = [
        ['function' => 'ddless_step_check'],
        ['function' => '{closure}', 'class' => 'App\\Service', 'type' => '->', 'file' => '/test/Service.php'],
        ['function' => 'process', 'class' => 'App\\Service', 'type' => '->', 'file' => '/test/Service.php'],
    ];
    $info = ddless_get_current_function_info($backtrace);
    assert_contains($info['function'], '{closure}');
});

test('static method type is ::', function () {
    $backtrace = [
        ['function' => 'ddless_step_check'],
        ['function' => 'create', 'class' => 'Factory', 'type' => '::', 'file' => '/test/Factory.php'],
    ];
    $info = ddless_get_current_function_info($backtrace);
    assert_eq('Factory::create', $info['function']);
});

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    exit(print_test_results());
}
