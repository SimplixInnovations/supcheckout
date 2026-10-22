<?php

namespace Simplixi\SUPCheckout\Provider;

defined('ABSPATH') || exit;

/**
 * Pure resolver for the legacy UPayments API mode and endpoint contract.
 *
 * The live base deliberately preserves the plugin's existing runtime value.
 * Changing provider hosts is a separate migration and is not part of A1.
 */
final class EndpointResolver {
    public const LIVE_BASE = 'https://apiv2api.upayments.com/api/v1/';
    public const SANDBOX_BASE = 'https://sandboxapi.upayments.com/api/v1/';

    public const CREATE_CUSTOMER_TOKEN = 'create-customer-unique-token';
    public const CHECK_PAYMENT_BUTTON_STATUS = 'check-payment-button-status';
    public const RETRIEVE_CUSTOMER_CARDS = 'retrieve-customer-cards';

    private $base;

    /**
     * @param mixed $test_mode Truthy selects the sandbox base; falsey selects live.
     */
    public function __construct($test_mode) {
        $this->base = $test_mode ? self::SANDBOX_BASE : self::LIVE_BASE;
    }

    /**
     * Preserve legacy route concatenation exactly; no trimming or normalization.
     *
     * @param mixed $route Route text appended to the selected API base.
     * @return string
     */
    public function resolve($route = '') {
        return $this->base . $route;
    }

    public function create_customer_token() {
        return $this->resolve(self::CREATE_CUSTOMER_TOKEN);
    }

    public function check_payment_button_status() {
        return $this->resolve(self::CHECK_PAYMENT_BUTTON_STATUS);
    }

    public function retrieve_customer_cards() {
        return $this->resolve(self::RETRIEVE_CUSTOMER_CARDS);
    }
}
