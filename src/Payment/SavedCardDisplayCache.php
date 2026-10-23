<?php

namespace Simplixi\SUPCheckout\Payment;

defined('ABSPATH') || exit;

/**
 * Two-minute display cache for the saved-card list shown at checkout (A3-01).
 *
 * Every Classic order-review refresh and every Blocks checkout load used to ask
 * UPayments for the shopper's saved cards. The list is now kept for two minutes in
 * the shopper's WooCommerce session, which WooCommerce destroys at logout.
 *
 * Only display-safe fields are kept: the opaque selection handle the page already
 * carries (SavedCardSelection), the last four digits and the brand. Never a provider
 * card token, a card number or the customer token. A stale handle cannot pay: the
 * Charge path resolves it against a fresh, uncached Retrieve and fails closed when
 * the card is gone.
 *
 * An entry is bound to the user, credential, mode, token-identity scope and
 * generation, and customer token, so any change misses. A save-card Charge forgets
 * it (CheckoutOrchestrator through Gateway::forget_saved_card_views()). The card is
 * only added later, on the hosted payment page, so a render in the next 120 s can
 * cache the old list again: a new card may take up to two minutes to show. The gateway
 * hands in the session; without one nothing is cached. This class reads no request
 * globals itself.
 */
final class SavedCardDisplayCache {
    public const SESSION_KEY = 'supcheckout_saved_card_views_v1';
    public const MAX_AGE = 120;

    private const KEY_DOMAIN = 'supcheckout_saved_card_views_key_v1';

    /** @var object WooCommerce session: get($key, $default) and set($key, $value). */
    private $session;

    /**
     * @param object $session WooCommerce session (WC_Session or a compatible object).
     */
    public function __construct($session) {
        $this->session = $session;
    }

    /**
     * Cache key for one shopper's identity, or null when any part is missing.
     *
     * @param mixed $user_id WordPress user ID.
     * @param mixed $api_key UPayments API key.
     * @param mixed $is_test_mode Provider mode.
     * @param mixed $scope Token-identity scope.
     * @param mixed $generation_id Token-identity secret generation.
     * @param mixed $customer_token Provider customer token from valid provenance.
     * @return string|null
     */
    public static function key($user_id, $api_key, $is_test_mode, $scope, $generation_id, $customer_token) {
        if (!is_int($user_id) || $user_id <= 0 || !is_bool($is_test_mode)) {
            return null;
        }
        foreach (array($api_key, $scope, $generation_id, $customer_token) as $part) {
            if (!is_string($part) || $part === '') {
                return null;
            }
        }
        $salt = wp_salt('auth');
        if (!is_string($salt) || $salt === '') {
            return null;
        }
        $context = implode("\n", array(
            self::KEY_DOMAIN,
            (string) $user_id,
            $is_test_mode ? 'test' : 'live',
            $api_key,
            $scope,
            $generation_id,
            $customer_token,
        ));
        return hash_hmac('sha256', $context, $salt);
    }

    /**
     * Display-safe views of a provider card list. Cards without a usable token or
     * handle are dropped, exactly as the Classic and Blocks renders drop them.
     *
     * @param array<mixed> $cards Provider saved cards.
     * @param mixed        $user_id WordPress user ID.
     * @param mixed        $api_key UPayments API key.
     * @param mixed        $is_test_mode Provider mode.
     * @return list<array{selection: string, last4: string, brand: string}>
     */
    public static function views(array $cards, $user_id, $api_key, $is_test_mode) {
        $views = array();
        foreach ($cards as $card) {
            if (!is_array($card)) {
                continue;
            }
            $token = SavedCardPresentation::token($card);
            if ($token === null) {
                continue;
            }
            $selection = SavedCardSelection::create($token, $user_id, $api_key, $is_test_mode);
            if ($selection === null) {
                continue;
            }
            $views[] = array(
                'selection' => $selection,
                'last4'     => SavedCardPresentation::last_four($card),
                'brand'     => SavedCardPresentation::safe_brand($card),
            );
        }
        return $views;
    }

    /**
     * The cached views for $key, or null on a miss, an expired or foreign entry, or
     * any entry that is not exactly display-safe.
     *
     * @param string $key Cache key from key().
     * @return list<array{selection: string, last4: string, brand: string}>|null
     */
    public function get($key) {
        $entry = $this->session->get(self::SESSION_KEY, null);
        if (!is_array($entry)
            || !isset($entry['key'], $entry['expires_at'], $entry['views'])
            || !is_string($entry['key'])
            || !hash_equals($entry['key'], (string) $key)
            || !is_int($entry['expires_at'])
            || $entry['expires_at'] <= time()
            || $entry['expires_at'] > time() + self::MAX_AGE
            || !is_array($entry['views'])
        ) {
            return null;
        }
        $views = array();
        foreach ($entry['views'] as $view) {
            if (!self::is_view($view)) {
                return null;
            }
            $views[] = $view;
        }
        return $views;
    }

    /**
     * @param string                                                      $key Cache key from key().
     * @param list<array{selection: string, last4: string, brand: string}> $views From views().
     * @return void
     */
    public function put($key, array $views) {
        $this->session->set(self::SESSION_KEY, array(
            'key'        => (string) $key,
            'expires_at' => time() + self::MAX_AGE,
            'views'      => array_values($views),
        ));
    }

    /** @return void */
    public function forget() {
        $this->session->set(self::SESSION_KEY, null);
    }

    /**
     * @param mixed $view Candidate cached view.
     * @return bool
     */
    private static function is_view($view) {
        if (!is_array($view) || count($view) !== 3
            || !isset($view['selection'], $view['last4'], $view['brand'])
            || !SavedCardSelection::is_handle($view['selection'])
            || !is_string($view['last4'])
            || !is_string($view['brand'])
        ) {
            return false;
        }
        if ($view['last4'] !== '' && preg_match('/^[0-9]{4}$/D', $view['last4']) !== 1) {
            return false;
        }
        return $view['brand'] === SavedCardPresentation::safe_brand(array('brand' => $view['brand']));
    }
}
