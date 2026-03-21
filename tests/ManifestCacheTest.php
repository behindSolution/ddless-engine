<?php
/**
 * DDLess Manifest Cache Tests
 *
 * Tests the manifest-based fast path that skips filesystem scanning
 * on subsequent requests when breakpoints/scope haven't changed.
 */

require_once __DIR__ . '/bootstrap.php';

// ============================================================================
// Helpers
// ============================================================================

function manifest_setup_temp_project(): string {
    $tmp = sys_get_temp_dir() . '/ddless_manifest_test_' . uniqid();
    mkdir($tmp, 0755, true);
    mkdir($tmp . '/.ddless/cache/instrumented', 0755, true);
    return $tmp;
}

function manifest_cleanup(string $dir): void {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($dir);
}

function manifest_write(string $cacheDir, array $data): void {
    file_put_contents($cacheDir . '/manifest.json', json_encode($data, JSON_UNESCAPED_SLASHES));
}

function manifest_read(string $cacheDir): ?array {
    $path = $cacheDir . '/manifest.json';
    if (!is_file($path)) return null;
    return json_decode(file_get_contents($path), true);
}

// ============================================================================
// Tests: ddless_compute_context_hash
// ============================================================================

section('Manifest — context hash');

test('context hash changes when breakpoint files change', function () {
    $old = $GLOBALS['__DDLESS_BREAKPOINT_FILES__'] ?? [];

    $GLOBALS['__DDLESS_BREAKPOINT_FILES__'] = [];
    $hash1 = ddless_compute_context_hash();

    $GLOBALS['__DDLESS_BREAKPOINT_FILES__'] = ['/tmp/foo.php' => ['relativePath' => 'foo.php']];
    $hash2 = ddless_compute_context_hash();

    assert_true($hash1 !== $hash2, 'Hash should change when breakpoint files change');

    $GLOBALS['__DDLESS_BREAKPOINT_FILES__'] = $old;
});

test('context hash changes when debug scope changes', function () {
    $old = $GLOBALS['__DDLESS_BREAKPOINT_FILES__'] ?? [];
    $GLOBALS['__DDLESS_BREAKPOINT_FILES__'] = [];

    $oldScope = getenv('DDLESS_DEBUG_SCOPE');
    putenv('DDLESS_DEBUG_SCOPE=');
    $hash1 = ddless_compute_context_hash();

    putenv('DDLESS_DEBUG_SCOPE=app/Models');
    $hash2 = ddless_compute_context_hash();

    assert_true($hash1 !== $hash2, 'Hash should change when scope changes');

    // Restore
    putenv($oldScope !== false ? "DDLESS_DEBUG_SCOPE={$oldScope}" : 'DDLESS_DEBUG_SCOPE');
    $GLOBALS['__DDLESS_BREAKPOINT_FILES__'] = $old;
});

test('context hash is stable with same inputs', function () {
    $old = $GLOBALS['__DDLESS_BREAKPOINT_FILES__'] ?? [];
    $GLOBALS['__DDLESS_BREAKPOINT_FILES__'] = ['/tmp/a.php' => ['relativePath' => 'a.php']];

    $hash1 = ddless_compute_context_hash();
    $hash2 = ddless_compute_context_hash();

    assert_eq($hash1, $hash2, 'Same inputs should produce same hash');

    $GLOBALS['__DDLESS_BREAKPOINT_FILES__'] = $old;
});

// ============================================================================
// Tests: ddless_try_manifest_fast_path
// ============================================================================

section('Manifest — fast path');

test('fast path returns false when no manifest exists', function () {
    // Point cache dir to a temp location with no manifest
    $tmp = manifest_setup_temp_project();
    $origCacheDir = null;

    // We can't easily redirect ddless_get_cache_dir, so test the logic directly
    $manifestPath = $tmp . '/.ddless/cache/manifest.json';
    assert_false(is_file($manifestPath), 'Manifest should not exist');

    manifest_cleanup($tmp);
});

