<?php

namespace Simplixi\SUPCheckout\Tests\Payment;

use PHPUnit\Framework\TestCase;
use Simplixi\SUPCheckout\Payment\SavedCardDisplayCache;
use Simplixi\SUPCheckout\Payment\SavedCardSelection;

/**
 * U5 (A3-01): the saved-card list shown at checkout is cached for two minutes in the
 * shopper's WooCommerce session, so order-review refreshes stop calling UPayments.
 * The cache holds display-safe fields only: the opaque selection handle, the last
 * four digits and the brand. It never holds a provider card token or a card number.
 */
final class SavedCardDisplayCacheTest extends TestCase {
    private const CARD_TOKEN_A = 'provider-card-token-AAAA';
    private const CARD_TOKEN_B = 'provider-card-token-BBBB';
    private const CUSTOMER_TOKEN = '12345678';

    protected function setUp(): void {
        parent::setUp();
        supcheckout_test_reset_wp_options();
        $GLOBALS['supcheckout_test_wp_salt'] = 'saved-card-display-cache-test-salt';
    }

    public function test_views_keep_only_the_handle_last_four_and_brand(): void {
        $views = SavedCardDisplayCache::views($this->provider_cards(), 42, 'merchant-key', false);

        self::assertSame(
            array(
                array(
                    'selection' => SavedCardSelection::create(self::CARD_TOKEN_A, 42, 'merchant-key', false),
                    'last4'     => '4242',
                    'brand'     => 'Visa',
                ),
                array(
                    'selection' => SavedCardSelection::create(self::CARD_TOKEN_B, 42, 'merchant-key', false),
                    'last4'     => '',
                    'brand'     => '',
                ),
            ),
            $views
        );
        $serialized = serialize($views);
        self::assertStringNotContainsString(self::CARD_TOKEN_A, $serialized);
        self::assertStringNotContainsString(self::CARD_TOKEN_B, $serialized);
        self::assertStringNotContainsString('4111', $serialized);
    }

    public function test_a_put_view_list_is_served_from_the_session_without_any_token(): void {
        $session = $this->session();
        $cache = new SavedCardDisplayCache($session);
        $key = $this->key();
        $views = SavedCardDisplayCache::views($this->provider_cards(), 42, 'merchant-key', false);

        self::assertNull($cache->get($key));
        $cache->put($key, $views);

        self::assertSame($views, $cache->get($key));
        self::assertSame($views, (new SavedCardDisplayCache($session))->get($key), 'a later request reads the same session');
        $stored = serialize($session->data);
        self::assertStringNotContainsString(self::CARD_TOKEN_A, $stored);
        self::assertStringNotContainsString(self::CUSTOMER_TOKEN, $stored);
        self::assertStringNotContainsString('merchant-key', $stored);
    }

    public function test_an_empty_card_list_is_cached_too(): void {
        $cache = new SavedCardDisplayCache($this->session());
        $cache->put($this->key(), array());

        self::assertSame(array(), $cache->get($this->key()));
    }

    public function test_entries_expire_after_two_minutes(): void {
        $session = $this->session();
        $cache = new SavedCardDisplayCache($session);
        $cache->put($this->key(), SavedCardDisplayCache::views($this->provider_cards(), 42, 'merchant-key', false));

        $session->data[SavedCardDisplayCache::SESSION_KEY]['expires_at'] = time() - 1;
        self::assertNull($cache->get($this->key()));

        // An expiry further out than the two-minute life is not trusted either.
        $session->data[SavedCardDisplayCache::SESSION_KEY]['expires_at'] = time() + SavedCardDisplayCache::MAX_AGE + 5;
        self::assertNull($cache->get($this->key()));
        self::assertSame(120, SavedCardDisplayCache::MAX_AGE);
    }

