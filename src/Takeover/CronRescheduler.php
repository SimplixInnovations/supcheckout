<?php

namespace Simplixi\SUPCheckout\Takeover;

defined('ABSPATH') || exit;

/**
 * Cutover step 3 (Sub-project 0b Task 7): every WP-Cron event under a Keys::cron_hooks()
 * legacy name gets a twin under the new name, with the same timestamp, arguments and
 * recurrence. The legacy event is kept until Finalize (copy, never move); from `copying`
 * on both names run the same callback (Keys::cron_hook_names()), so a pending reconcile
 * for an order runs once per name, which the payment lifecycle tolerates: it verifies
 * provider status under the order lock and pays an order at most once.
 *
 * Idempotent: an event whose new name already has an event with the same arguments (at
 * any time: a resumed cutover, or a runtime reschedule) is skipped. Each twin is
 * journaled before it is scheduled (target `cron`, path `<new hook>@<timestamp>#<md5 of
 * the serialized args>`, restore source the kept legacy event), so Rollback can remove it.
 * Already-canonical hooks (supcheckout_reconciliation_sweep, supcheckout_process_due_parent)
 * are not in Keys::cron_hooks() and are left alone.
 */
final class CronRescheduler {
    /**
     * @param CutoverSystem $system System.
     * @return array{ok:bool, scheduled:int, failed:int}
     * @throws \RuntimeException When a journal entry cannot be stored.
     */
    public static function reschedule(CutoverSystem $system) {
        $hooks = Keys::cron_hooks();
        $events = $system->cron_events();
        $twinned = array();
        foreach ($events as $event) {
            if (in_array($event['hook'], $hooks, true)) {
                $twinned[$event['hook'] . '#' . self::args_key($event['args'])] = true;
            }
        }
        $scheduled = 0;
        $failed = 0;
        foreach ($events as $event) {
            if (!isset($hooks[$event['hook']])) {
                continue;
            }
            $new_hook = $hooks[$event['hook']];
            $args_key = self::args_key($event['args']);
            if (isset($twinned[$new_hook . '#' . $args_key])) {
                continue;
            }
            Journal::record(
                'cron',
                $new_hook . '@' . (int) $event['timestamp'] . '#' . $args_key,
                null,
                array('schedule' => $event['schedule'], 'args' => $event['args']),
                'cron:' . $event['hook'] . '@' . (int) $event['timestamp'] . '#' . $args_key
            );
            if ($system->schedule_event((int) $event['timestamp'], $new_hook, $event['args'], $event['schedule'])) {
                ++$scheduled;
                $twinned[$new_hook . '#' . $args_key] = true;
            } else {
                ++$failed;
            }
        }
        return array('ok' => $failed === 0, 'scheduled' => $scheduled, 'failed' => $failed);
    }

    /**
     * @param array<mixed> $args Event arguments.
     * @return string WP-Cron's own instance key (md5 of the serialized arguments).
     */
    private static function args_key(array $args) {
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- WP-Cron keys event instances by md5(serialize($args)); hashed only.
        return md5(serialize($args));
    }

    private function __construct() {}
}
