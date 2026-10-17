<?php
namespace {
    define('ABSPATH', __DIR__ . '/');

    $GLOBALS['p9o'] = array(
        'options' => array(),
        'user_meta' => array(),
        'user_meta_fail' => array(),
        'executor_calls' => array(),
        'executor_results' => array(),
        'cli_lines' => array(),
        'cli_errors' => array(),
    );

    function get_option($name, $default = false) {
        return array_key_exists($name, $GLOBALS['p9o']['options']) ? $GLOBALS['p9o']['options'][$name] : $default;
    }
    function update_user_meta($user_id, $key, $value) {
        if (!empty($GLOBALS['p9o']['user_meta_fail'][$user_id])) {
            return false;
        }
        if (!isset($GLOBALS['p9o']['user_meta'][$user_id])) {
            $GLOBALS['p9o']['user_meta'][$user_id] = array();
        }
        if (array_key_exists($key, $GLOBALS['p9o']['user_meta'][$user_id])
            && $GLOBALS['p9o']['user_meta'][$user_id][$key] === $value
        ) {
            return false;
        }
        $GLOBALS['p9o']['user_meta'][$user_id][$key] = $value;
        return true;
    }
    function get_user_meta($user_id, $key, $single = false) {
        if (!isset($GLOBALS['p9o']['user_meta'][$user_id])
            || !array_key_exists($key, $GLOBALS['p9o']['user_meta'][$user_id])
        ) {
            return $single ? '' : array();
        }
        $value = $GLOBALS['p9o']['user_meta'][$user_id][$key];
        return $single ? $value : array($value);
    }
    function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }

    class WP_CLI {
        public static $commands = array();
        public static function add_command($name, $class) { self::$commands[$name] = $class; return true; }
        public static function line($message) { $GLOBALS['p9o']['cli_lines'][] = $message; }
        public static function error($message, $exit = true) {
            $GLOBALS['p9o']['cli_errors'][] = $message;
            if ($exit) throw new \RuntimeException($message);
        }
    }
}

namespace Simplixi\SUPCheckout\Migration {
    final class MigrationExecutor {
        public static function execute($user_id, $api_key, $is_test_mode, $dry_run = false) {
            $GLOBALS['p9o']['executor_calls'][] = array(
                'user_id' => $user_id,
                'api_key' => $api_key,
                'is_test_mode' => $is_test_mode,
                'dry_run' => $dry_run,
            );
            if ($user_id === 4) {
                throw new \RuntimeException('synthetic executor failure');
            }
            if (isset($GLOBALS['p9o']['executor_results'][$user_id])) {
                return $GLOBALS['p9o']['executor_results'][$user_id];
            }
            return array(
                'success' => true,
                'reason' => 'already_clean',
                'classification' => 'CLEAN',
                'migrated' => false,
                'idempotent' => true,
                'ledger_written' => false,
                'token_digest' => null,
                'preflight' => array('migration' => array('token' => 'SHOULD_NEVER_ESCAPE')),
            );
        }
    }
}

namespace {
    $root = realpath(__DIR__ . '/../..');
    require_once $root . '/src/Takeover/TakeoverState.php';
    require_once $root . '/src/Takeover/Keys.php';
    require_once $root . '/src/Migration/MigrationSettings.php';
    require_once $root . '/src/Migration/MigrationBatch.php';
    require_once $root . '/src/Migration/MigrationCliCommand.php';

    use Simplixi\SUPCheckout\Migration\MigrationSettings;
    use Simplixi\SUPCheckout\Migration\MigrationBatch;
    use Simplixi\SUPCheckout\Migration\MigrationCliCommand;

    $pass = 0; $fail = 0;
    function p9o_assert($condition, $label) {
        global $pass, $fail;
        if ($condition) { $pass++; echo "PASS: $label\n"; }
        else { $fail++; echo "FAIL: $label\n"; }
    }
    function p9o_eq($actual, $expected, $label) {
        p9o_assert($actual === $expected, $label . ' expected=' . var_export($expected, true) . ' got=' . var_export($actual, true));
    }
    function p9o_reset_runtime() {
        $GLOBALS['p9o']['executor_calls'] = array();
        $GLOBALS['p9o']['cli_lines'] = array();
        $GLOBALS['p9o']['cli_errors'] = array();
    }
    function p9o_reset_ledgers() {
        $GLOBALS['p9o']['user_meta'] = array();
        $GLOBALS['p9o']['user_meta_fail'] = array();
    }

