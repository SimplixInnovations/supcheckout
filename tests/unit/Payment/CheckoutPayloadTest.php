<?php

namespace Simplixi\SUPCheckout\Tests\Payment;

use PHPUnit\Framework\TestCase;
use Simplixi\SUPCheckout\Payment\CheckoutPayload;

final class CheckoutPayloadTest extends TestCase {
    public function test_classic_method_selection_is_required_only_for_white_label_accounts(): void {
        $white_label = array('whitelabled' => true, 'payment' => array('knet' => 'KNET', 'cc' => 'Credit Card'));
        $hosted = array('whitelabled' => false, 'payment' => array('knet' => 'KNET'));

        // White-label: the customer picks the method on the store, so it must be present.
        self::assertFalse(CheckoutPayload::classic_method_selected($white_label, array()));
        self::assertFalse(CheckoutPayload::classic_method_selected($white_label, array('upayment_payment_type' => '')));
        self::assertFalse(CheckoutPayload::classic_method_selected($white_label, array('upayment_payment_type' => 'upayments')));
        self::assertFalse(CheckoutPayload::classic_method_selected($white_label, array('upayment_payment_type' => array('knet'))));
        self::assertTrue(CheckoutPayload::classic_method_selected($white_label, array('upayment_payment_type' => 'knet')));
        // Hosted: UPayments' own page chooses the method; nothing is required here.
        self::assertTrue(CheckoutPayload::classic_method_selected($hosted, array()));
        // Unknown availability: let the authoritative server checks in process_payment decide.
        self::assertTrue(CheckoutPayload::classic_method_selected(null, array()));
    }

    public function test_provider_mobile_accepts_only_unambiguous_international_numbers(): void {
        self::assertSame('+96555551234', CheckoutPayload::provider_mobile('+965 5555 1234'));
        self::assertSame('+96555551234', CheckoutPayload::provider_mobile('+965-5555-(1234)'));
        // Arabic-Indic and Extended Arabic-Indic digits, as typed on Arabic keyboards.
        self::assertSame('+96555551234', CheckoutPayload::provider_mobile('+٩٦٥ ٥٥٥٥ ١٢٣٤'));
        self::assertSame('+96555551234', CheckoutPayload::provider_mobile('+۹۶۵۵۵۵۵۱۲۳۴'));
        // The 00 international prefix is the same number.
        self::assertSame('+96555551234', CheckoutPayload::provider_mobile('0096555551234'));
        self::assertSame('+96555551234', CheckoutPayload::provider_mobile('٠٠٩٦٥٥٥٥٥١٢٣٤'));
        // No country code, or not E.164: omit rather than guess.
        self::assertSame('', CheckoutPayload::provider_mobile('55551234'));
        self::assertSame('', CheckoutPayload::provider_mobile('+0965555'));
        self::assertSame('', CheckoutPayload::provider_mobile('+12345'));
        self::assertSame('', CheckoutPayload::provider_mobile('+1234567890123456'));
        // UPayments limits customer.mobile to 15 characters including '+' (XLIM-12).
        self::assertSame('+12345678901234', CheckoutPayload::provider_mobile('+12345678901234'));
        self::assertSame('', CheckoutPayload::provider_mobile('+123456789012345'));
        self::assertSame('', CheckoutPayload::provider_mobile('00123456789012345'));
        self::assertSame('', CheckoutPayload::provider_mobile('+965 5555 abcd'));
        self::assertSame('', CheckoutPayload::provider_mobile(''));
        self::assertSame('', CheckoutPayload::provider_mobile(null));
        self::assertSame('', CheckoutPayload::provider_mobile(array('+96555551234')));
    }

    public function test_decimal_comparison_preserves_exact_provider_economics(): void {
        self::assertSame(-1, CheckoutPayload::compare_nonnegative_decimal_strings('9.99', '10'));
        self::assertSame(0, CheckoutPayload::compare_nonnegative_decimal_strings('1.0', '1.000'));
        self::assertSame(1, CheckoutPayload::compare_nonnegative_decimal_strings('1.01', '1.001'));
    }

