<?php

namespace Simplixi\SUPCheckout\Tests\Takeover;

use PHPUnit\Framework\TestCase;
use Simplixi\SUPCheckout\Takeover\Journal;
use Simplixi\SUPCheckout\Takeover\Quiesce;
use Simplixi\SUPCheckout\Takeover\StaleClaims;

/**
 * 0b Task 9: the stale-claims report and `release-stale-claims`. Only `claimed` rows older
 * than STALE_SECONDS are listed or released: a `claimed` row means its worker never passed
 * mark_dispatching(), so no provider POST was sent and releasing it is money-safe.
 */
final class StaleClaimsTest extends TestCase {
    private const NOW = 1800000000;

    /** @var FakeStaleClaimStore */
    private $store;

    protected function setUp(): void {
        \supcheckout_test_reset_wp_options();
        Journal::flush_cache();
        $this->store = new FakeStaleClaimStore();
    }

    protected function tearDown(): void {
        \supcheckout_test_reset_wp_options();
        Journal::flush_cache();
    }

    private function seed(): void {
        $this->store->add(str_repeat('a', 64), 'claimed', 'owner-a', self::NOW - 3600);
        $this->store->add(str_repeat('b', 64), 'claimed', 'owner-b', self::NOW - 60);
        $this->store->add(str_repeat('c', 64), 'dispatching', 'owner-c', self::NOW - 7200);
        $this->store->add(str_repeat('d', 64), 'held', 'owner-d', self::NOW - 7200);
        $this->store->add(str_repeat('e', 64), 'resolved', 'owner-e', self::NOW - 7200);
        $this->store->add(str_repeat('f', 64), 'claimed', 'owner-f', self::NOW - StaleClaims::STALE_SECONDS - 1);
    }

    public function test_the_report_lists_only_claimed_rows_older_than_600_seconds_by_key_and_age(): void {
        $this->seed();

        $report = StaleClaims::report($this->store, self::NOW);

        self::assertSame(600, StaleClaims::STALE_SECONDS);
        self::assertSame(array(
            array('cycle_key' => str_repeat('a', 64), 'age_seconds' => 3600),
            array('cycle_key' => str_repeat('f', 64), 'age_seconds' => 601),
        ), $report);
        self::assertStringNotContainsString('owner-', (string) json_encode($report), 'no owner token in the report');
    }

    public function test_release_removes_only_stale_claimed_rows_under_the_lease_and_journals_each(): void {
        $this->seed();
        $leased = array();
        $this->store->on_release = static function () use (&$leased) {
            $leased[] = Quiesce::is_active(self::NOW);
        };

        $result = StaleClaims::release($this->store, self::NOW);

        self::assertTrue($result['ok'], (string) json_encode($result));
        self::assertNull($result['error']);
        self::assertSame(array(str_repeat('a', 64), str_repeat('f', 64)), $result['released']);
        self::assertSame(array(), $result['skipped']);
        self::assertSame(array(true, true), $leased, 'every release runs under the quiesce lease');
        self::assertFalse(Quiesce::is_active(self::NOW), 'the lease is released afterwards');
        self::assertSame(array(str_repeat('b', 64), str_repeat('c', 64), str_repeat('d', 64), str_repeat('e', 64)), array_keys($this->store->rows), 'a fresh claim and every dispatching, held and resolved row stay');

        $entries = Journal::entries();
        self::assertCount(2, $entries);
        self::assertSame('billing_claim', $entries[0]['target']);
        self::assertSame('billing_attempts/' . str_repeat('a', 64), $entries[0]['path']);
        self::assertSame(StaleClaims::RESTORE_SOURCE, $entries[0]['restore_source']);
        self::assertSame(Journal::fingerprint(null), $entries[0]['after_fingerprint']);
        self::assertStringNotContainsString('owner-a', (string) json_encode($entries));
    }

    public function test_a_live_worker_that_moves_its_row_first_is_never_released(): void {
        // Between the listing and the CAS: one worker reached mark_dispatching() (it may
        // POST now), another reclaimed a row (new owner_token and updated_gmt).
        $this->store->add(str_repeat('a', 64), 'claimed', 'owner-a', self::NOW - 3600);
        $this->store->add(str_repeat('b', 64), 'claimed', 'owner-b', self::NOW - 3600);
        $store = $this->store;
        $this->store->on_release = static function (array $row) use ($store) {
            if ($row['cycle_key'] === str_repeat('a', 64)) {
                $store->rows[$row['cycle_key']]['state'] = 'dispatching';
            } else {
                $store->rows[$row['cycle_key']]['owner_token'] = 'owner-new';
                $store->rows[$row['cycle_key']]['updated_gmt'] = gmdate('Y-m-d H:i:s', self::NOW - 3599);
            }
        };

        $result = StaleClaims::release($this->store, self::NOW);

        self::assertTrue($result['ok']);
        self::assertSame(array(), $result['released']);
        self::assertSame(array(str_repeat('a', 64), str_repeat('b', 64)), $result['skipped']);
        self::assertSame('dispatching', $this->store->rows[str_repeat('a', 64)]['state'], 'the dispatching row is untouched');
        self::assertSame('owner-new', $this->store->rows[str_repeat('b', 64)]['owner_token'], 'the reclaimed row is untouched');
    }

    public function test_release_is_refused_while_another_operator_holds_the_lease(): void {
        $this->seed();
        $held = Quiesce::acquire(self::NOW);

        $result = StaleClaims::release($this->store, self::NOW);

        self::assertFalse($result['ok']);
        self::assertSame('busy', $result['error']);
        self::assertSame(array(), $this->store->released);
        self::assertSame(array(), Journal::entries());
        self::assertTrue(Quiesce::holds((string) $held, self::NOW), 'the other lease is untouched');
    }
}
