<?php
/**
 * SUPCheckout provenance DB-error fail-closed regression.
 *
 * WordPress wpdb::get_col() can return an empty array after a failed query;
 * last_error is therefore part of the authoritative error boundary.
 */

require __DIR__ . '/_bootstrap.php';

$pass = 0;
$fail = 0;

function supdb_assert($condition, $message) {
    global $pass, $fail;
    if ($condition) {
        ++$pass;
        echo "PASS: {$message}\n";
        return;
    }
    ++$fail;
    echo "FAIL: {$message}\n";
}

class SUPCheckout_Provenance_Query_Failure_Wpdb extends WpdbStub {
    public $last_error = '';

    public function get_col($sql = null) {
        $this->last_error = 'synthetic provenance query failure';
        return array();
    }
}

upay_reset_state();
$GLOBALS['wpdb'] = new SUPCheckout_Provenance_Query_Failure_Wpdb();

$result = \UPayments\Token\CustomerTokenIdentity::inspect_current_user_prior_provenance(
    1,
    str_repeat('b', 32)
);

supdb_assert(is_array($result), 'provenance DB failure returns structured outcome');
supdb_assert(
    isset($result['state']) && $result['state'] === 'read_failure',
    'provenance DB failure fails closed as read_failure'
);
supdb_assert(
    isset($result['reason']) && $result['reason'] === 'db_query_failed',
    'provenance DB failure preserves db_query_failed reason'
);

$GLOBALS['wpdb'] = new WpdbStub();

echo "\nSUPCheckout Provenance DB Failure: {$pass} PASS / {$fail} FAIL\n";
echo 'HARNESS-COMPLETE: ' . basename(__FILE__) . "\n";
exit($fail === 0 ? 0 : 1);
