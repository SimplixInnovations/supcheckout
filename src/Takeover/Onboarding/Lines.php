<?php

namespace Simplixi\SUPCheckout\Takeover\Onboarding;

use Simplixi\SUPCheckout\Takeover\TakeoverState;

defined('ABSPATH') || exit;

/**
 * The informational lines `wp supcheckout takeover` prints, built from engine results
 * (Sub-project 0c). Pure functions: no state read, no write. Onboarding puts them in each
 * Outcome's `lines()`; FinalizeCommand prints the dry run with finalize_plan_lines().
 */
final class Lines {
    /**
     * @param array{blocking:list<array<string,mixed>>, warnings:list<array<string,mixed>>, facts:list<array<string,mixed>>} $report Preflight report.
     * @return list<string>
     */
    public static function report_lines(array $report) {
        $lines = array();
        foreach (array('blocking' => 'BLOCKING', 'warnings' => 'warning', 'facts' => 'fact') as $list => $label) {
            foreach ($report[$list] as $finding) {
                $code = isset($finding['code']) ? (string) $finding['code'] : '';
                $message = isset($finding['message']) ? (string) $finding['message'] : '';
                $lines[] = $label . ': ' . $code . ': ' . $message;
            }
        }
        return $lines;
    }

    /**
     * @param array<string, mixed> $plan Finalize::plan().
     * @return list<string> The dry run as the CLI prints it.
     */
    public static function finalize_plan_lines(array $plan) {
        $lines = array('dry run: nothing is written');
        foreach ($plan['would_refuse'] as $code) {
            $lines[] = 'would refuse: ' . $code;
        }
        if ($plan['status'] === TakeoverState::CUT_OVER) {
            $lines[] = 'finalize allowed in ' . $plan['days_left'] . ' day(s)';
        }
        $lines[] = 'would remove: ' . self::counts($plan['removed']);
        $lines = array_merge($lines, self::kept_lines($plan['kept'], 'would keep'));
        $lines[] = 'legacy billing table: ' . $plan['table'];
        return array_merge($lines, self::reported_lines($plan['reported']));
    }

    /**
     * @param list<array<string, mixed>> $kept  Kept groups.
     * @param string                     $label Line label.
     * @return list<string>
     */
    public static function kept_lines(array $kept, $label) {
        $lines = array();
        foreach ($kept as $group) {
            $ids = $group['ids'] === array() ? '' : ' (ids ' . implode(',', array_map('intval', $group['ids'])) . ($group['count'] > count($group['ids']) ? ',...' : '') . ')';
            $lines[] = $label . ': ' . $group['kind'] . ' ' . $group['name'] . ' ' . $group['reason'] . ' x' . (int) $group['count'] . $ids;
        }
        return $lines;
    }

    /**
     * @param array<string, mixed> $reported Finalize's reported-only items.
     * @return list<string>
     */
    public static function reported_lines(array $reported) {
        $lines = array();
        if (isset($reported['block_checkout_page']['page_id'])) {
            $lines[] = 'reported (never removed): the official "Checkout (Block)" page ' . (int) $reported['block_checkout_page']['page_id'] . ' (' . (string) $reported['block_checkout_page']['status'] . '); delete it yourself if unused';
        }
        foreach (isset($reported['official_tables']) && is_array($reported['official_tables']) ? $reported['official_tables'] : array() as $table) {
            $lines[] = 'reported (never removed): table ' . (string) $table;
        }
        if (isset($reported['callback_route'])) {
            $lines[] = 'kept forever: the legacy callback route ' . (string) $reported['callback_route'];
        }
        return $lines;
    }

    /**
     * The one cache-purge line (0c): which layers were purged, which need a manual purge
     * and which failed, by name only. Empty when the engine never reached the purge.
     *
     * @param array<string, mixed> $result Engine result.
     * @return list<string>
     */
    public static function cache_purge_lines(array $result) {
        $purge = isset($result['report']['cache_purge']) && is_array($result['report']['cache_purge']) ? $result['report']['cache_purge'] : null;
        if ($purge === null) {
            return array();
        }
        $csv = static function ($names) {
            return is_array($names) && $names !== array() ? implode(',', array_map('strval', $names)) : '-';
        };
        return array('cache purge: ran=' . $csv($purge['ran'] ?? null) . ' manual=' . $csv($purge['manual'] ?? null) . ' failed=' . $csv($purge['failed'] ?? null));
    }

    /**
     * @param array<string, int> $counts Counter => count.
     * @return string
     */
    public static function counts(array $counts) {
        $parts = array();
        foreach ($counts as $name => $count) {
            $parts[] = $name . '=' . (int) $count;
        }
        return implode(' ', $parts);
    }

    private function __construct() {}
}
