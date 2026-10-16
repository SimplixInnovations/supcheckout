<?php
/**
 * Fail-closed completion contract for the permanent PHP regression harness loop.
 *
 * A harness that requires a production file guarded by `defined('ABSPATH') || exit;`
 * before defining ABSPATH exits with status 0 without running its assertions, and an
 * exit-status-only loop counts it as passing. The Quality Gates loop therefore also
 * requires the last line of each harness's output to be `HARNESS-COMPLETE: <basename>`.
 *
 * This harness proves both halves of that contract:
 *  - statically, every harness in the workflow list prints its own completion marker;
 *  - behaviourally, the exact loop script from quality-gates.yml (extracted, with only
 *    the harness list replaced by fixtures) passes a complete harness and fails a
 *    silent guard exit, an early exit(0), a missing/foreign/non-final marker, a
 *    non-zero exit and a PHP warning.
 *
 * Bash is located through SUPCHECKOUT_HARNESS_BASH, then Git for Windows on Windows,
 * then `bash` on PATH. If no bash can run, the harness fails; it never skips.
 */

$root = dirname(__DIR__, 2);
$workflow_path = $root . '/.github/workflows/quality-gates.yml';
$step_name = 'Permanent PHP regression harnesses';
$marker_statement = "echo 'HARNESS-COMPLETE: ' . basename(__FILE__) . \"\\n\";";

$pass = 0;
$fail = 0;

function hcm_assert($condition, $message) {
    global $pass, $fail;
    if ($condition) {
        $pass++;
        echo "PASS: {$message}\n";
        return;
    }
    $fail++;
    echo "FAIL: {$message}\n";
}

/**
 * Return the dedented `run: |` body of the named workflow step, or null.
 *
 * @param string $yaml Workflow source.
 * @param string $name Step name.
 * @return string|null
 */
function hcm_step_script($yaml, $name) {
    $lines = explode("\n", str_replace("\r\n", "\n", $yaml));
    $count = count($lines);
    for ($i = 0; $i < $count; $i++) {
        if (trim($lines[$i]) !== '- name: ' . $name) {
            continue;
        }
        for ($j = $i + 1; $j < $count; $j++) {
            if (preg_match('/^(\s*)run:\s*\|\s*$/', $lines[$j])) {
                break;
            }
            if (preg_match('/^\s*- name:/', $lines[$j])) {
                return null;
            }
        }
        if ($j >= $count) {
            return null;
        }
        $body = array();
        $indent = null;
        for ($k = $j + 1; $k < $count; $k++) {
            $line = $lines[$k];
            if (trim($line) === '') {
                $body[] = '';
                continue;
            }
            preg_match('/^(\s*)/', $line, $m);
            $width = strlen($m[1]);
            if ($indent === null) {
                $indent = $width;
            }
            if ($width < $indent) {
                break;
            }
            $body[] = substr($line, $indent);
        }
        return rtrim(implode("\n", $body)) . "\n";
    }
    return null;
}

/**
 * Return the harness paths listed in the step script's `harnesses=( ... )` array.
 *
 * @param string $script Step script.
 * @return array<int, string>
 */
function hcm_listed_harnesses($script) {
    if (!preg_match('/^harnesses=\(\n(.*?)\n\)$/ms', $script, $m)) {
        return array();
    }
    return array_values(array_filter(array_map('trim', explode("\n", $m[1])), 'strlen'));
}

/** @return string|null */
function hcm_find_bash() {
    $candidates = array();
    $override = getenv('SUPCHECKOUT_HARNESS_BASH');
    if (is_string($override) && $override !== '') {
        $candidates[] = $override;
    }
    if (PHP_OS_FAMILY === 'Windows') {
        $candidates[] = 'C:\\Program Files\\Git\\bin\\bash.exe';
        $candidates[] = 'C:\\Program Files\\Git\\usr\\bin\\bash.exe';
    } else {
        $candidates[] = 'bash';
    }
    foreach ($candidates as $candidate) {
        $result = hcm_run(array($candidate, '-c', 'printf ok'), null);
        if ($result !== null && $result['status'] === 0 && $result['output'] === 'ok') {
            return $candidate;
        }
    }
    return null;
}