    public function test_amount_token_rejects_ambiguous_or_nonpositive_values(): void {
        self::assertSame('0.125', CheckoutPayload::build_amount_json_token('0.125'));
        self::assertNull(CheckoutPayload::build_amount_json_token(''));
        self::assertNull(CheckoutPayload::build_amount_json_token('0.00'));
        self::assertNull(CheckoutPayload::build_amount_json_token('01.00'));
        self::assertNull(CheckoutPayload::build_amount_json_token('1e3'));
        self::assertNull(CheckoutPayload::build_amount_json_token(1.0));
    }

    public function test_nonnegative_number_token_allows_zero_without_weakening_order_amount(): void {
        self::assertTrue(method_exists(CheckoutPayload::class, 'build_nonnegative_json_number_token'));
        self::assertSame('0', CheckoutPayload::build_nonnegative_json_number_token('0'));
        self::assertSame('0.000', CheckoutPayload::build_nonnegative_json_number_token('0.000'));
        self::assertSame('10.500', CheckoutPayload::build_nonnegative_json_number_token('10.500'));
        self::assertNull(CheckoutPayload::build_nonnegative_json_number_token('01.00'));
        self::assertNull(CheckoutPayload::build_nonnegative_json_number_token('1e3'));
        self::assertNull(CheckoutPayload::build_nonnegative_json_number_token(' 0'));
        self::assertNull(CheckoutPayload::build_nonnegative_json_number_token(0));
        self::assertNull(CheckoutPayload::build_amount_json_token('0'));
        self::assertNull(CheckoutPayload::build_amount_json_token('0.000'));
    }

    public function test_json_number_injection_never_quotes_provider_amount(): void {
        $sentinel = '__UPAY_ORDER_AMOUNT_SENTINEL__';
        $encoded = '{"order":{"amount":"' . $sentinel . '"}}';

        self::assertSame(
            '{"order":{"amount":10.500}}',
            CheckoutPayload::inject_amount_token_into_payload_json($encoded, array($sentinel => '10.500'))
        );
        self::assertNull(
            CheckoutPayload::inject_amount_token_into_payload_json(
                '{"a":"' . $sentinel . '","b":"' . $sentinel . '"}',
                array($sentinel => '10.500')
            )
        );
    }

    public function test_checkout_context_and_redirects_fail_closed(): void {
        self::assertSame(
            '/wc/store/v1/checkout',
            CheckoutPayload::normalize_store_api_route('/shop/wp-json/wc/store/v1/checkout')
        );
        self::assertTrue(
            CheckoutPayload::classify_checkout_request_context(true, '/wc/store/v1/checkout/', 'post')
        );
        self::assertFalse(
            CheckoutPayload::classify_checkout_request_context(true, '/wc/store/v1/cart', 'POST')
        );
        self::assertSame(
            'https://pay.example/redirect',
            CheckoutPayload::normalize_upayments_redirect_url(' https://pay.example/redirect ')
        );
        self::assertSame(
            'http://sandboxapi.upayments.com/get-pay-by-apple',
            CheckoutPayload::normalize_upayments_redirect_url('http://sandboxapi.upayments.com/get-pay-by-apple')
        );
        self::assertNull(
            CheckoutPayload::normalize_upayments_redirect_url('https://user@pay.example/redirect')
        );
        self::assertNull(
            CheckoutPayload::normalize_upayments_redirect_url('https://user:pass@pay.example/redirect')
        );
        self::assertSame(
            'https://pay.example/redirect?email=user@example.com',
            CheckoutPayload::normalize_upayments_redirect_url('https://pay.example/redirect?email=user@example.com')
        );
        self::assertNull(CheckoutPayload::normalize_upayments_redirect_url('javascript:alert(1)'));
        self::assertNull(
            CheckoutPayload::normalize_upayments_redirect_url("https://pay.example/ok\r\nX-Test: bad")
        );
    }

    /**
     * A2-02 revised (Q12 evidence): in live mode a Charge redirect must be https. An http
     * link on upayments.com or a subdomain is upgraded (UPayments documents http
     * white-label wallet links, and the host 301-redirects to https); any other non-https
     * link is rejected. No host allowlist for https links. Sandbox is unchanged.
     */
    public function test_a2_02_live_mode_accepts_an_https_redirect_on_any_host(): void {
        self::assertSame('https://pay.example/redirect', CheckoutPayload::normalize_upayments_redirect_url(' https://pay.example/redirect ', true));
        self::assertSame('HTTPS://pay.example/redirect', CheckoutPayload::normalize_upayments_redirect_url('HTTPS://pay.example/redirect', true));
        self::assertSame('https://www.kpay.com.kw/kpg/PaymentHTTP.htm?x=1', CheckoutPayload::normalize_upayments_redirect_url('https://www.kpay.com.kw/kpg/PaymentHTTP.htm?x=1', true));
    }

