<?php
/**
 * Tests for HTTP request handling: stream wrapper, cookie parsing, body integrity.
 * Run: php tests/php/HttpRequestTest.php
 */
require_once __DIR__ . '/bootstrap.php';

// ============================================================================
// DDLessPhpInputStream test double
// ============================================================================

// The real class is embedded in http_request.php which bootstraps Laravel/PHP,
// so we replicate it here for isolated testing. This matches the code in
// .ddless/frameworks/laravel/http_request.php and .ddless/frameworks/php/http_request.php
if (!class_exists('DDLessPhpInputStreamTestDouble')) {
    class DDLessPhpInputStreamTestDouble
    {
        public $context;
        private int $position = 0;
        private bool $isInput = false;
        private string $buffer = '';

        public function stream_open($path, $mode, $options, &$opened_path)
        {
            $this->position = 0;
            $this->isInput = ($path === 'php://input');
            $this->buffer = '';
            return true;
        }

        public function stream_read($count)
        {
            $dataSource = $this->isInput ? ($GLOBALS['__DDLESS_RAW_INPUT__'] ?? '') : $this->buffer;
            $data = substr($dataSource, $this->position, $count);
            $this->position += strlen($data);
            return $data;
        }

        public function stream_eof()
        {
            $dataSource = $this->isInput ? ($GLOBALS['__DDLESS_RAW_INPUT__'] ?? '') : $this->buffer;
            return $this->position >= strlen($dataSource);
        }

        public function stream_tell()
        {
            return $this->position;
        }

        public function stream_seek($offset, $whence = SEEK_SET)
        {
            $dataSource = $this->isInput ? ($GLOBALS['__DDLESS_RAW_INPUT__'] ?? '') : $this->buffer;
            $length = strlen($dataSource);

            switch ($whence) {
                case SEEK_SET: $target = $offset; break;
                case SEEK_CUR: $target = $this->position + $offset; break;
                case SEEK_END: $target = $length + $offset; break;
                default: return false;
            }

            if ($target < 0) return false;
            $this->position = $target;
            return true;
        }

        public function stream_stat()
        {
            $size = $this->isInput ? strlen($GLOBALS['__DDLESS_RAW_INPUT__'] ?? '') : strlen($this->buffer);
            return ['size' => $size];
        }

        public function stream_write($data)
        {
            if ($this->isInput) {
                // This MUST NOT modify __DDLESS_RAW_INPUT__
                return strlen($data);
            }

            $len = strlen($data);
            $before = substr($this->buffer, 0, $this->position);
            $after = substr($this->buffer, $this->position + $len);
            $this->buffer = $before . $data . $after;
            $this->position += $len;
            return $len;
        }

        public function stream_truncate(int $newSize)
        {
            if ($this->isInput) {
                return false;
            }
            if ($newSize < strlen($this->buffer)) {
                $this->buffer = substr($this->buffer, 0, $newSize);
            } else {
                $this->buffer = str_pad($this->buffer, $newSize, "\0");
            }
            if ($this->position > $newSize) {
                $this->position = $newSize;
            }
            return true;
        }
    }
}

// ============================================================================
// Helper: simulate http_trigger.php cookie parsing logic
// ============================================================================

function ddless_test_parse_cookies(array $cookiesArray): array
{
    $cookies = [];
    $cookiesArray = array_filter(array_map('trim', $cookiesArray), fn($c) => $c !== '');
    foreach ($cookiesArray as $cookieEntry) {
        $parts = explode('=', $cookieEntry, 2);
        $cookieName = trim($parts[0]);
        if ($cookieName === '') {
            continue;
        }
        // Must use rawurldecode (not urldecode) to preserve '+' in encrypted values
        $cookieValue = isset($parts[1]) ? rawurldecode($parts[1]) : '';
        $cookies[$cookieName] = $cookieValue;
    }
    return $cookies;
}

// ============================================================================
// Tests: DDLessPhpInputStream — stream_write must not corrupt raw input
// ============================================================================

section('DDLessPhpInputStream — stream_write isolation');

