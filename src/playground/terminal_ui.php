<?php
/*
 * DDLess - PHP Debug Engine
 *
 * @author    Jefferson T.S
 * @copyright 2025-2026 DDLess
 * @license   Proprietary
 *
 * Shared terminal UI utilities for the playground test suite.
 * Provides ANSI colors, variable formatting, breakpoint handler,
 * session management, and output filtering.
 */

// ─── STDERR/STDIN fallback (cli-server SAPI does not define these) ───────────

if (!defined('STDERR')) {
    define('STDERR', fopen('php://stderr', 'w'));
}
if (!defined('STDIN')) {
    define('STDIN', fopen('php://stdin', 'r'));
}

// ─── ANSI colors ─────────────────────────────────────────────────────────────

define('CLR_RESET',     "\033[0m");
define('CLR_BOLD',      "\033[1m");
define('CLR_DIM',       "\033[2m");
define('CLR_RED',       "\033[31m");
define('CLR_GREEN',     "\033[32m");
define('CLR_YELLOW',    "\033[33m");
define('CLR_BLUE',      "\033[34m");
define('CLR_CYAN',      "\033[36m");
define('CLR_BG_YELLOW', "\033[43m");
define('CLR_WHITE',     "\033[97m");

// ─── Path resolution ────────────────────────────────────────────────────────

/**
 * Resolve the .ddless directory. Supports two layouts:
 *   - ddless-engine: playground is inside .ddless/ → parent dir has debug.php
 *   - development:   playground is at project root → .ddless/ is a sibling
 */
function ddless_resolve_paths(): array
{
    $playgroundDir = __DIR__;
    $ddlessDir = dirname($playgroundDir);

    // Layout 1: playground inside .ddless/ (ddless-engine)
    if (is_file($ddlessDir . '/debug.php')) {
        $projectRoot = dirname($ddlessDir);

        // ddless-engine cloned as a subdirectory of the actual project
        // (e.g. /var/www/html/ddless-engine/src/playground/)
        // If no composer.json at this level, the real project is one level up
        if (!is_file($projectRoot . '/composer.json') && is_file(dirname($projectRoot) . '/composer.json')) {
            $projectRoot = dirname($projectRoot);
        }

        return [
            'ddlessDir'    => $ddlessDir,
            'projectRoot'  => $projectRoot,
            'playgroundDir' => $playgroundDir,
        ];
    }

    // Layout 2: playground at project root (development)
    $ddlessDir = $playgroundDir . '/../.ddless';
    $ddlessDir = realpath($ddlessDir) ?: $ddlessDir;
    $projectRoot = dirname($playgroundDir);

    if (is_file($ddlessDir . '/debug.php')) {
        return [
            'ddlessDir'    => $ddlessDir,
            'projectRoot'  => $projectRoot,
            'playgroundDir' => $playgroundDir,
        ];
    }

    fwrite(STDERR, CLR_RED . "Error: Cannot find debug.php. Expected at {$ddlessDir}/debug.php" . CLR_RESET . "\n");
    exit(1);
}

// ─── Variable formatting ────────────────────────────────────────────────────

function ddless_terminal_format_value($value, int $indent = 4, int $depth = 0, int $maxDepth = 3): string
{
    $pad = str_repeat(' ', $indent);

    if ($value === null) return CLR_DIM . 'null' . CLR_RESET;
    if ($value === true) return CLR_YELLOW . 'true' . CLR_RESET;
    if ($value === false) return CLR_YELLOW . 'false' . CLR_RESET;
    if (is_int($value) || is_float($value)) return CLR_CYAN . $value . CLR_RESET;
    if (is_string($value)) {
        if (strlen($value) > 120) {
            $value = substr($value, 0, 117) . '...';
        }
        return CLR_GREEN . '"' . $value . '"' . CLR_RESET;
    }
    if (is_array($value)) {
        if (empty($value)) return '[]';
        if ($depth >= $maxDepth) return CLR_DIM . '[...]' . CLR_RESET;

        $isObject = false;
        foreach ($value as $k => $v) {
            if (is_string($k) && $k === '__class__') {
                $isObject = true;
                break;
            }
        }

        $lines = [];
        $count = 0;
        foreach ($value as $k => $v) {
            if ($count >= 8) {
                $lines[] = $pad . '  ' . CLR_DIM . '... (' . (count($value) - $count) . ' more)' . CLR_RESET;
                break;
            }
            $formattedVal = ddless_terminal_format_value($v, $indent + 2, $depth + 1, $maxDepth);
            if (is_string($k)) {
                $lines[] = $pad . '  ' . CLR_DIM . $k . CLR_RESET . ': ' . $formattedVal;
            } else {
                $lines[] = $pad . '  ' . $formattedVal;
            }
            $count++;
        }
        $open = $isObject ? '{ ' : '[ ';
        $close = $isObject ? ' }' : ' ]';
        if (count($lines) <= 3 && $depth < $maxDepth) {
            $inline = [];
            foreach ($value as $k => $v) {
                if (count($inline) >= 5) break;
                $fv = ddless_terminal_format_value($v, 0, $depth + 1, $maxDepth);
                $inline[] = is_string($k) ? (CLR_DIM . $k . CLR_RESET . ': ' . $fv) : $fv;
            }
            return $open . implode(', ', $inline) . $close;
        }
        return "\n" . implode("\n", $lines);
    }

    return CLR_DIM . (string) $value . CLR_RESET;
}

