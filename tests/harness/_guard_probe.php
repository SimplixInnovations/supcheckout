<?php
/**
 * Phase 9G Residual Correction #33 — guard-probe sibling (final guard integrity).
 *
 * #33 fixes:
 *  - $GLOBALS-keyed fields (family counts, unknown-family count, unknown-
 *    family samples) preserve EXACT presence/absence: array_key_exists()
 *    against $GLOBALS before snapshot, exact restore-or-unset() after.
 *    Never convert "absent" into present-with-0 or present-with-empty-array.
 *  - Probe self-defect (post-restore mismatch) HARD-FAILS the harness:
 *    $fail++ and $_fail_harness_self_test++ AND append an explicit FAIL
 *    log entry BEFORE returning 3. A return of 3 is a harness defect,
 *    never evidence that a guard worked.
 *  - $pass/$fail are INTEGER counters, snapshotted as native int.
 *  - $log is the FULL array (snap the entire array, restore exactly).
 *  - Snapshots/restore every category counter, _semantic_runtime_assert_calls,
 *    _upay_semantic_family_counts, _upay_semantic_unknown_family_count,
 *    _upay_semantic_unknown_samples.
 *
 * Decision return codes (exact):
 *   0 = assertion accepted (no guard fired, no taxonomy reject)
 *   1 = assertion rejected by guard / category / family
 *   2 = probe bootstrap defect (parent harness missing)
 *   3 = probe self-defect (snapshot/restore mismatch)
 *
 * A return of 2 or 3 is a HARNESS DEFECT, never evidence that a guard
 * worked. Negative guard assertions in the parent must require EXACTLY
 * 1 (not !== 0); permitted harness_self_test probes require EXACTLY 0.
 */

defined('ABSPATH') || exit;

