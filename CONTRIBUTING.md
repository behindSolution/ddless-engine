# Contributing to DDLess Engine

Thanks for your interest in contributing! This document explains how to get started.

## Getting Started

1. Fork this repository
2. Clone your fork inside your PHP project (or anywhere with PHP available):
   ```bash
   cd /var/www/html          # your project root
   git clone https://github.com/YOUR_USER/ddless-engine.git .ddless
   ```
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
    symfony/             # Symfony-specific handlers
    codeigniter/         # CodeIgniter 4 handlers
    tempest/             # Tempest handlers
    wordpress/           # WordPress handlers
    php/                 # Vanilla PHP handlers (minimal reference)
  playground/            # Interactive terminal test suite
  vendor-internal/       # Bundled PHP-Parser (do not modify)
tests/
  bootstrap.php          # Test runner and assertions
  run-all.php            # Runs all test files
  *Test.php              # Individual test files
```

## Playground — Testing Your Changes

The playground lets you test the debug engine and framework integrations directly
from the terminal, without the DDLess desktop app. Use it to validate your changes
before submitting a PR.

### Setup

Clone ddless-engine inside a real PHP project (or use an existing one):

```bash
# Inside a Laravel project, for example
cd /var/www/html
git clone https://github.com/YOUR_USER/ddless-engine.git .ddless
```

The playground is at `src/playground/`. Run everything from the `.ddless/` directory:

```bash
cd .ddless
```

### Step 1 — Engine Test

Validates the core debug engine (breakpoints, stepping, variable inspection).
Use `--boot` to boot your framework first, then run a script with breakpoints:

```bash
# Quick sanity check — no framework
php src/playground/test_trigger.php --code '$x = 1; $y = 2; echo $x + $y;' --bp 2

# With framework — create a boot.php at your project root:
#   <?php
#   $app = require __DIR__ . '/bootstrap/app.php';
#   $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

# Then run a script that uses the framework:
php src/playground/test_trigger.php --boot boot.php --file test_orders.php --bp 3
```

This is the first test when adding a new framework. If the boot works and breakpoints
hit, you understand the framework bootstrap — and can replicate it in the handlers.

### Step 2 — Method Test

Validates a framework's `method_executor.php` — resolves a class from the container
and calls a method:

```bash
php src/playground/test_trigger.php --test method --framework laravel \
  --class "App\Services\OrderService" --method calculate

# With parameters
php src/playground/test_trigger.php --test method --framework laravel \
  --class "App\Services\OrderService" --method calculate \
  --params-file test_params.php

# With breakpoints
php src/playground/test_trigger.php --test method --framework laravel \
  --class "App\Services\OrderService" --method calculate \
  --bp app/Services/OrderService.php:45
```

### Step 3 — Task Test

Validates a framework's `task_runner.php` — executes arbitrary PHP code with
framework context:

```bash
php src/playground/test_trigger.php --test task --framework laravel \
  -c '$this->info("Users: " . User::count());' \
  -u "App\Models\User"

# From a file
php src/playground/test_trigger.php --test task --framework laravel \
  --file my_task.php
```

### Step 4 — HTTP Test

Validates a framework's `http_request.php` — sends real HTTP requests through
the full pipeline:

**Terminal 1:**
```bash
DDLESS_FRAMEWORK=laravel php -S localhost:8080 src/playground/test_trigger.php

# With breakpoints
DDLESS_FRAMEWORK=laravel DDLESS_BP="app/Http/Controllers/OrderController.php:45" \
  php -S localhost:8080 src/playground/test_trigger.php
```

**Terminal 2:**
```bash
curl http://localhost:8080/api/orders
curl -X POST http://localhost:8080/api/orders \
  -H "Content-Type: application/json" -d '{"item":"test"}'
```

Each step validates a layer. If one fails, you know exactly where to look.
See `src/playground/README.md` for full details and all options.

## Adding a New Framework

To add support for a new framework (e.g. CakePHP):

1. Create `src/frameworks/cakephp/` with three files:

   | File | Purpose |
   |------|---------|
   | `method_executor.php` | Resolve class from container, call method, output result |
   | `task_runner.php` | Boot framework, eval user code with `DdlessTask` context |
   | `http_request.php` | Process HTTP request through full middleware pipeline |

2. Use `src/frameworks/php/` as a minimal reference and `src/frameworks/laravel/` as a full example.

3. Test with the playground (Steps 2, 3, 4 above).

4. See `src/playground/README.md` for input/output protocols of each handler.

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
3. Test with the playground to validate framework integrations
4. Make sure all tests pass: `php tests/run-all.php`
5. Open a Pull Request with a clear description of what you changed and why

## What Not to Modify

- `src/vendor-internal/` — This is a bundled copy of nikic/PHP-Parser. Changes here will be overwritten.

## Reporting Issues

Open an issue on this repository. Include:

- PHP version (`php -v`)
- A minimal code snippet that reproduces the problem
- Expected vs actual behavior