// ─── Terminal breakpoint handler ─────────────────────────────────────────────

/**
 * Register the interactive terminal breakpoint handler.
 * When a breakpoint is hit, debug.php calls this instead of file-based IPC.
 * Redraws the debug block in-place (erases previous, draws new).
 */
function ddless_register_terminal_handler(): void
{
    $GLOBALS['__DDLESS_TERMINAL_LINES__'] = 0;

    $GLOBALS['__DDLESS_TERMINAL_HANDLER__'] = function (array $payload, string $file): string {
        $relativePath = $payload['relativeFile'] ?? basename($file);
        $line = $payload['line'] ?? 0;
        $variables = $payload['variables'] ?? [];
        $callStack = $payload['callStack'] ?? [];

        $drawDebugView = function () use ($relativePath, $line, $variables, $callStack, $file) {
            $prevLines = $GLOBALS['__DDLESS_TERMINAL_LINES__'] ?? 0;
            if ($prevLines > 0) {
                fwrite(STDERR, "\033[{$prevLines}A\033[J");
            }

            $output = [];
            $output[] = '';
            $output[] = CLR_BOLD . CLR_YELLOW . "  ● Breakpoint" . CLR_RESET . " at "
                . CLR_CYAN . $relativePath . CLR_RESET . ":" . CLR_BOLD . $line . CLR_RESET;

            if (!empty($callStack) && count($callStack) > 1) {
                $stackLabels = [];
                foreach (array_slice($callStack, 0, 5) as $frame) {
                    $stackLabels[] = CLR_DIM . ($frame['label'] ?? '?') . CLR_RESET;
                }
                $output[] = CLR_DIM . "  Stack: " . CLR_RESET . implode(CLR_DIM . ' → ' . CLR_RESET, $stackLabels);
            }

            @stream_wrapper_restore('file');
            $content = @file_get_contents($file);
            @stream_wrapper_unregister('file');
            @stream_wrapper_register('file', 'DDLessSafeIncludeWrapper', 0);

            if ($content !== false) {
                $srcLines = explode("\n", $content);
                $context = 4;
                $start = max(0, $line - $context - 1);
                $end = min(count($srcLines), $line + $context);

                $output[] = CLR_DIM . "  ──────────────────────────────────────────" . CLR_RESET;
                for ($i = $start; $i < $end; $i++) {
                    $lineNum = $i + 1;
                    $numStr = str_pad((string) $lineNum, 4, ' ', STR_PAD_LEFT);
                    $codeLine = rtrim($srcLines[$i]);

                    if ($lineNum === $line) {
                        $output[] = CLR_BG_YELLOW . CLR_WHITE . "  {$numStr}" . CLR_RESET
                            . CLR_BG_YELLOW . CLR_WHITE . "│ " . CLR_RESET
                            . CLR_BOLD . " {$codeLine}" . CLR_RESET . "  ◄";
                    } else {
                        $output[] = CLR_DIM . "  {$numStr}│ " . CLR_RESET . "{$codeLine}";
                    }
                }
                $output[] = CLR_DIM . "  ──────────────────────────────────────────" . CLR_RESET;
            }

            $output[] = '';
            fwrite(STDERR, implode("\n", $output) . "\n");
            $GLOBALS['__DDLESS_TERMINAL_LINES__'] = count($output) + 2;
        };

        $drawVarsView = function () use ($relativePath, $line, $variables) {
            $prevLines = $GLOBALS['__DDLESS_TERMINAL_LINES__'] ?? 0;
            if ($prevLines > 0) {
                fwrite(STDERR, "\033[{$prevLines}A\033[J");
            }

            $output = [];
            $output[] = '';
            $output[] = CLR_BOLD . CLR_BLUE . "  ◈ Variables" . CLR_RESET . " at "
                . CLR_CYAN . $relativePath . CLR_RESET . ":" . CLR_BOLD . $line . CLR_RESET;
            $output[] = CLR_DIM . "  ──────────────────────────────────────────" . CLR_RESET;

            if (empty($variables)) {
                $output[] = CLR_DIM . "    (no variables in scope)" . CLR_RESET;
            } else {
                foreach ($variables as $name => $value) {
                    if ($name === '__ddless_notice__') {
                        $output[] = CLR_DIM . "    ({$value})" . CLR_RESET;
                        continue;
                    }
                    $formatted = ddless_terminal_format_value($value, 4, 0, 5);
                    $output[] = "    " . CLR_BLUE . '$' . $name . CLR_RESET . " = " . $formatted;
                }
            }

            $output[] = CLR_DIM . "  ──────────────────────────────────────────" . CLR_RESET;
            $output[] = '';
            fwrite(STDERR, implode("\n", $output) . "\n");
            $GLOBALS['__DDLESS_TERMINAL_LINES__'] = count($output) + 2;
        };

        $promptLine = "  " . CLR_DIM . "[" . CLR_RESET . CLR_BOLD . "c" . CLR_RESET . CLR_DIM
            . "]ontinue  [" . CLR_RESET . CLR_BOLD . "n" . CLR_RESET . CLR_DIM
            . "]ext  [" . CLR_RESET . CLR_BOLD . "s" . CLR_RESET . CLR_DIM
            . "]tep  [" . CLR_RESET . CLR_BOLD . "o" . CLR_RESET . CLR_DIM
            . "]ut  [" . CLR_RESET . CLR_BOLD . "d" . CLR_RESET . CLR_DIM
            . "]ebug  [" . CLR_RESET . CLR_BOLD . "q" . CLR_RESET . CLR_DIM
            . "]uit" . CLR_RESET . " > ";

        $showingVars = false;
        $drawDebugView();

        while (true) {
            fwrite(STDERR, $promptLine);

            $input = trim((string) fgets(STDIN));
            if ($input === false || $input === '') $input = 'n';

            switch (strtolower($input)) {
                case 'c': case 'continue': return 'continue';
                case 'n': case 'next':     return 'next';
                case 's': case 'step': case 'step_in':  return 'step_in';
                case 'o': case 'out':  case 'step_out': return 'step_out';
                case 'd': case 'debug':
                    $showingVars = !$showingVars;
                    $showingVars ? $drawVarsView() : $drawDebugView();
                    break;
                case 'q': case 'quit': case 'exit':
                    fwrite(STDERR, CLR_YELLOW . "  Debug session ended." . CLR_RESET . "\n");
                    exit(0);
                default:
                    $GLOBALS['__DDLESS_TERMINAL_LINES__'] += 2;
                    fwrite(STDERR, CLR_RED . "  Unknown command: {$input}" . CLR_RESET . "\n");
                    break;
            }
        }
    };
}

