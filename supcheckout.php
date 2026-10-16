<?php
/**
 * Plugin Name: SUPCheckout for UPayments
 * Plugin URI: https://github.com/SimplixInnovations/supcheckout
 * Description: Independently engineered UPayments payment integration for WooCommerce by Simplix Innovations.
 * Version: 0.1.0
 * Author: Simplix Innovations
 * Author URI: https://simplixi.com
 * Requires at least: 6.9
 * Tested up to: 7.1
 * Requires PHP: 7.4
 * WC requires at least: 10.8
 * WC tested up to: 11.1
 * License: MIT
 * Text Domain: supcheckout
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
require_once __DIR__ . '/src/Autoloader.php';
\Simplixi\SUPCheckout\Autoloader::register(__DIR__ . '/src');

use Simplixi\SUPCheckout\Release\Identity;

require_once __DIR__ . '/src/Gateway/OfficialPluginConflict.php';

// While the official UPayments plugin is active, declare nothing global: both plugins
// share the protected legacy function and class names, so the second to load would
// die with "Cannot redeclare". Top-level functions are bound at compile time, so the
// declarations below must sit inside this condition rather than after a return.
if ( ! \Simplixi\SUPCheckout\Gateway\OfficialPluginConflict::pause_if_active( __FILE__ ) ) :

\Simplixi\SUPCheckout\Plugin::boot(__FILE__);

define("UP_PLUGIN_URL", plugin_dir_url(__FILE__));
define("UP_PLUGIN_PATH", plugin_dir_path(__FILE__));
define('UPAYMENTS_PLUGIN_FILE', __FILE__ );

require_once __DIR__ . '/src/Release/Identity.php';
require_once __DIR__ . '/src/Provider/MultiMerchantContract.php';
require_once __DIR__ . '/src/Admin/GatewaySettings.php';
require_once __DIR__ . '/src/Gateway/Availability.php';
require_once __DIR__ . '/src/Gateway/OrderPresentation.php';
require_once __DIR__ . '/src/Provider/EndpointResolver.php';
require_once __DIR__ . '/src/Provider/PaymentMethodAvailability.php';
require_once __DIR__ . '/src/Payment/CheckoutPayload.php';
require_once __DIR__ . '/src/Payment/CheckoutOrchestrator.php';
require_once __DIR__ . '/src/Payment/SavedCardSelection.php';
require_once __DIR__ . '/src/Payment/SavedCardPresentation.php';
require_once __DIR__ . '/src/Subscription/Presentation.php';
require_once __DIR__ . '/src/Subscription/Composition.php';
require_once __DIR__ . '/src/Subscription/RenewalCardAuthority.php';
require_once __DIR__ . '/src/Subscription/CycleEconomics.php';
require_once __DIR__ . '/src/Subscription/AutoDeductResultVerifier.php';
require_once __DIR__ . '/includes/Token/CustomerTokenIdentity.php';
require_once __DIR__ . '/src/Migration/MigrationBootstrap.php';


define('SUPCHECKOUT_VERSION', Identity::VERSION);
define('SUPCHECKOUT_SLUG', Identity::SLUG);
define('SUPCHECKOUT_PLUGIN_FILE', __FILE__);
define('SUPCHECKOUT_UPDATE_CHANNEL', Identity::UPDATE_CHANNEL);

endif;