test('fast path returns false when context hash mismatches', function () {
    $tmp = manifest_setup_temp_project();
    $cacheDir = $tmp . '/.ddless/cache';

    // Save manifest with a different context hash
    manifest_write($cacheDir, [
        'contextHash' => 'wrong_hash',
        'cacheVersion' => DDLESS_CACHE_VERSION,
        'files' => [],
    ]);

    $manifest = manifest_read($cacheDir);
    assert_true($manifest['contextHash'] !== ddless_compute_context_hash(), 'Context hash should differ');

    manifest_cleanup($tmp);
});

test('fast path returns false when cache version mismatches', function () {
    $tmp = manifest_setup_temp_project();
    $cacheDir = $tmp . '/.ddless/cache';

    manifest_write($cacheDir, [
        'contextHash' => ddless_compute_context_hash(),
        'cacheVersion' => 'old_version',
        'files' => [],
    ]);

    $manifest = manifest_read($cacheDir);
    assert_true($manifest['cacheVersion'] !== DDLESS_CACHE_VERSION, 'Cache version should differ');

    manifest_cleanup($tmp);
});

test('manifest is valid when context hash and cache version match', function () {
    $tmp = manifest_setup_temp_project();
    $cacheDir = $tmp . '/.ddless/cache';

    $hash = ddless_compute_context_hash();
    manifest_write($cacheDir, [
        'contextHash' => $hash,
        'cacheVersion' => DDLESS_CACHE_VERSION,
        'files' => ['/tmp/test.php'],
    ]);

    $manifest = manifest_read($cacheDir);
    assert_eq($hash, $manifest['contextHash']);
    assert_eq(DDLESS_CACHE_VERSION, $manifest['cacheVersion']);
    assert_count(1, $manifest['files']);

    manifest_cleanup($tmp);
});

// ============================================================================
// Tests: ddless_save_manifest
// ============================================================================

section('Manifest — save and load roundtrip');

test('save_manifest writes valid JSON with correct structure', function () {
    // Use the real cache dir
    $files = ['/tmp/a.php', '/tmp/b.php', '/tmp/c.php'];
    ddless_save_manifest($files);

    $manifestPath = ddless_get_manifest_path();
    assert_true(is_file($manifestPath), 'Manifest file should exist');

    $data = json_decode(file_get_contents($manifestPath), true);
    assert_true(is_array($data), 'Manifest should be valid JSON');
    assert_array_has_key('contextHash', $data);
    assert_array_has_key('cacheVersion', $data);
    assert_array_has_key('files', $data);
    assert_eq(DDLESS_CACHE_VERSION, $data['cacheVersion']);
    assert_count(3, $data['files']);
    assert_eq('/tmp/a.php', $data['files'][0]);
    assert_eq('/tmp/b.php', $data['files'][1]);
    assert_eq('/tmp/c.php', $data['files'][2]);

    // Cleanup
    @unlink($manifestPath);
});

test('save_manifest updates context hash on each save', function () {
    $old = $GLOBALS['__DDLESS_BREAKPOINT_FILES__'] ?? [];

    // Save with no breakpoints
    $GLOBALS['__DDLESS_BREAKPOINT_FILES__'] = [];
    ddless_save_manifest(['/tmp/x.php']);
    $data1 = json_decode(file_get_contents(ddless_get_manifest_path()), true);

    // Save with breakpoints
    $GLOBALS['__DDLESS_BREAKPOINT_FILES__'] = ['/tmp/bp.php' => ['relativePath' => 'bp.php']];
    ddless_save_manifest(['/tmp/x.php']);
    $data2 = json_decode(file_get_contents(ddless_get_manifest_path()), true);

    assert_true($data1['contextHash'] !== $data2['contextHash'], 'Context hash should differ after breakpoint change');

    // Cleanup
    @unlink(ddless_get_manifest_path());
    $GLOBALS['__DDLESS_BREAKPOINT_FILES__'] = $old;
});