    // 1. Strict user-id parser.
    $parsed = MigrationBatch::parseUserIds("1, 2\n3\t4");
    p9o_eq($parsed['ok'], true, 'P9O-01 parse valid');
    p9o_eq($parsed['user_ids'], array(1,2,3,4), 'P9O-01 IDs exact');
    p9o_eq(MigrationBatch::parseUserIds('01,2')['reason'], 'user_id_invalid', 'P9O-01 leading zero rejected');
    p9o_eq(MigrationBatch::parseUserIds('1,1')['reason'], 'duplicate_user_id', 'P9O-01 duplicate rejected');
    p9o_eq(MigrationBatch::parseUserIds('1e2')['reason'], 'user_id_invalid', 'P9O-01 exponent rejected');

    // 2. Settings are resolved from existing Woo gateway option only.
    $GLOBALS['p9o']['options'] = array();
    p9o_eq(MigrationSettings::resolve()['reason'], 'settings_missing', 'P9O-02 missing settings');
    $GLOBALS['p9o']['options'][MigrationSettings::OPTION_KEY] = array('api_key'=>'secret-api','test_mode'=>'maybe');
    p9o_eq(MigrationSettings::resolve()['reason'], 'test_mode_invalid', 'P9O-02 malformed mode rejected');
    $GLOBALS['p9o']['options'][MigrationSettings::OPTION_KEY] = array('api_key'=>'secret-api','test_mode'=>'yes');
    $settings = MigrationSettings::resolve();
    p9o_eq($settings['ok'], true, 'P9O-02 settings valid');
    p9o_eq($settings['is_test_mode'], true, 'P9O-02 test mode exact');
    $redacted = MigrationSettings::redact($settings);
    p9o_assert(!array_key_exists('api_key', $redacted), 'P9O-02 redaction removes API key');

    $GLOBALS['p9o']['executor_results'] = array(
        1 => array('success'=>true,'reason'=>'already_clean','classification'=>'CLEAN','migrated'=>false,'idempotent'=>true,'ledger_written'=>false,'token_digest'=>null),
        2 => array('success'=>true,'reason'=>'migrated','classification'=>'CLEAN','migrated'=>true,'idempotent'=>false,'ledger_written'=>true,'token_digest'=>str_repeat('a',64)),
        3 => array('success'=>false,'reason'=>'preflight_blocked','classification'=>'BLOCKED','migrated'=>false,'idempotent'=>false,'ledger_written'=>false,'token_digest'=>null),
    );

    // 3. Bounded page persists a redacted result ledger for every evaluated user.
    p9o_reset_ledgers();
    p9o_reset_runtime();
    $r = MigrationBatch::run(array(1,2,3), 'secret-api', true, true, 0, 2);
    p9o_eq($r['processed'], 2, 'P9O-03 bounded processed');
    p9o_eq($r['next_offset'], 2, 'P9O-03 next offset');
    p9o_eq($r['checkpoint_offset'], 2, 'P9O-03 durable checkpoint offset');
    p9o_eq($r['done'], false, 'P9O-03 not done');
    p9o_eq($r['success'], true, 'P9O-03 page success');
    p9o_eq(count($GLOBALS['p9o']['executor_calls']), 2, 'P9O-03 exactly two executor calls');
    p9o_eq($GLOBALS['p9o']['executor_calls'][0]['dry_run'], true, 'P9O-03 dry-run propagated');
    p9o_eq($r['results'][0]['operations_ledger_written'], true, 'P9O-03 CLEAN result ledger written');
    p9o_eq($r['results'][1]['operations_ledger_written'], true, 'P9O-03 migrated result ledger written');
    $resume = MigrationBatch::resumeOffset(array(1,2,3), 'secret-api', true, true);
    p9o_eq($resume['offset'], 2, 'P9O-03 resume recovers first unprocessed position');
    $ledger_json = json_encode($GLOBALS['p9o']['user_meta']);
    p9o_assert(strpos($ledger_json, 'secret-api') === false, 'P9O-03 durable ledger hides API key');
    p9o_assert(strpos($ledger_json, 'SHOULD_NEVER_ESCAPE') === false, 'P9O-03 durable ledger hides nested raw token');
    $json = json_encode($r);
    p9o_assert(strpos($json, 'secret-api') === false, 'P9O-03 API key not returned');
    p9o_assert(strpos($json, 'SHOULD_NEVER_ESCAPE') === false, 'P9O-03 nested raw token not returned');

