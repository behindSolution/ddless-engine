<?php
/**
 * Tests for task_trigger.php run end to end with the plain PHP task runner:
 * the per-run PID file the app uses to stop a task under docker/wsl/ssh.
 * Run: php tests/php/TaskTriggerTest.php
 */
require_once __DIR__ . '/bootstrap.php';

/**
 * Run a scratchpad through task_trigger.php in a throwaway project and return
 * the decoded task output entries plus the PID files left behind.
 *
 * @return array{entries: array<int, array>, leftover: string[], stderr: string}
 */
function run_task_trigger(array $input): array
{
    $engineDir = dirname($GLOBALS['debugPhpPath']);
    $root = sys_get_temp_dir() . '/ddless_task_trigger_' . uniqid();
    mkdir($root . '/.ddless/frameworks/php', 0755, true);
    copy($engineDir . '/task_trigger.php', $root . '/.ddless/task_trigger.php');
    copy($engineDir . '/frameworks/php/task_runner.php', $root . '/.ddless/frameworks/php/task_runner.php');
    file_put_contents($root . '/.ddless/task_input_temp.json', json_encode($input + ['framework' => 'php', 'imports' => []]));

    $process = proc_open(
        [PHP_BINARY, $root . '/.ddless/task_trigger.php'],
        [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
        $pipes,
        $root,
        array_merge(getenv(), ['DDLESS_DEBUG_MODE' => 'false'])
    );
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    $entries = [];
    foreach (preg_split("/\r?\n/", (string) $stdout) as $line) {
        if (strpos($line, '__DDLESS_TASK_OUTPUT__:') === 0) {
            $entries[] = json_decode(substr($line, strlen('__DDLESS_TASK_OUTPUT__:')), true);
        }
    }
    $leftover = array_map('basename', glob($root . '/.ddless/task_*.pid') ?: []);

    remove_isolated_project($root);

    return ['entries' => $entries, 'leftover' => $leftover, 'stderr' => (string) $stderr];
}

$reportPidFiles = "<?php\n"
    . "\$files = glob(__DIR__ . '/../../task_*.pid');\n"
    . "\$this->info(json_encode(['files' => array_map('basename', \$files), 'pid' => \$files ? trim((string) file_get_contents(\$files[0])) : '', 'self' => (string) getmypid()]));\n";

section('task_trigger.php — per-run PID file');

test('the running task records its own PID under the run id and removes it on exit', function () use ($reportPidFiles) {
    $run = run_task_trigger(['code' => $reportPidFiles, 'runId' => 'abc123def456']);
    assert_true(count($run['entries']) > 0, 'task produced no output. stderr: ' . $run['stderr']);
    $seen = json_decode($run['entries'][0]['message'] ?? '', true);
    assert_eq(['task_abc123def456.pid'], $seen['files'] ?? null);
    assert_eq($seen['self'], $seen['pid'], 'the file must hold the PID of the PHP process running the task');
    assert_eq([], $run['leftover'], 'PID file removed when the task ends');
});

test('a run id that is not a plain token never becomes a file name', function () use ($reportPidFiles) {
    $run = run_task_trigger(['code' => $reportPidFiles, 'runId' => '../../evil']);
    $seen = json_decode($run['entries'][0]['message'] ?? '', true);
    assert_eq([], $seen['files'] ?? null);
});

test('tasks without a run id still run (older app versions)', function () use ($reportPidFiles) {
    $run = run_task_trigger(['code' => $reportPidFiles]);
    $seen = json_decode($run['entries'][0]['message'] ?? '', true);
    assert_eq([], $seen['files'] ?? null);
});

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    exit(print_test_results());
}
