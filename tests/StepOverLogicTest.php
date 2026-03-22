<?php
/**
 * Tests for step-over logic (stack-occurrence based)
 * Run: php tests/php/StepOverLogicTest.php
 */
require_once __DIR__ . '/bootstrap.php';

function should_step_over_stop(
    string $file,
    string $targetFunction,
    string $targetFile,
    array $scopeBacktrace
): bool {
    $context = ddless_get_current_function_info($scopeBacktrace);
    $currentFunction = $context['function'];

    $targetOccurrencesInStack = 0;
    foreach ($scopeBacktrace as $frame) {
        $fn = $frame['function'] ?? '';
        if (str_starts_with($fn, 'ddless_')) continue;
        $fullName = ($frame['class'] ?? '') . ($frame['type'] ?? '') . $fn;
        if ($fullName === $targetFunction) {
            $targetOccurrencesInStack++;
        }
    }

    $isSameFunction = ($currentFunction === $targetFunction);
    $isSameFileClosure = ($file === $targetFile && str_contains($currentFunction, '{closure}'));
    $isOriginalInvocation = ($isSameFunction && $targetOccurrencesInStack <= 1);
    $hasReturnedFromTarget = ($targetOccurrencesInStack === 0);

    return $isOriginalInvocation || $isSameFileClosure || $hasReturnedFromTarget;
}

section('Step-over: same function, next line');

test('stops at next line in same function', function () {
    $result = should_step_over_stop(
        '/app/Services/PrizeService.php',
        'App\\Services\\PrizeService->available',
        '/app/Services/PrizeService.php',
        [
            ['function' => 'available', 'class' => 'App\\Services\\PrizeService', 'type' => '->', 'file' => '/app/Http/Controllers/PrizeController.php'],
            ['function' => 'index', 'class' => 'App\\Http\\Controllers\\PrizeController', 'type' => '->', 'file' => '/vendor/laravel/Router.php'],
        ]
    );
    assert_true($result, 'should stop at next line in same function');
});

section('Step-over: same-file method call');

test('skips when entering another method in the same file', function () {
    $result = should_step_over_stop(
        '/app/Services/PrizeService.php',
        'App\\Services\\PrizeService->available',
        '/app/Services/PrizeService.php',
        [
            ['function' => 'getUserByGodfatherCode', 'class' => 'App\\Services\\PrizeService', 'type' => '->', 'file' => '/app/Services/PrizeService.php'],
            ['function' => 'available', 'class' => 'App\\Services\\PrizeService', 'type' => '->', 'file' => '/app/Http/Controllers/PrizeController.php'],
            ['function' => 'index', 'class' => 'App\\Http\\Controllers\\PrizeController', 'type' => '->', 'file' => '/vendor/laravel/Router.php'],
        ]
    );
    assert_false($result, 'should NOT stop inside getUserByGodfatherCode');
});

test('stops when returning from same-file method back to original', function () {
    $result = should_step_over_stop(
        '/app/Services/PrizeService.php',
        'App\\Services\\PrizeService->available',
        '/app/Services/PrizeService.php',
        [
            ['function' => 'available', 'class' => 'App\\Services\\PrizeService', 'type' => '->', 'file' => '/app/Http/Controllers/PrizeController.php'],
            ['function' => 'index', 'class' => 'App\\Http\\Controllers\\PrizeController', 'type' => '->', 'file' => '/vendor/laravel/Router.php'],
        ]
    );
    assert_true($result, 'should stop back in available() after return');
});

section('Step-over: different-file method call');

test('skips when entering a method in a different file', function () {
    $result = should_step_over_stop(
        '/vendor/laravel/framework/src/Log/Logger.php',
        'App\\Services\\PrizeService->available',
        '/app/Services/PrizeService.php',
        [
            ['function' => 'info', 'class' => 'Illuminate\\Log\\Logger', 'type' => '->', 'file' => '/app/Services/PrizeService.php'],
            ['function' => 'available', 'class' => 'App\\Services\\PrizeService', 'type' => '->', 'file' => '/app/Http/Controllers/PrizeController.php'],
            ['function' => 'index', 'class' => 'App\\Http\\Controllers\\PrizeController', 'type' => '->', 'file' => '/vendor/laravel/Router.php'],
        ]
    );
    assert_false($result, 'should NOT stop inside Log::info()');
});

