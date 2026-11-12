<?php
/**
 * Tests for the enhanced debug context extractors ($this, static properties)
 * Run: php tests/php/DebugContextTest.php
 */
require_once __DIR__ . '/bootstrap.php';

class ContextParent
{
    private $secret = 'parent-private';
    public $name = 'kb';
}

class ContextChild extends ContextParent
{
}

class ContextOther
{
    public $other = 'other-object';
}

class ContextWithStatic
{
    public static bool $destructed = false;
    public string $label = 'x';

    public function __destruct()
    {
        self::$destructed = true;
    }
}

section('Debug context — reflection on PHP >= 8.5');

test('inspecting an object emits no deprecation (setAccessible, PHP >= 8.5)', function () {
    $deprecations = [];
    set_error_handler(function ($no, $msg) use (&$deprecations) {
        $deprecations[] = $msg;
        return true;
    }, E_DEPRECATED);
    try {
        $object = new ContextChild();
        ddless_extract_this_properties([['function' => 'run', 'class' => 'ContextParent', 'object' => $object]]);
        ddless_extract_static_properties([['function' => 'run', 'class' => 'ContextWithStatic']]);
        ddless_eager_expand($object);
        ddless_lazy_reset();
        $marker = ddless_lazy_register($object);
        ddless_lazy_expand($marker['token']);
        ddless_lazy_reset();
    } finally {
        restore_error_handler();
    }
    assert_eq([], array_values(array_unique($deprecations)), 'deprecations raised');
});

test('$this panel shows the paused object when deprecations are fatal', function () {
    set_error_handler(function ($no, $msg) {
        throw new \ErrorException($msg, 0, $no);
    }, E_DEPRECATED);
    try {
        $properties = ddless_extract_this_properties([
            ['function' => 'run', 'class' => 'ContextParent', 'object' => new ContextChild()],
            ['function' => 'caller', 'class' => 'ContextOther', 'object' => new ContextOther()],
        ]);
    } finally {
        restore_error_handler();
    }
    assert_array_has_key('$this->name', $properties, 'got ' . json_encode(array_keys($properties)));
    assert_array_not_has_key('$this->other', $properties, 'properties of the CALLER object were shown as $this');
});

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    exit(print_test_results());
}
