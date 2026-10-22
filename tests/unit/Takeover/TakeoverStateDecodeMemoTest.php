<?php

namespace Simplixi\SUPCheckout\Takeover {
    /**
     * Counting seam for the Takeover namespace: unqualified json_decode() calls made by
     * TakeoverState resolve here first, and delegate to the global function unchanged.
     *
     * @param string $json  JSON text.
     * @param bool   $assoc Associative arrays.
     * @param int    $depth Depth.
     * @param int    $flags Flags.
     * @return mixed
     */
    function json_decode($json, $assoc = null, $depth = 512, $flags = 0) {
        $GLOBALS['supcheckout_test_takeover_decodes'] = (int) ($GLOBALS['supcheckout_test_takeover_decodes'] ?? 0) + 1;
        return \json_decode($json, $assoc, $depth, $flags);
    }
}

namespace Simplixi\SUPCheckout\Tests\Takeover {
    use PHPUnit\Framework\TestCase;
    use Simplixi\SUPCheckout\Takeover\TakeoverState;

    /** A3-07: the stored takeover state is decoded once per request, not once per get(). */
    final class TakeoverStateDecodeMemoTest extends TestCase {
        private const OPTION = 'supcheckout_takeover_state_v1';

        protected function setUp(): void {
            \supcheckout_test_reset_wp_options();
            TakeoverState::flush_cache();
            $GLOBALS['supcheckout_test_takeover_decodes'] = 0;
        }

        protected function tearDown(): void {
            \supcheckout_test_reset_wp_options();
            TakeoverState::flush_cache();
            unset($GLOBALS['supcheckout_test_takeover_decodes']);
        }

        /** @param array<string, mixed> $state */
        private static function seed(array $state): void {
            $GLOBALS['supcheckout_test_options'][self::OPTION] = (string) json_encode($state);
            TakeoverState::flush_cache();
            $GLOBALS['supcheckout_test_takeover_decodes'] = 0;
        }

        private static function decodes(): int {
            return (int) $GLOBALS['supcheckout_test_takeover_decodes'];
        }

        public function test_repeated_reads_of_an_unchanged_record_decode_it_once(): void {
            self::seed(array('status' => 'cut_over', 'cut_over_at' => 5, 'cursor' => 3, 'totals' => array('orders' => 9)));

            for ($i = 0; $i < 10; $i++) {
                $state = TakeoverState::get();
                self::assertSame('cut_over', $state['status']);
                self::assertSame(array('orders' => 9), $state['totals']);
            }

            self::assertSame(1, self::decodes());
        }

        public function test_a_caller_changing_a_returned_state_does_not_alter_the_next_read(): void {
            self::seed(array('status' => 'copying', 'cut_over_at' => 0, 'cursor' => 3, 'totals' => array()));

            $first = TakeoverState::get();
            $first['status'] = 'finalized';
            $first['totals']['x'] = 1;

            $second = TakeoverState::get();
            self::assertSame('copying', $second['status']);
            self::assertSame(array(), $second['totals']);
        }

        public function test_a_changed_raw_value_is_decoded_again(): void {
            self::seed(array('status' => 'copying', 'cut_over_at' => 0, 'cursor' => 1, 'totals' => array()));
            self::assertSame('copying', TakeoverState::get()['status']);

            // Another process changed the row; this request's next read sees new raw text.
            $GLOBALS['supcheckout_test_options'][self::OPTION] = (string) json_encode(array('status' => 'verified', 'cut_over_at' => 0, 'cursor' => 1, 'totals' => array()));

            self::assertSame('verified', TakeoverState::get()['status']);
            self::assertSame(2, self::decodes());
        }

        public function test_flush_cache_and_refresh_drop_the_memo(): void {
            self::seed(array('status' => 'verified', 'cut_over_at' => 0, 'cursor' => 0, 'totals' => array()));
            TakeoverState::get();
            TakeoverState::get();
            self::assertSame(1, self::decodes());

            TakeoverState::flush_cache();
            TakeoverState::get();
            self::assertSame(2, self::decodes(), 'flush_cache() forgets the decoded copy');

            TakeoverState::refresh();
            TakeoverState::get();
            self::assertSame(3, self::decodes(), 'refresh() forgets the decoded copy');
        }

        public function test_a_transition_and_a_patch_are_visible_to_the_next_read(): void {
            self::seed(array('status' => 'preflight_ok', 'cut_over_at' => 0, 'cursor' => 0, 'totals' => array()));
            self::assertSame('preflight_ok', TakeoverState::get()['status']);

            self::assertTrue(TakeoverState::transition('copying'));
            self::assertSame('copying', TakeoverState::get()['status']);

            self::assertTrue(TakeoverState::patch(array('cursor' => 42)));
            self::assertSame(42, TakeoverState::get()['cursor']);
            self::assertSame('copying', TakeoverState::get()['status']);
        }

        public function test_the_first_record_is_visible_to_the_next_read(): void {
            self::assertSame('idle', TakeoverState::get()['status']);

            self::assertTrue(TakeoverState::transition('preflight_ok'));

            self::assertSame('preflight_ok', TakeoverState::get()['status']);
        }

        public function test_a_transition_compares_against_the_stored_record_not_the_memo(): void {
            self::seed(array('status' => 'copying', 'cut_over_at' => 0, 'cursor' => 0, 'totals' => array()));
            self::assertSame('copying', TakeoverState::get()['status'], 'primes the memo');

            // Another process moved the store on behind this request's back.
            $winner = (string) json_encode(array('status' => 'preflight_ok', 'cut_over_at' => 0, 'cursor' => 0, 'totals' => array(), 'mismatches' => array('order 7')));
            $GLOBALS['supcheckout_test_options'][self::OPTION] = $winner;

            self::assertFalse(TakeoverState::transition('verified'), 'copying -> verified is not allowed from the stored status');
            self::assertFalse(TakeoverState::patch(array('cursor' => 9), 'copying'), 'the expected status is checked against storage');
            self::assertSame($winner, $GLOBALS['supcheckout_test_options'][self::OPTION], 'never overwritten');
        }

        public function test_an_unreadable_record_stays_unreadable_and_is_not_memoised_as_valid(): void {
            $GLOBALS['supcheckout_test_options'][self::OPTION] = 'garbage';
            TakeoverState::flush_cache();

            self::assertSame('invalid', TakeoverState::get()['status']);
            self::assertSame('invalid', TakeoverState::get()['status']);

            $GLOBALS['supcheckout_test_options'][self::OPTION] = (string) json_encode(array('status' => 'verified'));
            self::assertSame('verified', TakeoverState::get()['status']);
        }
    }
}
