<?php

namespace Simplixi\SUPCheckout\Tests\Rest;

use PHPUnit\Framework\Attributes\DataProvider;
use Simplixi\SUPCheckout\Rest\Responses;
use Simplixi\SUPCheckout\Takeover\Onboarding\Bounded;
use Simplixi\SUPCheckout\Takeover\Onboarding\Outcome;

/**
 * 0c Task 7: the response envelope, the Outcome → HTTP map, allow-listed projections and
 * bounded documents.
 */
final class ResponsesTest extends RestTestCase {
    /** @return array<string, array{int, int, string}> */
    public static function outcome_codes(): array {
        return array(
            'error' => array(Outcome::ERROR, 500, 'supcheckout_error'),
            'blocked' => array(Outcome::BLOCKED, 409, 'supcheckout_blocked'),
            'mismatch' => array(Outcome::MISMATCH, 409, 'supcheckout_mismatch'),
            'busy' => array(Outcome::BUSY, 409, 'supcheckout_busy'),
            'stale claims' => array(Outcome::STALE_CLAIMS, 409, 'supcheckout_stale_claims'),
            'state' => array(Outcome::STATE, 409, 'supcheckout_state'),
            'official still active' => array(Outcome::OFFICIAL_STILL_ACTIVE, 409, 'supcheckout_official_still_active'),
            'unknown code' => array(42, 500, 'supcheckout_error'),
        );
    }

    #[DataProvider('outcome_codes')]
    public function test_outcomes_map_to_http(int $code, int $status, string $error_code): void {
        $error = Responses::from_outcome(Outcome::fail($code, 'some_reason', 'message for ' . $code));

        self::assertInstanceOf(\WP_Error::class, $error);
        self::assertSame($error_code, $error->get_error_code());
        self::assertSame('message for ' . $code, $error->get_error_message());
        self::assertSame(array('status' => $status, 'exit_code' => $code, 'reason' => 'some_reason'), $error->get_error_data());
    }

    public function test_success_is_200_with_the_envelope(): void {
        $response = Responses::from_outcome(Outcome::ok('released 1, kept 0', array('released' => array('cycle-1'), 'skipped' => array())));

        self::assertInstanceOf(\WP_REST_Response::class, $response);
        self::assertSame(200, $response->get_status());
        self::assertSame(array('ok' => true, 'data' => array('released' => array('cycle-1'), 'skipped' => array(), 'message' => 'released 1, kept 0'), 'warnings' => array()), $response->get_data());
        self::assertSame('no-store, private', $response->get_headers()['Cache-Control']);
    }

    public function test_conflicts_and_official_not_reactivated_are_200_with_a_warning(): void {
        $conflicts = Responses::from_outcome(Outcome::fail(Outcome::CONFLICTS, 'conflicts', 'rolled back, but 1 conflict(s) were kept'));
        $not_reactivated = Responses::from_outcome(Outcome::fail(Outcome::OFFICIAL_NOT_REACTIVATED, 'official_not_reactivated', 'rolled back, but not reactivated'));

        self::assertSame(array(200, array('conflicts')), array($conflicts->get_status(), $conflicts->get_data()['warnings']));
        self::assertSame(array(200, array('official_not_reactivated')), array($not_reactivated->get_status(), $not_reactivated->get_data()['warnings']));
        self::assertTrue($conflicts->get_data()['ok']);
    }

    public function test_error_data_carries_days_left_and_a_hint_when_present(): void {
        $error = Responses::from_outcome(Outcome::fail(Outcome::STATE, 'too_early', 'finalize is allowed in 30 day(s)', array('days_left' => 30, 'due_at' => 1, 'status' => 'cut_over')), array('hint' => 'review the dry run'));

        self::assertSame(array('status' => 409, 'exit_code' => 6, 'reason' => 'too_early', 'hint' => 'review the dry run', 'days_left' => 30), $error->get_error_data());
    }

    public function test_engine_results_and_reports_are_never_serialized(): void {
        // Carry-in (a): Outcome::data() holds raw engine results; only an allow-list goes out.
        $data = array(
            'result' => array('report' => array('owner_token' => 'tok-SECRET'), 'steps' => array('x')),
            'report' => array('blocking' => array(array('data' => array('api_key' => 'sk-SECRET')))),
            'mismatches' => array('orders' => array('keys' => array('SECRET-KEY'))),
            'unexpected' => 'SECRET-unexpected',
            'status' => 'cut_over',
            'next' => 'done',
        );

        $ok = Responses::from_outcome(Outcome::ok('already cut over', $data));
        $error = Responses::from_outcome(Outcome::fail(Outcome::MISMATCH, 'verify_mismatch', 'cutover refused', $data));

        self::assertStringNotContainsString('SECRET', (string) wp_json_encode($ok->get_data()));
        self::assertStringNotContainsString('SECRET', (string) wp_json_encode($error->get_error_data()));
        self::assertSame(array('status' => 'cut_over', 'next' => 'done', 'message' => 'already cut over'), $ok->get_data()['data']);
    }

    public function test_ok_bounds_the_whole_document(): void {
        $big = array();
        for ($i = 0; $i < 400; $i++) {
            $big['entry_' . $i] = str_repeat('x', 400);
        }

        $response = Responses::ok(array('operation' => $big), 202);

        self::assertSame(202, $response->get_status());
        self::assertLessThanOrEqual(Bounded::MAX_BYTES, strlen((string) wp_json_encode($response->get_data())));
        self::assertTrue($response->get_data()['ok']);
    }

    public function test_project_caps_maps_and_lists_in_linear_time(): void {
        // Carry-in (d): thousands of map entries each holding a list stay cheap and small.
        $map = array();
        for ($i = 0; $i < 20000; $i++) {
            $map['group_' . $i] = array('count' => $i, 'keys' => range(1, 30));
        }

        $start = microtime(true);
        $capped = Responses::cap(array('mismatches' => $map));
        $response = Responses::ok(array('takeover' => $capped));
        $elapsed = microtime(true) - $start;

        self::assertCount(Responses::MAP_LIMIT + 2, $capped['mismatches'], 'MAP_LIMIT entries and the _truncated / _omitted markers');
        self::assertSame(20000 - Responses::MAP_LIMIT, $capped['mismatches']['_omitted']);
        self::assertTrue($capped['mismatches']['_truncated']);
        self::assertSame(array('truncated' => 10), $capped['mismatches']['group_0']['keys'][20]);
        self::assertLessThanOrEqual(Bounded::MAX_BYTES, strlen((string) wp_json_encode($response->get_data())));
        self::assertLessThan(2.0, $elapsed);
    }

    public function test_error_builds_a_wp_error_with_its_status(): void {
        $error = Responses::error('supcheckout_rate_limited', 'slow down', 429, array('retry_after' => 9));

        self::assertSame(array('supcheckout_rate_limited', 'slow down', array('status' => 429, 'retry_after' => 9)), array($error->get_error_code(), $error->get_error_message(), $error->get_error_data()));
    }
}