/**
 * Run a command without a shell and capture combined output.
 *
 * @param array<int, string> $command Command and arguments.
 * @param array<string, string>|null $env Environment, or null to inherit.
 * @return array{status: int, output: string}|null
 */
function hcm_run(array $command, $env) {
    $descriptors = array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('redirect', 1));
    $process = @proc_open($command, $descriptors, $pipes, null, $env, array('bypass_shell' => true));
    if (!is_resource($process)) {
        return null;
    }
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $status = proc_close($process);
    return array('status' => $status, 'output' => is_string($output) ? $output : '');
}

// --- Static half: the workflow loop and every listed harness honour the contract. ---

$yaml = is_file($workflow_path) ? (string) file_get_contents($workflow_path) : '';
$script = hcm_step_script($yaml, $step_name);
hcm_assert(is_string($script) && strpos($script, 'for harness in "${harnesses[@]}"; do') !== false, 'quality-gates.yml carries the permanent PHP harness loop');
$script = is_string($script) ? $script : '';
hcm_assert(
    strpos($script, 'last_line="$(tail -n 1 <<<"$output")"') !== false
    && strpos($script, 'if [[ "$last_line" != "HARNESS-COMPLETE: $(basename "$harness")" ]]; then') !== false,
    'loop requires the last output line to be the harness\'s own completion marker'
);

$listed = hcm_listed_harnesses($script);
hcm_assert(count($listed) >= 50, 'harness list parsed from the workflow (' . count($listed) . ' entries)');
hcm_assert(count($listed) === count(array_unique($listed)), 'harness list has no duplicates');
hcm_assert(in_array('tests/harness/' . basename(__FILE__), $listed, true), 'this completion-contract harness is itself in the permanent list');

$missing_files = array();
$missing_markers = array();
foreach ($listed as $relative) {
    $path = $root . '/' . $relative;
    if (!is_file($path)) {
        $missing_files[] = $relative;
        continue;
    }
    if (strpos((string) file_get_contents($path), $marker_statement) === false) {
        $missing_markers[] = $relative;
    }
}
hcm_assert($missing_files === array(), 'every listed harness exists' . ($missing_files ? ': missing ' . implode(', ', $missing_files) : ''));
hcm_assert($missing_markers === array(), 'every listed harness prints its completion marker' . ($missing_markers ? ': missing in ' . implode(', ', $missing_markers) : ''));

// --- Behavioural half: the exact loop script fails closed on incomplete harnesses. ---

$bash = hcm_find_bash();
hcm_assert($bash !== null, 'bash is available to execute the workflow loop (set SUPCHECKOUT_HARNESS_BASH if needed)');

$tmp = rtrim(sys_get_temp_dir(), '/\\') . '/supcheckout-hcm-' . getmypid() . '-' . bin2hex(random_bytes(4));
@mkdir($tmp, 0700, true);
hcm_assert(is_dir($tmp), 'temporary fixture directory created');