test('stream_write does not modify __DDLESS_RAW_INPUT__', function () {
    $originalBody = '{"event":"adf","data":{"id":123}}';
    $GLOBALS['__DDLESS_RAW_INPUT__'] = $originalBody;

    $stream = new DDLessPhpInputStreamTestDouble();
    $stream->stream_open('php://input', 'w', 0, $opened);

    // Simulate Guzzle writing an outgoing request body
    $outgoingBody = '{"action":"spin","wheel_id":456}';
    $written = $stream->stream_write($outgoingBody);

    assert_eq(strlen($outgoingBody), $written, 'stream_write returns byte count');
    assert_eq($originalBody, $GLOBALS['__DDLESS_RAW_INPUT__'], 'raw input must be unchanged after stream_write');
});

test('stream_write with multiple writes does not accumulate in global', function () {
    $originalBody = 'original-request-body';
    $GLOBALS['__DDLESS_RAW_INPUT__'] = $originalBody;

    $stream = new DDLessPhpInputStreamTestDouble();
    $stream->stream_open('php://input', 'w', 0, $opened);

    $stream->stream_write('chunk1');
    $stream->stream_write('chunk2');
    $stream->stream_write('chunk3');

    assert_eq($originalBody, $GLOBALS['__DDLESS_RAW_INPUT__'], 'raw input unchanged after multiple writes');
});

test('stream_read still works correctly after stream_write', function () {
    $originalBody = '{"test":"value"}';
    $GLOBALS['__DDLESS_RAW_INPUT__'] = $originalBody;

    // First, someone writes (should be no-op)
    $writeStream = new DDLessPhpInputStreamTestDouble();
    $writeStream->stream_open('php://input', 'w', 0, $opened);
    $writeStream->stream_write('garbage-data');

    // Then, read the original body
    $readStream = new DDLessPhpInputStreamTestDouble();
    $readStream->stream_open('php://input', 'r', 0, $opened);
    $data = $readStream->stream_read(4096);

    assert_eq($originalBody, $data, 'read returns original body, not corrupted data');
});

test('stream_write returns correct byte count for multi-byte strings', function () {
    $GLOBALS['__DDLESS_RAW_INPUT__'] = '';
    $stream = new DDLessPhpInputStreamTestDouble();
    $stream->stream_open('php://input', 'w', 0, $opened);

    $utf8Data = 'café résumé naïve';
    $written = $stream->stream_write($utf8Data);
    assert_eq(strlen($utf8Data), $written, 'returns byte length, not character length');
});

// ============================================================================
// Tests: php://temp and php://memory — Guzzle outgoing request compatibility
// ============================================================================

section('DDLessPhpInputStream — php://temp write/read (Guzzle pattern)');

test('php://temp write then read returns written data', function () {
    $GLOBALS['__DDLESS_RAW_INPUT__'] = 'incoming-request-body';

    $stream = new DDLessPhpInputStreamTestDouble();
    $stream->stream_open('php://temp', 'r+', 0, $opened);

    $payload = '{"action":"create","name":"test"}';
    $stream->stream_write($payload);

    // Rewind and read back — must get what was written, NOT __DDLESS_RAW_INPUT__
    $stream->stream_seek(0);
    $result = $stream->stream_read(4096);

    assert_eq($payload, $result, 'php://temp must return written data, not raw input');
});

test('php://temp simulates full Guzzle request body flow', function () {
    $GLOBALS['__DDLESS_RAW_INPUT__'] = '{"original":"incoming"}';

    // Guzzle opens php://temp, writes the outgoing body, rewinds, reads back
    $stream = new DDLessPhpInputStreamTestDouble();
    $stream->stream_open('php://temp', 'r+', 0, $opened);

    $outgoingBody = '{"event":"webhook","data":{"id":42}}';
    $stream->stream_write($outgoingBody);

    $stream->stream_seek(0);
    $readBack = '';
    while (!$stream->stream_eof()) {
        $readBack .= $stream->stream_read(8192);
    }

    assert_eq($outgoingBody, $readBack, 'full read after rewind must match written body');
    assert_eq('{"original":"incoming"}', $GLOBALS['__DDLESS_RAW_INPUT__'], 'raw input must be untouched');
});

test('php://memory works the same as php://temp', function () {
    $GLOBALS['__DDLESS_RAW_INPUT__'] = 'should-not-appear';

    $stream = new DDLessPhpInputStreamTestDouble();
    $stream->stream_open('php://memory', 'r+', 0, $opened);

    $data = 'memory-buffer-content';
    $stream->stream_write($data);
    $stream->stream_seek(0);
    $result = $stream->stream_read(4096);

    assert_eq($data, $result);
});