    // 4. BLOCKED is durably recorded as evaluated and explicit progress survives lost output.
    p9o_reset_runtime();
    $r = MigrationBatch::run(array(1,2,3), 'secret-api', true, true, 2, 2);
    p9o_eq($r['processed'], 1, 'P9O-04 resumed one');
    p9o_eq($r['done'], true, 'P9O-04 done');
    p9o_eq($r['next_offset'], null, 'P9O-04 no next offset');
    p9o_eq($r['checkpoint_offset'], 3, 'P9O-04 checkpoint reaches end');
    p9o_eq($r['success'], false, 'P9O-04 failure summarized');
    p9o_eq($r['summary']['failed'], 1, 'P9O-04 failed count');
    p9o_eq($r['summary']['classifications']['BLOCKED'], 1, 'P9O-04 blocked count');
    p9o_eq($r['results'][0]['operations_ledger_written'], true, 'P9O-04 BLOCKED result persisted');
    p9o_eq(MigrationBatch::resumeOffset(array(1,2,3), 'secret-api', true, true)['offset'], 3, 'P9O-04 resume sees BLOCKED as evaluated');

    // 5. Executor exception is isolated, redacted and durably classified.
    p9o_reset_ledgers();
    p9o_reset_runtime();
    $r = MigrationBatch::run(array(4), 'secret-api', false, true, 0, 1);
    p9o_eq($r['results'][0]['reason'], 'executor_exception', 'P9O-05 exception classified');
    p9o_eq($r['results'][0]['operations_ledger_written'], true, 'P9O-05 exception result persisted');
    p9o_eq($r['success'], false, 'P9O-05 exception fails page');
    p9o_eq(MigrationBatch::resumeOffset(array(4), 'secret-api', false, true)['offset'], 1, 'P9O-05 exception remains durable evaluated state');

    // 6. Invalid windows/caps fail before executor and ledger mutation.
    p9o_reset_ledgers();
    p9o_reset_runtime();
    p9o_eq(MigrationBatch::run(array(1), 'secret-api', false, true, -1, 1)['reason'], 'invalid_offset', 'P9O-06 negative offset rejected');
    p9o_eq(MigrationBatch::run(array(1), 'secret-api', false, true, 0, MigrationBatch::MAX_LIMIT + 1)['reason'], 'invalid_limit', 'P9O-06 over-limit rejected');
    p9o_eq(count($GLOBALS['p9o']['executor_calls']), 0, 'P9O-06 invalid window never executes');
    p9o_eq(count($GLOBALS['p9o']['user_meta']), 0, 'P9O-06 invalid window never persists');

    // 7. CLI preflight resolves settings, emits safe JSON, and never exposes API key.
    p9o_reset_ledgers();
    p9o_reset_runtime();
    $cli = new MigrationCliCommand();
    $cli->preflight(array(), array('user-ids'=>'10,11','limit'=>'2','offset'=>'0'));
    p9o_eq(count($GLOBALS['p9o']['cli_lines']), 1, 'P9O-07 CLI emitted one payload');
    p9o_assert(strpos($GLOBALS['p9o']['cli_lines'][0], 'secret-api') === false, 'P9O-07 CLI hides API key');
    p9o_eq(count($GLOBALS['p9o']['cli_errors']), 0, 'P9O-07 CLI preflight no errors');
    p9o_eq(MigrationBatch::resumeOffset(array(10,11), 'secret-api', true, true)['offset'], 2, 'P9O-07 CLI results persisted');