$marker = "echo 'HARNESS-COMPLETE: ' . basename(__FILE__) . \"\\n\";\n";
$fixtures = array(
    'complete-harness.php' => array(0, "<?php\necho \"PASS: fixture\\n\";\n" . $marker . "exit(0);\n"),
    'silent-guard-harness.php' => array(1, "<?php\ndefined('ABSPATH') || exit;\necho \"PASS: never reached\\n\";\n" . $marker . "exit(0);\n"),
    'early-exit-harness.php' => array(1, "<?php\necho \"PASS: first assertion\\n\";\nexit(0);\n" . $marker),
    'no-marker-harness.php' => array(1, "<?php\necho \"PASS: fixture\\n\";\necho \"Fixture: 1 PASS / 0 FAIL\\n\";\nexit(0);\n"),
    'foreign-marker-harness.php' => array(1, "<?php\necho \"HARNESS-COMPLETE: complete-harness.php\\n\";\nexit(0);\n"),
    'marker-not-last-harness.php' => array(1, "<?php\n" . $marker . "echo \"late output\\n\";\nexit(0);\n"),
    'failing-harness.php' => array(1, "<?php\necho \"FAIL: fixture\\n\";\n" . $marker . "exit(1);\n"),
    'warning-harness.php' => array(1, "<?php\n\$values = array();\necho \$values['missing'];\n" . $marker . "exit(0);\n"),
);

$env = null;
if ($bash !== null && is_dir($tmp)) {
    $env = array();
    foreach (getenv() as $key => $value) {
        $env[$key] = $value;
    }
    $path_key = 'PATH';
    foreach (array_keys($env) as $key) {
        if (strtoupper($key) === 'PATH') {
            $path_key = $key;
        }
    }
    // The loop invokes `php`; make it the interpreter running this harness. Git Bash
    // converts a native Windows PATH itself, so the native separator is correct here.
    $env[$path_key] = dirname(PHP_BINARY) . PATH_SEPARATOR . (isset($env[$path_key]) ? $env[$path_key] : '');

    $replacement_count = 0;
    foreach ($fixtures as $name => $fixture) {
        file_put_contents($tmp . '/' . $name, $fixture[1]);
        $fixture_path = str_replace('\\', '/', $tmp . '/' . $name);
        $fixture_script = preg_replace(
            '/^harnesses=\(\n.*?\n\)$/ms',
            "harnesses=(\n  '" . $fixture_path . "'\n)",
            $script,
            1,
            $replacement_count
        );
        if ($replacement_count !== 1) {
            hcm_assert(false, "harness list could be substituted for fixture {$name}");
            continue;
        }
        $script_path = $tmp . '/loop-' . $name . '.sh';
        file_put_contents($script_path, $fixture_script);
        $result = hcm_run(array($bash, str_replace('\\', '/', $script_path)), $env);
        $status = $result === null ? -1 : $result['status'];
        if ($fixture[0] === 0) {
            hcm_assert($status === 0, "workflow loop accepts a harness that ran to completion ({$name}, status {$status})");
        } else {
            hcm_assert($status !== 0 && $status !== -1, "workflow loop rejects {$name} (status {$status})");
        }
    }

    // A mixed list fails as a whole even when only one harness is incomplete.
    $mixed = array(str_replace('\\', '/', $tmp . '/complete-harness.php'), str_replace('\\', '/', $tmp . '/silent-guard-harness.php'));
    $mixed_script = preg_replace(
        '/^harnesses=\(\n.*?\n\)$/ms',
        "harnesses=(\n  '" . implode("'\n  '", $mixed) . "'\n)",
        $script,
        1
    );
    file_put_contents($tmp . '/loop-mixed.sh', (string) $mixed_script);
    $result = hcm_run(array($bash, str_replace('\\', '/', $tmp . '/loop-mixed.sh')), $env);
    $status = $result === null ? -1 : $result['status'];
    hcm_assert($status !== 0 && $status !== -1, "workflow loop fails a list containing one silent harness (status {$status})");
    hcm_assert(
        $result !== null && strpos($result['output'], 'exited without its completion marker') !== false && strpos($result['output'], 'silent-guard-harness.php') !== false,
        'workflow loop names the harness that did not run to completion'
    );
}

foreach (glob($tmp . '/*') ?: array() as $file) {
    @unlink($file);
}
@rmdir($tmp);

echo "\nHarness completion marker: {$pass} PASS / {$fail} FAIL\n";
echo 'HARNESS-COMPLETE: ' . basename(__FILE__) . "\n";
exit($fail === 0 ? 0 : 1);