    public function test_a2_02_live_mode_upgrades_an_http_upayments_link_to_https(): void {
        self::assertSame(
            'https://sandboxapi.upayments.com/get-pay-by-apple?session_id=abc',
            CheckoutPayload::normalize_upayments_redirect_url('http://sandboxapi.upayments.com/get-pay-by-apple?session_id=abc', true)
        );
        self::assertSame('https://x.upayments.com/pay', CheckoutPayload::normalize_upayments_redirect_url('http://x.upayments.com/pay', true));
        self::assertSame('https://upayments.com/pay', CheckoutPayload::normalize_upayments_redirect_url('http://upayments.com/pay', true));
        self::assertSame('https://X.UPayments.COM/pay', CheckoutPayload::normalize_upayments_redirect_url('HTTP://X.UPayments.COM/pay', true));
    }

    public function test_a2_02_live_mode_rejects_every_other_non_https_link(): void {
        foreach (array(
            'http://evil.example/pay',
            'http://upayments.com.evil.example/pay',
            'http://evilupayments.com/pay',
            'http://evil.example\\.upayments.com/pay',
            'javascript:alert(1)',
            'ftp://x.upayments.com/pay',
            'http://user@x.upayments.com/pay',
        ) as $link) {
            self::assertNull(CheckoutPayload::normalize_upayments_redirect_url($link, true), $link);
        }
        // An upgrade never lets the URL past the 250-character production cap.
        $long = 'http://x.upayments.com/' . str_repeat('a', 250 - strlen('http://x.upayments.com/'));
        self::assertSame(250, strlen($long));
        self::assertNull(CheckoutPayload::normalize_upayments_redirect_url($long, true));
    }

    public function test_a2_02_sandbox_keeps_http_payment_links(): void {
        self::assertSame(
            'http://sandboxapi.upayments.com/get-pay-by-apple',
            CheckoutPayload::normalize_upayments_redirect_url('http://sandboxapi.upayments.com/get-pay-by-apple', false)
        );
        self::assertSame('http://pay.example/redirect', CheckoutPayload::normalize_upayments_redirect_url('http://pay.example/redirect', false));
    }

    public function test_field_presence_distinguishes_absent_from_explicit_null(): void {
        self::assertTrue(CheckoutPayload::field_present(array('save' => null), 'save'));
        self::assertFalse(CheckoutPayload::field_present(array(), 'save'));
        self::assertTrue(CheckoutPayload::field_present(array(0 => 'zero'), 0));
        self::assertFalse(CheckoutPayload::field_present('not-an-array', 'save'));
    }

    public function test_save_card_parser_accepts_only_exact_zero_or_one_tokens(): void {
        self::assertFalse(CheckoutPayload::parse_save_card_strict(0));
        self::assertFalse(CheckoutPayload::parse_save_card_strict('0'));
        self::assertTrue(CheckoutPayload::parse_save_card_strict(1));
        self::assertTrue(CheckoutPayload::parse_save_card_strict('1'));

        foreach (array(null, '', false, true, 1.0, ' 1', 'yes', 2, array()) as $invalid) {
            self::assertNull(CheckoutPayload::parse_save_card_strict($invalid));
        }
    }

    public function test_payment_source_parsing_and_allowlist_are_separate_fail_closed_steps(): void {
        self::assertSame('knet', CheckoutPayload::parse_payment_source_strict('knet'));
        self::assertTrue(CheckoutPayload::is_valid_payment_source('knet'));
        self::assertSame('future-source', CheckoutPayload::parse_payment_source_strict('future-source'));
        self::assertFalse(CheckoutPayload::is_valid_payment_source('future-source'));

        foreach (array('', ' knet', "knet\n", null, true, 1, array()) as $invalid) {
            self::assertNull(CheckoutPayload::parse_payment_source_strict($invalid));
        }
    }