// ─── Session management ─────────────────────────────────────────────────────

function ddless_setup_session(string $ddlessDir, array $breakpointMap, int $depth): array
{
    $sessionId = 'test-' . getmypid() . '-' . time();
    $sessionDir = $ddlessDir . '/sessions/' . $sessionId;
    @mkdir($sessionDir, 0755, true);

    $breakpointsPayload = [
        'breakpoints' => $breakpointMap,
        'conditions'  => [],
        'settings'    => [
            'serializeDepth'  => $depth,
            'allowGlobalScope' => true,
        ],
    ];

    file_put_contents(
        $sessionDir . '/breakpoints.json',
        json_encode($breakpointsPayload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
    );

    putenv('DDLESS_DEBUG_MODE=true');
    putenv('DDLESS_DEBUG_SESSION=' . $sessionId);
    putenv('DDLESS_CLI_MODE=true');
    $_ENV['DDLESS_DEBUG_MODE'] = 'true';
    $_ENV['DDLESS_DEBUG_SESSION'] = $sessionId;
    $GLOBALS['__DDLESS_CLI_MODE__'] = true;

    return ['sessionId' => $sessionId, 'sessionDir' => $sessionDir];
}

function ddless_cleanup_session(string $sessionDir, ?string $tempCodeFile = null): void
{
    if ($tempCodeFile !== null && is_file($tempCodeFile)) {
        @unlink($tempCodeFile);
    }
    @unlink($sessionDir . '/breakpoints.json');
    @unlink($sessionDir . '/breakpoint_state.json');
    @unlink($sessionDir . '/breakpoint_command.json');
    @rmdir($sessionDir);
}

// ─── Output filtering ───────────────────────────────────────────────────────

function ddless_terminal_output_filter(string $buffer): string
{
    $lines = explode("\n", $buffer);
    $clean = [];
    foreach ($lines as $line) {
        if (str_starts_with($line, '__DDLESS_')) continue;
        $clean[] = $line;
    }
    return implode("\n", $clean);
}

// ─── Debug engine loader ────────────────────────────────────────────────────

function ddless_load_engine(string $ddlessDir, string $projectRoot): void
{
    ob_start();
    require_once $ddlessDir . '/debug.php';
    ob_end_clean();

    $composerAutoload = $projectRoot . '/vendor/autoload.php';
    if (is_file($composerAutoload)) {
        require_once $composerAutoload;
    }

    if (function_exists('ddless_register_stream_wrapper')) {
        ddless_register_stream_wrapper();
    }
}

// ─── Result display ─────────────────────────────────────────────────────────

function ddless_display_result(array $result): void
{
    if (isset($result['exception'])) {
        $ex = $result['exception'];
        fwrite(STDERR, "\n" . CLR_BOLD . CLR_YELLOW . "  ⚠ " . ($ex['class'] ?? 'Exception') . CLR_RESET . "\n");
        fwrite(STDERR, "    " . ($ex['message'] ?? '') . "\n");
        if (isset($ex['statusCode'])) {
            fwrite(STDERR, CLR_DIM . "    Status: " . CLR_RESET . $ex['statusCode'] . "\n");
        }
        if (isset($ex['errors']) && is_array($ex['errors'])) {
            foreach ($ex['errors'] as $field => $msgs) {
                foreach ((array)$msgs as $msg) {
                    fwrite(STDERR, CLR_RED . "    • {$field}: {$msg}" . CLR_RESET . "\n");
                }
            }
        }
    } elseif (isset($result['error'])) {
        fwrite(STDERR, "\n" . CLR_RED . CLR_BOLD . "  ✖ Error" . CLR_RESET . ": " . $result['error'] . "\n");
    } elseif (isset($result['result'])) {
        fwrite(STDERR, "\n" . CLR_GREEN . CLR_BOLD . "  ✔ Result:" . CLR_RESET . "\n");
        $formatted = ddless_terminal_format_value($result['result']);
        fwrite(STDERR, "    " . $formatted . "\n");
    }

    if (isset($result['durationMs'])) {
        fwrite(STDERR, CLR_DIM . "    Duration: " . $result['durationMs'] . "ms" . CLR_RESET . "\n");
    }
}

function ddless_display_exception(\Throwable $e): void
{
    fwrite(STDERR, "\n" . CLR_RED . CLR_BOLD . "  ✖ " . get_class($e) . CLR_RESET . ": " . $e->getMessage() . "\n");
    fwrite(STDERR, CLR_DIM . "    at " . $e->getFile() . ":" . $e->getLine() . CLR_RESET . "\n");

    $trace = $e->getTrace();
    $shown = 0;
    foreach ($trace as $frame) {
        $traceFile = $frame['file'] ?? '';
        if (str_contains($traceFile, 'vendor') || str_contains($traceFile, '.ddless') || str_contains($traceFile, 'playground')) continue;
        $traceFunc = ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? '?');
        fwrite(STDERR, CLR_DIM . "    → {$traceFunc} at " . basename($traceFile) . ":" . ($frame['line'] ?? '?') . CLR_RESET . "\n");
        $shown++;
        if ($shown >= 8) break;
    }
}
