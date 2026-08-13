<?php
/*
 * DDLess - PHP Debug Engine
 *
 * @author    Jefferson T.S
 * @copyright 2025-2026 DDLess
 * @license   Proprietary
 *
 * Core debug engine: breakpoint management, code instrumentation via AST,
 * stream wrapper for in-memory code serving, and file-based IPC for
 * cross-environment debugging (Docker, WSL, SSH).
 */

// STDOUT/STDERR constants only exist in CLI SAPI. Under php -S (built-in
// web server) they are undefined. Define fallbacks so the rest of the
// engine can use them unconditionally.
if (!defined('STDOUT')) { define('STDOUT', fopen('php://stdout', 'w')); }
if (!defined('STDERR')) { define('STDERR', fopen('php://stderr', 'w')); }

// In php -S (cli-server SAPI), STDOUT writes to the HTTP response body.
// Redirect DDLess IPC messages to STDERR so they go to the process stderr
// (captured by local-server.ts / ssh-proxy-server.ts) instead of corrupting responses.
$GLOBALS['__DDLESS_IPC_STREAM__'] = (php_sapi_name() === 'cli-server') ? STDERR : STDOUT;

// CLI mode suppresses verbose diagnostic output to keep terminal clean
$GLOBALS['__DDLESS_CLI_MODE__'] = (bool) getenv('DDLESS_CLI_MODE');

function ddless_log(string $msg): void {
    if (!$GLOBALS['__DDLESS_CLI_MODE__']) {
        fwrite(STDERR, $msg);
    }
}

ddless_log("[ddless] debug.php loaded, DEBUG_MODE=" . (getenv('DDLESS_DEBUG_MODE') ?: 'not set') . "\n");

function ddless_safe_send_response($response): void {
    if (php_sapi_name() !== 'cli-server') {
        $response->send();
        return;
    }

    $isBinary = $response instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse;

    if ($isBinary) {
        $file = $response->getFile();
        $content = file_get_contents($file->getPathname());
        $response->headers->set('Content-Type', $response->headers->get('Content-Type'));
        foreach ($response->headers->allPreserveCase() as $name => $values) {
            if (strtolower($name) === 'content-length') continue;
            foreach ((array) $values as $v) {
                header("{$name}: {$v}", strtolower($name) !== 'set-cookie');
            }
        }
        http_response_code($response->getStatusCode());
        header('Content-Length: ' . strlen($content));
        echo $content;
    } else {
        $response->headers->remove('Content-Length');
        $response->send();
    }
}

// PHP 7.4 compatibility polyfills for PHP 8.0+ string functions
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool {
        return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool {
        return $needle === '' || ($haystack !== '' && substr_compare($haystack, $needle, -strlen($needle)) === 0);
    }
}

if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}

// Memory limit — will be overridden by user setting from breakpoints.json if available
ini_set('memory_limit', '5G');  // Default: 5G

// PSR-4 autoloader for nikic/PHP-Parser (bundled in vendor-internal)
spl_autoload_register(function (string $class): void {
    $prefix = 'PhpParser\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) return;
    $relativeClass = substr($class, strlen($prefix));
    $file = __DIR__ . '/vendor-internal/nikic/php-parser/lib/PhpParser/'
          . str_replace('\\', '/', $relativeClass) . '.php';
    if (is_file($file)) require $file;
});

if (!defined('DDLESS_PROJECT_ROOT')) {
    define('DDLESS_PROJECT_ROOT', realpath(dirname(__DIR__)));
}

function ddless_is_debug_mode_active(): bool
{
    return getenv('DDLESS_DEBUG_MODE') === 'true';
}

function ddless_get_session_dir(): string
{
    $sessionId = getenv('DDLESS_DEBUG_SESSION') ?: 'default';
    $sessionDir = __DIR__ . '/sessions/' . $sessionId;

    if (!is_dir($sessionDir)) {
        @mkdir($sessionDir, 0755, true);
    }

    return $sessionDir;
}

function ddless_get_breakpoint_state_file(): string
{
    return ddless_get_session_dir() . '/breakpoint_state.json';
}

function ddless_get_breakpoint_command_file(): string
{
    return ddless_get_session_dir() . '/breakpoint_command.json';
}

function ddless_get_breakpoints_file(): string
{
    $sessionFile = ddless_get_session_dir() . '/breakpoints.json';

    if (is_file($sessionFile)) {
        return $sessionFile;
    }

    return __DIR__ . '/breakpoints.json';
}

function ddless_write_breakpoint_state(array $payload): bool
{
    $stateFile = ddless_get_breakpoint_state_file();
    $commandFile = ddless_get_breakpoint_command_file();

    @unlink($commandFile);

    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) {
        ddless_log("[ddless] Failed to encode breakpoint state\n");
        return false;
    }

    $written = @file_put_contents($stateFile, $json, LOCK_EX);
    if ($written === false) {
        ddless_log("[ddless] Failed to write breakpoint state file\n");
        return false;
    }

    return true;
}

function ddless_wait_for_command(int $timeoutSeconds = 0): ?array
{
    if ($timeoutSeconds <= 0) {
        $timeoutSeconds = $GLOBALS['__DDLESS_BREAKPOINT_TIMEOUT__'] ?? 3600;
    }

    $commandFile = ddless_get_breakpoint_command_file();
    $startTime = time();
    $pollInterval = 50000; // 50ms in microseconds
    $loggedWaiting = false;

    while ((time() - $startTime) < $timeoutSeconds) {
        clearstatcache(true, $commandFile);

        if (is_file($commandFile)) {
            usleep(10000); // 10ms

            $content = @file_get_contents($commandFile);
            if ($content !== false && $content !== '') {
                $data = json_decode($content, true);
                if ($data !== null) {
                    @unlink($commandFile);
                    ddless_log("[ddless] Command received: " . json_encode($data) . "\n");
                    return $data;
                } else {
                    ddless_log("[ddless] Failed to parse command JSON: " . substr($content, 0, 100) . "\n");
                }
            }
        } else if (!$loggedWaiting) {
            ddless_log("[ddless] Waiting for command file: {$commandFile}\n");
            $loggedWaiting = true;
        }

        usleep($pollInterval);
    }

    ddless_log("[ddless] Timeout waiting for debug command\n");
    return null;
}

function ddless_cleanup_debug_files(): void
{
    @unlink(ddless_get_breakpoint_state_file());
    @unlink(ddless_get_breakpoint_command_file());
}

$GLOBALS['__DDLESS_BREAKPOINT_FILES__'] = [];
$GLOBALS['__DDLESS_USER_BP_LINES__'] = []; // Track which lines are user breakpoints
$GLOBALS['__DDLESS_ONDEMAND_TRIED__'] = []; // Track files we've tried to instrument on-demand
$GLOBALS['__DDLESS_HIT_USER_BPS__'] = []; // Track user breakpoints already hit (don't stop twice after Continue)

// Settings
$GLOBALS['__DDLESS_SERIALIZE_DEPTH__'] = 4; // Default serialize depth for variables
$GLOBALS['__DDLESS_ALLOW_GLOBAL_SCOPE__'] = false; // Experimental: instrument code outside function bodies
$GLOBALS['__DDLESS_BREAKPOINT_TIMEOUT__'] = 3600; // Default: 60 minutes (in seconds)

// Lazy expand: emit clickable markers for objects past the depth ceiling
// instead of "[object Foo]" so the frontend can request deeper levels on demand.
$GLOBALS['__DDLESS_LAZY_EXPAND_ENABLED__'] = true;
$GLOBALS['__DDLESS_EAGER_EXPAND_ENABLED__'] = false;
$GLOBALS['__DDLESS_LAZY_REGISTRY__'] = [];
$GLOBALS['__DDLESS_LAZY_BY_OID__'] = [];
$GLOBALS['__DDLESS_LAZY_TOKEN_COUNTER__'] = 0;
$GLOBALS['__DDLESS_LAZY_REGISTRY_LIMIT__'] = 5000;

$GLOBALS['__DDLESS_MODIFIED_VARS__'] = null; // Playground: modified variables to extract back into scope

$GLOBALS['__DDLESS_STEP_OVER_FUNCTION__'] = null;
$GLOBALS['__DDLESS_STEP_OVER_FILE__'] = null;
$GLOBALS['__DDLESS_STEP_IN_MODE__'] = false; // Step In: stop on any next line (enters functions)
$GLOBALS['__DDLESS_STEP_OUT_TARGET__'] = null; // Step Out: stop when we return to this function

// Timeframe (time travel). Opt-in — it instruments every instrumentable line
// and serializes scope on each one, so it is deliberately heavier than a normal
// debug run. See the TIMEFRAME section further down for the position model.
$GLOBALS['__DDLESS_TIMEFRAME_MODE__'] = getenv('DDLESS_TIMEFRAME') === 'true';
$GLOBALS['__DDLESS_TF_DETAIL__'] = getenv('DDLESS_TIMEFRAME_DETAIL') ?: 'normal'; // light | normal | full
$GLOBALS['__DDLESS_TF_SEQ__'] = 0;
$GLOBALS['__DDLESS_TF_HANDLE__'] = null;
$GLOBALS['__DDLESS_TF_SCOPE_STACK__'] = []; // depth => ['fn' => label, 'vars' => [...]] for delta encoding
$GLOBALS['__DDLESS_TF_FRAMES_WRITTEN__'] = 0;
$GLOBALS['__DDLESS_TF_TRUNCATED__'] = false;
$GLOBALS['__DDLESS_TF_MAX_FRAMES__'] = 200000; // Runaway guard (infinite loop in user code)
$GLOBALS['__DDLESS_TF_MAX_VARS__'] = 60;
// Optional trigger: stay idle until execution enters this file (see ddless_tf_should_record).
$GLOBALS['__DDLESS_TF_START_FILE__'] = str_replace('\\', '/', trim((string) getenv('DDLESS_TIMEFRAME_START')));
$GLOBALS['__DDLESS_TF_ARMED__'] = false;
// 'from' (default) records onward from the start file; 'only' records just it.
$GLOBALS['__DDLESS_TF_SCOPE_MODE__'] = getenv('DDLESS_TIMEFRAME_SCOPE') === 'only' ? 'only' : 'from';
// Only when recording: the proxy loads this on every request, and a page load
// would turn an always-on line into noise.
if ($GLOBALS['__DDLESS_TIMEFRAME_MODE__']) {
    ddless_log('[ddless] Timeframe: recording'
        . ($GLOBALS['__DDLESS_TF_START_FILE__'] !== ''
            ? ", filter={$GLOBALS['__DDLESS_TF_SCOPE_MODE__']}:{$GLOBALS['__DDLESS_TF_START_FILE__']}"
            : ' (no filter)')
        . ', sapi=' . php_sapi_name() . "
");
}

// Position identity: "<file>:<line>" => hit count. Shared by the recorder and
// the replay gate — the nth hit of a line is what makes a frame addressable.
$GLOBALS['__DDLESS_HIT_COUNTS__'] = [];

// Replay: run silently until this position, then pause there like a breakpoint.
$GLOBALS['__DDLESS_REPLAY_TARGET__'] = null;
$GLOBALS['__DDLESS_IS_REPLAY__'] = false;

$GLOBALS['__DDLESS_TRACE_MODE__'] = getenv('DDLESS_TRACE_MODE') === 'true';
$GLOBALS['__DDLESS_TRACE_TRIED__'] = []; // Track files we've tried to trace-instrument
$GLOBALS['__DDLESS_TRACE_STARTS__'] = []; // Trace entry timestamps keyed by seq (for duration calculation)
$GLOBALS['__DDLESS_TRACE_REQUEST_START__'] = $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true);
$GLOBALS['__DDLESS_LOGPOINT_DEDUP__'] = []; // Dedup: file:line:message => last emit microtime

$__ddless_session_id = getenv('DDLESS_DEBUG_SESSION') ?: 'default';
$__ddless_session_dir = __DIR__ . '/sessions/' . $__ddless_session_id;
$__ddless_session_bp_file = $__ddless_session_dir . '/breakpoints.json';
ddless_log("[ddless] Session ID: {$__ddless_session_id}\n");
ddless_log("[ddless] Checking session breakpoints at: {$__ddless_session_bp_file}\n");
ddless_log("[ddless] Session file exists: " . (is_file($__ddless_session_bp_file) ? 'yes' : 'no') . "\n");
ddless_log("[ddless] Session dir exists: " . (is_dir($__ddless_session_dir) ? 'yes' : 'no') . "\n");

$__ddless_breakpoints_file = ddless_get_breakpoints_file();
ddless_log("[ddless] Loading breakpoints from: {$__ddless_breakpoints_file}\n");
if (is_file($__ddless_breakpoints_file)) {
    $__ddless_content = file_get_contents($__ddless_breakpoints_file);
    ddless_log("[ddless] Breakpoints file content: " . ($__ddless_content ?: "(empty)") . "\n");
    if ($__ddless_content !== false) {
        $__ddless_raw = json_decode($__ddless_content, true) ?: [];

        if (isset($__ddless_raw['breakpoints']) && is_array($__ddless_raw['breakpoints'])) {
            $__ddless_bp_map = $__ddless_raw['breakpoints'];
            $__ddless_cond_map = $__ddless_raw['conditions'] ?? [];
            $__ddless_settings = $__ddless_raw['settings'] ?? [];
            if (isset($__ddless_settings['serializeDepth']) && is_numeric($__ddless_settings['serializeDepth'])) {
                $GLOBALS['__DDLESS_SERIALIZE_DEPTH__'] = max(1, min(20, (int)$__ddless_settings['serializeDepth']));
            }
            if (isset($__ddless_settings['allowGlobalScope'])) {
                $GLOBALS['__DDLESS_ALLOW_GLOBAL_SCOPE__'] = (bool)$__ddless_settings['allowGlobalScope'];
            }
            if (isset($__ddless_settings['breakpointTimeout']) && is_numeric($__ddless_settings['breakpointTimeout'])) {
                $GLOBALS['__DDLESS_BREAKPOINT_TIMEOUT__'] = max(1, (int)$__ddless_settings['breakpointTimeout']) * 60; // Convert minutes to seconds
            }
            if (isset($__ddless_settings['memoryLimit']) && is_numeric($__ddless_settings['memoryLimit'])) {
                $limitMb = max(256, (int)$__ddless_settings['memoryLimit']);
                ini_set('memory_limit', ($limitMb >= 1024 ? intdiv($limitMb, 1024) . 'G' : $limitMb . 'M'));
            }
        } else {
            $__ddless_bp_map = $__ddless_raw;
            $__ddless_cond_map = [];
        }

        $GLOBALS['__DDLESS_WATCHES__'] = [];
        if (isset($__ddless_raw['watches']) && is_array($__ddless_raw['watches'])) {
            foreach ($__ddless_raw['watches'] as $expr) {
                if (is_string($expr) && $expr !== '') {
                    $GLOBALS['__DDLESS_WATCHES__'][] = $expr;
                }
            }
        }
        if (!empty($GLOBALS['__DDLESS_WATCHES__'])) {
            ddless_log("[ddless] Loaded " . count($GLOBALS['__DDLESS_WATCHES__']) . " watch expressions\n");
        }

        foreach ($__ddless_bp_map as $relativePath => $lines) {
            if (!is_array($lines)) continue;
            $normalizedPath = str_replace('\\', '/', ltrim($relativePath, '/\\'));
            $absolutePath = DDLESS_PROJECT_ROOT . '/' . $normalizedPath;

            $validLines = [];
            foreach ($lines as $line) {
                if (is_numeric($line) && (int)$line > 0) {
                    $validLines[] = (int)$line;
                }
            }

            $fileConditions = [];
            if (isset($__ddless_cond_map[$relativePath]) && is_array($__ddless_cond_map[$relativePath])) {
                foreach ($__ddless_cond_map[$relativePath] as $condLine => $condExpr) {
                    if (is_string($condExpr) && $condExpr !== '') {
                        $fileConditions[(int)$condLine] = $condExpr;
                    }
                }
            }

            $__ddless_log_map = $__ddless_raw['logpoints'] ?? [];
            $fileLogpoints = [];
            if (isset($__ddless_log_map[$relativePath]) && is_array($__ddless_log_map[$relativePath])) {
                foreach ($__ddless_log_map[$relativePath] as $logLine => $logExpr) {
                    if (is_string($logExpr) && $logExpr !== '') {
                        $fileLogpoints[(int)$logLine] = $logExpr;
                    }
                }
            }

            $__ddless_dump_map = $__ddless_raw['dumppoints'] ?? [];
            $fileDumppoints = [];
            if (isset($__ddless_dump_map[$relativePath]) && is_array($__ddless_dump_map[$relativePath])) {
                foreach ($__ddless_dump_map[$relativePath] as $dumpLine => $dumpExpr) {
                    if (is_string($dumpExpr) && $dumpExpr !== '') {
                        $fileDumppoints[(int)$dumpLine] = $dumpExpr;
                    }
                }
            }

            if (!empty($validLines)) {
                $bpEntry = [
                    'relativePath' => $normalizedPath,
                    'lines' => array_unique($validLines),
                ];
                if (!empty($fileConditions)) {
                    $bpEntry['conditions'] = $fileConditions;
                }
                if (!empty($fileLogpoints)) {
                    $bpEntry['logpoints'] = $fileLogpoints;
                }
                if (!empty($fileDumppoints)) {
                    $bpEntry['dumppoints'] = $fileDumppoints;
                }

                $__ddless_conddump_map = $__ddless_raw['conditionalDumppoints'] ?? [];
                $fileConditionalDumppoints = [];
                if (isset($__ddless_conddump_map[$relativePath]) && is_array($__ddless_conddump_map[$relativePath])) {
                    foreach ($__ddless_conddump_map[$relativePath] as $cdLine => $cdData) {
                        if (is_array($cdData) && isset($cdData['condition'], $cdData['expressions'])
                            && is_string($cdData['condition']) && $cdData['condition'] !== ''
                            && is_string($cdData['expressions']) && $cdData['expressions'] !== '') {
                            $fileConditionalDumppoints[(int)$cdLine] = $cdData;
                        }
                    }
                }
                if (!empty($fileConditionalDumppoints)) {
                    $bpEntry['conditionalDumppoints'] = $fileConditionalDumppoints;
                }

                $GLOBALS['__DDLESS_BREAKPOINT_FILES__'][$absolutePath] = $bpEntry;

                $GLOBALS['__DDLESS_BREAKPOINT_FILES__'][realpath($absolutePath) ?: $absolutePath] = $bpEntry;
            }
        }
    }
} else {
    ddless_log("[ddless] WARNING: Breakpoints file not found at: {$__ddless_breakpoints_file}\n");
}

