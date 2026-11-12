<?php
/**
 * Tests for what debug.php does when it is loaded: reading breakpoints.json
 * and keeping its own variables out of the user's global scope.
 * Run: php tests/php/EngineBootstrapTest.php
 */
require_once __DIR__ . '/bootstrap.php';

section('Engine bootstrap — breakpoints.json');

test('loads breakpoints, conditions, logpoints and settings', function () {
    $root = create_isolated_project([
        'app/User.php' => "<?php\nfunction user() {\n    return 1;\n}\n",
        '.ddless/breakpoints.json' => json_encode([
            'breakpoints' => ['app/User.php' => [3, 3, 'x', 0]],
            'conditions' => ['app/User.php' => ['3' => '$id > 5']],
            'logpoints' => ['app/User.php' => ['3' => 'id={$id}']],
            'watches' => ['$id', ''],
            'settings' => ['serializeDepth' => 7, 'breakpointTimeout' => 2],
        ]),
    ]);
    try {
        $result = run_isolated_engine($root, <<<'PHP'
$entry = $GLOBALS['__DDLESS_BREAKPOINT_FILES__'][DDLESS_PROJECT_ROOT . '/app/User.php'] ?? null;
echo json_encode([
    'entry' => $entry,
    'watches' => $GLOBALS['__DDLESS_WATCHES__'],
    'depth' => $GLOBALS['__DDLESS_SERIALIZE_DEPTH__'],
    'timeout' => $GLOBALS['__DDLESS_BREAKPOINT_TIMEOUT__'],
]);
PHP);
    } finally {
        remove_isolated_project($root);
    }

    assert_not_null($result['entry'], 'breakpoint file registered by absolute path');
    assert_eq('app/User.php', $result['entry']['relativePath']);
    assert_eq([3], array_values($result['entry']['lines']), 'invalid and duplicate lines dropped');
    assert_eq('$id > 5', $result['entry']['conditions'][3]);
    assert_eq('id={$id}', $result['entry']['logpoints'][3]);
    assert_eq(['$id'], $result['watches']);
    assert_eq(7, $result['depth']);
    assert_eq(120, $result['timeout'], 'minutes converted to seconds');
});

section('Engine bootstrap — global scope');

test('loading debug.php leaves no unprefixed variables in the global scope', function () {
    $root = create_isolated_project([
        'app/User.php' => "<?php\nfunction user() {\n    return 1;\n}\n",
        '.ddless/breakpoints.json' => json_encode([
            'breakpoints' => ['app/User.php' => [3]],
            'conditions' => ['app/User.php' => ['3' => '$id > 5']],
            'logpoints' => ['app/User.php' => ['3' => 'id={$id}']],
            'dumppoints' => ['app/User.php' => ['3' => '$id']],
        ]),
    ]);
    try {
        $leaked = run_isolated_engine($root, <<<'PHP'
echo json_encode(array_values(array_filter($__leaked, fn($name) => stripos($name, '__ddless') !== 0)));
PHP, ['DDLESS_DEBUG_MODE' => 'true']);
    } finally {
        remove_isolated_project($root);
    }

    assert_eq([], $leaked, 'a procedural script would see these as its own variables');
});

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    exit(print_test_results());
}