test('skips deep calls inside different-file method', function () {
    $result = should_step_over_stop(
        '/vendor/laravel/framework/src/Log/Logger.php',
        'App\\Services\\PrizeService->available',
        '/app/Services/PrizeService.php',
        [
            ['function' => 'writeLog', 'class' => 'Illuminate\\Log\\Logger', 'type' => '->', 'file' => '/vendor/laravel/framework/src/Log/Logger.php'],
            ['function' => 'info', 'class' => 'Illuminate\\Log\\Logger', 'type' => '->', 'file' => '/app/Services/PrizeService.php'],
            ['function' => 'available', 'class' => 'App\\Services\\PrizeService', 'type' => '->', 'file' => '/app/Http/Controllers/PrizeController.php'],
            ['function' => 'index', 'class' => 'App\\Http\\Controllers\\PrizeController', 'type' => '->', 'file' => '/vendor/laravel/Router.php'],
        ]
    );
    assert_false($result, 'should NOT stop deep inside vendor calls');
});

section('Step-over: closures in same file');

test('stops inside closure in the same file', function () {
    $result = should_step_over_stop(
        '/app/Services/PrizeService.php',
        'App\\Services\\PrizeService->available',
        '/app/Services/PrizeService.php',
        [
            ['function' => '{closure}', 'class' => 'App\\Services\\PrizeService', 'type' => '->', 'file' => '/vendor/laravel/framework/src/Database/Query/Builder.php'],
            ['function' => 'when', 'class' => 'Illuminate\\Database\\Query\\Builder', 'type' => '->', 'file' => '/app/Services/PrizeService.php'],
            ['function' => 'available', 'class' => 'App\\Services\\PrizeService', 'type' => '->', 'file' => '/app/Http/Controllers/PrizeController.php'],
        ]
    );
    assert_true($result, 'should stop inside same-file closure (query builder callback)');
});

test('skips closure in a different file', function () {
    $result = should_step_over_stop(
        '/vendor/laravel/framework/src/Database/Query/Builder.php',
        'App\\Services\\PrizeService->available',
        '/app/Services/PrizeService.php',
        [
            ['function' => '{closure}', 'class' => 'Illuminate\\Database\\Query\\Builder', 'type' => '->', 'file' => '/vendor/laravel/framework/src/Database/Query/Builder.php'],
            ['function' => 'available', 'class' => 'App\\Services\\PrizeService', 'type' => '->', 'file' => '/app/Http/Controllers/PrizeController.php'],
        ]
    );
    assert_false($result, 'should NOT stop inside vendor closure');
});

section('Step-over: returning past target function');

test('stops when target function returned to caller in different file', function () {
    $result = should_step_over_stop(
        '/app/Http/Controllers/PrizeController.php',
        'App\\Services\\PrizeService->available',
        '/app/Services/PrizeService.php',
        [
            ['function' => 'index', 'class' => 'App\\Http\\Controllers\\PrizeController', 'type' => '->', 'file' => '/vendor/laravel/Router.php'],
            ['function' => 'dispatch', 'class' => 'Illuminate\\Routing\\Router', 'type' => '->', 'file' => '/vendor/laravel/Kernel.php'],
        ]
    );
    assert_true($result, 'should stop — target function no longer in stack');
});

section('Step-over: recursion protection');

test('skips when entering recursive call of same function', function () {
    $result = should_step_over_stop(
        '/app/Services/TreeService.php',
        'App\\Services\\TreeService->traverse',
        '/app/Services/TreeService.php',
        [
            ['function' => 'traverse', 'class' => 'App\\Services\\TreeService', 'type' => '->', 'file' => '/app/Services/TreeService.php'],
            ['function' => 'traverse', 'class' => 'App\\Services\\TreeService', 'type' => '->', 'file' => '/app/Http/Controllers/TreeController.php'],
            ['function' => 'index', 'class' => 'App\\Http\\Controllers\\TreeController', 'type' => '->', 'file' => '/vendor/laravel/Router.php'],
        ]
    );
    assert_false($result, 'should NOT stop inside recursive call (2 occurrences)');
});

