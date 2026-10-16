<?php

namespace Simplixi\SUPCheckout;

defined('ABSPATH') || exit;

use Simplixi\SUPCheckout\Account\SubscriptionActions;
use Simplixi\SUPCheckout\Blocks\PaymentMethodRegistration;
use Simplixi\SUPCheckout\Bootstrap\Requirements;
use Simplixi\SUPCheckout\Bootstrap\WooCommerceDeclarations;
use Simplixi\SUPCheckout\Gateway\GatewayRegistration;
use Simplixi\SUPCheckout\Subscription\Composition as SubscriptionComposition;
use Simplixi\SUPCheckout\Subscription\SchedulerBootstrap;

/**
 * Composition root. From Task 3 on, every WordPress hook SUPCheckout registers is
 * added from here (or from a class this method calls), never from the main file.
 */
final class Plugin {
    /** @var string|null */
    private static $main_file = null;

    /**
     * Registers in the order the main file used to, so the callbacks on each hook
     * keep their execution order.
     *
     * @param string $main_file Absolute path of supcheckout.php.
     * @return void
     */
    public static function boot($main_file) {
        if (self::$main_file !== null) {
            return;
        }
        self::$main_file = (string) $main_file;
        GatewayRegistration::register();
        Requirements::register();
        WooCommerceDeclarations::register();
        PaymentMethodRegistration::register();
        // Also registers Subscription\ProductTypeHooks, at their place in the topology.
        SubscriptionComposition::register_presentation_hooks();
        SchedulerBootstrap::register();
        SubscriptionActions::register();
    }

    /** @return string */
    public static function main_file() {
        return (string) self::$main_file;
    }

    private function __construct() {}
}