test('php://temp multiple writes accumulate correctly', function () {
    $stream = new DDLessPhpInputStreamTestDouble();
    $stream->stream_open('php://temp', 'r+', 0, $opened);

    $stream->stream_write('{"name":');
    $stream->stream_write('"test"}');

    $stream->stream_seek(0);
    $result = $stream->stream_read(4096);

    assert_eq('{"name":"test"}', $result);
});

test('php://temp position advances correctly with tell', function () {
    $stream = new DDLessPhpInputStreamTestDouble();
    $stream->stream_open('php://temp', 'r+', 0, $opened);

    assert_eq(0, $stream->stream_tell());
    $stream->stream_write('hello');
    assert_eq(5, $stream->stream_tell());
    $stream->stream_write(' world');
    assert_eq(11, $stream->stream_tell());
});

test('php://temp seek SEEK_END works', function () {
    $stream = new DDLessPhpInputStreamTestDouble();
    $stream->stream_open('php://temp', 'r+', 0, $opened);

    $stream->stream_write('abcdef');
    $stream->stream_seek(-3, SEEK_END);
    $result = $stream->stream_read(3);

    assert_eq('def', $result);
});

test('php://temp stat returns correct size', function () {
    $stream = new DDLessPhpInputStreamTestDouble();
    $stream->stream_open('php://temp', 'r+', 0, $opened);

    $stat = $stream->stream_stat();
    assert_eq(0, $stat['size'], 'empty buffer has size 0');

    $stream->stream_write('12345');
    $stat = $stream->stream_stat();
    assert_eq(5, $stat['size'], 'size matches written data');
});

test('php://input still reads from __DDLESS_RAW_INPUT__ (unchanged behavior)', function () {
    $GLOBALS['__DDLESS_RAW_INPUT__'] = '{"incoming":"data"}';

    $stream = new DDLessPhpInputStreamTestDouble();
    $stream->stream_open('php://input', 'r', 0, $opened);
    $result = $stream->stream_read(4096);

    assert_eq('{"incoming":"data"}', $result);
});

test('php://input write is still no-op', function () {
    $GLOBALS['__DDLESS_RAW_INPUT__'] = 'original';

    $stream = new DDLessPhpInputStreamTestDouble();
    $stream->stream_open('php://input', 'w', 0, $opened);
    $stream->stream_write('overwrite-attempt');

    assert_eq('original', $GLOBALS['__DDLESS_RAW_INPUT__']);
});

test('php://temp with binary data preserves bytes', function () {
    $binary = '';
    for ($i = 0; $i < 256; $i++) {
        $binary .= chr($i);
    }

    $stream = new DDLessPhpInputStreamTestDouble();
    $stream->stream_open('php://temp', 'r+', 0, $opened);

    $stream->stream_write($binary);
    $stream->stream_seek(0);
    $result = $stream->stream_read(512);

    assert_eq($binary, $result, 'binary data must survive write/read round-trip');
});

// ============================================================================
// Tests: Cookie parsing — rawurldecode preserves encrypted values
// ============================================================================

section('Cookie parsing — rawurldecode preserves encrypted values');

test('cookie with + character is preserved (not converted to space)', function () {
    // Laravel encrypted cookies contain base64 which can have +
    $cookies = ddless_test_parse_cookies(['laravel_session=eyJpdiI6IkxhNmVQ+abc/def==']);
    assert_eq('eyJpdiI6IkxhNmVQ+abc/def==', $cookies['laravel_session']);
});

test('cookie with literal + signs in base64 value stays intact', function () {
    // Real-world encrypted cookie value with multiple + signs
    $value = 'eyJpdiI6IjRUNGJ+K0xr+M29iZTVz+bWFpbCI6Im';
    $cookies = ddless_test_parse_cookies(["XSRF-TOKEN={$value}"]);
    assert_eq($value, $cookies['XSRF-TOKEN']);
});

test('cookie with %20 is decoded to space (standard percent-encoding)', function () {
    $cookies = ddless_test_parse_cookies(['name=hello%20world']);
    assert_eq('hello world', $cookies['name']);
});

test('cookie with %2B is decoded to + (percent-encoded plus)', function () {
    $cookies = ddless_test_parse_cookies(['token=abc%2Bdef']);
    assert_eq('abc+def', $cookies['token']);
});

