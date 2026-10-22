<?php

namespace Simplixi\SUPCheckout;

defined('ABSPATH') || exit;

use Simplixi\SUPCheckout\Account\SubscriptionActions;
use Simplixi\SUPCheckout\Blocks\PaymentMethodRegistration;
use Simplixi\SUPCheckout\Bootstrap\Requirements;
use Simplixi\SUPCheckout\Bootstrap\WooCommerceDeclarations;
use Simplixi\SUPCheckout\Gateway\GatewayRegistration;
use Simplixi\SUPCheckout\Release\Identity;
use Simplixi\SUPCheckout\Rest\Routes as RestRoutes;
use Simplixi\SUPCheckout\Subscription\Composition as SubscriptionComposition;
use Simplixi\SUPCheckout\Subscription\SchedulerBootstrap;
use Simplixi\SUPCheckout\Takeover\Cli as TakeoverCli;
use Simplixi\SUPCheckout\Takeover\CopyRunner;
use Simplixi\SUPCheckout\Takeover\Onboarding\AsyncDriver;
use Simplixi\SUPCheckout\Takeover\ReactivationGuard;

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
        CopyRunner::register();
        AsyncDriver::register();
        RestRoutes::register();
        TakeoverCli::register();
        ReactivationGuard::register(self::$main_file);
        self::load_runtime(self::$main_file);
    }

    /**
     * Was the tail of supcheckout.php, moved verbatim and in its order (Sub-project 0a
     * Task 5). It still runs while the main file loads, right after the registrations
     * above, so every constant exists, every file loads and every load-time hook
     * (PaymentLifecycle::bootstrap() from Release/Identity.php,
     * CheckoutAssets::bootstrap() from Gateway/Availability.php and
     * MigrationBootstrap::boot()) is added exactly as before. The requires stay
     * explicit rather than autoloaded so load order and timing do not change.
     * Token\CustomerTokenIdentity (formerly includes/, required here) is autoloaded
     * since Task 6: it is a side-effect-free class declaration.
     *
     * The asset-path constants are SUPCheckout-owned since 0b Task 2. The official plugin
     * defines UP_PLUGIN_URL, UP_PLUGIN_PATH and UPAYMENTS_PLUGIN_FILE itself, so SUPCheckout
     * never defines them (SUPCHECKOUT_PLUGIN_FILE replaces the last): two runtimes in one
     * request no longer collide or swap asset URLs.
     *
     * @param string $main_file Absolute path of supcheckout.php.
     * @return void
     */
    private static function load_runtime($main_file) {
        define('SUPCHECKOUT_PLUGIN_URL', plugin_dir_url($main_file));
        define('SUPCHECKOUT_PLUGIN_PATH', plugin_dir_path($main_file));

        require_once __DIR__ . '/Release/Identity.php';
        require_once __DIR__ . '/Provider/MultiMerchantContract.php';
        require_once __DIR__ . '/Admin/GatewaySettings.php';
        require_once __DIR__ . '/Gateway/Availability.php';
        require_once __DIR__ . '/Gateway/OrderPresentation.php';
        require_once __DIR__ . '/Provider/EndpointResolver.php';
        require_once __DIR__ . '/Provider/PaymentMethodAvailability.php';
        require_once __DIR__ . '/Payment/CheckoutPayload.php';
        require_once __DIR__ . '/Payment/CheckoutOrchestrator.php';
        require_once __DIR__ . '/Payment/SavedCardSelection.php';
        require_once __DIR__ . '/Payment/SavedCardPresentation.php';
        require_once __DIR__ . '/Subscription/Presentation.php';
        require_once __DIR__ . '/Subscription/Composition.php';
        require_once __DIR__ . '/Subscription/RenewalCardAuthority.php';
        require_once __DIR__ . '/Subscription/CycleEconomics.php';
        require_once __DIR__ . '/Subscription/AutoDeductResultVerifier.php';
        require_once __DIR__ . '/Migration/MigrationBootstrap.php';

        define('SUPCHECKOUT_VERSION', Identity::VERSION);
        define('SUPCHECKOUT_SLUG', Identity::SLUG);
        define('SUPCHECKOUT_PLUGIN_FILE', $main_file);
        define('SUPCHECKOUT_UPDATE_CHANNEL', Identity::UPDATE_CHANNEL);
    }

    /** @return string */
    public static function main_file() {
        return (string) self::$main_file;
    }

    private function __construct() {}
}
