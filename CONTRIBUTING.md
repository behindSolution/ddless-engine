# Contributing

## Setup

Clone your fork at the root of a PHP project (alongside `composer.json`):

```bash
cd /var/www/html
git clone https://github.com/YOUR_USER/ddless-engine.git
cd ddless-engine
php tests/run-all.php   # make sure everything passes first
```

## PHP compatibility

All code must run on PHP 7.4+. That means no:

- `mixed` type hints (use `#[\ReturnTypeWillChange]` where needed)
- named arguments, `match`, union types, `readonly`, enums, fibers

When in doubt: [php.net/migration80](https://www.php.net/migration80).

## Playground

The playground lets you test the engine and framework integrations from the terminal, without the desktop app. It expects ddless-engine to live inside a real PHP project:

```
/var/www/html/                  ← project root (has composer.json)
├── app/
├── vendor/
├── composer.json
└── ddless-engine/              ← your fork
```

Each step validates a layer. If one fails, you know exactly where to look.

### Engine test

Validates breakpoints, stepping, and variable inspection:

```bash
php src/playground/test_trigger.php --code '$x = 1; $y = 2; echo $x + $y;' --bp 2
```

With a framework, create `boot.php` and a test file:

```php
// boot.php — boots the framework (runs without stream wrapper)
<?php
define('DDLESS_PROJECT_ROOT', dirname(__DIR__));
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
```

```php
// test_orders.php
<?php
$service = app(\App\Services\OrderService::class);
$result = $service->calculate(42);
var_dump($result);
```

```bash
php src/playground/test_trigger.php --boot boot.php --file test_orders.php \
  --bp app/Services/OrderService.php:38
```

### Method test

Validates `method_executor.php` — resolves a class from the container and calls a method:

```bash
php src/playground/test_trigger.php --test method --framework laravel \
  --class "App\Services\OrderService" --method calculate

# with parameters
php src/playground/test_trigger.php --test method --framework laravel \
  --class "App\Services\OrderService" --method calculate \
  --params-file test_params.php

# with breakpoints
php src/playground/test_trigger.php --test method --framework laravel \
  --class "App\Services\OrderService" --method calculate \
  --bp app/Services/OrderService.php:45
```

### Task test

Validates `task_runner.php` — executes PHP code with framework context:

```bash
php src/playground/test_trigger.php --test task --framework laravel \
  -c '$this->info("Users: " . User::count());' \
  -u "App\Models\User"

# from a file
php src/playground/test_trigger.php --test task --framework laravel \
  --file my_task.php
```

### HTTP test

Validates `http_request.php` — sends real requests through the full pipeline:

**Terminal 1:**
```bash
DDLESS_FRAMEWORK=laravel php -S localhost:8080 src/playground/test_trigger.php

# with breakpoints
DDLESS_FRAMEWORK=laravel DDLESS_BP="app/Http/Controllers/OrderController.php:45" \
  php -S localhost:8080 src/playground/test_trigger.php
```

**Terminal 2:**
```bash
curl http://localhost:8080/api/orders
curl -X POST http://localhost:8080/api/orders \
  -H "Content-Type: application/json" -d '{"item":"test"}'
```

See `src/playground/README.md` for all options and protocols.

## Adding a framework

To add support for a new framework (e.g. CakePHP), create `src/frameworks/cakephp/` with:

| File | Purpose |
|------|---------|
| `method_executor.php` | Resolve class from container, call method, output result |
| `task_runner.php` | Boot framework, eval user code with `DdlessTask` context |
| `http_request.php` | Process HTTP request through the full middleware pipeline |

Use `src/frameworks/php/` as a minimal reference and `src/frameworks/laravel/` as a full one. Validate with the playground steps above.

## Writing tests

Tests use a zero-dependency runner defined in `tests/bootstrap.php`:

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

## Submitting changes

1. Branch from `main`
2. Write or update tests
3. Validate with the playground
4. `php tests/run-all.php` — all green
5. Open a PR with a clear description of what and why

## Don't modify

`src/vendor-internal/` is a bundled copy of nikic/PHP-Parser. Changes will be overwritten.

## Reporting issues

Open an issue with:
- PHP version (`php -v`)
- Minimal reproduction snippet
- Expected vs actual behavior