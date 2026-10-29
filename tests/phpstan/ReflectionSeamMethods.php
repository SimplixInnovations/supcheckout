<?php
/**
 * PHPStan extension (analysis only; A6-06, hardening batch 6).
 *
 * The private methods listed here are compatibility and test seams that the
 * permanent harnesses call through ReflectionMethod, so PHPStan's unused-method
 * rule cannot see their callers. Only these exact methods are treated as used;
 * every other private method is still checked. The static-analysis harness
 * fails if a listed method stops existing, stops being private, or is no longer
 * invoked through reflection by a file under tests/.
 */

namespace Simplixi\SUPCheckout\Tests\PHPStan;

use PHPStan\Reflection\ExtendedMethodReflection;
use PHPStan\Rules\Methods\AlwaysUsedMethodExtension;

final class ReflectionSeamMethods implements AlwaysUsedMethodExtension
{
    /**
     * Class => private methods reached only through reflection.
     *
     * @var array<string, list<string>>
     */
    const SEAMS = array(
        // Checkout payload compatibility delegates, exercised by
        // tests/harness/phase-9g-h12-php-harness.php and
        // tests/harness/architecture-checkout-orchestration-harness.php.
        'Simplixi\\SUPCheckout\\Gateway\\Gateway' => array(
            'field_present',
            'parse_save_card_strict',
            'parse_payment_source_strict',
            'build_amount_json_token',
            'inject_amount_token_into_payload_json',
            'get_max_length_for_sentinel',
            'is_valid_subscription_plan',
            'parse_subscription_plan_strict',
            'parse_interval',
            'normalize_upayments_redirect_url',
            'normalize_store_api_route',
            'digit_long_divide',
            'digit_long_divide_remainder',
            'is_store_api_checkout_request',
            'is_valid_cached_availability',
        ),
        // H12 rollback-state test seams, exercised by
        // tests/harness/phase-9g-h12-php-harness.php.
        'Simplixi\\SUPCheckout\\Token\\CustomerTokenIdentity' => array(
            'last_rollback_state',
            'reset_rollback_state_for_tests',
        ),
    );

    public function isAlwaysUsed(ExtendedMethodReflection $methodReflection): bool
    {
        $class = $methodReflection->getDeclaringClass()->getName();

        return isset(self::SEAMS[$class])
            && in_array($methodReflection->getName(), self::SEAMS[$class], true);
    }
}