    public function test_subscription_plan_and_interval_boundaries_are_exact(): void {
        self::assertSame('monthly', CheckoutPayload::parse_subscription_plan_strict('monthly'));
        self::assertTrue(CheckoutPayload::is_valid_subscription_plan('monthly'));
        self::assertSame('future-plan', CheckoutPayload::parse_subscription_plan_strict('future-plan'));
        self::assertFalse(CheckoutPayload::is_valid_subscription_plan('future-plan'));
        self::assertNull(CheckoutPayload::parse_subscription_plan_strict(' monthly'));
        self::assertNull(CheckoutPayload::parse_subscription_plan_strict(1));

        self::assertSame(0, CheckoutPayload::parse_interval('0'));
        self::assertSame(3, CheckoutPayload::parse_interval(3));
        self::assertSame(-1, CheckoutPayload::parse_interval(true));
        self::assertSame(-1, CheckoutPayload::parse_interval('4'));
        self::assertTrue(CheckoutPayload::is_valid_subscription_interval('quarterly', 3));
        self::assertFalse(CheckoutPayload::is_valid_subscription_interval('monthly', 3));
        self::assertTrue(CheckoutPayload::is_valid_subscription_interval('one_time', 0));
    }

    public function test_decimal_validators_preserve_lexical_provider_values(): void {
        self::assertSame('0.01', CheckoutPayload::validate_provider_positive_decimal('0.01'));
        self::assertSame('10.500', CheckoutPayload::validate_provider_positive_decimal('10.500'));
        self::assertNull(CheckoutPayload::validate_provider_positive_decimal('0.00'));
        self::assertNull(CheckoutPayload::validate_provider_positive_decimal('1e3'));
        self::assertNull(CheckoutPayload::validate_provider_positive_decimal(10));

        self::assertSame('0.00', CheckoutPayload::validate_provider_nonnegative_decimal('0.00'));
        self::assertSame('00.50', CheckoutPayload::validate_provider_nonnegative_decimal('00.50'));
        self::assertNull(CheckoutPayload::validate_provider_nonnegative_decimal('-1'));
        self::assertNull(CheckoutPayload::validate_provider_nonnegative_decimal('1.'));
    }

    public function test_exact_unit_price_division_never_rounds_or_uses_float_input(): void {
        self::assertSame('0.125', CheckoutPayload::compute_provider_unit_price_decimal('1.00', 8));
        self::assertSame('0', CheckoutPayload::compute_provider_unit_price_decimal('0.00', 5));
        self::assertSame('2.5', CheckoutPayload::compute_provider_unit_price_decimal('10.00', 4));
        self::assertNull(CheckoutPayload::compute_provider_unit_price_decimal('10.00', 3));
        self::assertNull(CheckoutPayload::compute_provider_unit_price_decimal('01.00', 1));
        self::assertNull(CheckoutPayload::compute_provider_unit_price_decimal(1.00, 8));
        self::assertNull(CheckoutPayload::compute_provider_unit_price_decimal('1.00', 0));
        self::assertNull(CheckoutPayload::compute_provider_unit_price_decimal('1.00', '8'));
    }

    public function test_digit_long_division_exposes_exact_quotient_and_remainder(): void {
        self::assertSame('12', CheckoutPayload::digit_long_divide('100', 8));
        self::assertSame(4, CheckoutPayload::digit_long_divide_remainder('100', 8));
        self::assertSame('0', CheckoutPayload::digit_long_divide('1', 8));
        self::assertSame(1, CheckoutPayload::digit_long_divide_remainder('1', 8));
        self::assertNull(CheckoutPayload::digit_long_divide('1.0', 8));
        self::assertNull(CheckoutPayload::digit_long_divide('10', 0));
        self::assertNull(CheckoutPayload::digit_long_divide_remainder(array('10'), 2));
    }

    public function test_decimal_canonicalizer_rejects_ambiguous_lexical_forms(): void {
        self::assertSame('10', CheckoutPayload::canonicalize_provider_decimal_string(10));
        self::assertSame('10.5', CheckoutPayload::canonicalize_provider_decimal_string('10.5'));
        self::assertNull(CheckoutPayload::canonicalize_provider_decimal_string('01.00'));
        self::assertNull(CheckoutPayload::canonicalize_provider_decimal_string('+1'));
        self::assertNull(CheckoutPayload::canonicalize_provider_decimal_string('1e3'));
        self::assertNull(CheckoutPayload::canonicalize_provider_decimal_string(array('1')));
    }

