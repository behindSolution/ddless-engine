# Contributing to DDLess Engine

Thanks for your interest in contributing! This document explains how to get started.

## Getting Started

1. Fork this repository
2. Clone your fork locally
3. Make sure tests pass before making changes:
   ```bash
   php tests/run-all.php
   ```

## PHP Version Compatibility

All code **must** work on PHP 7.4 through 8.4. This means:

- No `mixed` type hints (use `#[\ReturnTypeWillChange]` where needed)
- No named arguments in function calls
- No `match` expressions
- No enums (the engine detects them at runtime with `instanceof`)
- No union types in signatures
- No `readonly` properties
- No fibers

If you're unsure whether a feature is available on 7.4, check [php.net/migration80](https://www.php.net/migration80).

## Project Structure

```
src/
  debug.php              # Core engine (AST analysis, instrumentation, breakpoints)
  http_trigger.php       # HTTP debug entry point
  method_trigger.php     # Method debug entry point
  task_trigger.php       # Task debug entry point
  cli_trigger.php        # CLI debug entry point
  frameworks/
    laravel/             # Laravel-specific handlers
    php/                 # Vanilla PHP handlers
  vendor-internal/       # Bundled PHP-Parser (do not modify)
tests/
  bootstrap.php          # Test runner and assertions
  run-all.php            # Runs all test files
  *Test.php              # Individual test files
```

## Writing Tests

Tests use a zero-dependency mini test runner defined in `tests/bootstrap.php`.

```php
<?php
require_once __DIR__ . '/bootstrap.php';

section('My Feature');

test('it does something', function () {
    assert_eq('expected', my_function());
});

test('it handles edge cases', function () {
    assert_true(some_check());
    assert_contains('hello world', 'hello');
});

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    exit(print_test_results());
}
```

Available assertions: `assert_eq`, `assert_true`, `assert_false`, `assert_null`, `assert_not_null`, `assert_contains`, `assert_not_contains`, `assert_array_has_key`, `assert_array_not_has_key`, `assert_count`.

## Submitting Changes

1. Create a branch from `main`
2. Write or update tests for your changes
3. Make sure all tests pass: `php tests/run-all.php`
4. Open a Pull Request with a clear description of what you changed and why

## What Not to Modify

- `src/vendor-internal/` — This is a bundled copy of nikic/PHP-Parser. Changes here will be overwritten.

## Reporting Issues

Open an issue on this repository. Include:

- PHP version (`php -v`)
- A minimal code snippet that reproduces the problem
- Expected vs actual behavior