test('cookie with = in value is preserved', function () {
    // Base64 values often end with = or ==
    $cookies = ddless_test_parse_cookies(['session=dGVzdA==']);
    assert_eq('dGVzdA==', $cookies['session']);
});

test('multiple cookies parsed correctly', function () {
    $cookies = ddless_test_parse_cookies([
        'session_id=abc123',
        'XSRF-TOKEN=eyJpdiI6+base64/data==',
        'remember_me=1',
    ]);
    assert_count(3, $cookies);
    assert_eq('abc123', $cookies['session_id']);
    assert_eq('eyJpdiI6+base64/data==', $cookies['XSRF-TOKEN']);
    assert_eq('1', $cookies['remember_me']);
});

test('empty cookie value returns empty string', function () {
    $cookies = ddless_test_parse_cookies(['empty_cookie=']);
    assert_eq('', $cookies['empty_cookie']);
});

test('cookie without value returns empty string', function () {
    $cookies = ddless_test_parse_cookies(['no_value']);
    assert_eq('', $cookies['no_value']);
});

test('empty and whitespace entries are filtered', function () {
    $cookies = ddless_test_parse_cookies(['', '  ', 'valid=1']);
    assert_count(1, $cookies);
    assert_eq('1', $cookies['valid']);
});

test('urldecode would corrupt + but rawurldecode preserves it', function () {
    // This is the core bug test: urldecode('+') === ' ' but rawurldecode('+') === '+'
    $valueWithPlus = 'abc+def+ghi';

    // What the OLD code would do (broken):
    $oldResult = urldecode($valueWithPlus);
    assert_eq('abc def ghi', $oldResult, 'urldecode converts + to space (broken behavior)');

    // What the NEW code does (correct):
    $newResult = rawurldecode($valueWithPlus);
    assert_eq('abc+def+ghi', $newResult, 'rawurldecode preserves + (correct behavior)');
});

// ============================================================================
// Helper: simulate http_trigger.php $_FILES reconstruction
// ============================================================================

function ddless_test_reconstruct_files(array $payloadFiles): array
{
    $files = [];
    foreach ($payloadFiles as $fileEntry) {
        if (!is_array($fileEntry) || empty($fileEntry['fieldName']) || empty($fileEntry['fileName'])) {
            continue;
        }

        $fieldName = $fileEntry['fieldName'];
        $fileName = $fileEntry['fileName'];
        $fileContentType = $fileEntry['contentType'] ?? 'application/octet-stream';
        $fileContent = isset($fileEntry['content']) ? base64_decode($fileEntry['content'], true) : '';
        if ($fileContent === false) {
            $fileContent = '';
        }
        $fileSize = strlen($fileContent);

        $tmpPath = tempnam(sys_get_temp_dir(), 'ddless_test_');
        if ($tmpPath !== false) {
            file_put_contents($tmpPath, $fileContent);

            $isArrayField = preg_match('/^(.+)\[([^\]]*)\]$/', $fieldName, $arrayMatch);

            if ($isArrayField) {
                $baseField = $arrayMatch[1];
                if (!isset($files[$baseField])) {
                    $files[$baseField] = [
                        'name' => [], 'type' => [], 'tmp_name' => [], 'error' => [], 'size' => [],
                    ];
                }
                $files[$baseField]['name'][] = $fileName;
                $files[$baseField]['type'][] = $fileContentType;
                $files[$baseField]['tmp_name'][] = $tmpPath;
                $files[$baseField]['error'][] = UPLOAD_ERR_OK;
                $files[$baseField]['size'][] = $fileSize;
            } else {
                $files[$fieldName] = [
                    'name' => $fileName,
                    'type' => $fileContentType,
                    'tmp_name' => $tmpPath,
                    'error' => UPLOAD_ERR_OK,
                    'size' => $fileSize,
                ];
            }
        }
    }
    return $files;
}

function ddless_test_cleanup_files(array $files): void
{
    foreach ($files as $file) {
        $paths = is_array($file['tmp_name'] ?? null) ? $file['tmp_name'] : [$file['tmp_name'] ?? ''];
        foreach ($paths as $path) {
            if (is_string($path) && is_file($path)) {
                @unlink($path);
            }
        }
    }
}

// ============================================================================
// Tests: $_FILES reconstruction from payload
// ============================================================================

section('$_FILES reconstruction from multipart uploads');