    // 8. CLI execute requires explicit --yes.
    p9o_reset_runtime();
    try { $cli->execute(array(), array('user-ids'=>'12')); } catch (RuntimeException $e) {}
    p9o_assert(count($GLOBALS['p9o']['cli_errors']) === 1 && strpos($GLOBALS['p9o']['cli_errors'][0], 'explicit_yes_required') !== false, 'P9O-08 CLI execute confirmation required');
    p9o_eq(count($GLOBALS['p9o']['executor_calls']), 0, 'P9O-08 no execute without yes');
    p9o_reset_runtime();
    $cli->execute(array(), array('user-ids'=>'12','yes'=>true));
    p9o_eq($GLOBALS['p9o']['executor_calls'][0]['dry_run'], false, 'P9O-08 confirmed execute writes enabled');

    // 9. Failed CLI batch emits redacted result, then exits non-zero.
    p9o_reset_runtime();
    $threw = false;
    try { $cli->preflight(array(), array('user-ids'=>'3')); } catch (RuntimeException $e) { $threw = true; }
    p9o_eq($threw, true, 'P9O-09 failed CLI batch exits nonzero');
    p9o_eq(count($GLOBALS['p9o']['cli_lines']), 1, 'P9O-09 failed CLI batch emits result first');
    p9o_assert(strpos($GLOBALS['p9o']['cli_lines'][0], 'secret-api') === false, 'P9O-09 failed output hides API key');
    p9o_assert(count($GLOBALS['p9o']['cli_errors']) === 1 && strpos($GLOBALS['p9o']['cli_errors'][0], 'preflight_completed_with_failures') !== false, 'P9O-09 failure exit reason exact');

    // 10. CLI --resume recovers durable progress; explicit offset remains deliberate re-evaluation.
    p9o_reset_ledgers();
    p9o_reset_runtime();
    MigrationBatch::run(array(5,6,7), 'secret-api', true, true, 0, 2);
    p9o_reset_runtime();
    $cli->preflight(array(), array('user-ids'=>'5,6,7','resume'=>true,'limit'=>'2'));
    p9o_eq(count($GLOBALS['p9o']['executor_calls']), 1, 'P9O-10 resume executes only remaining user');
    p9o_eq($GLOBALS['p9o']['executor_calls'][0]['user_id'], 7, 'P9O-10 resume starts at durable offset');
    p9o_assert(strpos($GLOBALS['p9o']['cli_lines'][0], '"offset": 2') !== false, 'P9O-10 resume output reports recovered offset');
    p9o_reset_runtime();
    try { $cli->preflight(array(), array('user-ids'=>'5,6,7','resume'=>true,'offset'=>'1')); } catch (RuntimeException $e) {}
    p9o_assert(count($GLOBALS['p9o']['cli_errors']) === 1 && strpos($GLOBALS['p9o']['cli_errors'][0], 'resume_with_offset_invalid') !== false, 'P9O-10 resume and offset mutually exclusive');
    p9o_eq(count($GLOBALS['p9o']['executor_calls']), 0, 'P9O-10 invalid resume request never executes');

    // 11. Ledger-write uncertainty stops the page and leaves current user as retry point.
    p9o_reset_ledgers();
    p9o_reset_runtime();
    $GLOBALS['p9o']['user_meta_fail'][8] = true;
    $r = MigrationBatch::run(array(8,9), 'secret-api', true, true, 0, 2);
    p9o_eq($r['reason'], 'batch_checkpoint_failed', 'P9O-11 checkpoint failure exact');
    p9o_eq($r['processed'], 1, 'P9O-11 stops after uncertain ledger write');
    p9o_eq(count($GLOBALS['p9o']['executor_calls']), 1, 'P9O-11 no later user execution after checkpoint failure');
    p9o_eq($r['next_offset'], 0, 'P9O-11 current user remains retry point');
    p9o_eq($r['checkpoint_offset'], 0, 'P9O-11 durable checkpoint does not advance');
    p9o_eq($r['results'][0]['operations_ledger_written'], false, 'P9O-11 failed ledger surfaced');
    p9o_eq($r['results'][0]['reason'], 'operations_result_ledger_write_failed', 'P9O-11 result reason exposes observability failure');
    p9o_eq(MigrationBatch::resumeOffset(array(8,9), 'secret-api', true, true)['offset'], 0, 'P9O-11 recovery re-evaluates uncertain user');
    $GLOBALS['p9o']['user_meta_fail'] = array();