    public function test_an_entry_is_bound_to_user_credential_mode_identity_generation_and_customer_token(): void {
        $cache = new SavedCardDisplayCache($this->session());
        $cache->put($this->key(), SavedCardDisplayCache::views($this->provider_cards(), 42, 'merchant-key', false));

        $others = array(
            SavedCardDisplayCache::key(43, 'merchant-key', false, 'scope-1', 'generation-1', self::CUSTOMER_TOKEN),
            SavedCardDisplayCache::key(42, 'other-key', false, 'scope-1', 'generation-1', self::CUSTOMER_TOKEN),
            SavedCardDisplayCache::key(42, 'merchant-key', true, 'scope-1', 'generation-1', self::CUSTOMER_TOKEN),
            SavedCardDisplayCache::key(42, 'merchant-key', false, 'scope-2', 'generation-1', self::CUSTOMER_TOKEN),
            SavedCardDisplayCache::key(42, 'merchant-key', false, 'scope-1', 'generation-2', self::CUSTOMER_TOKEN),
            SavedCardDisplayCache::key(42, 'merchant-key', false, 'scope-1', 'generation-1', '87654321'),
        );
        foreach ($others as $index => $other) {
            self::assertIsString($other);
            self::assertNotSame($this->key(), $other, (string) $index);
            self::assertNull($cache->get($other), (string) $index);
        }
        self::assertStringNotContainsString(self::CUSTOMER_TOKEN, $this->key());
        self::assertNull(SavedCardDisplayCache::key(0, 'merchant-key', false, 'scope-1', 'generation-1', self::CUSTOMER_TOKEN));
        self::assertNull(SavedCardDisplayCache::key(42, '', false, 'scope-1', 'generation-1', self::CUSTOMER_TOKEN));
        self::assertNull(SavedCardDisplayCache::key(42, 'merchant-key', false, '', 'generation-1', self::CUSTOMER_TOKEN));
    }

    public function test_forget_drops_the_entry(): void {
        $session = $this->session();
        $cache = new SavedCardDisplayCache($session);
        $cache->put($this->key(), array());
        $cache->forget();

        self::assertNull($cache->get($this->key()));
        self::assertNull($session->data[SavedCardDisplayCache::SESSION_KEY]);
    }

    public function test_tampered_or_token_bearing_entries_are_never_served(): void {
        $session = $this->session();
        $cache = new SavedCardDisplayCache($session);
        $views = SavedCardDisplayCache::views($this->provider_cards(), 42, 'merchant-key', false);
        $cache->put($this->key(), $views);
        $good = $session->data[SavedCardDisplayCache::SESSION_KEY];

        $mutations = array(
            'not an array' => 'cached',
            'raw token as selection' => array('selection' => self::CARD_TOKEN_A, 'last4' => '4242', 'brand' => 'Visa'),
            'extra token field' => array('selection' => $views[0]['selection'], 'last4' => '4242', 'brand' => 'Visa', 'token' => self::CARD_TOKEN_A),
            'full number as last4' => array('selection' => $views[0]['selection'], 'last4' => '4111111111114242', 'brand' => 'Visa'),
            'digits in brand' => array('selection' => $views[0]['selection'], 'last4' => '4242', 'brand' => 'Visa 4111'),
        );
        foreach ($mutations as $label => $view) {
            $entry = $good;
            $entry['views'] = array($view);
            $session->data[SavedCardDisplayCache::SESSION_KEY] = $entry;
            self::assertNull($cache->get($this->key()), $label);
        }
        $session->data[SavedCardDisplayCache::SESSION_KEY] = 'garbage';
        self::assertNull($cache->get($this->key()));
    }

    private function key(): string {
        $key = SavedCardDisplayCache::key(42, 'merchant-key', false, 'scope-1', 'generation-1', self::CUSTOMER_TOKEN);
        self::assertIsString($key);
        return $key;
    }

    /** @return list<mixed> */
    private function provider_cards(): array {
        return array(
            array('token' => self::CARD_TOKEN_A, 'number' => '4111 1111 1111 4242', 'brand' => 'Visa'),
            array('token' => self::CARD_TOKEN_B, 'brand' => '<script>'),
            array('token' => ' spaced token ', 'number' => '****3456'),
            array('number' => '****3456'),
            'not a card',
        );
    }

    private function session(): object {
        return new class {
            /** @var array<string, mixed> */
            public $data = array();

            /** @return mixed */
            public function get($key, $default = null) {
                return array_key_exists($key, $this->data) ? $this->data[$key] : $default;
            }

            /** @param mixed $value */
            public function set($key, $value): void {
                $this->data[$key] = $value;
            }
        };
    }
}
