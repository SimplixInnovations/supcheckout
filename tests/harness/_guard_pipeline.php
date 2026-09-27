<?php
/**
 * Phase 9G Residual Correction #32 — guard pipeline (final closure).
 *
 * Final evidence-integrity closure:
 *  - $pass and $fail are integer counters (not arrays). Removed all
 *    is_array() guards around their increment.
 *  - Dispatch ordering for semantic_runtime: validate family BEFORE
 *    crediting PASS / category counter / family count.
 *  - Family rule order: SP-SELECTED-PROV must precede SP-SELECTED-CARD
 *    and SP-SELECT (specific before generic).
 *  - Route-phrase guard: rejects path=store_api, path!=store_api, and
 *    evasive renames (Store-API route confirmed/taken, Store API route
 *    confirmed/taken, Store route confirmed/taken) — case-insensitive.
 *  - UNKNOWN_FAMILY is hard-fail.
 *  - XART/XHAZ/XDB/XLIM/XCFG/XMETA/XEND prefix sniff remains.
 *
 * Shared by parent harness AND --guard-probe child via require_once.
 */

defined('ABSPATH') || exit;

/**
 * Upay ledger family attribution (#28 + #32 ordering fix).
 * Most-specific prefixes MUST precede generic prefixes.
 */
function upay_ledger_family_for($description) {
    static $rules = array(
        // Most specific first.
        array('BLOCKS-SAN',       'BLOCKS-SAN'),
        array('CLASSIC-PAN',      'CLASSIC-PAN'),
        array('MALFORMED-CARD',   'MALFORMED-CARD'),
        array('MM-VALID-FIXED',   'MM'),
        array('MM-VALID-PERCENTAGE', 'MM'),
        array('MM-INVALID',       'MM'),
        array('MM-',              'MM'),
        array('MM ',              'MM'),
        array('SP-SUCCESS',       'SP-SUCCESS'),
        array('SP-SAVE-CARD',     'SP-SAVE-CARD'),
        array('SP-SAVE',          'SP-SAVE-CARD'),
        // SP-SELECTED-PROV MUST come BEFORE SP-SELECTED-CARD and SP-SELECT
        // so that descriptions like 'SP-SELECTED-PROV-PROBE ...' resolve to
        // SP-SELECTED-PROV, not SP-SELECTED. (#32 ordering fix.)
        array('SP-SELECTED-PROV', 'SP-SELECTED-PROV'),
        array('SP-SELECTED-CARD', 'SP-SELECTED'),
        array('SP-SELECT',        'SP-SELECTED'),
        // SP-HISTORY family for history inspection scenarios.
        array('SP-HISTORY',       'SP-HISTORY'),
        array('SP-CARD-MISMATCH', 'SP-MISMATCH'),
        array('SP-MISMATCH',      'SP-MISMATCH'),
        array('HOSTILE',          'HOSTILE'),
        // PE-GUARD-PROBE family for --guard-probe child assertions.
        // MUST come BEFORE generic PE- / PE rules so PE-GUARD-PROBE
        // resolves to PE-GUARD-PROBE, not PE. (#32 ordering fix.)
        array('PE-GUARD-PROBE',   'PE-GUARD-PROBE'),
        array('PE-',              'PE'),
        array('PE ',              'PE'),
        array('WL-',              'WL'),
        array('WL ',              'WL'),
        array('OW-',              'OW'),
        array('OW ',              'OW'),
        array('ECON-E2E-',        'ECON-E2E'),
        array('SEM14-T-',         'SEM14-T'),
        // SP-CARD family for Store-API card-scoped semantic assertions.
        array('SP-X9 ',           'SP-CARD'),
        array('SP-X10 ',          'SP-CARD'),
        array('SP-X26 ',          'SP-CARD'),
        array('SP-X35 ',          'SP-CARD'),
        array('SP-X36 ',          'SP-CARD'),
        array('SP-X37 ',          'SP-CARD'),
        array('SP-X38 ',          'SP-CARD'),
        array('SP-X39 ',          'SP-CARD'),
        array('SP-X40 ',          'SP-CARD'),
        array('SP-X101 ',         'SP-CARD'),
        array('SP-6 ',            'SP-CARD'),
        array('SP-7 ',            'SP-CARD'),
    );
    foreach ($rules as $rule) {
        if (strpos($description, $rule[0]) === 0) {
            return $rule[1];
        }
    }
    return 'UNKNOWN_FAMILY';
}