function upay_probe_dispatch($condition, $description, $kind = 'semantic_runtime') {
    if (!function_exists('_upay_dispatch')) {
        fwrite(STDERR, "_upay_dispatch() not loaded — parent harness bootstrap missing\n");
        // Bootstrap defect: hard-fail harness before returning 2.
        global $fail, $_fail_harness_self_test, $log;
        $fail++;
        if (isset($_fail_harness_self_test)) $_fail_harness_self_test++;
        if (is_array($log)) $log[] = "FAIL: [probe-defect] _upay_dispatch() not loaded — parent harness bootstrap missing";
        return 2;
    }
    if (!function_exists('upay_ledger_family_for')) {
        fwrite(STDERR, "upay_ledger_family_for() not loaded — parent harness bootstrap missing\n");
        // Bootstrap defect: hard-fail harness before returning 2.
        global $fail, $_fail_harness_self_test, $log;
        $fail++;
        if (isset($_fail_harness_self_test)) $_fail_harness_self_test++;
        if (is_array($log)) $log[] = "FAIL: [probe-defect] upay_ledger_family_for() not loaded — parent harness bootstrap missing";
        return 2;
    }

    global $_pass_semantic_runtime, $_fail_semantic_runtime;
    global $_pass_helper_unit_runtime, $_fail_helper_unit_runtime;
    global $_pass_static_source, $_fail_static_source;
    global $_pass_harness_self_test, $_fail_harness_self_test;
    global $_pass_lint_tooling, $_fail_lint_tooling;
    global $_semantic_runtime_assert_calls;
    global $_upay_semantic_family_counts;
    global $pass, $fail, $log;

    // Snapshot — preserve EXACT presence/absence of $GLOBALS-keyed fields.
    // ($_upay_semantic_family_counts is reachable via global, but the
    // _upay_semantic_unknown_* fields live ONLY in $GLOBALS.)
    $fam_present  = array_key_exists('_upay_semantic_family_counts', $GLOBALS);
    $unkc_present = array_key_exists('_upay_semantic_unknown_family_count', $GLOBALS);
    $unks_present = array_key_exists('_upay_semantic_unknown_samples', $GLOBALS);

    $snap = array(
        'pass_int'        => (int) $pass,
        'fail_int'        => (int) $fail,
        'log_full'        => is_array($log) ? $log : array(),
        'p_sr'            => (int) $_pass_semantic_runtime,
        'f_sr'            => (int) $_fail_semantic_runtime,
        'p_hu'            => (int) $_pass_helper_unit_runtime,
        'f_hu'            => (int) $_fail_helper_unit_runtime,
        'p_ss'            => (int) $_pass_static_source,
        'f_ss'            => (int) $_fail_static_source,
        'p_hs'            => (int) $_pass_harness_self_test,
        'f_hs'            => (int) $_fail_harness_self_test,
        'p_lt'            => (int) $_pass_lint_tooling,
        'f_lt'            => (int) $_fail_lint_tooling,
        'calls'           => (int) $_semantic_runtime_assert_calls,
        // $GLOBALS-keyed fields: preserve presence + exact value.
        'fam_present'     => $fam_present,
        'fam_val'         => $fam_present ? $GLOBALS['_upay_semantic_family_counts'] : null,
        'unkc_present'    => $unkc_present,
        'unkc_val'        => $unkc_present ? $GLOBALS['_upay_semantic_unknown_family_count'] : null,
        'unks_present'    => $unks_present,
        'unks_val'        => $unks_present ? $GLOBALS['_upay_semantic_unknown_samples'] : null,
    );

    // Invoke the parent's REAL dispatch.
    _upay_dispatch((bool) $condition, (string) $description, (string) $kind);

    // Compute decision: 1 if guard fired in any category, else 0.
    $decision = 0;
    if ($_fail_semantic_runtime > $snap['f_sr'])      $decision = 1;
    elseif ($_fail_helper_unit_runtime > $snap['f_hu']) $decision = 1;
    elseif ($_fail_static_source > $snap['f_ss'])      $decision = 1;
    elseif ($_fail_harness_self_test > $snap['f_hs'])  $decision = 1;
    elseif ($_fail_lint_tooling > $snap['f_lt'])       $decision = 1;

    // RESTORE — exact restoration of all counters, $log, and presence-tracked $GLOBALS.
    $pass = $snap['pass_int'];
    $fail = $snap['fail_int'];
    $log  = $snap['log_full'];
    $_pass_semantic_runtime      = $snap['p_sr'];
    $_fail_semantic_runtime      = $snap['f_sr'];
    $_pass_helper_unit_runtime   = $snap['p_hu'];
    $_fail_helper_unit_runtime   = $snap['f_hu'];
    $_pass_static_source         = $snap['p_ss'];
    $_fail_static_source         = $snap['f_ss'];
    $_pass_harness_self_test     = $snap['p_hs'];
    $_fail_harness_self_test     = $snap['f_hs'];
    $_pass_lint_tooling          = $snap['p_lt'];
    $_fail_lint_tooling          = $snap['f_lt'];
    $_semantic_runtime_assert_calls = $snap['calls'];

    // $GLOBALS-keyed restore: presence + value, never convert absent to present.
    if ($snap['fam_present']) {
        $GLOBALS['_upay_semantic_family_counts'] = $snap['fam_val'];
    } else {
        unset($GLOBALS['_upay_semantic_family_counts']);
    }
    if ($snap['unkc_present']) {
        $GLOBALS['_upay_semantic_unknown_family_count'] = $snap['unkc_val'];
    } else {
        unset($GLOBALS['_upay_semantic_unknown_family_count']);
    }
    if ($snap['unks_present']) {
        $GLOBALS['_upay_semantic_unknown_samples'] = $snap['unks_val'];
    } else {
        unset($GLOBALS['_upay_semantic_unknown_samples']);
    }

    // #33 invariant assertion: post-restore state must equal snapshot exactly.
    $mismatch = array();
    if ((int) $pass !== $snap['pass_int'])        $mismatch[] = 'pass';
    if ((int) $fail !== $snap['fail_int'])        $mismatch[] = 'fail';
    if ($log !== $snap['log_full'])               $mismatch[] = 'log';
    if ((int) $_pass_semantic_runtime    !== $snap['p_sr']) $mismatch[] = 'p_sr';
    if ((int) $_fail_semantic_runtime    !== $snap['f_sr']) $mismatch[] = 'f_sr';
    if ((int) $_pass_helper_unit_runtime !== $snap['p_hu']) $mismatch[] = 'p_hu';
    if ((int) $_fail_helper_unit_runtime !== $snap['f_hu']) $mismatch[] = 'f_hu';
    if ((int) $_pass_static_source       !== $snap['p_ss']) $mismatch[] = 'p_ss';
    if ((int) $_fail_static_source       !== $snap['f_ss']) $mismatch[] = 'f_ss';
    if ((int) $_pass_harness_self_test   !== $snap['p_hs']) $mismatch[] = 'p_hs';
    if ((int) $_fail_harness_self_test   !== $snap['f_hs']) $mismatch[] = 'f_hs';
    if ((int) $_pass_lint_tooling        !== $snap['p_lt']) $mismatch[] = 'p_lt';
    if ((int) $_fail_lint_tooling        !== $snap['f_lt']) $mismatch[] = 'f_lt';
    if ((int) $_semantic_runtime_assert_calls !== $snap['calls']) $mismatch[] = 'calls';

    // Presence check (post-restore).
    $fam_present_now  = array_key_exists('_upay_semantic_family_counts', $GLOBALS);
    $unkc_present_now = array_key_exists('_upay_semantic_unknown_family_count', $GLOBALS);
    $unks_present_now = array_key_exists('_upay_semantic_unknown_samples', $GLOBALS);
    if ($fam_present_now !== $snap['fam_present']) $mismatch[] = 'fam_present';
    elseif ($fam_present_now && (!isset($GLOBALS['_upay_semantic_family_counts'])
            || $GLOBALS['_upay_semantic_family_counts'] !== $snap['fam_val'])) {
        $mismatch[] = 'fam_val';
    }
    if ($unkc_present_now !== $snap['unkc_present']) $mismatch[] = 'unkc_present';
    elseif ($unkc_present_now && (!array_key_exists('_upay_semantic_unknown_family_count', $GLOBALS)
            || $GLOBALS['_upay_semantic_unknown_family_count'] !== $snap['unkc_val'])) {
        $mismatch[] = 'unkc_val';
    }
    if ($unks_present_now !== $snap['unks_present']) $mismatch[] = 'unks_present';
    elseif ($unks_present_now && (!array_key_exists('_upay_semantic_unknown_samples', $GLOBALS)
            || $GLOBALS['_upay_semantic_unknown_samples'] !== $snap['unks_val'])) {
        $mismatch[] = 'unks_val';
    }

    if (!empty($mismatch)) {
        // Probe self-defect: HARD-FAIL the harness BEFORE returning 3.
        // Per directive: do NOT classify a probe implementation defect
        // as semantic_runtime. Append a FAIL log entry and bump the
        // global $fail counter and the harness_self_test FAIL counter
        // (NOT semantic_runtime).
        $fail++;
        $_fail_harness_self_test++;
        $log[] = 'FAIL: [probe-defect] snapshot/restore mismatch in fields: ' . implode(', ', $mismatch);
        return 3;
    }

    return $decision;
}
