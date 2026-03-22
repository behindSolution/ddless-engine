<?php
/**
 * Tests for step-out logic
 * Run: php tests/php/StepOutLogicTest.php
 */
require_once __DIR__ . '/bootstrap.php';

/**
 * Replicates the step-out decision from ddless_step_check.
 * Returns true if the debugger should STOP at this point.
 */
function should_step_out_stop(string $targetCaller, array $scopeBacktrace): bool {
    $context = ddless_get_current_function_info($scopeBacktrace);
    $currentFunction = $context['function'];
    return $currentFunction !== null && $currentFunction === $targetCaller;
}

section('Step-out: stops when returned to caller');

test('skips while still inside the current function', function () {
    $result = should_step_out_stop(
        'App\\Services\\PrizeService->available',
        [
            ['function' => 'getUserByGodfatherCode', 'class' => 'App\\Services\\PrizeService', 'type' => '->', 'file' => '/app/Services/PrizeService.php'],
            ['function' => 'available', 'class' => 'App\\Services\\PrizeService', 'type' => '->', 'file' => '/app/Http/Controllers/PrizeController.php'],
        ]
    );
    assert_false($result, 'should NOT stop — still inside getUserByGodfatherCode');
});

test('stops when returned to caller function', function () {
    $result = should_step_out_stop(
        'App\\Services\\PrizeService->available',
        [
            ['function' => 'available', 'class' => 'App\\Services\\PrizeService', 'type' => '->', 'file' => '/app/Http/Controllers/PrizeController.php'],
            ['function' => 'index', 'class' => 'App\\Http\\Controllers\\PrizeController', 'type' => '->', 'file' => '/vendor/laravel/Router.php'],
        ]
    );
    assert_true($result, 'should stop — back in available()');
});

test('skips when inside a deeper call from the current function', function () {
    $result = should_step_out_stop(
        'App\\Services\\PrizeService->available',
        [
            ['function' => 'select', 'class' => 'Illuminate\\Database\\Connection', 'type' => '->', 'file' => '/vendor/laravel/Database/Connection.php'],
            ['function' => 'getUserByGodfatherCode', 'class' => 'App\\Services\\PrizeService', 'type' => '->', 'file' => '/app/Services/PrizeService.php'],
            ['function' => 'available', 'class' => 'App\\Services\\PrizeService', 'type' => '->', 'file' => '/app/Http/Controllers/PrizeController.php'],
        ]
    );
    assert_false($result, 'should NOT stop — deeper inside DB::select');
});

test('skips when returned past caller to a grandparent', function () {
    $result = should_step_out_stop(
        'App\\Services\\PrizeService->available',
        [
            ['function' => 'index', 'class' => 'App\\Http\\Controllers\\PrizeController', 'type' => '->', 'file' => '/vendor/laravel/Router.php'],
            ['function' => 'dispatch', 'class' => 'Illuminate\\Routing\\Router', 'type' => '->', 'file' => '/vendor/laravel/Kernel.php'],
        ]
    );
    assert_false($result, 'should NOT stop — available is gone, we are past it');
});

section('Step-out: static and plain functions');

test('stops at correct caller with static methods', function () {
    $result = should_step_out_stop(
        'App\\Services\\ReportService::generate',
        [
            ['function' => 'generate', 'class' => 'App\\Services\\ReportService', 'type' => '::', 'file' => '/app/Http/Controllers/ReportController.php'],
        ]
    );
    assert_true($result, 'should stop — back in static generate()');
});

test('skips when different class has same method name as caller', function () {
    $result = should_step_out_stop(
        'App\\Services\\ServiceA->process',
        [
            ['function' => 'process', 'class' => 'App\\Services\\ServiceB', 'type' => '->', 'file' => '/app/Services/ServiceB.php'],
            ['function' => 'handle', 'class' => 'App\\Jobs\\SomeJob', 'type' => '->', 'file' => '/vendor/laravel/Queue/Worker.php'],
        ]
    );
    assert_false($result, 'should NOT stop — different class despite same method name');
});

test('handles plain functions (no class)', function () {
    $result = should_step_out_stop(
        'route_handler',
        [
            ['function' => 'route_handler', 'file' => '/app/routes.php'],
        ]
    );
    assert_true($result, 'should stop — back in plain function caller');
});

section('Step-out: closures');

test('step-out from closure stops in enclosing method', function () {
    $result = should_step_out_stop(
        'App\\Services\\PrizeService->available',
        [
            ['function' => 'available', 'class' => 'App\\Services\\PrizeService', 'type' => '->', 'file' => '/app/Http/Controllers/PrizeController.php'],
            ['function' => 'index', 'class' => 'App\\Http\\Controllers\\PrizeController', 'type' => '->', 'file' => '/vendor/laravel/Router.php'],
        ]
    );
    assert_true($result, 'should stop — returned from closure to available()');
});

test('skips when still inside closure (caller is enclosing method)', function () {
    $result = should_step_out_stop(
        'App\\Services\\PrizeService->available',
        [
            ['function' => '{closure}', 'class' => 'App\\Services\\PrizeService', 'type' => '->', 'file' => '/vendor/laravel/Database/Query/Builder.php'],
            ['function' => 'when', 'class' => 'Illuminate\\Database\\Query\\Builder', 'type' => '->', 'file' => '/app/Services/PrizeService.php'],
            ['function' => 'available', 'class' => 'App\\Services\\PrizeService', 'type' => '->', 'file' => '/app/Http/Controllers/PrizeController.php'],
        ]
    );
    assert_false($result, 'should NOT stop — still inside closure');
});

section('Step-out: edge cases');

test('null currentFunction does not match', function () {
    // All ddless frames → currentFunction = null
    $result = should_step_out_stop(
        'App\\Services\\PrizeService->available',
        [
            ['function' => 'ddless_step_check'],
            ['function' => 'ddless_handle_breakpoint'],
        ]
    );
    assert_false($result, 'should NOT stop — null function cannot match');
});

test('resetAllStepModes clears step-out target', function () {
    $GLOBALS['__DDLESS_STEP_OUT_TARGET__'] = 'SomeClass->method';
    // Simulate $resetAllStepModes
    $GLOBALS['__DDLESS_STEP_IN_MODE__'] = false;
    $GLOBALS['__DDLESS_STEP_OVER_FUNCTION__'] = null;
    $GLOBALS['__DDLESS_STEP_OVER_FILE__'] = null;
    $GLOBALS['__DDLESS_STEP_OUT_TARGET__'] = null;
    assert_null($GLOBALS['__DDLESS_STEP_OUT_TARGET__'], 'step-out target should be cleared');
});

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    exit(print_test_results());
}