test('skips deep recursion (3 levels)', function () {
    $result = should_step_over_stop(
        '/app/Services/TreeService.php',
        'App\\Services\\TreeService->traverse',
        '/app/Services/TreeService.php',
        [
            ['function' => 'traverse', 'class' => 'App\\Services\\TreeService', 'type' => '->', 'file' => '/app/Services/TreeService.php'],
            ['function' => 'traverse', 'class' => 'App\\Services\\TreeService', 'type' => '->', 'file' => '/app/Services/TreeService.php'],
            ['function' => 'traverse', 'class' => 'App\\Services\\TreeService', 'type' => '->', 'file' => '/app/Http/Controllers/TreeController.php'],
            ['function' => 'index', 'class' => 'App\\Http\\Controllers\\TreeController', 'type' => '->', 'file' => '/vendor/laravel/Router.php'],
        ]
    );
    assert_false($result, 'should NOT stop inside 3-level recursion');
});

test('stops when recursion unwinds back to original invocation', function () {
    $result = should_step_over_stop(
        '/app/Services/TreeService.php',
        'App\\Services\\TreeService->traverse',
        '/app/Services/TreeService.php',
        [
            ['function' => 'traverse', 'class' => 'App\\Services\\TreeService', 'type' => '->', 'file' => '/app/Http/Controllers/TreeController.php'],
            ['function' => 'index', 'class' => 'App\\Http\\Controllers\\TreeController', 'type' => '->', 'file' => '/vendor/laravel/Router.php'],
        ]
    );
    assert_true($result, 'should stop when back to original traverse() (1 occurrence)');
});

section('Step-over: edge cases');

test('skips when different-file function has same name as target', function () {
    $result = should_step_over_stop(
        '/app/Middleware/AuthMiddleware.php',
        'App\\Jobs\\ProcessOrder->handle',
        '/app/Jobs/ProcessOrder.php',
        [
            ['function' => 'handle', 'class' => 'App\\Middleware\\AuthMiddleware', 'type' => '->', 'file' => '/vendor/laravel/Pipeline.php'],
            ['function' => 'handle', 'class' => 'App\\Jobs\\ProcessOrder', 'type' => '->', 'file' => '/vendor/laravel/Queue/Worker.php'],
            ['function' => 'process', 'class' => 'Illuminate\\Queue\\Worker', 'type' => '->', 'file' => '/vendor/laravel/Queue/Worker.php'],
        ]
    );
    assert_false($result, 'should NOT stop — different class even though method name matches');
});

test('handles static method calls correctly', function () {
    $result = should_step_over_stop(
        '/app/Services/CacheService.php',
        'App\\Services\\ReportService::generate',
        '/app/Services/ReportService.php',
        [
            ['function' => 'get', 'class' => 'App\\Services\\CacheService', 'type' => '::', 'file' => '/app/Services/ReportService.php'],
            ['function' => 'generate', 'class' => 'App\\Services\\ReportService', 'type' => '::', 'file' => '/app/Http/Controllers/ReportController.php'],
        ]
    );
    assert_false($result, 'should NOT stop inside static CacheService::get()');
});

test('handles top-level code (no class)', function () {
    $result = should_step_over_stop(
        '/app/helpers.php',
        'calculate_total',
        '/app/routes.php',
        [
            ['function' => 'format_currency', 'file' => '/app/routes.php'],
            ['function' => 'calculate_total', 'file' => '/app/Http/Kernel.php'],
        ]
    );
    assert_false($result, 'should NOT stop inside format_currency()');
});

test('stops back in top-level plain function', function () {
    $result = should_step_over_stop(
        '/app/routes.php',
        'calculate_total',
        '/app/routes.php',
        [
            ['function' => 'calculate_total', 'file' => '/app/Http/Kernel.php'],
        ]
    );
    assert_true($result, 'should stop back in calculate_total()');
});

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    exit(print_test_results());
}