test('single file upload populates $_FILES correctly', function () {
    $payload = [
        ['fieldName' => 'avatar', 'fileName' => 'photo.jpg', 'contentType' => 'image/jpeg', 'content' => base64_encode('fake-jpeg-data'), 'size' => 14],
    ];

    $files = ddless_test_reconstruct_files($payload);

    assert_array_has_key('avatar', $files);
    assert_eq('photo.jpg', $files['avatar']['name']);
    assert_eq('image/jpeg', $files['avatar']['type']);
    assert_eq(UPLOAD_ERR_OK, $files['avatar']['error']);
    assert_true(is_file($files['avatar']['tmp_name']), 'temp file exists');
    assert_eq('fake-jpeg-data', file_get_contents($files['avatar']['tmp_name']));

    ddless_test_cleanup_files($files);
});

test('binary file content preserved through base64 round-trip', function () {
    // Create content with bytes that would be corrupted by UTF-8
    $binaryContent = '';
    for ($i = 0; $i < 256; $i++) {
        $binaryContent .= chr($i);
    }

    $payload = [
        ['fieldName' => 'file', 'fileName' => 'data.bin', 'contentType' => 'application/octet-stream', 'content' => base64_encode($binaryContent), 'size' => 256],
    ];

    $files = ddless_test_reconstruct_files($payload);
    $restored = file_get_contents($files['file']['tmp_name']);
    assert_eq($binaryContent, $restored, 'binary content must survive base64 round-trip');
    assert_eq(256, $files['file']['size']);

    ddless_test_cleanup_files($files);
});

test('multiple files with array field name (files[])', function () {
    $payload = [
        ['fieldName' => 'documents[]', 'fileName' => 'doc1.pdf', 'contentType' => 'application/pdf', 'content' => base64_encode('pdf1'), 'size' => 4],
        ['fieldName' => 'documents[]', 'fileName' => 'doc2.pdf', 'contentType' => 'application/pdf', 'content' => base64_encode('pdf2'), 'size' => 4],
    ];

    $files = ddless_test_reconstruct_files($payload);

    assert_array_has_key('documents', $files);
    assert_count(2, $files['documents']['name']);
    assert_eq('doc1.pdf', $files['documents']['name'][0]);
    assert_eq('doc2.pdf', $files['documents']['name'][1]);
    assert_eq('pdf1', file_get_contents($files['documents']['tmp_name'][0]));
    assert_eq('pdf2', file_get_contents($files['documents']['tmp_name'][1]));

    ddless_test_cleanup_files($files);
});

test('mixed single and array files', function () {
    $payload = [
        ['fieldName' => 'avatar', 'fileName' => 'me.png', 'contentType' => 'image/png', 'content' => base64_encode('png'), 'size' => 3],
        ['fieldName' => 'attachments[]', 'fileName' => 'a.txt', 'contentType' => 'text/plain', 'content' => base64_encode('hello'), 'size' => 5],
        ['fieldName' => 'attachments[]', 'fileName' => 'b.txt', 'contentType' => 'text/plain', 'content' => base64_encode('world'), 'size' => 5],
    ];

    $files = ddless_test_reconstruct_files($payload);

    assert_count(2, $files, '2 keys: avatar + attachments');
    assert_eq('me.png', $files['avatar']['name']);
    assert_count(2, $files['attachments']['name']);

    ddless_test_cleanup_files($files);
});

test('empty payload produces no files', function () {
    $files = ddless_test_reconstruct_files([]);
    assert_count(0, $files);
});

test('entries without fieldName or fileName are skipped', function () {
    $payload = [
        ['fieldName' => '', 'fileName' => 'test.txt', 'content' => base64_encode('x')],
        ['fieldName' => 'file', 'fileName' => '', 'content' => base64_encode('x')],
        ['fileName' => 'test.txt', 'content' => base64_encode('x')],
    ];

    $files = ddless_test_reconstruct_files($payload);
    assert_count(0, $files);
});

test('default content type is application/octet-stream', function () {
    $payload = [
        ['fieldName' => 'data', 'fileName' => 'unknown.bin', 'content' => base64_encode('bytes')],
    ];

    $files = ddless_test_reconstruct_files($payload);
    assert_eq('application/octet-stream', $files['data']['type']);

    ddless_test_cleanup_files($files);
});

// ============================================================================
// Standalone run
// ============================================================================

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    exit(print_test_results());
}