    public function test_number_injection_validates_indexed_tokens_and_leftover_sentinels(): void {
        $payload = '{"products":[{"price":"__UPAY_PRODUCT_PRICE_SENTINEL_0__"}]}';
        $extra = array(
            'product_price_sent_substring' => '__UPAY_PRODUCT_PRICE_SENTINEL_',
            'product_price_tokens' => array('0.125'),
        );

        self::assertSame(
            '{"products":[{"price":0.125}]}',
            CheckoutPayload::inject_amount_token_into_payload_json($payload, array(), $extra)
        );
        self::assertNull(
            CheckoutPayload::inject_amount_token_into_payload_json($payload, array(), array(
                'product_price_sent_substring' => '__UPAY_PRODUCT_PRICE_SENTINEL_',
                'product_price_tokens' => array('12345678'),
            ))
        );
        self::assertNull(
            CheckoutPayload::inject_amount_token_into_payload_json(
                '{"order":{"amount":"__UPAY_ORDER_AMOUNT_SENTINEL__"}}',
                array('__UPAY_ORDER_AMOUNT_SENTINEL__' => 'not-a-number')
            )
        );
        self::assertNull(CheckoutPayload::inject_amount_token_into_payload_json('', array()));
    }

    public function test_sentinel_limits_remain_field_specific(): void {
        self::assertSame(22, CheckoutPayload::get_max_length_for_sentinel('__UPAY_ORDER_AMOUNT_SENTINEL__'));
        self::assertSame(7, CheckoutPayload::get_max_length_for_sentinel('__UPAY_PRODUCT_PRICE_SENTINEL__'));
        self::assertSame(10, CheckoutPayload::get_max_length_for_sentinel('__UPAY_MM_AMOUNT_SENTINEL__'));
        self::assertSame(0, CheckoutPayload::get_max_length_for_sentinel('__UPAY_MM_CC_CHARGE_SENTINEL__'));
        self::assertSame(0, CheckoutPayload::get_max_length_for_sentinel(array('unknown')));
    }

    public function test_store_api_route_normalization_handles_supported_wordpress_shapes(): void {
        self::assertSame(
            '/wc/store/v1/checkout',
            CheckoutPayload::normalize_store_api_route('/?rest_route=%2Fwc%2Fstore%2Fv1%2Fcheckout')
        );
        self::assertSame(
            '/wc/store/v1/checkout',
            CheckoutPayload::normalize_store_api_route('/index.php/wc/store/v1/checkout')
        );
        self::assertSame('/wc/store/v1/cart', CheckoutPayload::normalize_store_api_route('/wc/store/v1/cart'));
        self::assertSame('', CheckoutPayload::normalize_store_api_route(array('/wc/store/v1/checkout')));
        self::assertFalse(CheckoutPayload::classify_checkout_request_context(1, '/wc/store/v1/checkout', 'POST'));
        self::assertFalse(CheckoutPayload::classify_checkout_request_context(true, array(), 'POST'));
        self::assertFalse(CheckoutPayload::classify_checkout_request_context(true, '/wc/store/v1/checkout', array()));
    }

    public function test_runtime_store_api_wrapper_requires_exact_route_and_post(): void {
        if (!defined('REST_REQUEST')) {
            define('REST_REQUEST', true);
        }

        $request_uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : null;
        $request_method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : null;
        try {
            $_SERVER['REQUEST_URI'] = '/wp-json/wc/store/v1/checkout';
            $_SERVER['REQUEST_METHOD'] = 'POST';
            self::assertTrue(CheckoutPayload::is_store_api_checkout_request());

            $_SERVER['REQUEST_URI'] = '/wp-json/wc/store/v1/cart';
            self::assertFalse(CheckoutPayload::is_store_api_checkout_request());

            $_SERVER['REQUEST_URI'] = '/wp-json/wc/store/v1/checkout';
            $_SERVER['REQUEST_METHOD'] = 'GET';
            self::assertFalse(CheckoutPayload::is_store_api_checkout_request());
        } finally {
            if ($request_uri === null) {
                unset($_SERVER['REQUEST_URI']);
            } else {
                $_SERVER['REQUEST_URI'] = $request_uri;
            }
            if ($request_method === null) {
                unset($_SERVER['REQUEST_METHOD']);
            } else {
                $_SERVER['REQUEST_METHOD'] = $request_method;
            }
        }
    }

