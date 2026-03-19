# DDLess PHP Debug Engine

![Tests](https://github.com/behindSolution/ddless-engine/actions/workflows/tests.yml/badge.svg)

> **This is the PHP engine only.** For the full debugging experience (desktop app, UI, AI Copilot, Task Runner), visit [ddless.com](https://ddless.com)

The PHP runtime engine that powers [DDLess](https://ddless.com) — a visual debugger for PHP applications.

This engine handles code instrumentation, breakpoint management, step-through execution, and variable inspection. It works with any PHP project (vanilla PHP, Laravel, Symfony, etc.) running on PHP 7.4+.

## How It Works

DDLess uses AST-based analysis (via [nikic/PHP-Parser](https://github.com/nikic/PHP-Parser)) to understand your code structure. It injects step-check calls before each executable line, allowing the debugger to pause, inspect variables, and control execution flow — all without modifying your original files.

### Core Components

| File | Purpose |
|------|---------|
| `src/debug.php` | Main engine — AST analysis, instrumentation, breakpoint handler, variable serialization |
| `src/http_trigger.php` | Entry point for HTTP debug sessions |
| `src/method_trigger.php` | Entry point for method-level debugging |
| `src/task_trigger.php` | Entry point for task/command debugging |
| `src/cli_trigger.php` | Entry point for CLI script debugging |
| `src/frameworks/laravel/` | Laravel-specific request handling, method execution, task runner |
| `src/frameworks/symfony/` | Symfony request handling, method execution, task runner |
| `src/frameworks/codeigniter/` | CodeIgniter 4 request handling, method execution, task runner |
| `src/frameworks/tempest/` | Tempest request handling, method execution, task runner |
| `src/frameworks/wordpress/` | WordPress request handling, method execution, task runner |
| `src/frameworks/php/` | Vanilla PHP request handling, method execution, task runner |
| `src/vendor-internal/` | Bundled PHP-Parser (no Composer required) |

### Key Functions

- `ddless_analyze_code_ast()` — Parses PHP source into AST and identifies instrumentable lines
- `ddless_instrument_code_with_ast()` — Injects debug hooks into source code
- `ddless_step_check()` — Called before every executable line, decides whether to pause
- `ddless_handle_breakpoint()` — Pauses execution, captures state, waits for debugger command
- `ddless_normalize_value()` — Serializes variables for display (handles objects, enums, DateTime, etc.)

## Requirements

- PHP 7.4 or higher (tested up to 8.4)
- `json` extension (included by default)

## Running Tests

```bash
php tests/run-all.php
```

Individual test files can also be run directly:

```bash
php tests/NormalizeValueTest.php
php tests/InstrumentCodeAstTest.php
php tests/HttpRequestTest.php
```

## Test Matrix

Tests run automatically on every push and PR against PHP 7.4, 8.0, 8.1, 8.2, 8.3, and 8.4.

## License

Source-available. You may read, audit, and report issues.
Redistribution and derivative products are not permitted.
See [LICENSE](LICENSE) for full terms.