    // 12. Credential/mode/dry-run scoping prevents stale checkpoint reuse.
    p9o_reset_ledgers();
    MigrationBatch::run(array(20), 'secret-api', true, true, 0, 1);
    p9o_eq(MigrationBatch::resumeOffset(array(20), 'different-api', true, true)['offset'], 0, 'P9O-12 API-key scope mismatch does not resume');
    p9o_eq(MigrationBatch::resumeOffset(array(20), 'secret-api', false, true)['offset'], 0, 'P9O-12 mode mismatch does not resume');
    p9o_eq(MigrationBatch::resumeOffset(array(20), 'secret-api', true, false)['offset'], 0, 'P9O-12 dry/execute mismatch does not resume');

    // 13. Static operational safety contracts.
    $batchSource = file_get_contents($root . '/src/Migration/MigrationBatch.php');
    $cliSource = file_get_contents($root . '/src/Migration/MigrationCliCommand.php');
    $adminSource = file_get_contents($root . '/src/Migration/MigrationAdmin.php');
    $bootstrapSource = file_get_contents($root . '/src/Migration/MigrationBootstrap.php');
    $cliCredentialInput = strpos($cliSource, '$assoc_args[\'api-key\']') !== false
        || strpos($cliSource, "array_key_exists('api-key'") !== false
        || strpos($cliSource, 'isset($assoc_args[\'api-key\'])') !== false;
    p9o_assert(!$cliCredentialInput && strpos($adminSource, 'name="api_key"') === false, 'P9O-13 no credential input surface');
    p9o_assert(strpos($adminSource, "current_user_can(self::CAPABILITY)") !== false, 'P9O-13 admin capability enforced');
    p9o_assert(strpos($adminSource, 'check_admin_referer(self::NONCE_ACTION, self::NONCE_FIELD)') !== false, 'P9O-13 admin nonce enforced');
    p9o_assert(strpos($adminSource, 'explicit_execute_confirmation_required') !== false, 'P9O-13 admin execute confirmation enforced');
    p9o_assert(strpos($adminSource, 'name="resume"') !== false && strpos($adminSource, 'MigrationBatch::resumeOffset') !== false, 'P9O-13 admin durable resume surface present');
    p9o_assert(strpos($bootstrapSource, "add_command('simplixpay-upayments migration'") !== false, 'P9O-13 canonical CLI namespace');
    p9o_assert(strpos($bootstrapSource, "add_action('admin_menu'") !== false, 'P9O-13 admin menu registered');
    p9o_assert(strpos($bootstrapSource, 'wp_enqueue_scripts') === false && strpos($bootstrapSource, 'woocommerce_checkout') === false, 'P9O-13 no checkout/frontend hook');
    $opsSource = $batchSource . $cliSource . $adminSource . $bootstrapSource;
    p9o_assert(strpos($opsSource, 'curl_') === false && strpos($opsSource, 'wp_remote_') === false && strpos($opsSource, 'getSavedCards') === false, 'P9O-13 no provider transport path');
    p9o_assert(strpos($batchSource, 'MAX_LIMIT = 50') !== false && strpos($batchSource, 'MAX_INPUT_USERS = 500') !== false, 'P9O-13 hard batch bounds present');
    p9o_assert(strpos($batchSource, "RESULT_LEDGER_KEY = '_simplixpay_upayments_migration_result_v1'") !== false, 'P9O-13 separate Simplix operations ledger key');
    p9o_assert(strpos($batchSource, "hash_hmac('sha256'") !== false, 'P9O-13 credential-scoped batch fingerprint is HMAC');
    p9o_assert(strpos($batchSource, 'update_user_meta') !== false && strpos($batchSource, 'operations_result_ledger_write_failed') !== false, 'P9O-13 durable ledger write/fail-closed path present');

    echo "\n--- Phase 9I Operations Report ---\nPASS: $pass\nFAIL: $fail\n";
    echo 'HARNESS-COMPLETE: ' . basename(__FILE__) . "\n";
    exit($fail === 0 ? 0 : 1);
}
