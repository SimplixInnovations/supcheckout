<?php
/**
 * HTTP request-context probe used only by Compatibility Certification.
 *
 * This file is copied into wp-content/mu-plugins by CI. It deliberately
 * exercises WooCommerce's public payment-gateway registry from real checkout,
 * wc-ajax, admin-ajax and REST/Store API requests without adding production
 * hooks to the shipped plugin.
 */

defined('ABSPATH') || exit;

/**
 * Return the observable gateway/context contract for the current HTTP request.
 *
 * @param bool $force_sessionless Deliberately remove the WooCommerce session
 *                                for this evaluation and restore it afterwards.
 * @return array<string, mixed>
 */
function supcheckout_cert_http_context_payload($force_sessionless = false) {
    $wc = function_exists('WC') ? WC() : null;
    $original_session = null;
    $can_restore_session = false;

    if ($wc && property_exists($wc, 'session')) {
        $original_session = $wc->session;
        $can_restore_session = true;
        if ($force_sessionless) {
            $wc->session = null;
        }
    }

    try {
        $available = array();
        if ($wc && $wc->payment_gateways()) {
            $available = $wc->payment_gateways()->get_available_payment_gateways();
        }

        return array(
            'gateway_present' => isset($available['upayments']),
            'cod_present'     => isset($available['cod']),
            'context'         => array(
                'is_checkout'     => function_exists('is_checkout') && is_checkout(),
                'is_admin'        => is_admin(),
                'doing_ajax'      => wp_doing_ajax(),
                'wc_doing_ajax'   => defined('WC_DOING_AJAX') && WC_DOING_AJAX,
                'rest_request'    => defined('REST_REQUEST') && REST_REQUEST,
                'session_present' => (bool) ($wc && $wc->session),
            ),
        );
    } finally {
        if ($force_sessionless && $wc && $can_restore_session) {
            $wc->session = $original_session;
        }
    }
}

/**
 * Emit a JSON probe response and terminate the request.
 *
 * @return void
 */
function supcheckout_cert_http_context_send_json() {
    $force_sessionless = isset($_REQUEST['sessionless']) && '1' === (string) $_REQUEST['sessionless']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only CI fixture.
    wp_send_json(supcheckout_cert_http_context_payload($force_sessionless));
}

add_action('wp_ajax_supcheckout_context_probe', 'supcheckout_cert_http_context_send_json');
add_action('wp_ajax_nopriv_supcheckout_context_probe', 'supcheckout_cert_http_context_send_json');
add_action('wc_ajax_supcheckout_context_probe', 'supcheckout_cert_http_context_send_json');

add_action(
    'rest_api_init',
    static function () {
        register_rest_route(
            'supcheckout-cert/v1',
            '/context',
            array(
                'methods'             => 'GET',
                'callback'            => static function ($request) {
                    $force_sessionless = '1' === (string) $request->get_param('sessionless');
                    return rest_ensure_response(supcheckout_cert_http_context_payload($force_sessionless));
                },
                'permission_callback' => '__return_true',
            )
        );
    }
);

add_filter(
    'rest_post_dispatch',
    static function ($response, $server, $request) {
        unset($server);
        if ('/wc/store/v1/cart' !== $request->get_route()
            || '1' !== (string) $request->get_header('x-supcheckout-cert')
            || is_wp_error($response)
        ) {
            return $response;
        }

        $response = rest_ensure_response($response);
        $data = $response->get_data();
        if (!is_array($data)) {
            return $response;
        }

        $data['_supcheckout_cert'] = supcheckout_cert_http_context_payload(false);
        $response->set_data($data);
        return $response;
    },
    PHP_INT_MAX,
    3
);

add_action(
    'template_redirect',
    static function () {
        // At template_redirect the main query is resolved, so is_checkout() is
        // authoritative. Run before WooCommerce's empty-cart redirect so this
        // certification can observe a real checkout request without inventing
        // cart state merely to keep the page render alive.
        if (!isset($_GET['supcheckout_context_probe']) || '1' !== (string) $_GET['supcheckout_context_probe']) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return;
        }

        supcheckout_cert_http_context_send_json();
    },
    -999
);