/**
 * Route-phrase guard. Detects whether a description inspects ONLY the
 * subprocess-generated route envelope (path=store_api etc.), regardless
 * of how the description was rewritten in English.
 *
 * Case-insensitive substring match.
 */
function upay_is_route_envelope_description($description) {
    static $phrases = array(
        'path=store_api',
        'path!=store_api',
        'path == store_api',
        'path !== store_api',
        'path is store_api',
        'path not store_api',
        'store-api route confirmed',
        'store-api route taken',
        'store api route confirmed',
        'store api route taken',
        'store-route confirmed',
        'store-route taken',
        'store route confirmed',
        'store route taken',
    );
    $desc_lower = strtolower((string) $description);
    foreach ($phrases as $phrase) {
        if (strpos($desc_lower, strtolower($phrase)) !== false) {
            return true;
        }
    }
    return false;
}

/**
 * Main dispatch function (#32 final):
 *   - For semantic_runtime: validate family BEFORE crediting PASS.
 *   - $pass and $fail are integers. $log is the array.
 */
function _upay_dispatch($condition, $description, $kind) {
    global $pass, $fail, $log;
    global $_pass_semantic_runtime, $_pass_helper_unit_runtime,
        $_pass_static_source, $_pass_harness_self_test, $_pass_lint_tooling;
    global $_fail_semantic_runtime, $_fail_helper_unit_runtime,
        $_fail_static_source, $_fail_harness_self_test, $_fail_lint_tooling;
    global $_semantic_runtime_assert_calls;

    $desc = (string) $description;
    $cond = (bool) $condition;
    $k = (string) $kind;

    // ===== semantic_runtime guard pipeline (#32 final order) =====
    if ($k === 'semantic_runtime') {
        // 1. Forbidden static/lint prefixes under semantic_runtime.
        if (preg_match('/^(XART|XHAZ|XDB|XLIM|XCFG|XMETA|XEND)-/', $desc)) {
            $fail++;
            $_fail_semantic_runtime++;
            $log[] = "FAIL: [guard] semantic_runtime category wrong for $desc (should be static_source / lint_tooling)";
            return;
        }
        // 2. Route-envelope guard (path=store_api and evasive renames).
        if (upay_is_route_envelope_description($desc)) {
            $fail++;
            $_fail_semantic_runtime++;
            $log[] = "FAIL: [guard] semantic_runtime inspects subprocess route envelope only: $desc (must be harness_self_test)";
            return;
        }
        // 3. Harness-subprocess-envelope phrase guard.
        static $harness_phrase_guard = array(
            'result is array',
            'process_payment returned array',
            'process_payment_result is array',
            'result key present',
            'has result key',
            'has redirect key',
            'body consumed',
            'body NOT consumed',
            'body_consumed_count',
            'last_charge_body is string',
            'create_token_bodies is array',
            'retrieve_bodies is array',
            'charge_bodies is array',
            'scenario label preserved',
            'wc_loaded=true',
            'payload decoded',
            '-> not store_api',
            '-> store_api path',
            'exact-match gate',
            'subprocess load confirmed',
            'subprocess arg echo',
            'subprocess invocation determinism',
            'plain + pretty permalink both consume body once',
        );
        $desc_lower = strtolower($desc);
        foreach ($harness_phrase_guard as $phrase) {
            if (strpos($desc_lower, strtolower($phrase)) !== false) {
                $fail++;
                $_fail_semantic_runtime++;
                $log[] = "FAIL: [guard] semantic_runtime contains harness-envelope phrase '$phrase' (must be harness_self_test): $desc";
                return;
            }
        }
        // 4. Resolve ledger family. UNKNOWN_FAMILY is hard-fail.
        $family = upay_ledger_family_for($desc);
        if ($family === 'UNKNOWN_FAMILY') {
            $fail++;
            $_fail_semantic_runtime++;
            $GLOBALS['_upay_semantic_unknown_family_count'] = isset($GLOBALS['_upay_semantic_unknown_family_count'])
                ? $GLOBALS['_upay_semantic_unknown_family_count'] + 1 : 1;
            if (!isset($GLOBALS['_upay_semantic_unknown_samples'])) {
                $GLOBALS['_upay_semantic_unknown_samples'] = array();
            }
            if (count($GLOBALS['_upay_semantic_unknown_samples']) < 50) {
                $GLOBALS['_upay_semantic_unknown_samples'][] = $desc;
            }
            $log[] = "FAIL: [guard] semantic_runtime unknown ledger family (no explicit rule): $desc";
            return;
        }
        // 5. ONLY NOW credit PASS / category / family / call.
        if ($cond) {
            $pass++;
            $_pass_semantic_runtime++;
            $_semantic_runtime_assert_calls++;
            if (!isset($GLOBALS['_upay_semantic_family_counts'][$family])) {
                $GLOBALS['_upay_semantic_family_counts'][$family] = 0;
            }
            $GLOBALS['_upay_semantic_family_counts'][$family]++;
            $log[] = "PASS: [semantic_runtime] $desc";
            return;
        }
        // semantic_runtime FAIL (assertion did not hold, taxonomy passed).
        $fail++;
        $_fail_semantic_runtime++;
        $log[] = "FAIL: [semantic_runtime] $desc";
        return;
    }

    // ===== non-semantic_runtime categories =====
    if ($k === 'helper_unit_runtime') {
        if ($cond) { $pass++; $_pass_helper_unit_runtime++; $log[] = "PASS: [helper_unit_runtime] $desc"; }
        else       { $fail++; $_fail_helper_unit_runtime++; $log[] = "FAIL: [helper_unit_runtime] $desc"; }
        return;
    }
    if ($k === 'static_source') {
        if ($cond) { $pass++; $_pass_static_source++; $log[] = "PASS: [static_source] $desc"; }
        else       { $fail++; $_fail_static_source++; $log[] = "FAIL: [static_source] $desc"; }
        return;
    }
    if ($k === 'harness_self_test') {
        if ($cond) { $pass++; $_pass_harness_self_test++; $log[] = "PASS: [harness_self_test] $desc"; }
        else       { $fail++; $_fail_harness_self_test++; $log[] = "FAIL: [harness_self_test] $desc"; }
        return;
    }
    if ($k === 'lint_tooling') {
        if ($cond) { $pass++; $_pass_lint_tooling++; $log[] = "PASS: [lint_tooling] $desc"; }
        else       { $fail++; $_fail_lint_tooling++; $log[] = "FAIL: [lint_tooling] $desc"; }
        return;
    }

    // Unknown category.
    $fail++;
    $log[] = "FAIL: [guard] unknown assertion category '$k': $desc";
}

function upay_assert($condition, $description, $kind = 'semantic_runtime') {
    $allowed = array(
        'semantic_runtime', 'helper_unit_runtime', 'static_source',
        'harness_self_test', 'lint_tooling'
    );
    if (!in_array($kind, $allowed, true)) {
        global $fail, $log;
        $fail++;
        $log[] = "FAIL: [guard] unknown assertion category '$kind': $description";
        return;
    }
    _upay_dispatch($condition, $description, $kind);
}

function sem_assert($condition, $description) {
    _upay_dispatch($condition, $description, 'semantic_runtime');
}
function helper_assert($condition, $description) {
    _upay_dispatch($condition, $description, 'helper_unit_runtime');
}
function static_assert($condition, $description) {
    _upay_dispatch($condition, $description, 'static_source');
}
