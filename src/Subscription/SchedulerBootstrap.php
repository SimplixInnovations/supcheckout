<?php

namespace Simplixi\SUPCheckout\Subscription;

defined('ABSPATH') || exit;

use Simplixi\SUPCheckout\Plugin;
use UPayments\Subscription\Cron\Scheduler;

/**
 * Loads and initializes the subscription scheduler once WooCommerce is ready.
 */
final class SchedulerBootstrap {
    /** @return void */
    public static function register() {
        add_action('woocommerce_init', array(self::class, 'init_scheduler'));
    }

    /** @return void */
    public static function init_scheduler() {
        require_once dirname(Plugin::main_file()) . '/includes/Subscription/Cron/Scheduler.php';
        Scheduler::init();
    }

    private function __construct() {}
}