    public function test_a4_02_provider_language_is_arabic_only_for_arabic_language_codes(): void {
        foreach (array('ar', 'AR', 'ar_KW', 'ar-SA', 'ary', 'ary_MA', ' ar ') as $arabic) {
            self::assertSame('ar', CheckoutPayload::provider_language_code(array($arabic)), $arabic);
        }
        // Aragonese (arg), Mapudungun (arn) and every other language get English.
        foreach (array('en', 'en_US', 'fr_FR', 'arg', 'arn', 'ara', 'x-ar', '') as $other) {
            self::assertSame('en', CheckoutPayload::provider_language_code(array($other)), $other);
        }
    }

    public function test_a4_02_provider_language_uses_the_first_known_language_in_order(): void {
        // The order's own language decides; a request locale never overrides it.
        self::assertSame('en', CheckoutPayload::provider_language_code(array('en', 'ar', 'ar')));
        self::assertSame('ar', CheckoutPayload::provider_language_code(array('ar', 'en', 'en_US')));
        // Unknown order language: the WPML current language, then the locale.
        self::assertSame('ar', CheckoutPayload::provider_language_code(array('', 'ar', 'en_US')));
        self::assertSame('ar', CheckoutPayload::provider_language_code(array(null, null, 'ar')));
        self::assertSame('en', CheckoutPayload::provider_language_code(array(null, array('ar'), false)));
        self::assertSame('en', CheckoutPayload::provider_language_code(array()));
    }

    public function test_a4_02_provider_language_reads_the_order_wpml_language(): void {
        $arabic_order = new class() {
            public function get_meta($key) { return $key === 'wpml_language' ? 'ar' : ''; }
        };
        $plain_order = new class() {
            public function get_meta($key) { return ''; }
        };

        self::assertSame('ar', CheckoutPayload::provider_language($arabic_order));
        self::assertSame('en', CheckoutPayload::provider_language($plain_order));
        self::assertSame('en', CheckoutPayload::provider_language(null));
    }

    public function test_a1_10_provider_text_keeps_accented_latin_and_nbsp_text(): void {
        foreach (array(
            'Café Crème Zoë',
            "Café 10\u{00A0}cm",
            'Size 10 cm ©',
            'Ærøskøbing Straße ½',
            'سلام Café',
        ) as $text) {
            self::assertSame($text, CheckoutPayload::truncate_provider_text($text, 255), $text);
        }
        self::assertSame('Café', CheckoutPayload::truncate_provider_text('Café Crème', 4));
    }

    public function test_a1_10_provider_text_drops_only_invalid_utf8_bytes(): void {
        // A sequence cut off before its continuation bytes is removed; the rest stays.
        self::assertSame('a', CheckoutPayload::truncate_provider_text("a\xC3", 255));
        self::assertSame('ab', CheckoutPayload::truncate_provider_text("a\xE2\x82b", 255));
        self::assertSame('Café', CheckoutPayload::truncate_provider_text("Café\xF0\x9F\x98", 255));
        // Stray continuation bytes, bytes that never occur in UTF-8, overlong forms and
        // UTF-16 surrogates are removed too, so the output is always valid UTF-8.
        self::assertSame('Café', CheckoutPayload::truncate_provider_text("Caf\xC3\xA9\x80", 255));
        self::assertSame('ab', CheckoutPayload::truncate_provider_text("a\xFF\xFEb", 255));
        self::assertSame('ab', CheckoutPayload::truncate_provider_text("a\xC0\xAFb", 255));
        self::assertSame('ab', CheckoutPayload::truncate_provider_text("a\xED\xA0\x80b", 255));
        self::assertSame('', CheckoutPayload::truncate_provider_text("\xFF", 255));
        self::assertSame('😀', CheckoutPayload::truncate_provider_text("\xF0\x9F\x98\x80", 255));
    }

    public function test_provider_text_truncation_is_scalar_only_and_utf8_safe(): void {
        self::assertSame('abc', CheckoutPayload::truncate_provider_text('abcdef', 3));
        self::assertSame('سلا', CheckoutPayload::truncate_provider_text('سلام', 3));
        self::assertSame('123', CheckoutPayload::truncate_provider_text(123, 5));
        self::assertSame('', CheckoutPayload::truncate_provider_text(array('text'), 5));
        self::assertSame('', CheckoutPayload::truncate_provider_text('', 5));
    }
}
