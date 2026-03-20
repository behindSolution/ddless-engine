# DDLess Playground

Test suite for validating the debug engine and framework integrations from the terminal,
without the Electron app.

## Adding a New Framework

To add support for a new framework (e.g. CakePHP), create a directory at:

```
.ddless/frameworks/cakephp/
```

With up to three handler files:

| File | Purpose | Required |
|---|---|---|
| `method_executor.php` | Resolves a class from the container and calls a method | Yes |
| `task_runner.php` | Boots the framework and executes arbitrary PHP code | Yes |
| `http_request.php` | Processes an HTTP request through the full framework pipeline | Yes |

Use the existing `php` framework (`frameworks/php/`) as a minimal reference, and
`laravel` as a full-featured example.

### method_executor.php

Receives input via `$GLOBALS['__DDLESS_METHOD_INPUT__']` (JSON string):

```json
{
  "class": "App\\Services\\OrderService",
  "method": "calculate",
  "parameterCode": "return [42, true];",
  "constructorCode": "return [];",
  "framework": "cakephp"
}
```

Must:
1. Boot the framework and get the service container
2. Resolve the class (from container or via reflection)
3. Execute the method with the evaluated parameters
4. Output result JSON to stdout via `ddless_method_success()` / `ddless_method_error()`
5. Call `exit()` after outputting

### task_runner.php

Receives input via `$GLOBALS['__DDLESS_TASK_INPUT__']` (JSON string):

```json
{
  "code": "$this->info(User::count());",
  "imports": ["App\\Models\\User"],
  "framework": "cakephp"
}
```

Must:
1. Boot the framework
2. Provide a `DdlessTask` instance as `$this` context (output methods: `info`, `error`, `table`, etc.)
3. Eval the user code with `use` statements prepended
4. Emit output via `__DDLESS_TASK_OUTPUT__:` markers on stdout
5. Emit completion via `__DDLESS_TASK_DONE__:` marker

### http_request.php

Receives a real HTTP request (superglobals already populated). The request body is
available at `$GLOBALS['__DDLESS_RAW_INPUT__']`.

Must:
1. Boot the framework
2. Create a request object from PHP superglobals
3. Process through the full middleware/routing pipeline
4. Send the response (headers + body)
5. If `DDLESS_DEBUG_MODE` env is `true`, load `debug.php` and register the stream wrapper

## Testing Steps

Follow this order. Each step validates a layer before moving to the next.

### Step 1 — Engine Test (debug engine)

Validates that breakpoints, stepping, and variable inspection work.

```bash
# Quick sanity check — inline code, no framework
php playground/test_trigger.php --code '$x = 1; $y = 2; $z = $x + $y; echo $z;' --bp 2

# With framework context — boot first, then run your script
php playground/test_trigger.php --boot boot.php --file test_orders.php --bp 3
```

The `--boot` flag lets you boot your framework before running the target script.
Create a `boot.php` at your project root:

```php
<?php
// boot.php — Laravel example
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
```

Then write a script that uses the framework:

```php
<?php
// test_orders.php
$service = app(\App\Services\OrderService::class);
$result = $service->calculate(42);
var_dump($result);
```

```bash
php playground/test_trigger.php --boot boot.php --file test_orders.php --bp 3 --bp 4
```

This is the first test a contributor should run when adding a new framework.
If the boot works and breakpoints hit, you understand how the framework boots —
and you can replicate that in `method_executor.php`, `task_runner.php`, and `http_request.php`.

At the breakpoint, use the interactive commands:
- `c` — continue to next breakpoint
- `n` — step over (next line)
- `s` — step into function
- `o` — step out of function
- `q` — quit

**What to verify:**
- Framework boots without errors
- Breakpoint stops at the correct line
- Source code is displayed with the current line highlighted (not instrumented code)
- Variables show correct values (framework objects, services, etc.)
- Step commands work as expected

### Step 2 — Method Test (method executor)

Validates your framework's `method_executor.php`. Tests that the container can resolve
a class and call a method with parameters.

```bash
# Call a method (framework resolves from container)
php playground/test_trigger.php --test method --framework cakephp \
  --class "App\Services\OrderService" --method calculate

# With method parameters (PHP file that returns an array)
php playground/test_trigger.php --test method --framework cakephp \
  --class "App\Services\OrderService" --method calculate \
  --params-file test_params.php

# With constructor parameters
php playground/test_trigger.php --test method --framework cakephp \
  --class "App\Services\PriceCalculator" --method getTotal \
  --ctor-file test_ctor.php --params-file test_params.php

# Call a global function (empty class)
php playground/test_trigger.php --test method --framework cakephp \
  --class "" --method array_sum --params-file test_params.php

# With breakpoints inside the method
php playground/test_trigger.php --test method --framework cakephp \
  --class "App\Services\OrderService" --method calculate \
  --bp app/Services/OrderService.php:45
```

