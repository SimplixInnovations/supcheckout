<?php
/**
 * Plugin Name: SUPCheckout Staging Guard
 * Description: Neutralizes real-world side effects on a staging copy of a production store. Active only on a staging environment with SUPCHECKOUT_STAGING_GUARD defined. Never install on production.
 * Version: 1.0.0
 *
 * Install on the STAGING copy only, as wp-content/mu-plugins/supcheckout-staging-guard.php,
 * after setting in that copy's wp-config.php:
 *
 *   define( 'WP_ENVIRONMENT_TYPE', 'staging' );
 *   define( 'SUPCHECKOUT_STAGING_GUARD', true );
 *
 * While active it:
 *   - blocks every outgoing email;
 *   - stops plugins that reach customers or outside services (ZeptoMail, FunnelKit
 *     Automations, Google for WooCommerce, Modular Connector, LiteSpeed Cache), plus any
 *     listed in SUPCHECKOUT_STAGING_DISABLE_PLUGINS;
 *   - blocks outbound HTTP except to this site, WordPress.org and the UPayments sandbox.
 *     Live UPayments (apiv2api.upayments.com) stays blocked unless
 *     SUPCHECKOUT_STAGING_ALLOW_LIVE_PROVIDER is true;
 *   - stops WooCommerce webhook delivery;
 *   - asks search engines not to index the site;
 *   - shows a banner, and warns when a persistent object cache is still connected.
 *
 * On any site that is not a staging environment with the constant defined it does nothing,
 * so an accidental copy to production cannot block that store's mail or HTTP.
 */

defined('ABSPATH') || exit;

const SUPCHECKOUT_STAGING_GUARD_DISABLED_PLUGINS = array(
    'zeptomail',
    'transmail', // Zoho ZeptoMail's plugin folder (its former product name)
    'wp-marketing-automations',
    'google-listings-and-ads',
    'modular-connector',
    'litespeed-cache',
);

const SUPCHECKOUT_STAGING_GUARD_ALWAYS_ALLOWED_HOSTS = array(
    'sandboxapi.upayments.com',
    'dev-apiv2api.upayments.com',
    'api.wordpress.org',
    'downloads.wordpress.org',
    'localhost',
    '127.0.0.1',
);

const SUPCHECKOUT_STAGING_GUARD_LIVE_PROVIDER_HOST = 'apiv2api.upayments.com';

/**
 * @return bool True only on a staging environment that opted in.
 */
function supcheckout_staging_guard_is_active() {
    return function_exists('wp_get_environment_type')
        && 'staging' === wp_get_environment_type()
        && defined('SUPCHECKOUT_STAGING_GUARD')
        && true === SUPCHECKOUT_STAGING_GUARD;
}

/**
 * @return bool
 */
function supcheckout_staging_guard_live_provider_allowed() {
    return defined('SUPCHECKOUT_STAGING_ALLOW_LIVE_PROVIDER') && true === SUPCHECKOUT_STAGING_ALLOW_LIVE_PROVIDER;
}

/**
 * @param string $host       Destination host.
 * @param string $site_host  This site's host.
 * @param bool   $allow_live Whether live UPayments traffic is explicitly allowed.
 * @return bool
 */
function supcheckout_staging_guard_host_allowed($host, $site_host, $allow_live) {
    $host = strtolower(rtrim((string) $host, '.'));
    if ('' === $host) {
        return false;
    }
    if ('' !== $site_host && strtolower((string) $site_host) === $host) {
        return true;
    }
    if (in_array($host, SUPCHECKOUT_STAGING_GUARD_ALWAYS_ALLOWED_HOSTS, true)) {
        return true;
    }
    return $allow_live && SUPCHECKOUT_STAGING_GUARD_LIVE_PROVIDER_HOST === $host;
}

/**
 * @return string[] Lower-case plugin directory fragments that must not run on staging.
 */
function supcheckout_staging_guard_disabled_fragments() {
    $fragments = SUPCHECKOUT_STAGING_GUARD_DISABLED_PLUGINS;
    if (defined('SUPCHECKOUT_STAGING_DISABLE_PLUGINS') && is_string(SUPCHECKOUT_STAGING_DISABLE_PLUGINS)) {
        foreach (explode(',', SUPCHECKOUT_STAGING_DISABLE_PLUGINS) as $extra) {
            $extra = strtolower(trim($extra));
            if ('' !== $extra) {
                $fragments[] = $extra;
            }
        }
    }
    return $fragments;
}

/**
 * @param mixed $active Active plugin list as stored by WordPress.
 * @return mixed The list without plugins whose directory matches a disabled fragment.
 */
function supcheckout_staging_guard_filter_plugins($active) {
    if (!is_array($active)) {
        return $active;
    }
    $fragments = supcheckout_staging_guard_disabled_fragments();
    $kept = array();
    foreach ($active as $plugin) {
        $directory = strtolower(strtok((string) $plugin, '/'));
        $blocked = false;
        foreach ($fragments as $fragment) {
            if (false !== strpos($directory, $fragment)) {
                $blocked = true;
                break;
            }
        }
        if (!$blocked) {
            $kept[] = $plugin;
        }
    }
    return $kept;
}

/**
 * @param false|array|WP_Error $preempt Existing short-circuit value.
 * @param array                $args    Request arguments.
 * @param string               $url     Request URL.
 * @return false|array|WP_Error
 */
function supcheckout_staging_guard_pre_http_request($preempt, $args, $url) {
    if (false !== $preempt) {
        return $preempt;
    }
    $host = (string) wp_parse_url((string) $url, PHP_URL_HOST);
    $site_host = (string) wp_parse_url(home_url(), PHP_URL_HOST);
    if (supcheckout_staging_guard_host_allowed($host, $site_host, supcheckout_staging_guard_live_provider_allowed())) {
        return $preempt;
    }
    return new WP_Error('supcheckout_staging_blocked', 'Blocked by SUPCheckout Staging Guard: ' . $host);
}

function supcheckout_staging_guard_admin_banner() {
    $object_cache = function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache();
    $live = supcheckout_staging_guard_live_provider_allowed();
    echo '<div class="notice notice-' . ($object_cache || $live ? 'error' : 'warning') . '"><p><strong>SUPCheckout Staging Guard active.</strong> ';
    echo 'Email blocked; outbound HTTP limited to this site, WordPress.org and the UPayments sandbox; webhooks stopped. ';
    if ($live) {
        echo '<strong>Live UPayments traffic is ALLOWED (SUPCHECKOUT_STAGING_ALLOW_LIVE_PROVIDER). Remove it after the live test.</strong> ';
    }
    if ($object_cache) {
        echo '<strong>A persistent object cache is connected. Delete wp-content/object-cache.php on this copy: a cache shared with production can corrupt production.</strong>';
    }
    echo '</p></div>';
}

if (supcheckout_staging_guard_is_active()) {
    add_filter('option_active_plugins', 'supcheckout_staging_guard_filter_plugins', PHP_INT_MAX);
    add_filter('pre_wp_mail', '__return_false', PHP_INT_MAX);
    add_filter('pre_http_request', 'supcheckout_staging_guard_pre_http_request', PHP_INT_MAX, 3);
    add_filter('woocommerce_webhook_should_deliver', '__return_false', PHP_INT_MAX);
    add_filter('pre_option_blog_public', static function () {
        return '0';
    }, PHP_INT_MAX);
    add_action('admin_notices', 'supcheckout_staging_guard_admin_banner');
}
