# ddless-engine

![Tests](https://github.com/behindSolution/ddless-engine/actions/workflows/tests.yml/badge.svg)

The PHP runtime engine behind [DDLess](https://ddless.com) — a visual step-through debugger for PHP.

This repo contains the engine only. The desktop app, UI, and AI Copilot are available at [ddless.com](https://ddless.com).

## What it does

ddless-engine parses PHP source via AST ([nikic/PHP-Parser](https://github.com/nikic/PHP-Parser)), injects `ddless_step_check()` calls before each executable line using a custom stream wrapper, and communicates with the desktop app through file-based IPC. Your original files are never modified on disk.

Supports breakpoints (conditional, logpoints, dumppoints), step-in/over/out, watch expressions, variable inspection, and trace mode.

## Frameworks

Laravel · Symfony · CodeIgniter 4 · Tempest · WordPress · vanilla PHP

## Project layout

```
src/
├── debug.php                Core engine (AST analysis, instrumentation, breakpoint handler)
├── http_trigger.php         Entry point for HTTP debug sessions
├── method_trigger.php       Entry point for method-level debugging
├── cli_trigger.php          Entry point for CLI script debugging
├── task_trigger.php         Entry point for task/command debugging
├── ssh_proxy_router.php     SSH remote debugging support
├── frameworks/              Framework-specific request handling and bootstrapping
│   ├── laravel/
│   ├── symfony/
│   ├── codeigniter/
│   ├── tempest/
│   ├── wordpress/
│   └── php/
├── sessions/                File-based IPC (runtime ↔ desktop app)
├── cache/                   Pre-instrumented code cache
└── vendor-internal/         Bundled PHP-Parser (no Composer required)
```

## Requirements

- PHP 7.4+ (tested up to 8.4)
- `json` extension (ships with PHP by default)
- No external dependencies

## Tests

```bash
php tests/run-all.php
```

Or run individually:

```bash
php tests/NormalizeValueTest.php
php tests/InstrumentCodeAstTest.php
php tests/HttpRequestTest.php
```

CI runs against PHP 7.4, 8.0, 8.1, 8.2, 8.3, and 8.4 on every push and PR.

## License

Source-available. See [LICENSE](LICENSE).