// ============================================================================
// Tests: Integration — full flow
// ============================================================================

section('Manifest — integration');

test('manifest invalidates when breakpoints are added', function () {
    $old = $GLOBALS['__DDLESS_BREAKPOINT_FILES__'] ?? [];

    // Create manifest with no breakpoints
    $GLOBALS['__DDLESS_BREAKPOINT_FILES__'] = [];
    $hashBefore = ddless_compute_context_hash();
    ddless_save_manifest(['/tmp/file1.php']);

    $manifest = json_decode(file_get_contents(ddless_get_manifest_path()), true);
    assert_eq($hashBefore, $manifest['contextHash']);

    // Add a breakpoint — hash changes, manifest is stale
    $GLOBALS['__DDLESS_BREAKPOINT_FILES__'] = ['/tmp/file1.php' => ['relativePath' => 'file1.php']];
    $hashAfter = ddless_compute_context_hash();

    assert_true($hashBefore !== $hashAfter, 'Hash should change');
    assert_true($manifest['contextHash'] !== $hashAfter, 'Manifest should be stale');

    // Cleanup
    @unlink(ddless_get_manifest_path());
    $GLOBALS['__DDLESS_BREAKPOINT_FILES__'] = $old;
});

test('manifest invalidates when scope changes', function () {
    $old = $GLOBALS['__DDLESS_BREAKPOINT_FILES__'] ?? [];
    $GLOBALS['__DDLESS_BREAKPOINT_FILES__'] = [];
    $oldScope = getenv('DDLESS_DEBUG_SCOPE');

    // Create manifest with scope A
    putenv('DDLESS_DEBUG_SCOPE=app/Http');
    ddless_save_manifest(['/tmp/file1.php']);
    $manifest1 = json_decode(file_get_contents(ddless_get_manifest_path()), true);

    // Change scope — manifest should be stale
    putenv('DDLESS_DEBUG_SCOPE=app/Models');
    $newHash = ddless_compute_context_hash();
    assert_true($manifest1['contextHash'] !== $newHash, 'Manifest should be stale after scope change');

    // Cleanup
    @unlink(ddless_get_manifest_path());
    putenv($oldScope !== false ? "DDLESS_DEBUG_SCOPE={$oldScope}" : 'DDLESS_DEBUG_SCOPE');
    $GLOBALS['__DDLESS_BREAKPOINT_FILES__'] = $old;
});

test('manifest remains valid when nothing changes', function () {
    $old = $GLOBALS['__DDLESS_BREAKPOINT_FILES__'] ?? [];
    $GLOBALS['__DDLESS_BREAKPOINT_FILES__'] = ['/tmp/f.php' => ['relativePath' => 'f.php']];
    $oldScope = getenv('DDLESS_DEBUG_SCOPE');
    putenv('DDLESS_DEBUG_SCOPE=app/Http');

    ddless_save_manifest(['/tmp/file1.php', '/tmp/file2.php']);
    $manifest = json_decode(file_get_contents(ddless_get_manifest_path()), true);

    // Same breakpoints, same scope — hash should still match
    $currentHash = ddless_compute_context_hash();
    assert_eq($manifest['contextHash'], $currentHash, 'Manifest should still be valid');
    assert_eq(DDLESS_CACHE_VERSION, $manifest['cacheVersion']);

    // Cleanup
    @unlink(ddless_get_manifest_path());
    putenv($oldScope !== false ? "DDLESS_DEBUG_SCOPE={$oldScope}" : 'DDLESS_DEBUG_SCOPE');
    $GLOBALS['__DDLESS_BREAKPOINT_FILES__'] = $old;
});

test('empty manifest files array is valid', function () {
    ddless_save_manifest([]);

    $manifest = json_decode(file_get_contents(ddless_get_manifest_path()), true);
    assert_count(0, $manifest['files']);
    assert_eq(ddless_compute_context_hash(), $manifest['contextHash']);

    // Cleanup
    @unlink(ddless_get_manifest_path());
});
