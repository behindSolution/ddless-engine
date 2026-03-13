<?php
/**
 * Tests for ddless_resolve_user_breakpoints()
 * Run: php tests/php/ResolveBreakpointsTest.php
 */
require_once __DIR__ . '/bootstrap.php';

section('ddless_resolve_user_breakpoints()');

test('BP on instrumentable line marks isUserBp', function () {
    $lines = [
        3 => ['type' => 'statement', 'isUserBp' => false],
        5 => ['type' => 'statement', 'isUserBp' => false],
    ];
    $result = ddless_resolve_user_breakpoints($lines, [3]);
    assert_true($result[3]['isUserBp']);
    assert_false($result[5]['isUserBp']);
});

test('BP on continuation line resolves to nearest instrumentable before', function () {
    $lines = [
        3 => ['type' => 'statement', 'isUserBp' => false],
        7 => ['type' => 'statement', 'isUserBp' => false],
    ];
    $result = ddless_resolve_user_breakpoints($lines, [5]);
    assert_true($result[3]['isUserBp'], 'should resolve to line 3');
    assert_false($result[7]['isUserBp']);
});

test('BP before any instrumentable line is ignored', function () {
    $lines = [
        5 => ['type' => 'statement', 'isUserBp' => false],
        7 => ['type' => 'statement', 'isUserBp' => false],
    ];
    $result = ddless_resolve_user_breakpoints($lines, [2]);
    assert_false($result[5]['isUserBp']);
    assert_false($result[7]['isUserBp']);
});

test('empty BP list returns unchanged array', function () {
    $lines = [
        3 => ['type' => 'statement', 'isUserBp' => false],
    ];
    $result = ddless_resolve_user_breakpoints($lines, []);
    assert_eq($lines, $result);
});

test('multiple BPs, mixed resolution', function () {
    $lines = [
        3 => ['type' => 'statement', 'isUserBp' => false],
        5 => ['type' => 'control', 'isUserBp' => false],
        10 => ['type' => 'statement', 'isUserBp' => false],
    ];
    $result = ddless_resolve_user_breakpoints($lines, [5, 8, 10]);
    assert_false($result[3]['isUserBp']);
    assert_true($result[5]['isUserBp'], 'line 5 direct + resolved from 8');
    assert_true($result[10]['isUserBp'], 'line 10 direct');
});

test('BP resolves to closest line, not first', function () {
    $lines = [
        3 => ['type' => 'statement', 'isUserBp' => false],
        5 => ['type' => 'statement', 'isUserBp' => false],
        10 => ['type' => 'statement', 'isUserBp' => false],
    ];
    $result = ddless_resolve_user_breakpoints($lines, [7]);
    assert_false($result[3]['isUserBp']);
    assert_true($result[5]['isUserBp'], 'should resolve to line 5 (closest before 7)');
});

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    exit(print_test_results());
}
