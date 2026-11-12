<?php
/**
 * Tests for DDLessSafeIncludeWrapper (the file:// stream wrapper)
 * Run: php tests/php/StreamWrapperTest.php
 */
require_once __DIR__ . '/bootstrap.php';

section('Stream wrapper — include vs. reading a file as data');

test('file_get_contents() of an instrumented file returns the real source', function () {
    $dir = sys_get_temp_dir() . '/ddless_wrapper_test_' . uniqid();
    mkdir($dir, 0755, true);
    $file = $dir . '/Source.php';
    $source = "<?php return 'original';\n";
    file_put_contents($file, $source);
    $real = realpath($file);

    $GLOBALS['__DDLESS_INSTRUMENTED_CODE__'][$real] = "<?php return 'instrumented';\n";
    DDLessSafeIncludeWrapper::register();
    try {
        $included = include $file;
        $read = file_get_contents($file);
    } finally {
        DDLessSafeIncludeWrapper::restore();
        unset($GLOBALS['__DDLESS_INSTRUMENTED_CODE__'][$real]);
        @unlink($file);
        @rmdir($dir);
    }

    assert_eq('instrumented', $included, 'include must still get the instrumented code');
    assert_eq($source, $read, 'reading the file as data must not leak instrumentation');
});

section('Stream wrapper — native stream handle');

test('a file handle opened through the wrapper works with cURL (stream_cast)', function () {
    if (!function_exists('curl_init')) {
        assert_true(method_exists('DDLessSafeIncludeWrapper', 'stream_cast'), 'wrapper has no stream_cast()');
        return;
    }
    $file = sys_get_temp_dir() . '/ddless_wrapper_curl_' . uniqid() . '.txt';
    $warnings = [];
    set_error_handler(function ($no, $msg) use (&$warnings) {
        $warnings[] = $msg;
        return true;
    });
    DDLessSafeIncludeWrapper::register();
    try {
        $fp = fopen($file, 'w');
        $ch = curl_init('http://127.0.0.1:9/');
        $ok = curl_setopt($ch, CURLOPT_FILE, $fp);
        fclose($fp);
    } finally {
        DDLessSafeIncludeWrapper::restore();
        restore_error_handler();
        @unlink($file);
    }
    assert_true($ok === true && $warnings === [], 'curl_setopt(CURLOPT_FILE) failed: ' . implode(' | ', $warnings));
});

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    exit(print_test_results());
}
