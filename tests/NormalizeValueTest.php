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
    if (function_exists('mb_strlen')) {
        assert_eq(10001, mb_strlen($result), 'truncated to 10000 chars + 1-char ellipsis');
    } else {
        assert_eq(10003, strlen($result), 'truncated to 10000 bytes + 3-byte UTF-8 ellipsis');
    }
});

test('max depth returns lazy marker for objects', function () {
    $obj = new \stdClass();
    $obj->name = 'test';
    $GLOBALS['__DDLESS_SERIALIZE_DEPTH__'] = 0;
    ddless_lazy_reset();
    $result = ddless_normalize_value($obj, 1);
    $GLOBALS['__DDLESS_SERIALIZE_DEPTH__'] = 4;
    assert_true(is_array($result) && !empty($result['__ddless_lazy__']), 'past-depth object becomes lazy marker');
    assert_eq('stdClass', $result['class']);
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
        #[\ReturnTypeWillChange]
        public function jsonSerialize() {
            return ['serialized' => true];
        }
    };
    $result = ddless_normalize_value($obj);
    assert_eq(['serialized' => true], $result);
});

test('objects with toArray() but no Arrayable/JsonSerializable fall through to lazy marker', function () {
    // toArray() alone is too unreliable across frameworks (Symfony/Laravel Request
    // both have toArray() with very different meanings). Classes that want to be
    // auto-serialized must implement JsonSerializable or Arrayable explicitly.
    ddless_lazy_reset();
    $obj = new class {
        public string $shown = 'hi';
        public function toArray(): array {
            return ['converted' => true];
        }
    };
    $result = ddless_normalize_value($obj);
    assert_true(is_array($result) && !empty($result['__ddless_lazy__']), 'becomes lazy marker, not toArray output');
});

test('plain objects without serialization hooks become lazy markers', function () {
    ddless_lazy_reset();
    $obj = new \stdClass();
    $result = ddless_normalize_value($obj);
    assert_true(is_array($result) && !empty($result['__ddless_lazy__']));
    assert_eq('stdClass', $result['class']);
});

section('ddless_normalize_value() — large arrays');

test('huge arrays are truncated instead of serialized whole', function () {
    $normalized = ddless_normalize_value(range(1, 50000), 0);
    assert_true(count($normalized) < 50000, 'all ' . count($normalized) . ' elements were serialized');
});

section('ddless_normalize_value() — values JSON cannot carry as they are');

test('ISO-8859-1 text is shown as readable UTF-8', function () {
    $latin1 = 'caf' . chr(233) . ' ' . chr(231) . 'a';
    $normalized = ddless_normalize_value($latin1, 0);
    assert_eq('café ça', $normalized);
    assert_true(json_encode($normalized) !== false, 'must be encodable');
});

test('binary strings stay encodable', function () {
    $binary = chr(0) . chr(255) . chr(128) . 'x' . chr(200);
    $normalized = ddless_normalize_value($binary, 0);
    assert_true(preg_match('//u', $normalized) === 1, 'result is valid UTF-8');
    assert_true(json_encode($normalized) !== false);
});

test('valid UTF-8 is left untouched', function () {
    assert_eq('São Paulo — 日本', ddless_normalize_value('São Paulo — 日本', 0));
});

test('NAN and INF become labels', function () {
    assert_eq('NAN', ddless_normalize_value(NAN, 0));
    assert_eq('INF', ddless_normalize_value(INF, 0));
    assert_eq('-INF', ddless_normalize_value(-INF, 0));
    assert_eq(1.5, ddless_normalize_value(1.5, 0));
});

test('a long string cut in the middle of a multibyte character stays valid', function () {
    $long = str_repeat('a', 9999) . 'é' . str_repeat('b', 50);
    $normalized = ddless_normalize_value($long, 0);
    assert_true(preg_match('//u', $normalized) === 1, 'result is valid UTF-8');
    assert_true(str_ends_with($normalized, '…'));
    assert_eq(str_repeat('a', 9999) . '…', $normalized, 'the half character is dropped, not turned into garbage');
});

test('array keys that are not UTF-8 are made safe too', function () {
    $normalized = ddless_normalize_value(['caf' . chr(233) => 1], 0);
    assert_eq(['café' => 1], $normalized);
});

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    exit(print_test_results());
}
