<?php
/**
 * Tests for ddless_prompt_with_rule() — the Task Runner interactive prompts
 * (ask / confirm / choice) — in every framework's task_runner.php.
 * Run: php tests/php/TaskPromptTest.php
 */
require_once __DIR__ . '/bootstrap.php';

$promptFrameworksBase = null;
foreach ([DDLESS_PROJECT_ROOT . '/.ddless/frameworks', DDLESS_PROJECT_ROOT . '/src/frameworks', __DIR__ . '/../frameworks', __DIR__ . '/../src/frameworks'] as $candidate) {
    if (is_dir($candidate)) {
        $promptFrameworksBase = realpath($candidate);
        break;
    }
}

/**
 * Run a framework's ddless_prompt_with_rule() in a child process, feeding it
 * $stdin and then closing the pipe. Returns the child's stdout, or null when
 * it had to be killed for not finishing in time.
 */
function run_prompt_in_child(string $runnerFile, string $stdin, bool $withRule): ?string
{
    $source = file_get_contents($runnerFile);
    if (!preg_match('/function\s+ddless_prompt_with_rule\b.*?(?=\n(?:function|class|abstract|final)\s)/s', $source, $m)) {
        throw new \RuntimeException("Could not extract ddless_prompt_with_rule from {$runnerFile}");
    }

    $rule = $withRule ? "fn(\$value) => \$value !== '' ? true : 'Required'" : 'null';
    $script = tempnam(sys_get_temp_dir(), 'ddless_prompt_');
    file_put_contents($script, "<?php\n"
        . "function ddless_task_emit(string \$type, array \$data): void { echo \"PROMPT\\n\"; }\n"
        . $m[0] . "\n"
        . "try {\n"
        . "    \$answer = ddless_prompt_with_rule('ask', 'Name?', [], {$rule});\n"
        . "    echo 'RETURNED:' . \$answer . \"\\n\";\n"
        . "} catch (\\RuntimeException \$e) {\n"
        . "    echo 'CLOSED:' . \$e->getMessage() . \"\\n\";\n"
        . "}\n");

    $process = proc_open([PHP_BINARY, $script], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
    fwrite($pipes[0], $stdin);
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);

    $output = '';
    $deadline = microtime(true) + 5.0;
    $finished = false;
    while (microtime(true) < $deadline) {
        $chunk = fread($pipes[1], 8192);
        if ($chunk !== false && $chunk !== '') {
            $output .= $chunk;
            if (strlen($output) > 200000) {
                break;
            }
        }
        $status = proc_get_status($process);
        if (!$status['running']) {
            $output .= (string) stream_get_contents($pipes[1]);
            $finished = true;
            break;
        }
        usleep(10000);
    }

    if (!$finished) {
        proc_terminate($process);
    }
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    @unlink($script);

    return $finished ? $output : null;
}

if ($promptFrameworksBase === null) {
    fwrite(STDERR, "[TaskPromptTest] frameworks directory not found; skipping.\n");
} else {
    foreach (glob($promptFrameworksBase . '/*/task_runner.php') as $runnerFile) {
        $framework = basename(dirname($runnerFile));

        section("Task Runner prompts — {$framework}");

        test("[{$framework}] a typed answer is returned", function () use ($runnerFile) {
            $output = run_prompt_in_child($runnerFile, "alice\n", true);
            assert_not_null($output, 'prompt did not finish');
            assert_contains($output, 'RETURNED:alice');
        });

        test("[{$framework}] a closed stdin aborts a validated prompt instead of looping", function () use ($runnerFile) {
            $output = run_prompt_in_child($runnerFile, '', true);
            assert_not_null($output, 'prompt kept looping after stdin closed');
            assert_contains($output, 'CLOSED:');
            assert_eq(1, substr_count($output, 'PROMPT'), 'the prompt must be emitted once');
        });

        test("[{$framework}] a closed stdin aborts a plain prompt instead of answering empty", function () use ($runnerFile) {
            $output = run_prompt_in_child($runnerFile, '', false);
            assert_not_null($output, 'prompt did not finish');
            assert_contains($output, 'CLOSED:');
        });
    }
}

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    exit(print_test_results());
}