**Parameter files** are PHP files that return an array:

```php
<?php
// test_params.php
return [42, true, 'premium'];
```

```php
<?php
// test_ctor.php
return [new \App\Repositories\OrderRepository()];
```

**What to verify:**
- Framework boots without errors
- Class is resolved from the container (or instantiated via reflection)
- Method executes and returns the serialized result
- Exceptions are caught and displayed with validation errors
- Breakpoints work inside the method code

### Step 3 — Task Test (task runner)

Validates your framework's `task_runner.php`. Tests arbitrary code execution with
framework context (models, services, helpers).

```bash
# Inline code
php playground/test_trigger.php --test task --framework cakephp \
  -c '$this->info("Hello from CakePHP!");'

# With imports
php playground/test_trigger.php --test task --framework cakephp \
  -c '$this->info("Users: " . UsersTable::find()->count());' \
  -u "App\Model\Table\UsersTable"

# From a file
php playground/test_trigger.php --test task --framework cakephp \
  --file my_task.php
```

**What to verify:**
- Framework boots and provides full context (DB, models, services)
- Output methods work: `$this->info()`, `$this->error()`, `$this->table()`, `$this->json()`
- Imports (`use` statements) are applied correctly
- Task done summary shows status and duration
- Exceptions are caught and displayed properly

### Step 4 — HTTP Test (http request)

Validates your framework's `http_request.php`. Sends real HTTP requests through
the full framework pipeline using PHP's built-in server.

**Terminal 1** — start the server:

```bash
# Basic
DDLESS_FRAMEWORK=cakephp php -S localhost:8080 playground/test_trigger.php

# With breakpoints (use relative path from project root to avoid ambiguity)
DDLESS_FRAMEWORK=cakephp DDLESS_BP="app/Controllers/OrdersController.php:45,app/Services/OrderService.php:32" \
  php -S localhost:8080 playground/test_trigger.php

# With deeper variable inspection
DDLESS_FRAMEWORK=cakephp DDLESS_BP="app/Controllers/OrdersController.php:45" DDLESS_DEPTH=6 \
  php -S localhost:8080 playground/test_trigger.php
```

On Windows PowerShell:

```powershell
$env:DDLESS_FRAMEWORK="cakephp"; $env:DDLESS_BP="app/Controllers/OrdersController.php:45"; php -S localhost:8080 playground/test_trigger.php
```

**Terminal 2** — send requests:

```bash
# GET request
curl http://localhost:8080/api/orders

# POST with JSON body
curl -X POST http://localhost:8080/api/orders \
  -H "Content-Type: application/json" \
  -d '{"item": "Widget", "quantity": 3}'

# POST with form data
curl -X POST http://localhost:8080/api/login \
  -d "email=user@example.com&password=secret"

# With cookies
curl http://localhost:8080/dashboard -b "session_id=abc123"

# With custom headers
curl http://localhost:8080/api/orders -H "Authorization: Bearer token123"
```

**What to verify:**
- Framework boots and routes the request correctly
- Response returns with proper status code, headers, and body
- POST data, query params, cookies, and headers are all received
- Breakpoints pause execution in the server terminal
- Interactive debugger shows variables at the breakpoint
- After stepping/continuing, the response is returned to curl

### Environment variables (HTTP mode)

| Variable | Description | Default |
|---|---|---|
| `DDLESS_FRAMEWORK` | Framework name | `php` |
| `DDLESS_BP` | Breakpoints, comma-separated (`file:line,file:line`) | (none) |
| `DDLESS_DEPTH` | Variable serialization depth | `4` |

## File Structure

```
playground/
  test_trigger.php    Entry point / router
  terminal_ui.php     Shared utilities (colors, breakpoint handler, session)
  engine_test.php     Debug engine test (plain PHP)
  method_test.php     Method executor test (framework)
  task_test.php       Task runner test (framework)
  http_test.php       HTTP request test (framework, built-in server)
  README.md           This file
```

## Help

Each test mode has a `--help` flag:

```bash
php playground/test_trigger.php --help
php playground/test_trigger.php --test method --help
php playground/test_trigger.php --test task --help
```
