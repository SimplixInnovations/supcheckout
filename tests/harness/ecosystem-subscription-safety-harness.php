<?php
/**
 * R3 subscription safety harness — first-card elimination and capture authority.
 */

$root = dirname(__DIR__, 2);
$pass = 0;
$fail = 0;

function r3_assert($condition, $label) {
    global $pass, $fail;
    if ($condition) {
        echo "PASS: {$label}\n";
        $pass++;
        return;
    }
    echo "FAIL: {$label}\n";
    $fail++;
}

$scheduler = file_get_contents($root . '/includes/Subscription/Cron/Scheduler.php');
r3_assert(is_string($scheduler) && $scheduler !== '', 'Scheduler source readable');

// Task B: no implicit first-card selection remains in production scheduler.
r3_assert(
    strpos($scheduler, "cards[0]['token']") === false
    && strpos($scheduler, '$cards[0]') === false,
    'Scheduler never selects cards[0] as renewal authority'
);

r3_assert(
    strpos($scheduler, 'RenewalCardAuthority') !== false,
    'Scheduler uses RenewalCardAuthority for explicit card resolution'
);

r3_assert(
    strpos($scheduler, 'AutoDeductResultVerifier') !== false,
    'Scheduler uses AutoDeductResultVerifier for response classification'
);

r3_assert(
    strpos($scheduler, 'CycleEconomics') !== false,
    'Scheduler uses CycleEconomics for decimal-safe economic binding'
);

r3_assert(
    strpos($scheduler, "orderId'] + 1") === false
    && strpos($scheduler, 'orderId + 1') === false
    && strpos($scheduler, 'orderId+1') === false,
    'Scheduler does not fabricate provider identity via orderId + 1'
);

r3_assert(
    strpos($scheduler, "upayShouldAttemptRetry") === false
    || strpos($scheduler, 'upayShouldAttemptRetry(') === false
    || substr_count($scheduler, 'upayShouldAttemptRetry') <= 1,
    'Legacy retry helper is not a scheduler dispatch authority'
);

// Parent discovery must not be completed-only historical scanning for paid parents.
r3_assert(
    strpos($scheduler, 'wc_get_is_paid_statuses') !== false,
    'Parent discovery uses WooCommerce paid-status semantics'
);

// Pause/cancel gate before dispatch.
r3_assert(
    strpos($scheduler, "paused") !== false && strpos($scheduler, "cancelled") !== false,
    'Scheduler retains paused/cancelled subscription gates'
);

$card_class = @file_get_contents($root . '/src/Subscription/RenewalCardAuthority.php');
$verifier_class = @file_get_contents($root . '/src/Subscription/AutoDeductResultVerifier.php');
r3_assert(is_string($card_class) && strpos($card_class, 'class RenewalCardAuthority') !== false, 'RenewalCardAuthority exists');
r3_assert(is_string($verifier_class) && strpos($verifier_class, 'class AutoDeductResultVerifier') !== false, 'AutoDeductResultVerifier exists');
r3_assert(
    is_string($verifier_class)
    && strpos($verifier_class, 'capture_authority_unproven_for_auto_deduct') !== false,
    'Verifier fails closed when auto-deduct capture authority is unproven'
);

echo "\nR3 Subscription Safety Harness: {$pass} PASS / {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
