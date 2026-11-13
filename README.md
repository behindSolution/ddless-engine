# ddless-engine

![Tests](https://github.com/behindSolution/ddless-engine/actions/workflows/tests.yml/badge.svg)

The PHP runtime engine behind [DDLess](https://ddless.com) — a visual step-through debugger for PHP.

This repo contains the engine only. The desktop app, UI, and AI Copilot are available at [ddless.com](https://ddless.com).

## What it does

ddless-engine parses PHP source via AST ([nikic/PHP-Parser](https://github.com/nikic/PHP-Parser)), injects `ddless_step_check()` calls before each executable line using a custom stream wrapper, and communicates with the desktop app through file-based IPC. Your original files are never modified on disk.

Supports breakpoints (conditional, logpoints, dumppoints), step-in/over/out, watch expressions, variable inspection, trace mode, and timeframe (record every executed line, then replay to any point).

## Frameworks

CakePHP · CodeIgniter 4 · Drupal · Laravel · Slim 4 · Symfony · Tempest · Vanilla PHP · WordPress · Yii2

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
│   ├── cakephp/
│   ├── codeigniter/
│   ├── drupal/
│   ├── laravel/
│   ├── php/
│   ├── slim/
│   ├── symfony/
│   ├── tempest/
│   ├── wordpress/
│   └── yii2/
├── playground/              Terminal debugger to exercise the engine without the desktop app
├── sessions/                File-based IPC (runtime ↔ desktop app) — created at runtime, git-ignored
├── cache/                   Pre-instrumented code cache — created at runtime, git-ignored
└── vendor-internal/         Bundled PHP-Parser (no Composer required)
```

## Try it

No desktop app and no project needed — a bare clone is enough:

```bash
php src/playground/test_trigger.php --code '$x = 1; $y = 2; echo $x + $y;' --bp 1
```

It pauses on the line and shows a prompt: `c` continue, `n` next, `s` step in, `o` step out, `v` variables, `q` quit.

The playground also drives the method executor, the task runner and the HTTP pipeline of each framework. See [CONTRIBUTING.md](CONTRIBUTING.md) and [src/playground/README.md](src/playground/README.md).

## Requirements

- PHP 7.4+ (tested up to 8.5)
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

CI runs against PHP 7.4+ on every push and PR.

## License

Source-available. See [LICENSE](LICENSE).