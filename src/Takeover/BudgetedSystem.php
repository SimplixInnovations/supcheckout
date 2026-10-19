<?php

namespace Simplixi\SUPCheckout\Takeover;

defined('ABSPATH') || exit;

/**
 * A RollbackSystem with a time budget (Sub-project 0c Task 3). With one, Rollback and
 * Finalize hand control back instead of sleeping, so an asynchronous caller never holds a
 * web or Action Scheduler worker through the settle barrier. WordPressCutoverSystem does
 * not implement it: WP-CLI never yields and keeps its bounded, sleeping waits.
 *
 * The yield, only after the transition (no Quiesce lease is held then):
 * - at the settle barrier, while now < transitioned_at + CopyRemoval::SETTLE_SECONDS, the
 *   run returns at once, without a sleep, with error `yielded` and `report['resume_at']`
 *   set to that deadline. The persisted progress is still the completed cursor pass, so the
 *   rerun waits again only if it comes early, then starts the rescans;
 * - after each removal unit whose progress was saved, when budget_spent() is true, it
 *   returns `yielded` with `report['resume_at']` = now. The rerun continues from the saved
 *   cursor (a yielded rescan keeps its pass and count, see Rollback::remove());
 * - the last step is `yielded`, and the result is never `ok`.
 *
 * The rerun is the same call (Rollback::run() / abort(), Finalize::run()), through the
 * existing pending/resume paths: the settle reference time (`transitioned_at`) is persisted
 * at the transition and kept, removal completion is recorded only after a clean full rescan,
 * and a yield never records it early. Onboarding maps `yielded` to Outcome::BUSY with
 * `data['resume_at']`.
 */
interface BudgetedSystem extends RollbackSystem {
    /** @return bool True once the caller's time budget is used up. */
    public function budget_spent();
}
