<?php
/**
 * Tests for ddless_normalize_value()
 * Run: php tests/php/NormalizeValueTest.php
 */
require_once __DIR__ . '/bootstrap.php';

section('ddless_normalize_value()');

test('scalars returned as-is', function () {
    assert_eq(42, ddless_normalize_value(42));
    assert_eq(3.14, ddless_normalize_value(3.14));
    assert_eq('hello', ddless_normalize_value('hello'));
    assert_eq(true, ddless_normalize_value(true));
    assert_eq(false, ddless_normalize_value(false));
    assert_eq(null, ddless_normalize_value(null));
});

test('array values are recursively normalized', function () {
    $result = ddless_normalize_value(['a' => 1, 'b' => [2, 3]]);
    assert_eq(['a' => 1, 'b' => [2, 3]], $result);
});

test('long strings are truncated at 10000 chars', function () {
    $long = str_repeat('x', 15000);
    $result = ddless_normalize_value($long);
    assert_eq(10001, mb_strlen($result), 'truncated to 10000 + ellipsis');
});

test('max depth returns placeholder for objects', function () {
    $obj = new \stdClass();
    $obj->name = 'test';
    $GLOBALS['__DDLESS_SERIALIZE_DEPTH__'] = 0;
    $result = ddless_normalize_value($obj, 1);
    $GLOBALS['__DDLESS_SERIALIZE_DEPTH__'] = 4;
    assert_eq('[object stdClass]', $result);
});

test('max depth returns [max-depth] for arrays', function () {
    $GLOBALS['__DDLESS_SERIALIZE_DEPTH__'] = 0;
    $result = ddless_normalize_value([1, 2, 3], 1);
    $GLOBALS['__DDLESS_SERIALIZE_DEPTH__'] = 4;
    assert_eq('[max-depth]', $result);
});

test('DateTime returns ATOM format', function () {
    $dt = new \DateTime('2026-01-15T10:30:00+00:00');
    $result = ddless_normalize_value($dt);
    assert_contains($result, '2026-01-15');
    assert_contains($result, '10:30:00');
});

test('JsonSerializable objects use jsonSerialize()', function () {
    $obj = new class implements \JsonSerializable {
        public function jsonSerialize(): mixed {
            return ['serialized' => true];
        }
    };
    $result = ddless_normalize_value($obj);
    assert_eq(['serialized' => true], $result);
});

test('objects with toArray() use that method', function () {
    $obj = new class {
        public function toArray(): array {
            return ['converted' => true];
        }
    };
    $result = ddless_normalize_value($obj);
    assert_eq(['converted' => true], $result);
});

test('plain objects return [object ClassName]', function () {
    $obj = new \stdClass();
    $result = ddless_normalize_value($obj);
    assert_eq('[object stdClass]', $result);
});

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    exit(print_test_results());
}