if (!empty($GLOBALS['__DDLESS_BREAKPOINT_FILES__'])) {
    ddless_log("[ddless] Loaded " . count($GLOBALS['__DDLESS_BREAKPOINT_FILES__']) . " files with breakpoints\n");
    foreach ($GLOBALS['__DDLESS_BREAKPOINT_FILES__'] as $path => $info) {
        ddless_log("[ddless]   - {$info['relativePath']}: lines " . implode(', ', $info['lines']) . "\n");
    }
} else {
    ddless_log("[ddless] No breakpoints defined - execution will run without stopping\n");
}

// Uses full AST for accurate line identification, textual injection
class DDLessInstrumentableLineVisitor extends \PhpParser\NodeVisitorAbstract
{
    /** @var array<int, array{type: string, isUserBp: bool}> */
    private array $instrumentableLines = [];

    /** @var int[] User-defined breakpoint line numbers */
    private array $userBreakpointLines = [];

    /** @var int Depth counter for function body nesting */
    private int $functionBodyDepth = 0;

    /** @var bool Whether to instrument code outside function bodies */
    private bool $allowGlobalScope = false;

    /** @var int[] Stack of function/method declaration start lines (for overlap detection) */
    private array $functionStartLineStack = [];

    public function __construct(array $userBreakpointLines = [], bool $allowGlobalScope = false)
    {
        $this->userBreakpointLines = $userBreakpointLines;
        $this->allowGlobalScope = $allowGlobalScope;
    }

    public function enterNode(\PhpParser\Node $node)
    {
        if ($node instanceof \PhpParser\Node\Stmt\Function_
            || $node instanceof \PhpParser\Node\Stmt\ClassMethod
            || $node instanceof \PhpParser\Node\Expr\Closure
            || $node instanceof \PhpParser\Node\Expr\ArrowFunction
        ) {
            $this->functionBodyDepth++;
            $this->functionStartLineStack[] = $node->getStartLine();
            return null;
        }

        if ($this->functionBodyDepth <= 0 && !$this->allowGlobalScope) {
            return null;
        }

        $line = $node->getStartLine();
        if ($line < 1) {
            return null;
        }

        // (e.g. single-line methods: public function foo() { return 42; })
        if (!empty($this->functionStartLineStack)
            && $line === end($this->functionStartLineStack)
        ) {
            return null;
        }

        if (isset($this->instrumentableLines[$line])) {
            return null;
        }

        $type = $this->classifyNode($node);
        if ($type !== null) {
            $isUserBp = in_array($line, $this->userBreakpointLines, true);
            $this->instrumentableLines[$line] = [
                'type' => $type,
                'isUserBp' => $isUserBp,
            ];
        }

        return null;
    }

    public function leaveNode(\PhpParser\Node $node)
    {
        if ($node instanceof \PhpParser\Node\Stmt\Function_
            || $node instanceof \PhpParser\Node\Stmt\ClassMethod
            || $node instanceof \PhpParser\Node\Expr\Closure
            || $node instanceof \PhpParser\Node\Expr\ArrowFunction
        ) {
            $this->functionBodyDepth--;
            array_pop($this->functionStartLineStack);
        }
        return null;
    }

    private function classifyNode(\PhpParser\Node $node): ?string
    {
        // Statements — inject BEFORE the line
        if ($node instanceof \PhpParser\Node\Stmt\Expression
            || $node instanceof \PhpParser\Node\Stmt\Return_
            || $node instanceof \PhpParser\Node\Stmt\Break_
            || $node instanceof \PhpParser\Node\Stmt\Continue_
            || $node instanceof \PhpParser\Node\Stmt\Echo_
            || $node instanceof \PhpParser\Node\Stmt\Unset_
            || $node instanceof \PhpParser\Node\Stmt\Global_
        ) {
            return 'statement';
        }

        // Control structures — inject BEFORE the line
        if ($node instanceof \PhpParser\Node\Stmt\If_
            || $node instanceof \PhpParser\Node\Stmt\For_
            || $node instanceof \PhpParser\Node\Stmt\Foreach_
            || $node instanceof \PhpParser\Node\Stmt\While_
            || $node instanceof \PhpParser\Node\Stmt\Do_
            || $node instanceof \PhpParser\Node\Stmt\Switch_
            || $node instanceof \PhpParser\Node\Stmt\TryCatch
        ) {
            return 'control';
        }

        // ElseIf — special injection into condition
        if ($node instanceof \PhpParser\Node\Stmt\ElseIf_) {
            return 'elseif';
        }

        // Else / Catch / Finally — inject INSIDE the block (only for user BPs)
        if ($node instanceof \PhpParser\Node\Stmt\Else_) {
            return 'else';
        }
        if ($node instanceof \PhpParser\Node\Stmt\Catch_) {
            return 'catch';
        }
        if ($node instanceof \PhpParser\Node\Stmt\Finally_) {
            return 'finally';
        }

        return null;
    }

    /** @return array<int, array{type: string, isUserBp: bool}> */
    public function getInstrumentableLines(): array
    {
        return $this->instrumentableLines;
    }
}

/**
 * Analyze PHP code using AST (nikic/PHP-Parser) and return instrumentable lines.
 *
 * @param string $code PHP source code
 * @param int[] $userBreakpointLines Line numbers with user-defined breakpoints
 * @return array<int, array{type: string, isUserBp: bool}>
 */
function ddless_analyze_code_ast(string $code, array $userBreakpointLines = []): array
{
    $prevTimeLimit = (int) ini_get('max_execution_time');
    set_time_limit(0);

    try {
        if (!isset($GLOBALS['__DDLESS_PHP_PARSER__'])) {
            $GLOBALS['__DDLESS_PHP_PARSER__'] = (new \PhpParser\ParserFactory())
                ->createForHostVersion();
        }
        $parser = $GLOBALS['__DDLESS_PHP_PARSER__'];

        $errorHandler = new \PhpParser\ErrorHandler\Collecting();

        try {
            $stmts = $parser->parse($code, $errorHandler);
        } catch (\Throwable $e) {
            ddless_log("[ddless] AST parse error: " . $e->getMessage() . "\n");
            return [];
        }

        if ($stmts === null) {
            ddless_log("[ddless] AST parse returned null (fatal syntax error)\n");
            return [];
        }

        $allowGlobalScope = !empty($GLOBALS['__DDLESS_ALLOW_GLOBAL_SCOPE__']);
        $visitor = new DDLessInstrumentableLineVisitor($userBreakpointLines, $allowGlobalScope);
        $traverser = new \PhpParser\NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($stmts);

        $lines = $visitor->getInstrumentableLines();

        $lines = ddless_resolve_user_breakpoints($lines, $userBreakpointLines);

        return $lines;
    } finally {
        set_time_limit($prevTimeLimit);
    }
}

/**
 * Resolve user breakpoints against instrumentable lines from the AST.
 * - BP on instrumentable line → mark isUserBp = true
 * - BP on non-instrumentable (continuation) line → find nearest instrumentable line BEFORE
 * - BP with no match → ignore
 *
 * @param array<int, array{type: string, isUserBp: bool}> $instrumentableLines
 * @param int[] $userBpLines
 * @return array<int, array{type: string, isUserBp: bool}>
 */
function ddless_resolve_user_breakpoints(array $instrumentableLines, array $userBpLines): array
{
    if (empty($userBpLines)) {
        return $instrumentableLines;
    }

    $sortedLines = array_keys($instrumentableLines);
    sort($sortedLines, SORT_NUMERIC);

    foreach ($userBpLines as $bpLine) {
        $bpLine = (int)$bpLine;

        if (isset($instrumentableLines[$bpLine])) {
            $instrumentableLines[$bpLine]['isUserBp'] = true;
            continue;
        }

        $resolvedLine = null;
        foreach ($sortedLines as $instrLine) {
            if ($instrLine > $bpLine) {
                break;
            }
            $resolvedLine = $instrLine;
        }

        if ($resolvedLine !== null) {
            $instrumentableLines[$resolvedLine]['isUserBp'] = true;
        }
    }

    return $instrumentableLines;
}

/**
 * Instrument PHP code using AST analysis.
 * Uses AST to identify instrumentable lines, then textual injection (same as tokens approach).
 *
 * @param string $code PHP source code
 * @param string $absolutePath Absolute file path
 * @param string $relativePath Relative file path (for display)
 * @param int[] $userBreakpointLines User breakpoint line numbers
 * @param array $breakpointConditions Conditions indexed by line number
 * @param array $breakpointLogpoints Logpoints indexed by line number
 * @return string|null Instrumented code, or null if no changes
 */
function ddless_instrument_code_with_ast(string $code, string $absolutePath, string $relativePath, array $userBreakpointLines = [], array $breakpointConditions = [], array $breakpointLogpoints = [], array $breakpointDumppoints = [], array $breakpointConditionalDumppoints = []): ?string
{
    $instrumentableLines = ddless_analyze_code_ast($code, $userBreakpointLines);

    if (empty($instrumentableLines)) {
        return null;
    }

    $lines = explode("\n", $code);

    krsort($instrumentableLines);

    $injectedCount = 0;

    foreach ($instrumentableLines as $lineNum => $info) {
        $type = $info['type'];
        $isUserBp = $info['isUserBp'];
        $idx = $lineNum - 1;

        if ($idx < 0 || $idx >= count($lines)) {
            continue;
        }

        $lineContent = $lines[$idx];
        $trimmed = trim($lineContent);

        $escapedPath = addslashes($absolutePath);
        $escapedRelative = addslashes($relativePath);
        $isUserBpStr = $isUserBp ? 'true' : 'false';
        $condition = $breakpointConditions[$lineNum] ?? '';
        $escapedCondition = str_replace(["\\", "'"], ["\\\\", "\\'"], $condition);
        $logpoint = $breakpointLogpoints[$lineNum] ?? '';
        $escapedLogpoint = str_replace(["\\", "'"], ["\\\\", "\\'"], $logpoint);
        $dumpExpr = $breakpointDumppoints[$lineNum] ?? '';
        $escapedDump = str_replace(["\\", "'"], ["\\\\", "\\'"], $dumpExpr);

        $condDumpData = $breakpointConditionalDumppoints[$lineNum] ?? null;
        $condDumpCondition = '';
        $condDumpExpressions = '';
        if ($condDumpData !== null) {
            $condDumpCondition = str_replace(["\\", "'"], ["\\\\", "\\'"], $condDumpData['condition'] ?? '');
            $condDumpExpressions = str_replace(["\\", "'"], ["\\\\", "\\'"], $condDumpData['expressions'] ?? '');
        }

        $stepCall = "\\ddless_step_check('{$escapedPath}', {$lineNum}, '{$escapedRelative}', {$isUserBpStr}, '{$escapedCondition}', get_defined_vars(), debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, 128), '{$escapedLogpoint}', '{$escapedDump}', '{$condDumpCondition}', '{$condDumpExpressions}')";
        $extractModified = 'if (!empty($GLOBALS[\'__DDLESS_MODIFIED_VARS__\'])) { extract($GLOBALS[\'__DDLESS_MODIFIED_VARS__\'], EXTR_OVERWRITE); $GLOBALS[\'__DDLESS_MODIFIED_VARS__\'] = null; }';

        if ($type === 'elseif') {
            if (preg_match('/^(\s*)(}\s*(?:elseif|else\s*if)\s*\()(.+)(\)\s*\{.*)$/s', $lineContent, $m)) {
                $lines[$idx] = "{$m[1]}{$m[2]}!{$stepCall} && ({$m[3]}){$m[4]} // DDLESS_BP";
                $injectedCount++;
            }
            continue;
        }

        if (($type === 'else' || $type === 'catch' || $type === 'finally') && $isUserBp) {
            if (str_ends_with($trimmed, '{')) {
                preg_match('/^(\s*)/', $lineContent, $m);
                $baseIndent = $m[1] ?? '';
                $blockIndent = $baseIndent . '    ';
                for ($ni = $idx + 1; $ni < count($lines); $ni++) {
                    if (trim($lines[$ni]) !== '') {
                        preg_match('/^(\s+)/', $lines[$ni], $nm);
                        if (!empty($nm[1]) && strlen($nm[1]) > strlen($baseIndent)) {
                            $blockIndent = $nm[1];
                        }
                        break;
                    }
                }
                $bpCall = "{$blockIndent}{$stepCall}; {$extractModified} ";
                array_splice($lines, $idx + 1, 0, [$bpCall]);
                $injectedCount++;
            }
            continue;
        }

        if ($type === 'else' || $type === 'catch' || $type === 'finally') {
            continue;
        }

        // Braceless control structures (if/while without { at end) — inject
        // step_check into the condition, like the elseif pattern.  We cannot
        // add a line before the statement because that would break the
        // single-statement block.
        if ($type === 'control') {
            // Strip trailing inline comment to avoid false braceless detection
            // e.g. `if ($x) { // comment with )` would otherwise look braceless
            $lineNoComment = preg_replace('/\/\/.*$/', '', $lineContent);
            $lineNoComment = preg_replace('/\/\*.*?\*\/\s*$/', '', $lineNoComment);
            $trimmedNoComment = rtrim($lineNoComment);

            if (preg_match('/^(\s*)((?:if|while)\s*\()(.+)(\)\s*)$/s', $trimmedNoComment, $m)) {
                $lines[$idx] = "{$m[1]}{$m[2]}!{$stepCall} && ({$m[3]}){$m[4]} // DDLESS_BP";
                $injectedCount++;
                continue;
            }
            // for/foreach/do/switch/try without braces — rare, just skip
            if (!preg_match('/\{\s*$/', $trimmedNoComment)) {
                continue;
            }
        }

        // Safety: skip lines whose first non-whitespace char is a structural
        // delimiter. No classified statement (Expression, Return_, If_, etc.)
        // ever starts with these characters, but they appear when:
        //   - Multi-line signature: `) { $stmt` or `): Type { $stmt`
        //   - Opening brace on own line: `{ $stmt`
        //   - Closing brace: `}`
        // Injecting before such lines would place code at the wrong scope.
        if ($trimmed !== '' && strpos(')]}>{', $trimmed[0]) !== false) {
            continue;
        }

        // Skip lines starting with case/default — injecting before would place
        // code between switch { and the case label, causing a parse error.
        // This happens when a statement shares a line with case, e.g.:
        //   case 'ACL': return true;
        if (preg_match('/^\s*(case\s|default\s*:)/', $lineContent)) {
            continue;
        }

        // Inline PHP block — inject INSIDE the opening tag
        if (preg_match('/^(\s*<\?php\s)/i', $lineContent)) {
            $safeStepCall = str_replace(['\\', '$'], ['\\\\', '\\$'], $stepCall);
            $safeExtract = str_replace(['\\', '$'], ['\\\\', '\\$'], $extractModified);
            $replacement = '$1' . $safeStepCall . '; ' . $safeExtract . ' ';
            $lines[$idx] = preg_replace('/^(\s*<\?php\s)/i', $replacement, $lineContent);
            $injectedCount++;
            continue;
        }

        // Skip lines starting with HTML tags — injecting before would place
        // PHP code in HTML context. Also skip short echo tags.
        $htmlCheck = '/^\s*<(?!\?php\s|\?' . '=)/i';
        if (preg_match($htmlCheck, $lineContent)) {
            continue;
        }
        $shortEchoCheck = '/^\s*<\?' . '=/';
        if (preg_match($shortEchoCheck, $lineContent)) {
            continue;
        }

        // Check if this statement is the body of a braceless if/else/elseif/for/foreach/while.
        // Cannot safely instrument — the control structure's condition already has step_check.
        $isBracelessBody = false;
        for ($pi = $idx - 1; $pi >= 0; $pi--) {
            $prevTrimmed = trim($lines[$pi]);
            if ($prevTrimmed === '') continue;
            if (preg_match('/\)\s*(\/\/.*)?$/', $prevTrimmed) && !preg_match('/\{\s*(\/\/.*)?$/', $prevTrimmed)) {
                $isBracelessBody = true;
            }
            if (preg_match('/^(}\s*else|else)\s*$/i', $prevTrimmed)) {
                $isBracelessBody = true;
            }
            break;
        }

        if ($isBracelessBody) {
            continue;
        }

        // Skip if the previous non-empty line is a PHP attribute (#[...]).
        // Injecting between an attribute and its target declaration causes
        // "unexpected ..., expecting function" parse errors.
        $isAfterAttribute = false;
        for ($ai = $idx - 1; $ai >= 0; $ai--) {
            $attrTrimmed = trim($lines[$ai]);
            if ($attrTrimmed === '') continue;
            if (preg_match('/^#\[/', $attrTrimmed)) {
                $isAfterAttribute = true;
            }
            break;
        }
        if ($isAfterAttribute) {
            continue;
        }

        preg_match('/^(\s*)/', $lineContent, $m);
        $indent = $m[1] ?? '';

        $bpCall = "{$indent}{$stepCall}; {$extractModified} // DDLESS_BP";
        array_splice($lines, $idx, 0, [$bpCall]);
        $injectedCount++;
    }

    if ($injectedCount === 0) {
        return null;
    }

    return implode("\n", $lines);
}

function ddless_instrument_code_with_tokens(string $code, string $absolutePath, string $relativePath, array $userBreakpointLines = [], array $breakpointConditions = [], array $breakpointLogpoints = [], array $breakpointDumppoints = [], array $breakpointConditionalDumppoints = []): ?string
{
    return ddless_instrument_code_with_ast($code, $absolutePath, $relativePath, $userBreakpointLines, $breakpointConditions, $breakpointLogpoints, $breakpointDumppoints, $breakpointConditionalDumppoints);
}

function ddless_is_vendor_path(string $path): bool
{
    $normalized = str_replace('\\', '/', $path);
    return strpos($normalized, '/vendor/') !== false
        || strpos($normalized, '/node_modules/') !== false
        || strpos($normalized, '/.ddless/') !== false
        || strpos($normalized, '/cache/') !== false;
}

function ddless_is_step_mode_active(): bool
{
    return ($GLOBALS['__DDLESS_STEP_IN_MODE__'] ?? false)
        || ($GLOBALS['__DDLESS_STEP_OVER_FUNCTION__'] ?? null) !== null
        || ($GLOBALS['__DDLESS_STEP_OUT_TARGET__'] ?? null) !== null;
}

function ddless_is_trace_mode_active(): bool
{
    return $GLOBALS['__DDLESS_TRACE_MODE__'] ?? false;
}

/**
 * Timeframe needs a step_check on every line of project code, not just the
 * files carrying breakpoints, or there is no timeline to scrub. A replay counts
 * too: it must instrument exactly what the recording did, or the positions it
 * races to would not line up.
 *
 * Vendor stays out. Recording a whole framework request frame by frame is both
 * unusable in the UI and ruinous for performance, and nobody debugs by stepping
 * through Symfony's kernel.
 */
function ddless_is_timeframe_active(): bool
{
    return !empty($GLOBALS['__DDLESS_TIMEFRAME_MODE__'])
        || !empty($GLOBALS['__DDLESS_IS_REPLAY__']);
}

