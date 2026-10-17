<?php
namespace Simplixi\SUPCheckout\Tests\Account;

use PHPUnit\Framework\TestCase;
use Simplixi\SUPCheckout\Account\SubscriptionActions;

final class SubscriptionActionsTest extends TestCase {
    /** @var array<string, mixed> */
    private $server_backup = array();

    /** @var array<string, mixed> */
    private $post_backup = array();

    protected function setUp(): void {
        $this->server_backup = $_SERVER;
        $this->post_backup = $_POST;
        \supcheckout_test_reset_public_order_status();
        \supcheckout_test_reset_subscription_presentation();
        $GLOBALS['supcheckout_test_redirects'] = array();
    }

    protected function tearDown(): void {
        $_SERVER = $this->server_backup;
        $_POST = $this->post_backup;
        $GLOBALS['supcheckout_test_status_orders'] = array();
        $GLOBALS['supcheckout_test_redirects'] = array();
    }

    public function test_non_post_and_unrelated_requests_are_ignored_without_side_effects(): void {
        $order = $this->store_order(44);

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_POST = array('upay_action' => 'pause', 'order_id' => '44');
        SubscriptionActions::handle();
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = array('unrelated' => '1');
        SubscriptionActions::handle();

        $this->assert_ignored($order, array());
    }

    public function test_an_action_outside_the_allowlist_is_ignored_before_any_order_lookup(): void {
        $order = $this->store_order(44);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = array('upay_action' => 'delete', 'order_id' => '44', '_wpnonce' => 'n');
        SubscriptionActions::handle();

        $this->assert_ignored($order, array());
    }

    public function test_a_missing_or_zero_order_id_is_ignored_before_any_order_lookup(): void {
        $order = $this->store_order(44);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        foreach (array(array(), array('order_id' => '0'), array('order_id' => 'abc')) as $order_field) {
            $_POST = array('upay_action' => 'pause', '_wpnonce' => 'n') + $order_field;
            SubscriptionActions::handle();
        }

        $this->assert_ignored($order, array());
    }

    public function test_a_valid_action_for_an_order_that_does_not_exist_is_ignored(): void {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = array('upay_action' => 'pause', 'order_id' => '45', '_wpnonce' => 'n');
        SubscriptionActions::handle();

        $this->assert_ignored(null, array(45));
    }

    public function test_the_redirect_observation_is_live_for_a_foreign_order(): void {
        // Control for the cases above: a request that does reach a redirect is seen.
        $order = $this->store_order(44, 99);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = array('upay_action' => 'pause', 'order_id' => '44', '_wpnonce' => 'n');
        try {
            SubscriptionActions::handle();
            self::fail('a foreign order must be rejected with a redirect');
        } catch (\SUPCheckout_Test_Redirect $redirect) {
            self::assertSame('https://example.test/account/orders/', $redirect->location);
        }

        self::assertSame(array(array('Unauthorized request.', 'error')), $GLOBALS['supcheckout_test_subscription_presentation']['notices']);
        self::assertSame(array(), $order->writes);
    }

    /**
     * @param int $order_id
     * @param int $user_id
     * @return object
     */
    private function store_order($order_id, $user_id = 7) {
        $order = new class($user_id) {
            /** @var array<int, array<int, mixed>> */
            public $writes = array();
            /** @var int */
            private $user_id;
            public function __construct($user_id) { $this->user_id = (int) $user_id; }
            public function get_user_id() { return $this->user_id; }
            public function get_payment_method() { return 'upayments'; }
            public function get_meta($key) { return ''; }
            public function update_meta_data($key, $value) { $this->writes[] = array('update_meta_data', $key, $value); }
            public function save() { $this->writes[] = array('save'); }
        };
        $GLOBALS['supcheckout_test_status_orders'][(int) $order_id] = $order;
        return $order;
    }

    /**
     * @param object|null $order
     * @param array<int, int> $expected_lookups
     * @return void
     */
    private function assert_ignored($order, array $expected_lookups) {
        self::assertSame(array(), $GLOBALS['supcheckout_test_redirects'], 'no redirect (and so no exit)');
        self::assertSame(array(), $GLOBALS['supcheckout_test_subscription_presentation']['notices'], 'no customer notice');
        self::assertSame($expected_lookups, $GLOBALS['supcheckout_test_wc_get_order_calls'], 'order lookups');
        if ($order !== null) {
            self::assertSame(array(), $order->writes, 'no meta write or save');
        }
    }
}
