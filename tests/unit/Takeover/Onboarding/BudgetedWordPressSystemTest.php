<?php

namespace Simplixi\SUPCheckout\Tests\Takeover\Onboarding;

use PHPUnit\Framework\TestCase;
use Simplixi\SUPCheckout\Takeover\BudgetedSystem;
use Simplixi\SUPCheckout\Takeover\Onboarding\BudgetedWordPressSystem;
use Simplixi\SUPCheckout\Tests\Takeover\FakeCutoverSystem;

/**
 * 0c Task 6: the driver's time budget around a RollbackSystem. A wait that does not fit the
 * budget is skipped and spends it, so a budget-aware caller yields at once and nothing
 * polls in a loop; a long phase waits for real (its time limit is raised).
 */
final class BudgetedWordPressSystemTest extends TestCase {
    /** @var FakeCutoverSystem */
    private $inner;
    /** @var int */
    private $clock = 1000;

    protected function setUp(): void {
        $this->inner = new FakeCutoverSystem();
    }

    private function system(bool $real_waits = false): BudgetedWordPressSystem {
        return new BudgetedWordPressSystem($this->inner, 1020, function () {
            return $this->clock;
        }, $real_waits);
    }

    public function test_it_is_a_budgeted_system_spent_at_its_deadline(): void {
        $system = $this->system();

        self::assertInstanceOf(BudgetedSystem::class, $system);
        self::assertFalse($system->budget_spent());
        $this->clock = 1019;
        self::assertFalse($system->budget_spent());
        $this->clock = 1020;
        self::assertTrue($system->budget_spent());
    }

    public function test_a_sleep_that_fits_the_budget_sleeps_through_the_inner_system(): void {
        $system = $this->system();
        $this->clock = 1018;

        $system->sleep(2);

        self::assertSame(1, $this->inner->sleeps);
        self::assertFalse($system->budget_spent());
    }

    public function test_a_sleep_past_the_deadline_is_skipped_and_spends_the_budget(): void {
        // Carry-in 1: the next budget check yields; a real-clock poll never spins.
        $system = $this->system();
        $this->clock = 1019;

        $system->sleep(2);

        self::assertSame(0, $this->inner->sleeps);
        self::assertTrue($system->budget_spent(), 'spent even though the clock is before the deadline');
    }

    public function test_a_long_phase_always_waits_for_real(): void {
        $system = $this->system(true);
        $this->clock = 5000;

        $system->sleep(2);

        self::assertSame(1, $this->inner->sleeps);
        self::assertTrue($system->real_waits());
        self::assertFalse($this->system()->real_waits());
    }

    public function test_reached_collects_steps_without_printing_them(): void {
        $system = $this->system();

        $system->reached('quiesced');
        $system->reached('cut_over');

        self::assertSame(array('quiesced', 'cut_over'), $system->steps());
        self::assertSame(array(), $this->inner->log, 'not delegated: WP-CLI would print it');
    }

    public function test_everything_else_delegates(): void {
        $system = $this->system();
        $this->inner->events = array(array('timestamp' => 1, 'hook' => 'h', 'args' => array(), 'schedule' => false));

        self::assertSame($this->inner->events, $system->cron_events());
        self::assertTrue($system->schedule_event(2, 'g', array(1), 'hourly'));
        self::assertTrue($system->unschedule_event(2, 'g', array(1)));
        self::assertSame('upayments/UPayments.php', $system->official_plugin());
        self::assertSame(array('upayments/UPayments.php'), $system->installed_official_plugins());
        self::assertTrue($system->deactivate_plugin('upayments/UPayments.php'));
        self::assertNull($system->official_plugin());
        self::assertTrue($system->activate_plugin('upayments/UPayments.php'));
        self::assertSame(1800000000, $system->now());
        self::assertSame(array('ran' => array('object_cache'), 'manual' => array(), 'failed' => array()), $system->purge_caches());
    }
}