// Lightweight trace function — records function entry without capturing variables or pausing
// Uses debug_backtrace(IGNORE_ARGS) for minimal overhead
// Returns seq number for pairing with ddless_trace_exit()
function ddless_trace_fn(string $file, int $line): int
{
    static $seq = 0;
    $seq++;
    $currentSeq = $seq;

    $now = microtime(true);
    $GLOBALS['__DDLESS_TRACE_STARTS__'][$currentSeq] = $now;

    $bt = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 30);
    // Frame 0 = ddless_trace_fn, Frame 1 = the traced function
    $caller = $bt[1] ?? [];
    $class = $caller['class'] ?? null;
    $function = $caller['function'] ?? 'unknown';
    $type = $caller['type'] ?? '::';
    $label = $class ? "{$class}{$type}{$function}" : $function;

    // Count project-file frames for call depth (skip frame 0 = ddless_trace_fn itself)
    $depth = 0;
    for ($i = 1, $len = count($bt); $i < $len; $i++) {
        $f = $bt[$i]['file'] ?? '';
        if ($f !== '' && !ddless_is_vendor_path($f)) {
            $depth++;
        }
    }

    $startMs = round(($now - $GLOBALS['__DDLESS_TRACE_REQUEST_START__']) * 1000, 3);

    fwrite($GLOBALS['__DDLESS_IPC_STREAM__'], "__DDLESS_TRACE__:" . json_encode([
        'seq' => $currentSeq,
        'label' => $label,
        'file' => $file,
        'line' => $line,
        'depth' => $depth,
        'start_ms' => $startMs,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");

    return $currentSeq;
}

// Trace exit function — called in finally block to compute function duration
function ddless_trace_exit(int $seq): void
{
    $startTime = $GLOBALS['__DDLESS_TRACE_STARTS__'][$seq] ?? null;
    if ($startTime === null) return;

    $durationMs = round((microtime(true) - $startTime) * 1000, 3);
    unset($GLOBALS['__DDLESS_TRACE_STARTS__'][$seq]);

    fwrite($GLOBALS['__DDLESS_IPC_STREAM__'], "__DDLESS_TRACE_EXIT__:" . json_encode([
        'seq' => $seq,
        'duration_ms' => $durationMs,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
}

// Instrument PHP code for trace-only mode — injects ddless_trace_fn() + try/finally at each function/method body
// Captures precise duration via try/finally wrapper (ddless_trace_exit in finally block)
// All injections on SAME LINE as original braces — zero line number shift, safe with breakpoints
function ddless_instrument_trace_only(string $code, string $absolutePath, string $relativePath): ?string
{
    $tokens = @token_get_all($code);
    if (!$tokens || !is_array($tokens)) return null;

    if (str_contains($code, '/* DDLESS_TRACE */')) {
        return null;
    }

    $escapedRelative = str_replace(["\\", "'"], ["\\\\", "\\'"], $relativePath);

    $functionBodies = []; // array of ['open' => tokenIdx, 'close' => tokenIdx, 'line' => funcLine]
    $count = count($tokens);
    $i = 0;

    while ($i < $count) {
        $token = $tokens[$i];
        if (!is_array($token)) { $i++; continue; }

        [$type, , $tokenLine] = $token;

        if ($type === T_FUNCTION) {
            $j = $i + 1;
            while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) $j++;

            if ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                $funcLine = $tokenLine;
                $parenDepth = 0;
                $openIdx = null;
                while ($j < $count) {
                    $t = $tokens[$j];
                    $s = is_string($t) ? $t : $t[1];
                    if ($s === '(') $parenDepth++;
                    if ($s === ')') $parenDepth--;
                    if ($s === '{' && $parenDepth === 0) {
                        $openIdx = $j;
                        break;
                    }
                    if ($s === ';' && $parenDepth === 0) {
                        $i = $j + 1;
                        break;
                    }
                    $j++;
                }

                if ($openIdx !== null) {
                    $braceDepth = 1;
                    $k = $openIdx + 1;
                    while ($k < $count && $braceDepth > 0) {
                        $t = $tokens[$k];
                        $s = is_string($t) ? $t : $t[1];
                        if ($s === '{') $braceDepth++;
                        if ($s === '}') {
                            $braceDepth--;
                            if ($braceDepth === 0) {
                                $functionBodies[] = ['open' => $openIdx, 'close' => $k, 'line' => $funcLine];
                                break;
                            }
                        }
                        $k++;
                    }
                    $i = $openIdx + 1;
                }
                continue;
            }
        }

        $i++;
    }

    if (empty($functionBodies)) return null;

    $injectAfterOpen = [];  // tokenIdx => ['line' => funcLine, 'var' => '$__ddless_seqN']
    $injectBeforeClose = []; // tokenIdx => varName
    $funcCounter = 0;

    foreach ($functionBodies as $fb) {
        $funcCounter++;
        $varName = '$__ddless_seq' . ($funcCounter === 1 ? '' : $funcCounter);
        $injectAfterOpen[$fb['open']] = ['line' => $fb['line'], 'var' => $varName];
        $injectBeforeClose[$fb['close']] = $varName;
    }

    $output = '';
    for ($i = 0; $i < $count; $i++) {
        if (isset($injectBeforeClose[$i])) {
            $varName = $injectBeforeClose[$i];
            $output .= "} finally { \\ddless_trace_exit({$varName}); } /* DDLESS_TRACE_END */ ";
        }

        $token = $tokens[$i];
        $output .= is_string($token) ? $token : $token[1];

        if (isset($injectAfterOpen[$i])) {
            $info = $injectAfterOpen[$i];
            $output .= " {$info['var']} = \\ddless_trace_fn('{$escapedRelative}', {$info['line']}); /* DDLESS_TRACE */ try {";
        }
    }

    return $output;
}

// Used for step in/out to files that weren't pre-instrumented
function ddless_instrument_php_file_ondemand(string $path, bool $silent = false): ?string
{
    $realPath = realpath($path) ?: $path;

    if (ddless_is_vendor_path($realPath)) {
        return null;
    }

    $projectRoot = defined('DDLESS_PROJECT_ROOT') ? DDLESS_PROJECT_ROOT : dirname(__DIR__);
    $projectRoot = str_replace('\\', '/', rtrim($projectRoot, '/\\'));
    $normalizedPath = str_replace('\\', '/', $realPath);

    if (strpos($normalizedPath, $projectRoot) === 0) {
        $relativePath = ltrim(substr($normalizedPath, strlen($projectRoot)), '/');
    } else {
        $relativePath = basename($realPath);
    }

    if (!$silent) {
        ddless_log("[ddless] On-demand instrumenting (AST): {$relativePath}\n");
    }

    $content = @file_get_contents($realPath);
    if ($content === false) {
        if (!$silent) {
            ddless_log("[ddless] ERROR: Could not read file for on-demand instrumentation\n");
        }
        return null;
    }

    if (str_contains($content, '// DDLESS_BP')) {
        return null;
    }

    // Use tokenizer-based instrumentation (no user breakpoints in on-demand mode)
    $instrumented = ddless_instrument_code_with_tokens($content, $realPath, $relativePath, []);

    if ($instrumented !== null && !$silent) {
        $lineCount = substr_count($instrumented, '// DDLESS_BP');
        ddless_log("[ddless] On-demand instrumented {$lineCount} lines in {$relativePath}\n");
    }

    return $instrumented;
}

// Uses tokenizer-based analysis for accurate parsing
function ddless_instrument_php_file(string $path): ?string
{
    $realPath = realpath($path) ?: $path;

    if (!isset($GLOBALS['__DDLESS_BREAKPOINT_FILES__'][$realPath]) &&
        !isset($GLOBALS['__DDLESS_BREAKPOINT_FILES__'][$path])) {
        return null;
    }

    $info = $GLOBALS['__DDLESS_BREAKPOINT_FILES__'][$realPath] ?? $GLOBALS['__DDLESS_BREAKPOINT_FILES__'][$path];
    $userBreakpointLines = $info['lines'];
    $relativePath = $info['relativePath'];
    $breakpointConditions = $info['conditions'] ?? [];
    $breakpointLogpoints = $info['logpoints'] ?? [];
    $breakpointDumppoints = $info['dumppoints'] ?? [];
    $breakpointConditionalDumppoints = $info['conditionalDumppoints'] ?? [];

    // Store user breakpoint lines globally
    foreach ($userBreakpointLines as $line) {
        $GLOBALS['__DDLESS_USER_BP_LINES__'][$realPath . ':' . $line] = true;
    }

    ddless_log("[ddless] Instrumenting (AST): {$relativePath} (breakpoints at lines: " . implode(', ', $userBreakpointLines) . ")\n");

    $content = @file_get_contents($path);
    if ($content === false) {
        ddless_log("[ddless] ERROR: Could not read file {$path}\n");
        return null;
    }

    if (str_contains($content, '// DDLESS_BP')) {
        ddless_log("[ddless] File already instrumented, skipping\n");
        return null;
    }

    // Use tokenizer-based instrumentation
    $instrumented = ddless_instrument_code_with_tokens($content, $realPath, $relativePath, $userBreakpointLines, $breakpointConditions, $breakpointLogpoints, $breakpointDumppoints, $breakpointConditionalDumppoints);

    if ($instrumented !== null) {
        $lineCount = substr_count($instrumented, '// DDLESS_BP');
        ddless_log("[ddless] Instrumented {$lineCount} lines in {$relativePath}\n");
    } else {
        ddless_log("[ddless] No instrumentable lines found in {$relativePath}\n");
    }

    return $instrumented;
}

/**
 * Build eval-ready, instrumented code for the Task Runner scratchpad so
 * breakpoints set on the virtual file (__taskrunner__.php) pause execution.
 * Returns null when there are no breakpoints or instrumentation isn't possible
 * (the caller then runs the code normally). Line numbers are preserved 1:1 with
 * the editor — only the <?php tag is neutralized so eval() accepts the string.
 *
 * Timeframe mode instruments even without breakpoints: the recorder needs a
 * step_check on every instrumentable line to build a complete timeline.
 */
function ddless_task_instrument_eval_code(string $userCode): ?string
{
    $virtualRel = '__taskrunner__.php';
    $virtualAbs = DDLESS_PROJECT_ROOT . '/' . $virtualRel;
    $info = $GLOBALS['__DDLESS_BREAKPOINT_FILES__'][$virtualAbs] ?? null;
    $timeframe = !empty($GLOBALS['__DDLESS_TIMEFRAME_MODE__']) || !empty($GLOBALS['__DDLESS_IS_REPLAY__']);

    if (!$timeframe && ($info === null || empty($info['lines']))) {
        return null;
    }
    if ($info === null) {
        $info = ['relativePath' => $virtualRel, 'lines' => []];
    }

    // The scratchpad is top-level code — instrument global scope.
    $GLOBALS['__DDLESS_ALLOW_GLOBAL_SCOPE__'] = true;
    foreach ($info['lines'] as $line) {
        $GLOBALS['__DDLESS_USER_BP_LINES__'][$virtualAbs . ':' . $line] = true;
    }

    $instrumented = ddless_instrument_code_with_ast(
        $userCode,
        $virtualAbs,
        $virtualRel,
        $info['lines'],
        $info['conditions'] ?? [],
        $info['logpoints'] ?? [],
        $info['dumppoints'] ?? [],
        $info['conditionalDumppoints'] ?? []
    );
    if ($instrumented === null) {
        return null;
    }

    // eval() runs code already in PHP mode: neutralize the open tag (keep the
    // line so numbers still match the editor) and drop any trailing close tag.
    $evalReady = preg_replace('/<\?php/i', '     ', $instrumented, 1);
    $evalReady = preg_replace('/\?>\s*$/', '', $evalReady);
    return $evalReady;
}

function ddless_get_current_function_info(array $backtrace): array
{
    $currentFunction = null;
    $currentFile = null;
    $depth = 0;
    
    foreach ($backtrace as $frame) {
        $funcName = $frame['function'] ?? '';
        
        // Skip ddless internal functions
        if (str_starts_with($funcName, 'ddless_')) {
            continue;
        }
        
        $depth++;
        
        if ($currentFunction === null) {
            $class = $frame['class'] ?? '';
            $type = $frame['type'] ?? '';
            $currentFunction = $class . $type . $funcName;
            $currentFile = $frame['file'] ?? null;
        }
    }
    
    return [
        'function' => $currentFunction,
        'file' => $currentFile,
        'depth' => $depth,
    ];
}

// Helper: evaluate an expression with $this bound to the caller's object context.
// When called from a global function (ddless_step_check / ddless_handle_breakpoint),
// $this is not available. This helper extracts the caller's object from the backtrace
// and uses Closure::bind() so that $this works inside eval().
function ddless_eval_in_context(string $expression, array &$scopeVariables, array $backtrace, bool $returnValue = true)
{
    $callerObject = null;
    $callerClass = null;
    foreach ($backtrace as $frame) {
        $funcName = $frame['function'] ?? '';
        if (str_starts_with($funcName, 'ddless_')) {
            continue;
        }
        if (isset($frame['object']) && is_object($frame['object'])) {
            $callerObject = $frame['object'];
            $callerClass = get_class($callerObject);
        }
        break; // Only check the first non-ddless frame
    }

    $evalCode = $returnValue ? "return ({$expression});" : $expression;

    $evalFn = function () use ($evalCode, &$scopeVariables, $returnValue) {
        extract($scopeVariables, EXTR_SKIP);
        $__ddless_eval_result__ = @eval($evalCode);

        if (!$returnValue) {
            // Playground mode: capture modified/new variables and propagate back
            $__ddless_current__ = get_defined_vars();
            unset(
                $__ddless_current__['evalCode'],
                $__ddless_current__['scopeVariables'],
                $__ddless_current__['returnValue'],
                $__ddless_current__['__ddless_eval_result__'],
                $__ddless_current__['__ddless_current__']
            );
            foreach ($__ddless_current__ as $__k__ => $__v__) {
                if (!array_key_exists($__k__, $scopeVariables) || $scopeVariables[$__k__] !== $__v__) {
                    $scopeVariables[$__k__] = $__v__;
                }
            }
        }

        return $__ddless_eval_result__;
    };

    if ($callerObject !== null) {
        $evalFn = \Closure::bind($evalFn, $callerObject, $callerClass);
    }

    return $evalFn();
}

// Step check function - decides whether to pause based on step mode and line type
// Backward compat: old instrumented code calls with (file, line, rel, bp, vars[], backtrace[])
// New instrumented code calls with (file, line, rel, bp, condition, vars[], backtrace[])
function ddless_step_check(string $file, int $line, string $relativePath, bool $isUserBreakpoint, $conditionOrVars = '', array $scopeVariablesOrBacktrace = [], array $scopeBacktrace = [], string $logpointExpression = '', string $dumppointExpressions = '', string $condDumpCondition = '', string $condDumpExpressions = ''): void
{
    // Detect old vs new call signature by checking type of arg #5
    if (is_array($conditionOrVars)) {
        $condition = '';
        $scopeVariables = $conditionOrVars;
        $scopeBacktrace = $scopeVariablesOrBacktrace;
    } else {
        $condition = (string) $conditionOrVars;
        $scopeVariables = $scopeVariablesOrBacktrace;
        // $scopeBacktrace already correct from arg #7
    }

    // Position identity for timeframe/replay: the nth time THIS line runs.
    // Counted before any early return so the numbering is identical between a
    // recording run and a replay of it.
    $positionKey = "{$file}:{$line}";
    $nth = ($GLOBALS['__DDLESS_HIT_COUNTS__'][$positionKey] = ($GLOBALS['__DDLESS_HIT_COUNTS__'][$positionKey] ?? 0) + 1);

    // REPLAY: race to the target position. Returning here skips watches,
    // logpoints, dumppoints (which would exit()) and every pause — the run must
    // reach the target exactly as the recording did.
    if ($GLOBALS['__DDLESS_REPLAY_TARGET__'] !== null) {
        $target = $GLOBALS['__DDLESS_REPLAY_TARGET__'];
        if ($target['line'] !== $line
            || $target['nth'] !== $nth
            || $target['file'] !== str_replace('\\', '/', $relativePath)
        ) {
            return;
        }

        $GLOBALS['__DDLESS_REPLAY_TARGET__'] = null;
        // Mark it hit so a user breakpoint on this same line doesn't pause twice.
        $GLOBALS['__DDLESS_HIT_USER_BPS__'][$positionKey] = true;
        ddless_log("[ddless] Timeframe: reached replay target {$relativePath}:{$line}#{$nth}\n");
        ddless_handle_breakpoint($file, $line, $relativePath, $scopeVariables, $scopeBacktrace);
        return;
    }

    if (ddless_tf_should_record($relativePath)) {
        // Which flavour of user breakpoint sits here, so the timeline can colour
        // it like the gutter does. Same precedence the branches below follow.
        $bpKind = null;
        if ($isUserBreakpoint) {
            if ($dumppointExpressions !== '') {
                $bpKind = 'dump';
            } elseif ($condDumpCondition !== '' && $condDumpExpressions !== '') {
                $bpKind = 'conddump';
            } elseif ($logpointExpression !== '') {
                $bpKind = 'log';
            } elseif ($condition !== '') {
                $bpKind = 'condition';
            } else {
                $bpKind = 'line';
            }
        }
        ddless_tf_record($line, $relativePath, $nth, $scopeVariables, $scopeBacktrace, $bpKind);
    }

    // Helper: evaluate watch expressions and emit without pausing
    $emitWatchResults = function() use ($scopeVariables, $scopeBacktrace) {
        $watches = $GLOBALS['__DDLESS_WATCHES__'] ?? [];
        if (empty($watches)) return;
        $results = [];
        foreach ($watches as $expr) {
            try {
                $val = ddless_eval_in_context($expr, $scopeVariables, $scopeBacktrace);
                $results[$expr] = ddless_normalize_value($val, 0);
            } catch (\Throwable $e) {
                $results[$expr] = ['__error__' => $e->getMessage()];
            }
        }
        $payload = json_encode($results, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        fwrite($GLOBALS['__DDLESS_IPC_STREAM__'], "__DDLESS_WATCH__:{$payload}\n");
        fflush($GLOBALS['__DDLESS_IPC_STREAM__']);
    };

    $watches = $GLOBALS['__DDLESS_WATCHES__'] ?? [];
    if (!empty($watches)) {
        if (!isset($GLOBALS['__DDLESS_WATCH_LAST__'])) {
            $GLOBALS['__DDLESS_WATCH_LAST__'] = [];
        }
        $prevLoc = $GLOBALS['__DDLESS_WATCH_PREV_LOC__'] ?? null;
        foreach ($watches as $watchExpr) {
            if (preg_match('/^\$(\w+)/', $watchExpr, $m)) {
                $rootName = $m[1];
                if ($rootName !== 'this' && !array_key_exists($rootName, $scopeVariables)) {
                    continue;
                }
                if ($rootName === 'this') {
                    $hasThis = false;
                    foreach ($scopeBacktrace as $frame) {
                        $fn = $frame['function'] ?? '';
                        if (str_starts_with($fn, 'ddless_')) continue;
                        if (isset($frame['object']) && is_object($frame['object'])) { $hasThis = true; }
                        break;
                    }
                    if (!$hasThis) continue;
                }
            }
            try {
                $watchVal = ddless_eval_in_context($watchExpr, $scopeVariables, $scopeBacktrace);
                $normalized = ddless_normalize_value($watchVal, 0);
                $serialized = json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $lastSerialized = $GLOBALS['__DDLESS_WATCH_LAST__'][$watchExpr] ?? null;
                if ($serialized !== $lastSerialized) {
                    $GLOBALS['__DDLESS_WATCH_LAST__'][$watchExpr] = $serialized;
                    $useLoc = $prevLoc ?? ['file' => $relativePath, 'line' => $line];
                    $historyPayload = json_encode([
                        'expr' => $watchExpr,
                        'value' => $normalized,
                        'file' => $useLoc['file'],
                        'line' => $useLoc['line'],
                        'timestamp' => microtime(true),
                    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    fwrite($GLOBALS['__DDLESS_IPC_STREAM__'], "__DDLESS_WATCH_HISTORY__:{$historyPayload}\n");
                    fflush($GLOBALS['__DDLESS_IPC_STREAM__']);
                }
            } catch (\Throwable $watchErr) {
            }
        }
        $GLOBALS['__DDLESS_WATCH_PREV_LOC__'] = ['file' => $relativePath, 'line' => $line];
    }

    if ($dumppointExpressions !== '' && $isUserBreakpoint) {
        $expressions = array_filter(explode("\n", $dumppointExpressions), fn($e) => trim($e) !== '');
        $results = [];
        $previousLazy = $GLOBALS['__DDLESS_LAZY_EXPAND_ENABLED__'] ?? false;
        $previousEager = $GLOBALS['__DDLESS_EAGER_EXPAND_ENABLED__'] ?? false;
        $GLOBALS['__DDLESS_LAZY_EXPAND_ENABLED__'] = false;
        $GLOBALS['__DDLESS_EAGER_EXPAND_ENABLED__'] = true;
        try {
            foreach ($expressions as $expr) {
                $expr = trim($expr);
                try {
                    $value = ddless_eval_in_context($expr, $scopeVariables, $scopeBacktrace);
                    $results[] = ['expr' => $expr, 'value' => ddless_normalize_value($value, 0)];
                } catch (\Throwable $e) {
                    $results[] = ['expr' => $expr, 'value' => null, 'error' => $e->getMessage()];
                }
            }
        } finally {
            $GLOBALS['__DDLESS_LAZY_EXPAND_ENABLED__'] = $previousLazy;
            $GLOBALS['__DDLESS_EAGER_EXPAND_ENABLED__'] = $previousEager;
        }
        $payload = json_encode([
            'file' => $relativePath, 'line' => $line, 'results' => $results,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        fwrite($GLOBALS['__DDLESS_IPC_STREAM__'], "__DDLESS_DUMPPOINT__:{$payload}\n");
        if (php_sapi_name() === 'cli-server') {
            fwrite($GLOBALS['__DDLESS_IPC_STREAM__'], "__DDLESS_REQUEST_COMPLETE__\n");
        }
        fflush($GLOBALS['__DDLESS_IPC_STREAM__']);
        exit(0);
    }

    if ($condDumpCondition !== '' && $condDumpExpressions !== '' && $isUserBreakpoint) {
        try {
            $condResult = ddless_eval_in_context($condDumpCondition, $scopeVariables, $scopeBacktrace);
            if (!$condResult) {
                return; // Condition is false — continue execution silently
            }
        } catch (\Throwable $e) {
            return; // Condition error — skip, don't dump
        }

        // Condition is true — evaluate dump expressions (identical to regular dumppoint)
        $expressions = array_filter(explode("\n", $condDumpExpressions), fn($e) => trim($e) !== '');
        $results = [];
        $previousLazy = $GLOBALS['__DDLESS_LAZY_EXPAND_ENABLED__'] ?? false;
        $previousEager = $GLOBALS['__DDLESS_EAGER_EXPAND_ENABLED__'] ?? false;
        $GLOBALS['__DDLESS_LAZY_EXPAND_ENABLED__'] = false;
        $GLOBALS['__DDLESS_EAGER_EXPAND_ENABLED__'] = true;
        try {
            foreach ($expressions as $expr) {
                $expr = trim($expr);
                try {
                    $value = ddless_eval_in_context($expr, $scopeVariables, $scopeBacktrace);
                    $results[] = ['expr' => $expr, 'value' => ddless_normalize_value($value, 0)];
                } catch (\Throwable $e) {
                    $results[] = ['expr' => $expr, 'value' => null, 'error' => $e->getMessage()];
                }
            }
        } finally {
            $GLOBALS['__DDLESS_LAZY_EXPAND_ENABLED__'] = $previousLazy;
            $GLOBALS['__DDLESS_EAGER_EXPAND_ENABLED__'] = $previousEager;
        }
        $payload = json_encode([
            'file' => $relativePath, 'line' => $line, 'results' => $results,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        fwrite($GLOBALS['__DDLESS_IPC_STREAM__'], "__DDLESS_DUMPPOINT__:{$payload}\n");
        if (php_sapi_name() === 'cli-server') {
            fwrite($GLOBALS['__DDLESS_IPC_STREAM__'], "__DDLESS_REQUEST_COMPLETE__\n");
        }
        fflush($GLOBALS['__DDLESS_IPC_STREAM__']);
        exit(0);
    }

    // Deduplication: skip if the same file:line:message was emitted within 150ms
    if ($logpointExpression !== '' && $isUserBreakpoint) {
        try {
            // Replace {expression} placeholders with evaluated values
            $message = preg_replace_callback('/\{(.+?)\}/', function($m) use ($scopeVariables, $scopeBacktrace) {
                try {
                    $result = ddless_eval_in_context($m[1], $scopeVariables, $scopeBacktrace);
                    if ($result === null) return 'null';
                    if (is_bool($result)) return $result ? 'true' : 'false';
                    if (is_string($result) || is_numeric($result)) return (string) $result;
                    return json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{?}';
                } catch (\Throwable $e) {
                    return '{error: ' . $e->getMessage() . '}';
                }
            }, $logpointExpression);

            // Deduplicate: same file:line:message within 150ms is skipped
            $now = microtime(true);
            $dedupeKey = "{$relativePath}:{$line}:{$message}";
            $lastEmit = $GLOBALS['__DDLESS_LOGPOINT_DEDUP__'][$dedupeKey] ?? 0.0;
            if (($now - $lastEmit) < 0.15) {
                $emitWatchResults();
                return; // Duplicate logpoint, skip
            }
            $GLOBALS['__DDLESS_LOGPOINT_DEDUP__'][$dedupeKey] = $now;

            $payload = json_encode([
                'file' => $relativePath,
                'line' => $line,
                'message' => $message,
                'timestamp' => $now,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            fwrite($GLOBALS['__DDLESS_IPC_STREAM__'], "__DDLESS_LOGPOINT__:{$payload}\n");
            fflush($GLOBALS['__DDLESS_IPC_STREAM__']);
        } catch (\Throwable $e) {
            ddless_log("[ddless] Logpoint error at {$relativePath}:{$line}: {$e->getMessage()}\n");
        }
        $emitWatchResults();
        return; // Continue execution without pausing
    }

    // User breakpoints: stop unless we just "Continued" from this exact breakpoint
    // This prevents double-stopping when Laravel/framework executes the same code twice
    if ($isUserBreakpoint) {
        // Evaluate condition if present
        if ($condition !== '') {
            try {
                // Suppress warnings/notices during condition evaluation
                $prevErrorReporting = error_reporting(0);
                $conditionResult = ddless_eval_in_context($condition, $scopeVariables, $scopeBacktrace);
                error_reporting($prevErrorReporting);
                if (!$conditionResult) {
                    $emitWatchResults();
                    return; // Condition not met, skip breakpoint
                }
            } catch (\Throwable $e) {
                // Condition error — log but skip (don't stop on broken conditions)
                error_reporting($prevErrorReporting ?? E_ALL);
                ddless_log("[ddless] Conditional breakpoint error at {$relativePath}:{$line}: {$e->getMessage()}\n");
                return;
            }
        }

        $bpKey = "{$file}:{$line}";

        $alreadyHit = isset($GLOBALS['__DDLESS_HIT_USER_BPS__'][$bpKey]);
        $isInStepMode = ($GLOBALS['__DDLESS_STEP_IN_MODE__'] ?? false)
            || ($GLOBALS['__DDLESS_STEP_OVER_FUNCTION__'] ?? null) !== null
            || ($GLOBALS['__DDLESS_STEP_OUT_TARGET__'] ?? null) !== null;

        if ($alreadyHit && !$isInStepMode) {
            // Skip this breakpoint - already hit and user pressed Continue
            ddless_log("[ddless] Skipping already-hit breakpoint: {$relativePath}:{$line}\n");
            return;
        }

        // Mark as hit and stop
        $GLOBALS['__DDLESS_HIT_USER_BPS__'][$bpKey] = true;
        ddless_handle_breakpoint($file, $line, $relativePath, $scopeVariables, $scopeBacktrace);
        return;
    }
    
    $context = ddless_get_current_function_info($scopeBacktrace);
    $currentFunction = $context['function'];
    
    // STEP IN MODE: Stop on ANY next line (enters functions)
    if ($GLOBALS['__DDLESS_STEP_IN_MODE__'] ?? false) {
        $GLOBALS['__DDLESS_STEP_IN_MODE__'] = false; // Reset after stopping
        ddless_handle_breakpoint($file, $line, $relativePath, $scopeVariables, $scopeBacktrace);
        return;
    }
    
    if ($GLOBALS['__DDLESS_STEP_OVER_FUNCTION__'] !== null) {
        $targetFunction = $GLOBALS['__DDLESS_STEP_OVER_FUNCTION__'];
        $targetFile = $GLOBALS['__DDLESS_STEP_OVER_FILE__'] ?? null;

        $targetOccurrencesInStack = 0;
        foreach ($scopeBacktrace as $frame) {
            $fn = $frame['function'] ?? '';
            if (str_starts_with($fn, 'ddless_')) continue;
            $fullName = ($frame['class'] ?? '') . ($frame['type'] ?? '') . $fn;
            if ($fullName === $targetFunction) {
                $targetOccurrencesInStack++;
            }
        }

        $isSameFunction = ($currentFunction === $targetFunction);
        $isSameFileClosure = ($targetFile !== null && $file === $targetFile && str_contains($currentFunction, '{closure}'));
        $isOriginalInvocation = ($isSameFunction && $targetOccurrencesInStack <= 1);
        $hasReturnedFromTarget = ($targetOccurrencesInStack === 0);
        $shouldStop = $isOriginalInvocation || $isSameFileClosure || $hasReturnedFromTarget;

        ddless_log("[ddless] Step-over check at {$relativePath}:{$line} - current: {$currentFunction}, target: {$targetFunction}, occurrences: {$targetOccurrencesInStack}, shouldStop: " . ($shouldStop ? 'yes' : 'no') . ($isSameFileClosure ? ' (closure)' : '') . "\n");

        if ($shouldStop) {
            $GLOBALS['__DDLESS_STEP_OVER_FUNCTION__'] = null;
            $GLOBALS['__DDLESS_STEP_OVER_FILE__'] = null;
            ddless_handle_breakpoint($file, $line, $relativePath, $scopeVariables, $scopeBacktrace);
            return;
        }
        return;
    }
    
    // STEP OUT MODE: Stop when we've returned to the caller function
    if ($GLOBALS['__DDLESS_STEP_OUT_TARGET__'] !== null) {
        $targetFunction = $GLOBALS['__DDLESS_STEP_OUT_TARGET__'];
        
        if ($currentFunction !== null && $currentFunction === $targetFunction) {
            // We've returned to the caller function, pause here
            $GLOBALS['__DDLESS_STEP_OUT_TARGET__'] = null;
            ddless_handle_breakpoint($file, $line, $relativePath, $scopeVariables, $scopeBacktrace);
            return;
        }
        // Continue executing until we return to target
        return;
    }
    
    // No step mode active and not a user breakpoint - continue execution
    // Log for debugging (uncomment if needed to trace execution):
    // ddless_log("[ddless] step_check passed through at {$relativePath}:{$line}\n");
}

// Normalize value for JSON serialization
// Guard: http_request.php may define this first (loaded before debug.php in playground)
if (!function_exists('ddless_lazy_reset')) {
    function ddless_lazy_reset(): void
    {
        $GLOBALS['__DDLESS_LAZY_REGISTRY__'] = [];
        $GLOBALS['__DDLESS_LAZY_BY_OID__'] = [];
        $GLOBALS['__DDLESS_LAZY_TOKEN_COUNTER__'] = 0;
    }
}

if (!function_exists('ddless_lazy_register')) {
    function ddless_lazy_register(object $obj): array
    {
        $oid = spl_object_id($obj);
        if (isset($GLOBALS['__DDLESS_LAZY_BY_OID__'][$oid])) {
            return [
                '__ddless_lazy__' => true,
                'class' => get_class($obj),
                'token' => $GLOBALS['__DDLESS_LAZY_BY_OID__'][$oid],
            ];
        }

        $limit = $GLOBALS['__DDLESS_LAZY_REGISTRY_LIMIT__'] ?? 5000;
        if (count($GLOBALS['__DDLESS_LAZY_REGISTRY__']) >= $limit) {
            return [
                '__ddless_lazy__' => true,
                'class' => get_class($obj),
                'token' => null,
                'reason' => 'registry_full',
            ];
        }

        $GLOBALS['__DDLESS_LAZY_TOKEN_COUNTER__']++;
        $token = 'lz_' . $GLOBALS['__DDLESS_LAZY_TOKEN_COUNTER__'];
        $GLOBALS['__DDLESS_LAZY_REGISTRY__'][$token] = $obj;
        $GLOBALS['__DDLESS_LAZY_BY_OID__'][$oid] = $token;

        return [
            '__ddless_lazy__' => true,
            'class' => get_class($obj),
            'token' => $token,
        ];
    }
}

if (!function_exists('ddless_eager_expand')) {
    /**
     * Reflection-based one-level expansion for callers that can't lazy-load
     * (dumppoint exits the process right after emitting). Children that are
     * themselves objects fall through to the legacy "[object Foo]" string
     * so we don't recurse forever or thrash on cycles.
     */
    function ddless_eager_expand(object $obj): array
    {
        static $seen = [];
        $oid = spl_object_id($obj);
        if (isset($seen[$oid])) {
            return ['__class' => get_class($obj), '__cycle__' => true];
        }

        if (interface_exists('Doctrine\\Persistence\\Proxy') && $obj instanceof \Doctrine\Persistence\Proxy) {
            $initialized = method_exists($obj, '__isInitialized') ? (bool)$obj->__isInitialized() : true;
            if (!$initialized) {
                return ['__class' => get_class($obj), '__doctrine_proxy__' => 'uninitialized'];
            }
        }
        if ($obj instanceof \Generator) {
            return ['__class' => 'Generator'];
        }
        if ($obj instanceof \Closure) {
            return ['__class' => 'Closure'];
        }

        $seen[$oid] = true;
        $previousEager = $GLOBALS['__DDLESS_EAGER_EXPAND_ENABLED__'] ?? false;
        $GLOBALS['__DDLESS_EAGER_EXPAND_ENABLED__'] = false;
        try {
            $reflection = new \ReflectionObject($obj);
            $result = ['__class' => get_class($obj)];
            foreach ($reflection->getProperties() as $prop) {
                if ($prop->isStatic()) continue;
                $name = $prop->getName();
                try {
                    $prop->setAccessible(true);
                    if (!$prop->isInitialized($obj)) {
                        $result[$name] = '[uninitialized]';
                        continue;
                    }
                    $result[$name] = ddless_normalize_value($prop->getValue($obj), 0);
                } catch (\Throwable $e) {
                    $result[$name] = '[error: ' . $e->getMessage() . ']';
                }
            }
            return $result;
        } finally {
            $GLOBALS['__DDLESS_EAGER_EXPAND_ENABLED__'] = $previousEager;
            unset($seen[$oid]);
        }
    }
}

if (!function_exists('ddless_lazy_object_placeholder')) {
    /**
     * Three modes for objects without explicit serialization contracts:
     *   - lazy (default): emit a clickable marker; renderer requests expand on demand.
     *   - eager: dump one level inline via reflection — used when the caller
     *     can't follow up async (e.g. dumppoint exits the process).
     *   - legacy: "[object ClassName]" string. Last resort when both are off.
     */
    function ddless_lazy_object_placeholder(object $obj)
    {
        if (!empty($GLOBALS['__DDLESS_LAZY_EXPAND_ENABLED__'])) {
            return ddless_lazy_register($obj);
        }
        if (!empty($GLOBALS['__DDLESS_EAGER_EXPAND_ENABLED__'])) {
            return ddless_eager_expand($obj);
        }
        return '[object ' . get_class($obj) . ']';
    }
}

if (!function_exists('ddless_lazy_expand')) {
    /**
     * Expand a registered object one level via reflection. Does NOT call
     * methods (no getter side-effects, no Doctrine lazy-load triggered).
     * Nested object values become lazy markers themselves so the frontend
     * can request the next level on demand.
     */
    function ddless_lazy_expand(string $token): array
    {
        $obj = $GLOBALS['__DDLESS_LAZY_REGISTRY__'][$token] ?? null;
        if (!is_object($obj)) {
            return ['error' => 'token_not_found', 'token' => $token];
        }

        $class = get_class($obj);

        // Doctrine proxy: expanding would trigger the lazy SQL load.
        // Surface the state honestly and let the user choose to force-load.
        if (interface_exists('Doctrine\\Persistence\\Proxy') && $obj instanceof \Doctrine\Persistence\Proxy) {
            $initialized = method_exists($obj, '__isInitialized') ? (bool)$obj->__isInitialized() : true;
            if (!$initialized) {
                return [
                    'class' => $class,
                    'children' => [],
                    'doctrineProxyUninitialized' => true,
                ];
            }
        }

        if ($obj instanceof \Generator) {
            return ['class' => 'Generator', 'children' => [], 'note' => 'generator state opaque'];
        }

        if ($obj instanceof \Closure) {
            try {
                $rfn = new \ReflectionFunction($obj);
                $params = [];
                foreach ($rfn->getParameters() as $p) {
                    $params[] = $p->getName();
                }
                return [
                    'class' => 'Closure',
                    'children' => [
                        'file' => ['visibility' => 'meta', 'value' => $rfn->getFileName() ?: null],
                        'line' => ['visibility' => 'meta', 'value' => $rfn->getStartLine() ?: null],
                        'parameters' => ['visibility' => 'meta', 'value' => $params],
                    ],
                ];
            } catch (\Throwable $e) {
                return ['class' => 'Closure', 'children' => [], 'error' => $e->getMessage()];
            }
        }

        try {
            $reflection = new \ReflectionObject($obj);
        } catch (\Throwable $e) {
            return ['class' => $class, 'children' => [], 'error' => $e->getMessage()];
        }

        $children = [];
        foreach ($reflection->getProperties() as $prop) {
            if ($prop->isStatic()) {
                continue;
            }
            $name = $prop->getName();
            $vis = $prop->isPublic() ? 'public' : ($prop->isProtected() ? 'protected' : 'private');
            try {
                $prop->setAccessible(true);
                if (!$prop->isInitialized($obj)) {
                    $children[$name] = ['visibility' => $vis, 'value' => '[uninitialized]'];
                    continue;
                }
                $value = $prop->getValue($obj);
                $children[$name] = [
                    'visibility' => $vis,
                    'value' => ddless_normalize_value($value, 0),
                ];
            } catch (\Throwable $e) {
                $children[$name] = ['visibility' => $vis, 'error' => $e->getMessage()];
            }
        }

        return [
            'class' => $class,
            'children' => $children,
        ];
    }
}

if (!function_exists('ddless_normalize_value')) {
    function ddless_normalize_value($value, int $depth = 0)
    {
        $maxDepth = $GLOBALS['__DDLESS_SERIALIZE_DEPTH__'] ?? 4;
        if ($depth > $maxDepth) {
            if (is_object($value)) {
                return ddless_lazy_object_placeholder($value);
            }
            return is_scalar($value) ? $value : '[max-depth]';
        }

        if (is_null($value) || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_string($value)) {
            if (strlen($value) > 10000) {
                return substr($value, 0, 10000) . '…';
            }
            return $value;
        }

        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $item) {
                $result[$key] = ddless_normalize_value($item, $depth + 1);
            }
            return $result;
        }

        if ($value instanceof \BackedEnum) {
            return $value->value; // Return the scalar value for backed enums
        }
        if ($value instanceof \UnitEnum) {
            return $value->name; // Return the name for unit enums
        }

        if ($value instanceof \JsonSerializable) {
            try {
                return ddless_normalize_value($value->jsonSerialize(), $depth + 1);
            } catch (\Throwable $exception) {
                return ddless_lazy_object_placeholder($value);
            }
        }

        if (interface_exists('Illuminate\Contracts\Support\Arrayable') && is_object($value) && is_a($value, 'Illuminate\Contracts\Support\Arrayable')) {
            try {
                return ddless_normalize_value($value->toArray(), $depth + 1);
            } catch (\Throwable $exception) {
                return ddless_lazy_object_placeholder($value);
            }
        }

        // toArray() is intentionally not duck-typed — its semantics vary by
        // framework (e.g. Symfony Request::toArray() decodes the JSON body,
        // Laravel Request::toArray() returns input merged). Classes that
        // genuinely want to be auto-serialized should implement JsonSerializable
        // or Arrayable above. Everything else surfaces real state via the
        // lazy-expand reflection path below.

        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }

        if (is_object($value)) {
            return ddless_lazy_object_placeholder($value);
        }

        if (is_resource($value)) {
            return '[resource ' . (get_resource_type($value) ?: 'resource') . ']';
        }

        return (string)$value;
    }
} // end function_exists('ddless_normalize_value')

// Guard: http_request.php may define this first (loaded before debug.php in playground)
if (!function_exists('ddless_build_call_stack')) {
    function ddless_build_call_stack(array $backtrace): array
    {
        $result = [];
        $limit = 30;

        foreach ($backtrace as $index => $frame) {
            if ($index >= $limit) {
                $result[] = [
                    'label' => null,
                    'file' => null,
                    'line' => null,
                    'truncated' => true,
                ];
                break;
            }

            if (!is_array($frame)) {
                continue;
            }

            $class = isset($frame['class']) && is_string($frame['class']) ? $frame['class'] : null;
            $type = isset($frame['type']) && is_string($frame['type']) ? $frame['type'] : '';
            $function = isset($frame['function']) && is_string($frame['function']) ? $frame['function'] : null;

            $file = null;
            if (isset($frame['file']) && is_string($frame['file'])) {
                $file = str_replace('\\', '/', $frame['file']);
                $root = defined('DDLESS_PROJECT_ROOT') ? str_replace('\\', '/', (string)DDLESS_PROJECT_ROOT) : null;
                if ($root && str_starts_with($file, $root)) {
                    $file = ltrim(substr($file, strlen($root)), '/');
                }
            }

            $line = isset($frame['line']) && (is_int($frame['line']) || ctype_digit((string)$frame['line']))
                ? (int)$frame['line']
                : null;

            $label = $function;
            if ($class !== null && $function !== null) {
                $label = $class . $type . $function;
            } elseif ($function === null) {
                $label = 'main';
            }

            $result[] = [
                'label' => $label,
                'file' => $file,
                'line' => $line,
            ];
        }

        return $result;
    }
} // end function_exists('ddless_build_call_stack')

// Extracts constants, $this properties, static properties, etc.
function ddless_extract_used_constants(string $filePath): array
{
    $constants = [];

    $content = @file_get_contents($filePath);
    if ($content === false) {
        return [];
    }

    // Find all ClassName::CONSTANT_NAME patterns (including self::, static::)
    // Pattern: word characters followed by :: followed by UPPER_CASE_NAME
    preg_match_all('/\b([A-Z][a-zA-Z0-9_]*|self|static)\s*::\s*([A-Z][A-Z0-9_]*)\b/', $content, $matches, PREG_SET_ORDER);

    // Also parse 'use' statements to resolve class aliases
    $useStatements = [];
    preg_match_all('/^use\s+([^;]+);/m', $content, $useMatches, PREG_SET_ORDER);
    foreach ($useMatches as $useMatch) {
        $usePath = trim($useMatch[1]);
        if (preg_match('/^(.+?)(?:\s+as\s+(\w+))?$/', $usePath, $parts)) {
            $fullClass = $parts[1];
            $alias = $parts[2] ?? basename(str_replace('\\', '/', $fullClass));
            $useStatements[$alias] = $fullClass;
        }
    }

    // Get namespace of current file
    $namespace = '';
    if (preg_match('/^namespace\s+([^;]+);/m', $content, $nsMatch)) {
        $namespace = trim($nsMatch[1]);
    }

    foreach ($matches as $match) {
        $classRef = $match[1]; // self, static, or ClassName
        $constName = $match[2];

        $displayKey = "{$classRef}::{$constName}";
        if (isset($constants[$displayKey])) {
            continue;
        }

        $resolvedClass = null;

        if ($classRef === 'self' || $classRef === 'static') {
            // Will be resolved by ddless_extract_class_constants
            continue;
        }

        // Try to resolve the class name
        if (isset($useStatements[$classRef])) {
            $resolvedClass = $useStatements[$classRef];
        } elseif ($namespace) {
            $fullName = $namespace . '\\' . $classRef;
            if (class_exists($fullName) || interface_exists($fullName) || (function_exists('enum_exists') && enum_exists($fullName))) {
                $resolvedClass = $fullName;
            }
        }

        if ($resolvedClass === null) {
            if (class_exists($classRef) || interface_exists($classRef) || (function_exists('enum_exists') && enum_exists($classRef))) {
                $resolvedClass = $classRef;
            }
        }

        if ($resolvedClass && (class_exists($resolvedClass) || interface_exists($resolvedClass) || (function_exists('enum_exists') && enum_exists($resolvedClass)))) {
            try {
                if (function_exists('enum_exists') && enum_exists($resolvedClass)) {
                    // Use ReflectionEnum for enums
                    $enumReflection = new \ReflectionEnum($resolvedClass);
                    if ($enumReflection->hasCase($constName)) {
                        $case = $enumReflection->getCase($constName);
                        $enumValue = $case->getValue();
                        // For backed enums, get the scalar value
                        if ($enumValue instanceof \BackedEnum) {
                            $constants[$displayKey] = $enumValue->value;
                        } else {
                            // For unit enums, just show the name
                            $constants[$displayKey] = $enumValue->name;
                        }
                    }
                } else {
                    // Regular class/interface constant
                    $reflection = new \ReflectionClass($resolvedClass);
                    if ($reflection->hasConstant($constName)) {
                        $value = $reflection->getConstant($constName);
                        $constants[$displayKey] = ddless_normalize_value($value, 0);
                    }
                }
            } catch (\Throwable $e) {
                // Log error for debugging
                ddless_log("[ddless] Failed to resolve {$displayKey}: " . $e->getMessage() . "\n");
            }
        }
    }

    return $constants;
}

function ddless_extract_class_constants(array $backtrace): array
{
    $constants = [];

    foreach ($backtrace as $frame) {
        // Skip ddless internal functions
        $funcName = $frame['function'] ?? '';
        if (str_starts_with($funcName, 'ddless_')) {
            continue;
        }

        $className = $frame['class'] ?? null;
        if (!$className) {
            continue;
        }

        if (str_contains($className, '\\Illuminate\\') ||
            str_contains($className, '\\Symfony\\') ||
            str_contains($className, '\\Composer\\')) {
            continue;
        }

        try {
            $reflection = new ReflectionClass($className);

            // Only get constants declared in THIS class, not inherited ones
            $classConstants = $reflection->getConstants();
            $parentConstants = [];
            if ($parent = $reflection->getParentClass()) {
                $parentConstants = $parent->getConstants();
            }

            foreach ($classConstants as $name => $value) {
                // Skip internal constants
                if (str_starts_with($name, '__')) {
                    continue;
                }

                // Skip if inherited from parent (unless overridden with different value)
                if (isset($parentConstants[$name]) && $parentConstants[$name] === $value) {
                    continue;
                }

                $key = $reflection->getShortName() . '::' . $name;

                if (!isset($constants[$key])) {
                    $constants[$key] = ddless_normalize_value($value, 0);
                }
            }

            // Found user class, stop here
            break;

        } catch (\Throwable $e) {
            continue;
        }
    }

    return $constants;
}

function ddless_extract_this_properties(array $backtrace): array
{
    $properties = [];

    foreach ($backtrace as $frame) {
        // Skip ddless internal functions
        $funcName = $frame['function'] ?? '';
        if (str_starts_with($funcName, 'ddless_')) {
            continue;
        }

        $object = $frame['object'] ?? null;
        if (!$object || !is_object($object)) {
            continue;
        }

        $className = get_class($object);

        if (str_contains($className, '\\Illuminate\\') ||
            str_contains($className, '\\Symfony\\') ||
            str_contains($className, '\\Composer\\')) {
            continue;
        }

        try {
            $reflection = new ReflectionObject($object);

            // Get all properties (including private/protected)
            foreach ($reflection->getProperties() as $property) {
                $property->setAccessible(true);
                $propName = $property->getName();

                // Skip internal properties
                if (str_starts_with($propName, '__') || str_starts_with($propName, 'ddless')) {
                    continue;
                }

                // Skip properties from parent framework classes
                $declaringClass = $property->getDeclaringClass()->getName();
                if (str_contains($declaringClass, '\\Illuminate\\') ||
                    str_contains($declaringClass, '\\Symfony\\')) {
                    continue;
                }

                try {
                    $value = $property->getValue($object);
                    $key = '$this->' . $propName;
                    $properties[$key] = ddless_normalize_value($value, 0);
                } catch (\Throwable $e) {
                    // Property not initialized or other error
                    $properties['$this->' . $propName] = '[uninitialized]';
                }
            }

            // Found user class, stop here
            break;

        } catch (\Throwable $e) {
            continue;
        }
    }

    return $properties;
}

function ddless_extract_static_properties(array $backtrace): array
{
    $statics = [];

    foreach ($backtrace as $frame) {
        $className = $frame['class'] ?? null;
        if (!$className) {
            continue;
        }

        try {
            $reflection = new ReflectionClass($className);

            foreach ($reflection->getProperties(ReflectionProperty::IS_STATIC) as $property) {
                $property->setAccessible(true);
                $propName = $property->getName();

                // Skip internal properties
                if (str_starts_with($propName, '__') || str_starts_with($propName, 'ddless')) {
                    continue;
                }

                try {
                    $value = $property->getValue(null);
                    $key = $reflection->getShortName() . '::$' . $propName;
                    $statics[$key] = ddless_normalize_value($value, 0);
                } catch (\Throwable $e) {
                    $statics[$reflection->getShortName() . '::$' . $propName] = '[uninitialized]';
                }
            }

            // Only get statics from the immediate class context
            break;

        } catch (\Throwable $e) {
            continue;
        }
    }

    return $statics;
}

function ddless_extract_request_data(): array
{
    $request = [];

    // GET parameters
    if (!empty($_GET)) {
        $request['$_GET'] = ddless_normalize_value($_GET, 0);
    }

    // POST parameters
    if (!empty($_POST)) {
        $request['$_POST'] = ddless_normalize_value($_POST, 0);
    }

    $rawInput = file_get_contents('php://input');
    if ($rawInput && strlen($rawInput) > 0 && strlen($rawInput) < 50000) {
        $decoded = json_decode($rawInput, true);
        if ($decoded !== null) {
            $request['Request Body (JSON)'] = ddless_normalize_value($decoded, 0);
        } elseif (strlen($rawInput) < 5000) {
            $request['Request Body (Raw)'] = $rawInput;
        }
    }

    $serverKeys = ['REQUEST_METHOD', 'REQUEST_URI', 'HTTP_HOST', 'CONTENT_TYPE', 'HTTP_AUTHORIZATION'];
    $serverInfo = [];
    foreach ($serverKeys as $key) {
        if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
            $serverInfo[$key] = $_SERVER[$key];
        }
    }
    if (!empty($serverInfo)) {
        $request['$_SERVER (relevant)'] = $serverInfo;
    }

    return $request;
}

function ddless_extract_function_arguments(array $backtrace): array
{
    $args = [];

    foreach ($backtrace as $index => $frame) {
        $funcName = $frame['function'] ?? '';
        if (str_starts_with($funcName, 'ddless_')) {
            continue;
        }

        $className = $frame['class'] ?? null;
        $function = $frame['function'] ?? null;
        $frameArgs = $frame['args'] ?? [];

        if (!$function || empty($frameArgs)) {
            break;
        }

        try {
            if ($className) {
                $reflection = new ReflectionMethod($className, $function);
            } else {
                $reflection = new ReflectionFunction($function);
            }

            $params = $reflection->getParameters();

            foreach ($frameArgs as $i => $argValue) {
                $paramName = isset($params[$i]) ? '$' . $params[$i]->getName() : "\$arg{$i}";
                $args[$paramName . ' (arg)'] = ddless_normalize_value($argValue, 0);
            }

        } catch (\Throwable $e) {
            foreach ($frameArgs as $i => $argValue) {
                $args["\$arg{$i}"] = ddless_normalize_value($argValue, 0);
            }
        }

        break;
    }

    return $args;
}

// ============================================================================
// TIMEFRAME — record & replay ("time travel")
//
// A frame is addressed by POSITION: "<relativeFile>:<line>#<nth>", where nth is
// the hit count of that exact line. Position beats a global step counter
// because instrumenting one more file (step-in, on-demand) shifts every global
// seq but never the per-line count of an unrelated line.
//
// RECORD: with DDLESS_TIMEFRAME=true every ddless_step_check() appends a frame
// to sessions/<id>/timeline.ndjson. Scope is delta-encoded against the previous
// frame of the same scope, so a loop body costs one changed variable per turn
// instead of a full snapshot.
//
// REPLAY: re-run the same input with DDLESS_REPLAY_TARGET=<position>. Every
// step_check before the target returns immediately — no watches, no logpoints,
// no dumppoint exit(), no pause — and on arrival it becomes an ordinary
// breakpoint pause. The Electron side needs no new pause protocol.
// ============================================================================

define('DDLESS_TIMEFRAME_VERSION', '1.1'); // v1.1: frame.bp carries the breakpoint kind

/** Parse "app/Foo.php:42#3" into its parts. Returns null when malformed. */
function ddless_tf_parse_position(string $raw): ?array
{
    if (!preg_match('/^(.+):(\d+)#(\d+)$/', trim($raw), $m)) {
        return null;
    }
    return [
        'file' => str_replace('\\', '/', $m[1]),
        'line' => (int) $m[2],
        'nth'  => (int) $m[3],
    ];
}

function ddless_tf_format_position(string $relativePath, int $line, int $nth): string
{
    return str_replace('\\', '/', $relativePath) . ':' . $line . '#' . $nth;
}

/** Recording is off during a replay run — the original timeline stays authoritative. */
function ddless_tf_is_recording(): bool
{
    return !empty($GLOBALS['__DDLESS_TIMEFRAME_MODE__'])
        && empty($GLOBALS['__DDLESS_IS_REPLAY__']);
}

/**
 * Optional file filter, in two shapes:
 *
 *   from  Recording stays idle until execution first enters the file, then runs
 *         to the end, following whatever it calls. A framework request spends
 *         most of its life in bootstrap, so starting at your controller drops
 *         the bulk of the frames.
 *   only  Records that file and nothing else. Calls into other files still
 *         execute, they just are not recorded, which isolates one unit of code
 *         instead of everything downstream of it.
 *
 * Either way the cheap part is what runs when a line is skipped: the counter
 * and one string compare, instead of serializing a whole scope.
 *
 * Position counting is untouched: __DDLESS_HIT_COUNTS__ advances on every
 * step_check regardless, so a replay still lands where the recording says.
 */
function ddless_tf_matches_start_file(string $relativePath, string $startFile): bool
{
    $current = str_replace('\\', '/', $relativePath);
    // Exact relative path, or a suffix so a bare file name also works.
    return $current === $startFile || str_ends_with($current, '/' . $startFile);
}

function ddless_tf_should_record(string $relativePath): bool
{
    if (!ddless_tf_is_recording()) {
        return false;
    }

    $startFile = $GLOBALS['__DDLESS_TF_START_FILE__'] ?? '';
    if ($startFile === '') {
        return true;
    }

    $matches = ddless_tf_matches_start_file($relativePath, $startFile);

    // 'only' never arms: each line is judged on its own file.
    if (($GLOBALS['__DDLESS_TF_SCOPE_MODE__'] ?? 'from') === 'only') {
        return $matches;
    }

    if (!empty($GLOBALS['__DDLESS_TF_ARMED__'])) {
        return true;
    }
    if ($matches) {
        $GLOBALS['__DDLESS_TF_ARMED__'] = true;
        ddless_log("[ddless] Timeframe: armed at {$relativePath}
");
        return true;
    }

    return false;
}


/**
 * Identity for this execution. Concurrent requests share a session (it is keyed
 * by host + project), so a single timeline file meant the second request
 * truncated the first and both timelines turned to garbage. PID plus start time
 * is unique per run without needing anything handed in from outside, which
 * matters because not every flow spawns PHP from Electron.
 */
function ddless_tf_run_id(): string
{
    static $runId = null;
    if ($runId === null) {
        $runId = getmypid() . '-' . str_pad((string) (int) round(microtime(true) * 1000), 13, '0', STR_PAD_LEFT);
    }
    return $runId;
}

function ddless_tf_timeline_dir(): string
{
    $dir = ddless_get_session_dir() . '/timeframe';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir;
}

function ddless_tf_timeline_file(): string
{
    return ddless_tf_timeline_dir() . '/' . ddless_tf_run_id() . '.ndjson';
}

/** Short human label so a list of runs reads like a list of things you did. */
function ddless_tf_run_label(): string
{
    $method = $_SERVER['REQUEST_METHOD'] ?? null;
    $uri = $_SERVER['REQUEST_URI'] ?? null;
    if (is_string($method) && is_string($uri) && $uri !== '') {
        $path = strtok($uri, '?');
        return $method . ' ' . ($path === false ? $uri : $path);
    }
    if (getenv('DDLESS_TASK_RUNNER') || defined('DDLESS_TASK_RUNNER')) {
        return 'Task Runner';
    }
    if (getenv('DDLESS_METHOD_EXECUTION')) {
        return 'Method';
    }
    return 'CLI';
}

/**
 * Keep the recording directory from growing without bound. Runs are named so
 * they sort by start time, so the newest survive.
 */
function ddless_tf_prune_old_runs(int $keep = 20): void
{
    $dir = ddless_tf_timeline_dir();
    $files = @glob($dir . '/*.ndjson');
    if ($files === false || count($files) <= $keep) {
        return;
    }
    sort($files);
    foreach (array_slice($files, 0, count($files) - $keep) as $stale) {
        @unlink($stale);
    }
}

/**
 * Open the timeline on first frame and write the meta header. Returns null (and
 * disables recording) when the file can't be written — a broken timeline must
 * never break the run itself.
 */
function ddless_tf_open()
{
    if ($GLOBALS['__DDLESS_TF_HANDLE__'] !== null) {
        return $GLOBALS['__DDLESS_TF_HANDLE__'];
    }

    $path = ddless_tf_timeline_file();
    $handle = @fopen($path, 'wb');
    if ($handle === false) {
        ddless_log("[ddless] Timeframe: cannot write {$path}, recording disabled\n");
        $GLOBALS['__DDLESS_TIMEFRAME_MODE__'] = false;
        return null;
    }

    $GLOBALS['__DDLESS_TF_HANDLE__'] = $handle;

    ddless_tf_prune_old_runs();

    $meta = [
        'type' => 'meta',
        'version' => DDLESS_TIMEFRAME_VERSION,
        'cacheVersion' => defined('DDLESS_CACHE_VERSION') ? DDLESS_CACHE_VERSION : null,
        'detail' => $GLOBALS['__DDLESS_TF_DETAIL__'],
        'sessionId' => getenv('DDLESS_DEBUG_SESSION') ?: 'default',
        'runId' => ddless_tf_run_id(),
        'label' => ddless_tf_run_label(),
        'startFile' => $GLOBALS['__DDLESS_TF_START_FILE__'] ?: null,
        'scopeMode' => $GLOBALS['__DDLESS_TF_START_FILE__'] ? $GLOBALS['__DDLESS_TF_SCOPE_MODE__'] : null,
        'startedAt' => microtime(true),
    ];
    fwrite($handle, json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");

    register_shutdown_function('ddless_tf_close');

    return $handle;
}

/**
 * Close the timeline and announce it on the IPC stream so the UI knows where
 * the recording landed and how many frames it holds.
 */
function ddless_tf_close(): void
{
    $handle = $GLOBALS['__DDLESS_TF_HANDLE__'] ?? null;
    if ($handle === null) {
        return;
    }

    $summary = [
        'runId' => ddless_tf_run_id(),
        'frames' => $GLOBALS['__DDLESS_TF_FRAMES_WRITTEN__'],
        'truncated' => (bool) $GLOBALS['__DDLESS_TF_TRUNCATED__'],
    ];

    @fwrite($handle, json_encode(
        ['type' => 'end'] + $summary + ['endedAt' => microtime(true)],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    ) . "\n");
    @fclose($handle);
    $GLOBALS['__DDLESS_TF_HANDLE__'] = null;

    // No stdout marker on purpose. The 'end' record above already carries the
    // summary and the tailer reads it from the file, so emitting one would only
    // create a line every consumer has to remember to strip: in the Task Runner
    // it surfaces as a bogus error line, and over HTTP stdout IS the response
    // body, so it would corrupt the response.
}

/**
 * Serialize the scope for a recorded frame.
 *
 * Lazy-expand tokens are useless here — the object they point at is long gone
 * by the time anyone scrubs the timeline — so objects are expanded inline at a
 * shallow depth instead. 'full' detail keeps the user's configured depth.
 */
function ddless_tf_capture_vars(array $scopeVariables): array
{
    static $ignored = ['GLOBALS', '_SERVER', '_GET', '_POST', '_FILES', '_COOKIE', '_SESSION', '_REQUEST', '_ENV'];

    $previousLazy = $GLOBALS['__DDLESS_LAZY_EXPAND_ENABLED__'] ?? false;
    $previousDepth = $GLOBALS['__DDLESS_SERIALIZE_DEPTH__'] ?? 4;
    $GLOBALS['__DDLESS_LAZY_EXPAND_ENABLED__'] = false;
    if (($GLOBALS['__DDLESS_TF_DETAIL__'] ?? 'normal') !== 'full') {
        $GLOBALS['__DDLESS_SERIALIZE_DEPTH__'] = 1;
    }

    $captured = [];
    $max = $GLOBALS['__DDLESS_TF_MAX_VARS__'] ?? 60;

    try {
        foreach ($scopeVariables as $name => $value) {
            if (!is_string($name) || in_array($name, $ignored, true)) {
                continue;
            }
            $lowerName = strtolower($name);
            if (str_starts_with($lowerName, 'ddless_') || str_starts_with($lowerName, '__ddless')) {
                continue;
            }
            $captured[$name] = ddless_normalize_value($value, 0);
            if (count($captured) >= $max) {
                break;
            }
        }
    } catch (\Throwable $e) {
        // A single unserializable value must not kill the recording.
    } finally {
        $GLOBALS['__DDLESS_LAZY_EXPAND_ENABLED__'] = $previousLazy;
        $GLOBALS['__DDLESS_SERIALIZE_DEPTH__'] = $previousDepth;
    }

    return $captured;
}

/**
 * Interactive input is the one thing a replay cannot re-derive: the run reaches
 * a prompt and there is nobody on the other end, so every answer comes back
 * empty and the whole result collapses to null.
 *
 * So stdin is journalled. Every line read during a recording is appended in
 * order; a replay hands them back in that same order and never emits a prompt.
 * Rejected answers are journalled too: the validation rule is deterministic, so
 * replaying the full sequence reproduces the loop exactly.
 */
function ddless_tf_input_journal_file(): string
{
    return ddless_get_session_dir() . '/stdin.ndjson';
}

/** Record one line read from stdin. No-op unless a recording is in progress. */
function ddless_tf_journal_input(string $value): void
{
    if (!ddless_tf_is_recording()) {
        return;
    }
    @file_put_contents(
        ddless_tf_input_journal_file(),
        json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
        FILE_APPEND | LOCK_EX
    );
}

/**
 * Next journalled answer during a replay, or null when not replaying or the
 * journal is spent (the caller then falls back to a real prompt).
 */
function ddless_tf_next_journaled_input(): ?string
{
    if (empty($GLOBALS['__DDLESS_IS_REPLAY__'])) {
        return null;
    }

    if (!isset($GLOBALS['__DDLESS_TF_INPUT_QUEUE__'])) {
        $queue = [];
        $raw = @file_get_contents(ddless_tf_input_journal_file());
        if ($raw !== false) {
            foreach (explode("\n", $raw) as $line) {
                if (trim($line) === '') continue;
                $decoded = json_decode($line, true);
                if (is_string($decoded)) $queue[] = $decoded;
            }
        }
        $GLOBALS['__DDLESS_TF_INPUT_QUEUE__'] = $queue;
        ddless_log('[ddless] Timeframe: ' . count($queue) . " journalled input(s) available for replay\n");
    }

    if (empty($GLOBALS['__DDLESS_TF_INPUT_QUEUE__'])) {
        return null;
    }

    return array_shift($GLOBALS['__DDLESS_TF_INPUT_QUEUE__']);
}

/** Append one frame to the timeline. Never throws — recording is best-effort. */
function ddless_tf_record(
    int $line,
    string $relativePath,
    int $nth,
    array $scopeVariables,
    array $scopeBacktrace,
    ?string $bpKind = null
): void {
    if ($GLOBALS['__DDLESS_TF_FRAMES_WRITTEN__'] >= $GLOBALS['__DDLESS_TF_MAX_FRAMES__']) {
        $GLOBALS['__DDLESS_TF_TRUNCATED__'] = true;
        return;
    }

    $handle = ddless_tf_open();
    if ($handle === null) {
        return;
    }

    $context = ddless_get_current_function_info($scopeBacktrace);
    $label = $context['function'] ?? '{main}';
    $depth = (int) $context['depth'];

    $frame = [
        'seq' => ++$GLOBALS['__DDLESS_TF_SEQ__'],
        'file' => $relativePath,
        'line' => $line,
        'nth' => $nth,
        'fn' => $label,
        'depth' => $depth,
        't' => round((microtime(true) - $GLOBALS['__DDLESS_TRACE_REQUEST_START__']) * 1000, 3),
    ];
    // 'line' | 'condition' | 'log' | 'dump' | 'conddump' — absent when the line
    // carries no user breakpoint.
    if ($bpKind !== null) {
        $frame['bp'] = $bpKind;
    }

    if (($GLOBALS['__DDLESS_TF_DETAIL__'] ?? 'normal') !== 'light') {
        // Anything deeper than the current frame has returned — those scopes are
        // gone, so a later call at that depth starts fresh instead of diffing
        // against a dead scope.
        foreach (array_keys($GLOBALS['__DDLESS_TF_SCOPE_STACK__']) as $stackDepth) {
            if ($stackDepth > $depth) {
                unset($GLOBALS['__DDLESS_TF_SCOPE_STACK__'][$stackDepth]);
            }
        }

        $vars = ddless_tf_capture_vars($scopeVariables);
        $previous = $GLOBALS['__DDLESS_TF_SCOPE_STACK__'][$depth] ?? null;

        if ($previous !== null && $previous['fn'] === $label) {
            $changed = [];
            foreach ($vars as $name => $value) {
                if (!array_key_exists($name, $previous['vars']) || $previous['vars'][$name] !== $value) {
                    $changed[$name] = $value;
                }
            }
            $removed = array_values(array_diff(array_keys($previous['vars']), array_keys($vars)));
            if (!empty($changed)) {
                $frame['vars'] = $changed;
            }
            if (!empty($removed)) {
                $frame['del'] = $removed;
            }
        } else {
            // New scope: full snapshot, so a reader can rebuild state from here
            // without replaying frames from an unrelated call.
            $frame['scopeStart'] = true;
            if (!empty($vars)) {
                $frame['vars'] = $vars;
            }
        }

        $GLOBALS['__DDLESS_TF_SCOPE_STACK__'][$depth] = ['fn' => $label, 'vars' => $vars];
    }

    $json = json_encode(
        $frame,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR
    );
    if ($json === false) {
        return;
    }

    fwrite($handle, $json . "\n");
    $GLOBALS['__DDLESS_TF_FRAMES_WRITTEN__']++;

    // The UI tails this file while the run is still going, so the buffer can't
    // sit until shutdown. Flushing in batches keeps the live view ~current
    // without paying an fsync per executed line.
    if ($GLOBALS['__DDLESS_TF_FRAMES_WRITTEN__'] % 50 === 0) {
        @fflush($handle);
    }
}

$__ddless_replay_raw = getenv('DDLESS_REPLAY_TARGET');
if (is_string($__ddless_replay_raw) && $__ddless_replay_raw !== '') {
    $__ddless_replay_target = ddless_tf_parse_position($__ddless_replay_raw);
    if ($__ddless_replay_target === null) {
        ddless_log("[ddless] Timeframe: invalid DDLESS_REPLAY_TARGET '{$__ddless_replay_raw}', ignoring\n");
    } else {
        $GLOBALS['__DDLESS_REPLAY_TARGET__'] = $__ddless_replay_target;
        $GLOBALS['__DDLESS_IS_REPLAY__'] = true;
        ddless_log("[ddless] Timeframe: replaying to {$__ddless_replay_raw}\n");
    }
}

$GLOBALS['__DDLESS_SESSION_ID__'] = null;

function ddless_handle_breakpoint(
    string $file,
    int $line,
    ?string $relativePath = null,
    $scopeVariables = null,
    $scopeBacktrace = null
): void
{
    if (!ddless_is_debug_mode_active()) {
        return; // Debug not active, continue execution
    }

    ddless_log("[ddless] BREAKPOINT HIT: {$relativePath}:{$line}\n");

    if (function_exists('ddless_lazy_reset')) {
        ddless_lazy_reset();
    }

    // This ensures PHP and Electron use the same session directory
    if ($GLOBALS['__DDLESS_SESSION_ID__'] === null) {
        $envSessionId = getenv('DDLESS_DEBUG_SESSION');
        if ($envSessionId && $envSessionId !== '') {
            $GLOBALS['__DDLESS_SESSION_ID__'] = $envSessionId;
            ddless_log("[ddless] Using session from env: {$envSessionId}\n");
        } else {
            try {
                $GLOBALS['__DDLESS_SESSION_ID__'] = 'ddless-' . bin2hex(random_bytes(16));
            } catch (\Throwable $exception) {
                $GLOBALS['__DDLESS_SESSION_ID__'] = 'ddless-' . uniqid('', true) . '-' . getmypid();
            }
            ddless_log("[ddless] WARNING: No DDLESS_DEBUG_SESSION env, generated: {$GLOBALS['__DDLESS_SESSION_ID__']}\n");
        }
    }

    $sessionId = $GLOBALS['__DDLESS_SESSION_ID__'];

    if ($relativePath !== null && $relativePath === '') {
        $relativePath = null;
    }

    $rawVariables = is_array($scopeVariables) ? $scopeVariables : [];
    $originalRawVariables = $rawVariables;
    $rawBacktrace = is_array($scopeBacktrace)
        ? $scopeBacktrace
        : debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, 32);

    $ignoredVariables = [
        'GLOBALS',
        '_SERVER',
        '_GET',
        '_POST',
        '_FILES',
        '_COOKIE',
        '_SESSION',
        '_REQUEST',
        '_ENV',
        '__ddless_temp__',
        '__ddless_bp_vars__',
        '__ddless_scope_variables__',
        '__ddless_scope_backtrace__',
    ];

    $locals = [];
    $maxVariables = 80;

    foreach ($rawVariables as $name => $value) {
        if (!is_string($name)) {
            continue;
        }
        if (in_array($name, $ignoredVariables, true)) {
            continue;
        }
        $lowerName = strtolower($name);
        if (str_starts_with($lowerName, 'ddless_') || str_starts_with($lowerName, '__ddless')) {
            continue;
        }

        $locals[$name] = ddless_normalize_value($value, 0);

        if (count($locals) >= $maxVariables) {
            $locals['__ddless_notice__'] = sprintf('Showing first %d variables.', $maxVariables);
            break;
        }
    }

    $callStack = ddless_build_call_stack($rawBacktrace);

    $classConstants = ddless_extract_class_constants($rawBacktrace);

    $usedConstants = ddless_extract_used_constants($file);
    $classConstants = array_merge($usedConstants, $classConstants); // self:: takes priority

    if (!empty($classConstants)) {
        ddless_log("[ddless] Extracted " . count($classConstants) . " class constants\n");
    }

    $thisProperties = ddless_extract_this_properties($rawBacktrace);
    if (!empty($thisProperties)) {
        ddless_log("[ddless] Extracted " . count($thisProperties) . " \$this properties\n");
    }

    $staticProperties = ddless_extract_static_properties($rawBacktrace);

    $functionArgs = ddless_extract_function_arguments($rawBacktrace);

    $requestData = ddless_extract_request_data();

    $watchResults = [];
    $watches = $GLOBALS['__DDLESS_WATCHES__'] ?? [];
    if (!empty($watches)) {
        foreach ($watches as $expr) {
            try {
                $watchVal = ddless_eval_in_context($expr, $rawVariables, $rawBacktrace);
                $watchResults[$expr] = ddless_normalize_value($watchVal, 0);
            } catch (\Throwable $e) {
                $watchResults[$expr] = ['__error__' => $e->getMessage()];
            }
        }
    }

    $payload = [
        'type' => 'breakpoint',
        'hitId' => microtime(true), // Unique ID per hit - prevents duplicate detection issues
        'sessionId' => $sessionId,
        'file' => $file,
        'line' => $line,
        'relativeFile' => $relativePath,
        'variables' => $locals,
        'callStack' => $callStack,
        'constants' => $classConstants,
        'thisProperties' => $thisProperties,
        'staticProperties' => $staticProperties,
        'arguments' => $functionArgs,
        'requestData' => $requestData,
        'watchResults' => !empty($watchResults) ? $watchResults : null,
    ];

    // Terminal mode: interactive debugging via STDIN/STDERR (used by test_trigger.php)
    if (isset($GLOBALS['__DDLESS_TERMINAL_HANDLER__'])) {
        $command = strtolower(($GLOBALS['__DDLESS_TERMINAL_HANDLER__'])($payload, $file) ?? 'continue');
    } else {

    if (!ddless_write_breakpoint_state($payload)) {
        ddless_log("[ddless] Failed to write breakpoint state, continuing execution\n");
        return;
    }

    $jsonPayload = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    fwrite($GLOBALS['__DDLESS_IPC_STREAM__'], "__DDLESS_BREAKPOINT__:{$jsonPayload}\n");
    fflush($GLOBALS['__DDLESS_IPC_STREAM__']);

    ddless_log("[ddless] Waiting for debug command...\n");

    while (true) {
        $responseData = ddless_wait_for_command();

        if ($responseData === null) {
            ddless_log("[ddless] Timeout waiting for command, forcing continue\n");
            ddless_cleanup_debug_files();
            $GLOBALS['__DDLESS_STEP_IN_MODE__'] = false;
            $GLOBALS['__DDLESS_STEP_OVER_FUNCTION__'] = null;
            $GLOBALS['__DDLESS_STEP_OVER_FILE__'] = null;
            $GLOBALS['__DDLESS_STEP_OUT_TARGET__'] = null;
            $timeoutPayload = json_encode([
                'type' => 'timeout',
                'sessionId' => $sessionId,
                'message' => 'Debug session timed out after ' . round(($GLOBALS['__DDLESS_BREAKPOINT_TIMEOUT__'] ?? 3600) / 60) . ' minutes of inactivity',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            fwrite($GLOBALS['__DDLESS_IPC_STREAM__'], "__DDLESS_SESSION_TIMEOUT__:{$timeoutPayload}\n");
            fflush($GLOBALS['__DDLESS_IPC_STREAM__']);
            return;
        }

        $command = strtolower($responseData['command'] ?? 'continue');

        ddless_log("[ddless] Received command: {$command}\n");

        if ($command === 'evaluate') {
            $expression = $responseData['expression'] ?? '';
            $requestId = $responseData['requestId'] ?? '';
            $useStatements = $responseData['useStatements'] ?? [];

            if ($expression === '') {
                $evalPayload = [
                    'type' => 'evaluate_result',
                    'sessionId' => $sessionId,
                    'requestId' => $requestId,
                    'success' => false,
                    'result' => null,
                    'error' => 'Empty expression',
                    'duration_ms' => 0,
                ];
                ddless_write_breakpoint_state($evalPayload);
                continue;
            }

            $blockedFunctions = [
                'var_dump'   => 'Use a return statement instead — the playground formats values automatically.',
                'var_export' => 'Use a return statement instead — the playground formats values automatically.',
                'print_r'    => 'Use a return statement instead — the playground formats values automatically.',
                'dump'       => 'Use a return statement instead — the playground formats values automatically.',
                'dd'         => 'Use a return statement instead — the playground formats values automatically.',
                'die'        => 'This would terminate the debug session.',
                'exit'       => 'This would terminate the debug session.',
                'echo'       => 'Use a return statement instead.',
                'print'      => 'Use a return statement instead.',
                'header'     => 'Headers cannot be sent during a debug session.',
            ];
            foreach ($blockedFunctions as $fn => $hint) {
                if (preg_match('/\b' . preg_quote($fn, '/') . '\s*\(/i', $expression)) {
                    $evalPayload = [
                        'type' => 'evaluate_result',
                        'sessionId' => $sessionId,
                        'requestId' => $requestId,
                        'success' => false,
                        'result' => null,
                        'error' => "{$fn}() is not allowed in the playground. {$hint}",
                        'duration_ms' => 0,
                    ];
                    ddless_write_breakpoint_state($evalPayload);
                    continue 2;
                }
            }

            $startTime = microtime(true);

            try {
                $previousTimeLimit = ini_get('max_execution_time');
                set_time_limit(60); // Prevent infinite loops

                ob_start();
                $result = ddless_eval_in_context($expression, $rawVariables, $rawBacktrace, false);
                $output = ob_get_clean();

                set_time_limit((int) $previousTimeLimit);

                $normalized = ddless_normalize_value($result, 0);
                $durationMs = round((microtime(true) - $startTime) * 1000, 2);

                // Re-normalize updated variables for frontend display
                $updatedLocals = [];
                foreach ($rawVariables as $name => $value) {
                    if (!is_string($name)) continue;
                    if (in_array($name, $ignoredVariables, true)) continue;
                    $lowerName = strtolower($name);
                    if (str_starts_with($lowerName, 'ddless_') || str_starts_with($lowerName, '__ddless')) continue;
                    $updatedLocals[$name] = ddless_normalize_value($value, 0);
                    if (count($updatedLocals) >= $maxVariables) break;
                }

                $evalPayload = [
                    'type' => 'evaluate_result',
                    'sessionId' => $sessionId,
                    'requestId' => $requestId,
                    'success' => true,
                    'result' => $normalized,
                    'output' => $output ?: null,
                    'error' => null,
                    'duration_ms' => $durationMs,
                    'updatedVariables' => $updatedLocals,
                ];
            } catch (\Throwable $e) {
                ob_end_clean();
                set_time_limit((int) ($previousTimeLimit ?? 0));

                $durationMs = round((microtime(true) - $startTime) * 1000, 2);

                $evalPayload = [
                    'type' => 'evaluate_result',
                    'sessionId' => $sessionId,
                    'requestId' => $requestId,
                    'success' => false,
                    'result' => null,
                    'error' => $e->getMessage(),
                    'duration_ms' => $durationMs,
                ];
            }

            ddless_write_breakpoint_state($evalPayload);
            ddless_log("[ddless] Evaluate result written, waiting for next command...\n");
            continue;
        }

        if ($command === 'expand') {
            $token = (string)($responseData['token'] ?? '');
            $requestId = $responseData['requestId'] ?? '';
            $expanded = ddless_lazy_expand($token);

            $expandPayload = [
                'type' => 'expand_result',
                'sessionId' => $sessionId,
                'requestId' => $requestId,
                'token' => $token,
                'result' => $expanded,
            ];
            ddless_write_breakpoint_state($expandPayload);
            ddless_log("[ddless] Expand result written for token {$token}, waiting for next command...\n");
            continue;
        }

        break;
    }

    } // end else (non-terminal mode)

    if ($rawVariables !== $originalRawVariables) {
        $GLOBALS['__DDLESS_MODIFIED_VARS__'] = $rawVariables;
    }

    $resetAllStepModes = function() {
        $GLOBALS['__DDLESS_STEP_IN_MODE__'] = false;
        $GLOBALS['__DDLESS_STEP_OVER_FUNCTION__'] = null;
        $GLOBALS['__DDLESS_STEP_OVER_FILE__'] = null;
        $GLOBALS['__DDLESS_STEP_OUT_TARGET__'] = null;
    };

    $getContext = function() use ($rawBacktrace) {
        $currentFunction = null;
        $callerFunction = null;
        $depth = 0;

        foreach ($rawBacktrace as $frame) {
            $funcName = $frame['function'] ?? '';
            if (str_starts_with($funcName, 'ddless_')) {
                continue;
            }
            $depth++;

            $class = $frame['class'] ?? '';
            $type = $frame['type'] ?? '';
            $fullName = $class . $type . $funcName;

            if ($currentFunction === null) {
                $currentFunction = $fullName;
            } elseif ($callerFunction === null) {
                $callerFunction = $fullName;
            }
        }

        return [
            'function' => $currentFunction,
            'caller' => $callerFunction,
            'depth' => $depth,
        ];
    };

    switch ($command) {
        case 'continue':
        case 'c':
            $resetAllStepModes();
            ddless_log("[ddless] Continue - running until next breakpoint\n");
            break;

        case 'next':
        case 'n':
            $resetAllStepModes();
            $ctx = $getContext();
            $GLOBALS['__DDLESS_STEP_OVER_FUNCTION__'] = $ctx['function'];
            $GLOBALS['__DDLESS_STEP_OVER_FILE__'] = $file;
            ddless_log("[ddless] Next (Step Over) - will stop at next line in: " . ($ctx['function'] ?? 'top-level') . "\n");
            break;

        case 'step_in':
        case 'step':
        case 's':
        case 'in':
        case 'i':
            $resetAllStepModes();
            $GLOBALS['__DDLESS_STEP_IN_MODE__'] = true;
            ddless_log("[ddless] Step In - will stop on next line (enters functions)\n");
            break;

        case 'step_out':
        case 'out':
        case 'o':
            $resetAllStepModes();
            $ctx = $getContext();
            $GLOBALS['__DDLESS_STEP_OUT_TARGET__'] = $ctx['caller'];
            ddless_log("[ddless] Step Out - will stop when returning to: " . ($ctx['caller'] ?? 'top-level') . "\n");
            break;

        case 'quit':
        case 'q':
            ddless_log("[ddless] Quit command received\n");
            exit(0);

        default:
            ddless_log("[ddless] Unknown command: {$command}, treating as continue\n");
            $resetAllStepModes();
            break;
    }

    ddless_log("[ddless] Resuming execution after command: {$command}\n");
}

$GLOBALS['__DDLESS_INSTRUMENTED_CODE__'] = [];

function ddless_find_all_php_files(string $directory): array
{
    // Debug scope: if set, only scan files within these relative paths
    $debugScope = getenv('DDLESS_DEBUG_SCOPE');
    $scopePaths = [];
    if ($debugScope !== false && $debugScope !== '') {
        $scopePaths = array_filter(
            array_map(fn($p) => str_replace('\\', '/', trim($p)), explode(',', $debugScope)),
            fn($p) => $p !== ''
        );
    }

    // When scope is active, scan only the scoped directories instead of the entire project
    if (!empty($scopePaths)) {
        $files = [];
        $directoryNorm = str_replace('\\', '/', rtrim($directory, '/\\'));
        foreach ($scopePaths as $scopePath) {
            $scanDir = $directoryNorm . '/' . $scopePath;
            if (is_file($scanDir) && pathinfo($scanDir, PATHINFO_EXTENSION) === 'php') {
                $files[] = $scanDir;
                continue;
            }
            if (!is_dir($scanDir)) {
                continue;
            }
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($scanDir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }
        fwrite(STDERR, "[ddless] Debug scope active: " . implode(', ', $scopePaths) . " — found " . count($files) . " files\n");
        return $files;
    }

    $files = [];
    $excludeDirs = ['vendor', 'node_modules', '.ddless', 'storage', 'bootstrap/cache', 'cache', '.git', '.idea', '.vscode'];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            function ($current, $key, $iterator) use ($excludeDirs) {
                // Skip excluded directories
                if ($current->isDir()) {
                    $dirName = $current->getFilename();
                    foreach ($excludeDirs as $exclude) {
                        if ($dirName === $exclude || strpos($current->getPathname(), DIRECTORY_SEPARATOR . $exclude . DIRECTORY_SEPARATOR) !== false) {
                            return false;
                        }
                    }
                }
                return true;
            }
        ),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    return $files;
}

function ddless_emit_progress(int $current, int $total, string $currentFile): void
{
    // Terminal mode: suppress progress markers (no Electron to consume them)
    if (isset($GLOBALS['__DDLESS_TERMINAL_HANDLER__'])) {
        return;
    }

    $percent = $total > 0 ? round(($current / $total) * 100) : 0;
    $payload = json_encode([
        'type' => 'instrumentation_progress',
        'current' => $current,
        'total' => $total,
        'percent' => $percent,
        'currentFile' => $currentFile,
    ], JSON_UNESCAPED_SLASHES);

    fwrite($GLOBALS['__DDLESS_IPC_STREAM__'], "__DDLESS_PROGRESS__:{$payload}\n");
    fflush($GLOBALS['__DDLESS_IPC_STREAM__']);
}

define('DDLESS_CACHE_VERSION', '3.4'); // v3.4: playground variable modification propagation via extract

function ddless_get_cache_dir(): string
{
    return __DIR__ . '/cache';
}

function ddless_get_cache_index_path(): string
{
    return ddless_get_cache_dir() . '/index.json';
}

function ddless_ensure_cache_dir(): bool
{
    $cacheDir = ddless_get_cache_dir();
    $instrumentedDir = $cacheDir . '/instrumented';
    
    if (!is_dir($cacheDir)) {
        if (!@mkdir($cacheDir, 0755, true)) {
            return false;
        }
    }
    
    if (!is_dir($instrumentedDir)) {
        if (!@mkdir($instrumentedDir, 0755, true)) {
            return false;
        }
    }
    
    return true;
}

function ddless_load_cache_index(): array
{
    $indexPath = ddless_get_cache_index_path();
    
    if (!is_file($indexPath)) {
        return [
            'version' => DDLESS_CACHE_VERSION,
            'files' => [],
        ];
    }
    
    $content = @file_get_contents($indexPath);
    if ($content === false) {
        return [
            'version' => DDLESS_CACHE_VERSION,
            'files' => [],
        ];
    }
    
    $data = json_decode($content, true);
    if (!is_array($data)) {
        return [
            'version' => DDLESS_CACHE_VERSION,
            'files' => [],
        ];
    }
    
    if (($data['version'] ?? '') !== DDLESS_CACHE_VERSION) {
        ddless_log("[ddless][cache] Cache version mismatch, invalidating all cache\n");
        ddless_clear_cache();
        return [
            'version' => DDLESS_CACHE_VERSION,
            'files' => [],
        ];
    }
    
    return $data;
}

function ddless_save_cache_index(array $index): bool
{
    if (!ddless_ensure_cache_dir()) {
        return false;
    }
    
    $indexPath = ddless_get_cache_index_path();
    $content = json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    
    return @file_put_contents($indexPath, $content) !== false;
}

function ddless_get_cache_filename(string $absolutePath): string
{
    return md5($absolutePath) . '.php';
}

function ddless_file_has_changed(string $absolutePath, array $cacheIndex): bool
{
    if (!isset($cacheIndex['files'][$absolutePath])) {
        return true; // Not in cache
    }
    
    $cached = $cacheIndex['files'][$absolutePath];
    
    if (!is_file($absolutePath)) {
        return true;
    }
    
    $currentMtime = @filemtime($absolutePath);
    $currentSize = @filesize($absolutePath);
    
    if ($currentMtime !== ($cached['mtime'] ?? null) || 
        $currentSize !== ($cached['size'] ?? null)) {
        return true;
    }
    
    // (We could add hash verification here for extra safety, but it's slower)
    return false;
}

function ddless_save_to_cache(string $absolutePath, string $instrumentedCode, array &$cacheIndex): bool
{
    if (!ddless_ensure_cache_dir()) {
        return false;
    }
    
    $cacheFilename = ddless_get_cache_filename($absolutePath);
    $cachePath = ddless_get_cache_dir() . '/instrumented/' . $cacheFilename;
    
    if (@file_put_contents($cachePath, $instrumentedCode) === false) {
        return false;
    }
    
    $cacheIndex['files'][$absolutePath] = [
        'mtime' => @filemtime($absolutePath),
        'size' => @filesize($absolutePath),
        'cacheFile' => $cacheFilename,
        'cachedAt' => time(),
    ];
    
    return true;
}

function ddless_load_from_cache(string $absolutePath, array $cacheIndex): ?string
{
    if (!isset($cacheIndex['files'][$absolutePath])) {
        return null;
    }
    
    $cached = $cacheIndex['files'][$absolutePath];
    $cacheFile = $cached['cacheFile'] ?? null;
    
    if (!$cacheFile) {
        return null;
    }
    
    $cachePath = ddless_get_cache_dir() . '/instrumented/' . $cacheFile;
    
    if (!is_file($cachePath)) {
        return null;
    }
    
    $content = @file_get_contents($cachePath);
    return $content !== false ? $content : null;
}

function ddless_clear_cache(): void
{
    $cacheDir = ddless_get_cache_dir();
    $instrumentedDir = $cacheDir . '/instrumented';
    
    if (is_dir($instrumentedDir)) {
        $files = glob($instrumentedDir . '/*.php');
        if ($files) {
            foreach ($files as $file) {
                @unlink($file);
            }
        }
    }
    
    $indexPath = ddless_get_cache_index_path();
    if (is_file($indexPath)) {
        @unlink($indexPath);
    }
}

function ddless_cleanup_cache(array &$cacheIndex): int
{
    $removed = 0;
    $instrumentedDir = ddless_get_cache_dir() . '/instrumented';
    
    foreach ($cacheIndex['files'] as $absolutePath => $cached) {
        if (!is_file($absolutePath)) {
            // File no longer exists, remove from cache
            $cacheFile = $cached['cacheFile'] ?? null;
            if ($cacheFile) {
                @unlink($instrumentedDir . '/' . $cacheFile);
            }
            unset($cacheIndex['files'][$absolutePath]);
            $removed++;
        }
    }
    
    return $removed;
}

// ---------------------------------------------------------------------------
// Manifest: skip full file scan when nothing changed (TTL-based)
// ---------------------------------------------------------------------------

function ddless_get_manifest_path(): string
{
    return ddless_get_session_dir() . '/manifest.json';
}

function ddless_get_invalidated_files_path(): string
{
    return ddless_get_session_dir() . '/invalidated_files.json';
}

function ddless_compute_context_hash(): string
{
    // Hash of breakpoint files + debug scope — if either changes, we must re-scan
    $bpFiles = array_keys($GLOBALS['__DDLESS_BREAKPOINT_FILES__'] ?? []);
    sort($bpFiles);
    $scope = getenv('DDLESS_DEBUG_SCOPE') ?: '';
    return md5(implode("\0", $bpFiles) . '|' . $scope);
}

/**
 * Read and consume the invalidated files queue written by Electron.
 * Returns array of absolute paths that need re-instrumentation, or null if no queue file.
 */
function ddless_consume_invalidated_files(): ?array
{
    $path = ddless_get_invalidated_files_path();
    if (!is_file($path)) {
        return null;
    }

    $raw = @file_get_contents($path);
    @unlink($path); // Consume: delete after reading

    if ($raw === false) {
        return null;
    }

    $list = json_decode($raw, true);
    if (!is_array($list)) {
        return null;
    }

    // Return as a lookup set for O(1) checks
    return $list;
}

function ddless_try_manifest_fast_path(): bool
{
    $manifestPath = ddless_get_manifest_path();
    if (!is_file($manifestPath)) {
        return false;
    }

    $raw = @file_get_contents($manifestPath);
    if ($raw === false) {
        return false;
    }

    $manifest = json_decode($raw, true);
    if (!is_array($manifest)) {
        return false;
    }

    // Check if breakpoints or scope changed
    if (($manifest['contextHash'] ?? '') !== ddless_compute_context_hash()) {
        return false;
    }

    // Check cache version
    if (($manifest['cacheVersion'] ?? '') !== DDLESS_CACHE_VERSION) {
        return false;
    }

    // Read invalidated files queue (written by Electron when auto-sync detects changes)
    $invalidatedList = ddless_consume_invalidated_files();
    $invalidatedSet = $invalidatedList !== null ? array_flip($invalidatedList) : [];

    // Fast path: load files from cache, re-instrument only invalidated ones
    $cacheIndex = ddless_load_cache_index();
    $filePaths = $manifest['files'] ?? [];
    $loaded = 0;
    $reInstrumented = 0;

    foreach ($filePaths as $realPath) {
        if ($realPath === '' || isset($GLOBALS['__DDLESS_INSTRUMENTED_CODE__'][$realPath])) {
            $loaded++;
            continue;
        }

        // Files with breakpoints must always be re-instrumented
        $hasBreakpoints = isset($GLOBALS['__DDLESS_BREAKPOINT_FILES__'][$realPath]);

        // Check if file is in the invalidation queue
        $isInvalidated = isset($invalidatedSet[$realPath]);

        if ($hasBreakpoints || $isInvalidated) {
            // Re-instrument this specific file (not the whole project)
            $instrumented = $hasBreakpoints
                ? ddless_instrument_php_file($realPath)
                : ddless_instrument_php_file_ondemand($realPath, true);

            if ($instrumented !== null && !$hasBreakpoints) {
                ddless_save_to_cache($realPath, $instrumented, $cacheIndex);
            }
            $reInstrumented++;
        } else {
            $instrumented = ddless_load_from_cache($realPath, $cacheIndex);
        }

        if ($instrumented !== null) {
            $GLOBALS['__DDLESS_INSTRUMENTED_CODE__'][$realPath] = $instrumented;
            $loaded++;
        }
    }

    if ($reInstrumented > 0) {
        ddless_save_cache_index($cacheIndex);
        ddless_log("[ddless] Manifest fast path: {$loaded} files loaded ({$reInstrumented} re-instrumented)\n");
    } else {
        ddless_log("[ddless] Manifest fast path: {$loaded} files loaded from cache\n");
    }
    return true;
}

function ddless_save_manifest(array $filePaths): void
{
    $manifest = [
        'contextHash' => ddless_compute_context_hash(),
        'cacheVersion' => DDLESS_CACHE_VERSION,
        'files' => array_values($filePaths),
    ];

    @file_put_contents(ddless_get_manifest_path(), json_encode($manifest, JSON_UNESCAPED_SLASHES));
}

// ---------------------------------------------------------------------------
// Full instrumentation (with manifest save at the end)
// ---------------------------------------------------------------------------

function ddless_has_debug_scope(): bool
{
    $scope = getenv('DDLESS_DEBUG_SCOPE');
    return $scope !== false && $scope !== '';
}

function ddless_instrument_all_project_files(): int
{
    if (ddless_try_manifest_fast_path()) {
        return count($GLOBALS['__DDLESS_INSTRUMENTED_CODE__'] ?? []);
    }

    if (!ddless_has_debug_scope()) {
        $bpFiles = array_keys($GLOBALS['__DDLESS_INSTRUMENTED_CODE__'] ?? []);
        ddless_save_manifest($bpFiles);
        $count = count($bpFiles);
        ddless_log("[ddless] No scope — breakpoint-only mode: {$count} files pre-instrumented (on-demand for stepping)\n");
        return $count;
    }

    $projectRoot = defined('DDLESS_PROJECT_ROOT') ? DDLESS_PROJECT_ROOT : dirname(__DIR__);

    ddless_log("[ddless] Starting scoped instrumentation with cache...\n");
    $startTime = microtime(true);

    $cacheIndex = ddless_load_cache_index();
    $cacheHits = 0;
    $cacheMisses = 0;
    $cacheIndexModified = false;

    $cleanedUp = ddless_cleanup_cache($cacheIndex);
    if ($cleanedUp > 0) {
        $cacheIndexModified = true;
    }

    $allFiles = ddless_find_all_php_files($projectRoot);
    $totalFiles = count($allFiles);
    ddless_log("[ddless] Scope active — found {$totalFiles} PHP files\n");

    ddless_emit_progress(0, $totalFiles, 'Checking cache...');

    $instrumentedCount = 0;
    $skippedCount = 0;
    $processedCount = 0;
    $lastProgressUpdate = 0;
    $manifestFiles = [];

    foreach ($allFiles as $absolutePath) {
        $processedCount++;

        if (isset($GLOBALS['__DDLESS_INSTRUMENTED_CODE__'][$absolutePath])) {
            $manifestFiles[] = $absolutePath;
            $instrumentedCount++;
            continue;
        }

        $realPath = realpath($absolutePath) ?: $absolutePath;

        $projectRootNorm = str_replace('\\', '/', rtrim($projectRoot, '/\\'));
        $pathNorm = str_replace('\\', '/', $realPath);

        if (strpos($pathNorm, $projectRootNorm) === 0) {
            $relativePath = ltrim(substr($pathNorm, strlen($projectRootNorm)), '/');
        } else {
            $relativePath = basename($realPath);
        }

        if ($processedCount - $lastProgressUpdate >= 10) {
            ddless_emit_progress($processedCount, $totalFiles, $relativePath);
            $lastProgressUpdate = $processedCount;
        }

        $hasBreakpoints = isset($GLOBALS['__DDLESS_BREAKPOINT_FILES__'][$realPath]) ||
                          isset($GLOBALS['__DDLESS_BREAKPOINT_FILES__'][$absolutePath]);

        $instrumented = null;

        if (!$hasBreakpoints && !ddless_file_has_changed($realPath, $cacheIndex)) {
            $instrumented = ddless_load_from_cache($realPath, $cacheIndex);
            if ($instrumented !== null) {
                $cacheHits++;
            }
        }

        if ($instrumented === null) {
            $cacheMisses++;

            if ($hasBreakpoints) {
                $instrumented = ddless_instrument_php_file($realPath);
            } else {
                $instrumented = ddless_instrument_php_file_ondemand($realPath, true);
            }

            if ($instrumented !== null && !$hasBreakpoints) {
                ddless_save_to_cache($realPath, $instrumented, $cacheIndex);
                $cacheIndexModified = true;
            }
        }

        if ($instrumented !== null) {
            $GLOBALS['__DDLESS_INSTRUMENTED_CODE__'][$realPath] = $instrumented;
            $manifestFiles[] = $realPath;
            $instrumentedCount++;
        } else {
            $skippedCount++;
        }
    }

    if ($cacheIndexModified) {
        ddless_save_cache_index($cacheIndex);
    }

    ddless_save_manifest($manifestFiles);

    ddless_emit_progress($totalFiles, $totalFiles, 'Complete');

    $elapsed = round((microtime(true) - $startTime) * 1000);
    ddless_log("[ddless] Scoped instrumentation complete: {$instrumentedCount} files ({$cacheHits} cached, {$cacheMisses} instrumented, {$skippedCount} skipped) in {$elapsed}ms\n");

    return $instrumentedCount;
}

if (ddless_is_debug_mode_active()) {
    if (!empty($GLOBALS['__DDLESS_BREAKPOINT_FILES__'])) {
        foreach ($GLOBALS['__DDLESS_BREAKPOINT_FILES__'] as $absolutePath => $info) {
            if (!is_file($absolutePath)) {
                continue;
            }
            
            if (isset($GLOBALS['__DDLESS_INSTRUMENTED_CODE__'][$absolutePath])) {
                continue;
            }
            
            $instrumented = ddless_instrument_php_file($absolutePath);
            if ($instrumented !== null) {
                $GLOBALS['__DDLESS_INSTRUMENTED_CODE__'][$absolutePath] = $instrumented;
                if (function_exists('opcache_invalidate')) {
                    opcache_invalidate($absolutePath, true);
                }
                $realAbsPath = realpath($absolutePath);
                if ($realAbsPath && $realAbsPath !== $absolutePath) {
                    $GLOBALS['__DDLESS_INSTRUMENTED_CODE__'][$realAbsPath] = $instrumented;
                    if (function_exists('opcache_invalidate')) {
                        opcache_invalidate($realAbsPath, true);
                    }
                }
                ddless_log("[ddless] Instrumented (with breakpoints): {$info['relativePath']}\n");
            }
        }
    }
    
    ddless_instrument_all_project_files();
    
    if (!empty($GLOBALS['__DDLESS_INSTRUMENTED_CODE__'])) {
        ddless_log("[ddless] Total files ready for debugging: " . count($GLOBALS['__DDLESS_INSTRUMENTED_CODE__']) . "\n");
    }
}

// Safe Include Wrapper - intercepts file includes without affecting other protocols
class DDLessSafeIncludeWrapper
{
    public $context;
    private $handle = null;
    private $content = null;
    private $position = 0;
    private $path = '';
    private static $registered = false;
    private static $inOperation = false;

    public static function register(): void
    {
        if (self::$registered) return;
        @stream_wrapper_unregister('file');
        stream_wrapper_register('file', self::class, 0);
        self::$registered = true;
    }

    public static function restore(): void
    {
        if (!self::$registered) return;
        @stream_wrapper_restore('file');
        self::$registered = false;
    }

    private static function isInstrumentedFile(string $path): bool
    {
        if (empty($GLOBALS['__DDLESS_INSTRUMENTED_CODE__'])) {
            return false;
        }
        if (!str_ends_with($path, '.php')) {
            return false;
        }
        $realPath = self::getRealPath($path);
        return isset($GLOBALS['__DDLESS_INSTRUMENTED_CODE__'][$realPath]) ||
               isset($GLOBALS['__DDLESS_INSTRUMENTED_CODE__'][$path]);
    }

    private static function getRealPath(string $path): string
    {
        self::restore();
        $real = realpath($path);
        self::register();
        return $real ?: $path;
    }

    private static function getInstrumentedContent(string $path): ?string
    {
        $realPath = self::getRealPath($path);
        return $GLOBALS['__DDLESS_INSTRUMENTED_CODE__'][$realPath] 
            ?? $GLOBALS['__DDLESS_INSTRUMENTED_CODE__'][$path] 
            ?? null;
    }

    public function stream_open($path, $mode, $options, &$opened_path)
    {
        $this->path = $path;
        
        $isReadMode = ($mode === 'r' || $mode === 'rb');
        $isPhpFile = str_ends_with($path, '.php');
        
        if ($isReadMode && $isPhpFile) {
            if (self::isInstrumentedFile($path)) {
                $this->content = self::getInstrumentedContent($path);
                if ($this->content !== null) {
                    // (not the pre-instrumented code, which has shifted line numbers)
                    if (ddless_is_trace_mode_active() && !ddless_is_vendor_path($path)) {
                        $realPath = self::getRealPath($path);
                        if (!isset($GLOBALS['__DDLESS_TRACE_TRIED__'][$realPath])) {
                            $GLOBALS['__DDLESS_TRACE_TRIED__'][$realPath] = true;
                            $projectRoot = defined('DDLESS_PROJECT_ROOT') ? DDLESS_PROJECT_ROOT : dirname(__DIR__);
                            $projectRoot = str_replace('\\', '/', rtrim($projectRoot, '/\\'));
                            $normalizedPath = str_replace('\\', '/', $realPath);
                            $relPath = strpos($normalizedPath, $projectRoot) === 0
                                ? ltrim(substr($normalizedPath, strlen($projectRoot)), '/')
                                : basename($realPath);

                            self::restore();
                            $originalContent = @file_get_contents($realPath);
                            self::register();

                            if ($originalContent !== false) {
                                $traced = ddless_instrument_trace_only($originalContent, $realPath, $relPath);
                                $baseCode = $traced ?? $originalContent;

                                $bpInfo = $GLOBALS['__DDLESS_BREAKPOINT_FILES__'][$realPath]
                                    ?? $GLOBALS['__DDLESS_BREAKPOINT_FILES__'][$path] ?? null;
                                if ($bpInfo) {
                                    $reInstrumented = ddless_instrument_code_with_tokens(
                                        $baseCode, $realPath, $relPath,
                                        $bpInfo['lines'],
                                        $bpInfo['conditions'] ?? [],
                                        $bpInfo['logpoints'] ?? [],
                                        $bpInfo['dumppoints'] ?? [],
                                        $bpInfo['conditionalDumppoints'] ?? []
                                    );
                                    if ($reInstrumented !== null) {
                                        $this->content = $reInstrumented;
                                        $GLOBALS['__DDLESS_INSTRUMENTED_CODE__'][$realPath] = $reInstrumented;
                                    }
                                } elseif ($traced !== null) {
                                    $this->content = $traced;
                                    $GLOBALS['__DDLESS_INSTRUMENTED_CODE__'][$realPath] = $traced;
                                }
                            }
                        }
                    }
                    $this->position = 0;
                    return true;
                }
            }
            
            if ((ddless_is_step_mode_active() || ddless_is_timeframe_active()) && !ddless_is_vendor_path($path)) {
                $realPath = self::getRealPath($path);

                if (!isset($GLOBALS['__DDLESS_ONDEMAND_TRIED__'][$realPath])) {
                    $GLOBALS['__DDLESS_ONDEMAND_TRIED__'][$realPath] = true;

                    self::restore();
                    $instrumented = ddless_instrument_php_file_ondemand($realPath);
                    self::register();

                    if ($instrumented !== null) {
                        $GLOBALS['__DDLESS_INSTRUMENTED_CODE__'][$realPath] = $instrumented;
                        $this->content = $instrumented;
                        $this->position = 0;
                        return true;
                    }
                }
            }

            if (ddless_is_trace_mode_active() && !ddless_is_vendor_path($path)) {
                $realPath = self::getRealPath($path);

                if (!isset($GLOBALS['__DDLESS_TRACE_TRIED__'][$realPath])) {
                    $GLOBALS['__DDLESS_TRACE_TRIED__'][$realPath] = true;

                    self::restore();
                    $content = @file_get_contents($realPath);
                    self::register();

                    if ($content !== false) {
                        $projectRoot = defined('DDLESS_PROJECT_ROOT') ? DDLESS_PROJECT_ROOT : dirname(__DIR__);
                        $projectRoot = str_replace('\\', '/', rtrim($projectRoot, '/\\'));
                        $normalizedPath = str_replace('\\', '/', $realPath);
                        $relPath = strpos($normalizedPath, $projectRoot) === 0
                            ? ltrim(substr($normalizedPath, strlen($projectRoot)), '/')
                            : basename($realPath);

                        $instrumented = ddless_instrument_trace_only($content, $realPath, $relPath);
                        if ($instrumented !== null) {
                            $GLOBALS['__DDLESS_INSTRUMENTED_CODE__'][$realPath] = $instrumented;
                            $this->content = $instrumented;
                            $this->position = 0;
                            return true;
                        }
                    }
                }
            }
        }
        
        self::restore();
        $this->handle = @fopen($path, $mode);
        self::register();
        
        return $this->handle !== false;
    }

    public function stream_read($count)
    {
        if ($this->content !== null) {
            $data = substr($this->content, $this->position, $count);
            $this->position += strlen($data);
            return $data;
        }
        return $this->handle ? fread($this->handle, $count) : false;
    }

    public function stream_write($data)
    {
        return $this->handle ? fwrite($this->handle, $data) : false;
    }

    public function stream_tell()
    {
        if ($this->content !== null) {
            return $this->position;
        }
        return $this->handle ? ftell($this->handle) : false;
    }

    public function stream_eof()
    {
        if ($this->content !== null) {
            return $this->position >= strlen($this->content);
        }
        return $this->handle ? feof($this->handle) : true;
    }

    public function stream_seek($offset, $whence = SEEK_SET)
    {
        if ($this->content !== null) {
            $length = strlen($this->content);
            switch ($whence) {
                case SEEK_SET: $this->position = $offset; break;
                case SEEK_CUR: $this->position += $offset; break;
                case SEEK_END: $this->position = $length + $offset; break;
            }
            return true;
        }
        return $this->handle ? fseek($this->handle, $offset, $whence) === 0 : false;
    }

    public function stream_stat()
    {
        if ($this->content !== null) {
            return [
                'dev' => 0, 'ino' => 0, 'mode' => 0100644, 'nlink' => 1,
                'uid' => 0, 'gid' => 0, 'rdev' => 0, 'size' => strlen($this->content),
                'atime' => time(), 'mtime' => time(), 'ctime' => time(),
                'blksize' => -1, 'blocks' => -1,
            ];
        }
        return $this->handle ? fstat($this->handle) : false;
    }

    public function stream_close()
    {
        if ($this->handle) {
            fclose($this->handle);
            $this->handle = null;
        }
        $this->content = null;
    }

    public function stream_set_option($option, $arg1, $arg2) { return false; }
    public function stream_lock($operation) {
        if ($this->handle && is_resource($this->handle)) {
            $baseOp = $operation & ~LOCK_NB;
            if ($baseOp === LOCK_SH || $baseOp === LOCK_EX || $baseOp === LOCK_UN) {
                return @flock($this->handle, $operation);
            }
        }
        // This is safe in debug environment where we don't have real concurrency concerns
        return true;
    }
    public function stream_flush() { return $this->handle ? fflush($this->handle) : true; }
    public function stream_truncate($new_size) { return $this->handle ? ftruncate($this->handle, $new_size) : false; }

    public function url_stat($path, $flags)
    {
        self::restore();
        $result = ($flags & STREAM_URL_STAT_LINK) ? @lstat($path) : @stat($path);
        self::register();
        return $result ?: false;
    }

    public function stream_metadata($path, $option, $value)
    {
        self::restore();
        switch ($option) {
            case STREAM_META_TOUCH:
                $r = empty($value) ? @touch($path) : @touch($path, $value[0], $value[1] ?? $value[0]);
                break;
            case STREAM_META_OWNER_NAME:
            case STREAM_META_OWNER:
                $r = @chown($path, $value);
                break;
            case STREAM_META_GROUP_NAME:
            case STREAM_META_GROUP:
                $r = @chgrp($path, $value);
                break;
            case STREAM_META_ACCESS:
                $r = @chmod($path, $value);
                break;
            default:
                $r = false;
        }
        self::register();
        return $r;
    }

    public function unlink($path) { self::restore(); $r = @unlink($path); self::register(); return $r; }
    public function rename($from, $to) { self::restore(); $r = @rename($from, $to); self::register(); return $r; }
    public function mkdir($path, $mode, $options) { self::restore(); $r = @mkdir($path, $mode, ($options & STREAM_MKDIR_RECURSIVE) !== 0); self::register(); return $r; }
    public function rmdir($path, $options) { self::restore(); $r = @rmdir($path); self::register(); return $r; }
    public function dir_opendir($path, $options) { self::restore(); $this->handle = @opendir($path); self::register(); return $this->handle !== false; }
    public function dir_readdir() { return readdir($this->handle); }
    public function dir_rewinddir() { rewinddir($this->handle); return true; }
    public function dir_closedir() { closedir($this->handle); return true; }
}

function ddless_register_stream_wrapper(): void
{
    if (!($GLOBALS['__DDLESS_WRAPPER_REGISTERED__'] ?? false) && ddless_is_debug_mode_active()) {
        DDLessSafeIncludeWrapper::register();
        $GLOBALS['__DDLESS_WRAPPER_REGISTERED__'] = true;
        $hasPreInstrumented = !empty($GLOBALS['__DDLESS_INSTRUMENTED_CODE__']);
        ddless_log("[ddless] Stream wrapper registered (pre-instrumented: " . ($hasPreInstrumented ? 'yes' : 'no') . ", on-demand: enabled)\n");
    }
}

// On-demand allows step-in and step-out to work across files even without breakpoints
if (ddless_is_debug_mode_active()) {
    if (empty($GLOBALS['__DDLESS_DEFER_WRAPPER__'])) {
        DDLessSafeIncludeWrapper::register();
        $GLOBALS['__DDLESS_WRAPPER_REGISTERED__'] = true;
        $hasPreInstrumented = !empty($GLOBALS['__DDLESS_INSTRUMENTED_CODE__']);
        ddless_log("[ddless] Stream wrapper registered (pre-instrumented: " . ($hasPreInstrumented ? 'yes' : 'no') . ", on-demand: enabled)\n");
    } else {
        ddless_log("[ddless] Stream wrapper registration deferred (will register after autoload)\n");
    }
}
