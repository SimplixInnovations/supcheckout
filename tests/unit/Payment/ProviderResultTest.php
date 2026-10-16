<?php

namespace Simplixi\SUPCheckout\Tests\Payment;

use PHPUnit\Framework\TestCase;
use Simplixi\SUPCheckout\Payment\ProviderResult;

final class ProviderResultTest extends TestCase {
    public function test_only_exact_documented_results_receive_terminal_meaning(): void {
        self::assertSame(ProviderResult::CAPTURED, ProviderResult::classify('CAPTURED'));
        self::assertSame(ProviderResult::PENDING, ProviderResult::classify('AUTHORIZED'));
        self::assertSame(ProviderResult::FAILED, ProviderResult::classify('NOT CAPTURED'));
        self::assertSame(ProviderResult::CANCELLED, ProviderResult::classify('CANCELED'));
    }

    public function test_negative_spellings_used_by_the_official_plugin_are_terminal(): void {
        // Official UPayments 3.1.1 treats both cancel spellings and FAILURE as final.
        // They can only fail or cancel an order, never mark it paid.
        self::assertSame(ProviderResult::CANCELLED, ProviderResult::classify('CANCELLED'));
        self::assertSame(ProviderResult::FAILED, ProviderResult::classify('FAILURE'));
    }

    public function test_success_is_never_treated_as_a_capture_without_provider_evidence(): void {
        // The official plugin marks SUCCESS paid; no UPayments status-API evidence shows
        // that value. Paid state needs evidence, so it stays indeterminate (retried,
        // then surfaced for manual review) until live acceptance proves its meaning.
        self::assertSame(ProviderResult::INDETERMINATE, ProviderResult::classify('SUCCESS'));
    }

    public function test_unknown_or_malformed_results_remain_indeterminate(): void {
        self::assertSame(ProviderResult::INDETERMINATE, ProviderResult::classify('Processing'));
        self::assertSame(ProviderResult::INDETERMINATE, ProviderResult::classify('REFUND'));
        self::assertSame(ProviderResult::INDETERMINATE, ProviderResult::classify(''));
        self::assertSame(ProviderResult::INDETERMINATE, ProviderResult::classify(null));
        self::assertSame(ProviderResult::INDETERMINATE, ProviderResult::classify(array('CAPTURED')));
    }
}
