<?php

namespace Simplixi\SUPCheckout\Tests\Subscription;

use PHPUnit\Framework\TestCase;
use Simplixi\SUPCheckout\Subscription\RenewalCardAuthority;

final class RenewalCardAuthorityTest extends TestCase
{
    private function order(array $meta) {
        return new class($meta) {
            private $meta;
            public function __construct($meta) { $this->meta = $meta; }
            public function get_meta($key) {
                return array_key_exists($key, $this->meta) ? $this->meta[$key] : '';
            }
        };
    }

    public function test_missing_explicit_token_never_selects_first_saved_card(): void
    {
        $order = $this->order(array(
            '_upay_customer_unique_token' => 'customer-token-a',
        ));
        $loader_calls = 0;
        $result = RenewalCardAuthority::resolve_explicit_token($order, function ($token) use (&$loader_calls) {
            $loader_calls++;
            return array(
                'result' => 'success',
                'data'   => array(
                    array('token' => 'first-card-token'),
                    array('token' => 'second-card-token'),
                ),
            );
        });

        self::assertSame(RenewalCardAuthority::STATE_MISSING, $result['state']);
        self::assertNull($result['token']);
        self::assertSame(0, $loader_calls, 'No provider card retrieval is required to refuse implicit first-card charging.');
    }

    public function test_explicit_token_is_used_only_when_membership_is_proven(): void
    {
        $order = $this->order(array(
            '_upay_credit_card_token'     => 'bound-card-token',
            '_upay_customer_unique_token' => 'customer-token-a',
        ));
        $result = RenewalCardAuthority::resolve_explicit_token($order, function () {
            return array(
                'result' => 'success',
                'data'   => array(
                    array('token' => 'other-card'),
                    array('token' => 'bound-card-token'),
                ),
            );
        });

        self::assertSame(RenewalCardAuthority::STATE_EXPLICIT, $result['state']);
        self::assertSame('bound-card-token', $result['token']);
    }

    public function test_stored_token_absent_from_provider_list_is_revoked_not_substituted(): void
    {
        $order = $this->order(array(
            '_upay_credit_card_token'     => 'bound-card-token',
            '_upay_customer_unique_token' => 'customer-token-a',
        ));
        $result = RenewalCardAuthority::resolve_explicit_token($order, function () {
            return array(
                'result' => 'success',
                'data'   => array(
                    array('token' => 'some-other-card'),
                ),
            );
        });

        self::assertSame(RenewalCardAuthority::STATE_REVOKED, $result['state']);
        self::assertNull($result['token']);
    }

    public function test_retrieval_failure_is_pre_dispatch_safe_and_substitutes_nothing(): void
    {
        $order = $this->order(array(
            '_upay_credit_card_token'     => 'bound-card-token',
            '_upay_customer_unique_token' => 'customer-token-a',
        ));
        $result = RenewalCardAuthority::resolve_explicit_token($order, function () {
            return array('result' => 'error');
        });

        self::assertSame(RenewalCardAuthority::STATE_RETRIEVAL_FAILED, $result['state']);
        self::assertNull($result['token']);
    }

    public function test_empty_card_list_with_missing_stored_token_is_missing_not_first_card(): void
    {
        $order = $this->order(array(
            '_upay_customer_unique_token' => 'customer-token-a',
        ));
        $result = RenewalCardAuthority::resolve_explicit_token($order, function () {
            return array('result' => 'success', 'data' => array());
        });

        self::assertSame(RenewalCardAuthority::STATE_MISSING, $result['state']);
        self::assertNull($result['token']);
    }
}
