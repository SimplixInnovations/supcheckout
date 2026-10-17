<?php

namespace Simplixi\SUPCheckout\Tests\Takeover;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Simplixi\SUPCheckout\Gateway\OrderPresentation;
use Simplixi\SUPCheckout\Payment\OrderLock;
use Simplixi\SUPCheckout\Payment\PaymentLifecycle;
use Simplixi\SUPCheckout\Subscription\Presentation;
use Simplixi\SUPCheckout\Subscription\Scheduling\HistoricalEnrollment;
use Simplixi\SUPCheckout\Subscription\Scheduling\LifecycleScheduler;
use Simplixi\SUPCheckout\Takeover\Keys;
use Simplixi\SUPCheckout\Takeover\TakeoverState;

/**
 * 0b Task 2: every runtime read, query, hook check, write, delete and lock goes through
 * Keys, under the real TakeoverState (an options-backed record, is_cut_over() cache
 * flushed exactly as a transition flushes it).
 *
 * Window model: idle/preflight_ok/rolled_back write and delete the legacy name only;
 * copying/verified/cut_over (and an unreadable record) write and delete BOTH names;
 * finalized writes only the new name. Reads stay legacy-only until is_cut_over().
 *
 * Tests that need the WC_Order fixture run in a separate process: the payment unit tests
 * declare a different global WC_Order. H12 provenance under both prefixes runs in
 * tests/harness/takeover-h12-dual-prefix-harness.php (the unit suite stubs
 * CustomerTokenIdentity).
 */
final class DualReadTest extends TestCase {
    protected function setUp(): void {
        \supcheckout_test_reset_wp_options();
        TakeoverState::flush_cache();
        $_GET = array();
    }

    protected function tearDown(): void {
        \supcheckout_test_reset_wp_options();
        TakeoverState::flush_cache();
    }

    // --- Before the window: byte-identical names. ---

    public function test_before_the_window_every_name_is_todays_legacy_name(): void {
        self::assertSame(TakeoverState::IDLE, Keys::takeover_status(), 'no record: idle');
        self::assertSame('upayments', Keys::gateway_id());
        self::assertSame('upayments', Keys::extension_namespace());
        self::assertSame('wc_upayments', Keys::callback_route());
        self::assertSame('upayments', Keys::payment_method_query(), 'order queries keep the exact scalar filter');
        self::assertSame(array('woocommerce_upayments_settings'), Keys::settings_option_names());
        self::assertSame(array('UPayments_PaymentID'), Keys::write_keys('UPayments_PaymentID'));
        self::assertSame(array('UPayments_PaymentID'), Keys::delete_names('UPayments_PaymentID'));
        self::assertSame(array('simplixpay_upay_order_lock_v1_7'), Keys::ephemeral_names('simplixpay_upay_order_lock_v1_7'));
        self::assertSame(array('upay_ctk_bootstrap_b1'), Keys::advisory_lock_names('upay_ctk_bootstrap_b1'));

        $clause = array('key' => '_upay_subscription_status', 'value' => 'paused');
        self::assertSame($clause, Keys::meta_clause_any($clause), 'meta_query clauses are unchanged');
    }

    public function test_before_cutover_only_the_legacy_gateway_identity_is_ours(): void {
        self::assertTrue(Keys::is_our_gateway('upayments'));
        foreach (array('supcheckout', 'UPayments', 'bacs', '', null, array('upayments')) as $foreign) {
            self::assertFalse(Keys::is_our_gateway($foreign), var_export($foreign, true));
        }
        $official = (object) array('id' => 'upayments');
        self::assertSame($official, Keys::find_gateway(array('upayments' => $official)), 'today: whatever WooCommerce registered at upayments');
        self::assertNull(Keys::find_gateway(array('supcheckout' => (object) array())), 'a supcheckout registration is ignored before cutover');

        foreach (array('copying', 'verified') as $status) {
            self::state($status);
            self::assertFalse(Keys::is_our_gateway('supcheckout'), "$status: orders still carry upayments until cutover");
        }
    }

    public function test_before_cutover_settings_and_options_read_exactly_the_legacy_option_once(): void {
        \update_option('woocommerce_upayments_settings', array('enabled' => 'yes'));
        \update_option('woocommerce_supcheckout_settings', array('enabled' => 'stale'));
        self::log_option_reads();

        self::assertSame(array('enabled' => 'yes'), Keys::get_settings(array()));
        self::assertSame(array('woocommerce_upayments_settings'), $GLOBALS['supcheckout_test_dual_reads']);

        \supcheckout_test_reset_wp_options();
        self::log_option_reads();
        self::assertNull(Keys::get_settings(null), 'an absent legacy option keeps the caller default; the target is never read');
        self::assertSame(array('woocommerce_upayments_settings'), $GLOBALS['supcheckout_test_dual_reads']);

        \update_option('supcheckout_subscription_enrollment_cursor', 99);
        self::assertSame(0, Keys::get_option('upay_subscription_enrollment_cursor', 0));
    }

    public function test_hook_checks_accept_either_name(): void {
        self::assertTrue(Keys::is_meta_key('_upay_subscription_status', '_upay_subscription_status'));
        self::assertTrue(Keys::is_meta_key('_supcheckout_subscription_status', '_upay_subscription_status'));
        self::assertFalse(Keys::is_meta_key('_upay_subscription_plan', '_upay_subscription_status'));
    }

    public function test_before_cutover_only_the_legacy_extension_namespace_is_read(): void {
        $plan = array('upay_subscription_plan' => 'monthly', 'upay_subscription_interval' => '1');
        self::assertSame('upayments', self::posted_namespace(array('upayments' => $plan)));
        self::assertNull(self::posted_namespace(array('supcheckout' => $plan)), 'a posted supcheckout namespace is ignored');
        self::assertSame('upayments', self::posted_namespace(array('supcheckout' => $plan, 'upayments' => $plan)));
        self::assertSame('upayments', self::posted_namespace(array('supcheckout' => null, 'upayments' => $plan)), 'supcheckout:null never shadows the legacy data');
        self::assertNull(self::posted_namespace(array('other' => $plan)));
        self::assertNull(self::posted_namespace('malformed'));

        self::state('cut_over');
        self::assertSame('upayments', self::posted_namespace(array('upayments' => $plan)), 'an already-open checkout posts the legacy namespace');
        self::assertSame('supcheckout', self::posted_namespace(array('supcheckout' => $plan)));
        self::assertSame('supcheckout', self::posted_namespace(array('upayments' => $plan, 'supcheckout' => $plan)), 'after cutover the current namespace wins');
    }

    public function test_every_order_query_site_uses_the_scalar_upayments_filter_before_cutover(): void {
        $root = dirname(__DIR__, 3);
        $expected = array(
            'src/Migration/MigrationPreflight.php' => 2,
            'src/Payment/ReconciliationSweep.php' => 1,
            'src/Subscription/Cron/Scheduler.php' => 1,
            'src/Subscription/Scheduling/HistoricalEnrollment.php' => 1,
            'src/Token/CustomerTokenIdentity.php' => 2,
        );
        $found = array();
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/src', \FilesystemIterator::SKIP_DOTS)) as $file) {
            $source = (string) file_get_contents((string) $file);
            $calls = self::wc_get_orders_calls($source, (string) $file);
            if ($calls === 0) {
                continue;
            }
            $relative = str_replace('\\', '/', substr((string) $file, strlen($root) + 1));
            $found[$relative] = $calls;
            self::assertSame($calls, substr_count($source, "'payment_method' => Keys::payment_method_query(),"), "$relative: every order query filters through Keys");
        }
        ksort($found);
        self::assertSame($expected, $found, 'the inventory of order-query sites is exact');
        self::assertSame('upayments', Keys::payment_method_query());
    }

    // --- The window model for writes and deletes. ---

    public function test_write_and_delete_names_follow_the_window_model(): void {
        $expected = array(
            'idle' => array('UPayments_PaymentID'),
            'preflight_ok' => array('UPayments_PaymentID'),
            'copying' => array('UPayments_PaymentID', '_supcheckout_payment_id'),
            'verified' => array('UPayments_PaymentID', '_supcheckout_payment_id'),
            'cut_over' => array('UPayments_PaymentID', '_supcheckout_payment_id'),
            'rolled_back' => array('UPayments_PaymentID'),
            'finalized' => array('_supcheckout_payment_id'),
            'invalid' => array('UPayments_PaymentID', '_supcheckout_payment_id'),
        );
        foreach ($expected as $status => $names) {
            self::state($status);
            self::assertSame($names, Keys::write_keys('UPayments_PaymentID'), "$status writes");
            self::assertSame($names, Keys::delete_names('UPayments_PaymentID'), "$status deletes");
        }
    }

    public function test_copied_options_follow_the_window_model(): void {
        self::state('copying');
        self::assertTrue(Keys::update_copied_option('upay_subscription_enrollment_cursor', 40, false));
        self::assertSame(40, $GLOBALS['supcheckout_test_options']['upay_subscription_enrollment_cursor']);
        self::assertSame(40, $GLOBALS['supcheckout_test_options']['supcheckout_subscription_enrollment_cursor']);
        Keys::delete_copied_option('upay_subscription_enrollment_cursor');
        self::assertArrayNotHasKey('upay_subscription_enrollment_cursor', $GLOBALS['supcheckout_test_options']);
        self::assertArrayNotHasKey('supcheckout_subscription_enrollment_cursor', $GLOBALS['supcheckout_test_options']);

        self::state('rolled_back');
        Keys::update_copied_option('upay_subscription_enrollment_cursor', 7, false);
        self::assertArrayNotHasKey('supcheckout_subscription_enrollment_cursor', $GLOBALS['supcheckout_test_options'], 'rolled_back writes legacy only');

        self::state('copying');
        \update_option('supcheckout_token_identity_secret_v2', array('foreign'));
        self::assertFalse(Keys::add_copied_option('upayments_token_identity_secret_v2', array('mine')), 'a differing copy is never overwritten: fail closed');
        self::assertSame(array('foreign'), $GLOBALS['supcheckout_test_options']['supcheckout_token_identity_secret_v2']);
        self::assertArrayNotHasKey('upayments_token_identity_secret_v2', $GLOBALS['supcheckout_test_options'], 'all or nothing: the legacy side is not added either');

        \delete_option('supcheckout_token_identity_secret_v2');
        \update_option('supcheckout_token_identity_secret_v2', array('mine'));
        self::assertTrue(Keys::add_copied_option('upayments_token_identity_secret_v2', array('mine')), 'an identical copy counts as present');
        self::assertSame(array('mine'), $GLOBALS['supcheckout_test_options']['upayments_token_identity_secret_v2']);
        self::assertFalse(Keys::add_copied_option('upayments_token_identity_secret_v2', array('mine')), 'the first name already exists: nothing is created');
    }

    public function test_a_stored_falsy_value_under_the_preferred_name_keeps_its_precedence(): void {
        \update_option('upay_subscription_enrollment_cursor', 5);
        \update_option('supcheckout_subscription_enrollment_cursor', 0);
        self::assertSame(5, Keys::get_option('upay_subscription_enrollment_cursor', 0), 'idle: legacy only');

        self::state('cut_over');
        self::assertSame(0, Keys::get_option('upay_subscription_enrollment_cursor', 0), 'cut_over: a stored 0 under the new name wins over the stale legacy 5');
        \update_option('supcheckout_subscription_enrollment_cursor', '');
        self::assertSame('', Keys::get_option('upay_subscription_enrollment_cursor', 0), 'a stored empty string too');
        \delete_option('supcheckout_subscription_enrollment_cursor');
        self::assertSame(5, Keys::get_option('upay_subscription_enrollment_cursor', 0), 'new name absent: the legacy value');
        \delete_option('upay_subscription_enrollment_cursor');
        self::assertSame(0, Keys::get_option('upay_subscription_enrollment_cursor', 0), 'neither: the caller default');
        self::assertFalse(Keys::get_option('upay_subscription_enrollment_cursor'), 'the default default is false');

        $order = self::meta_order(array('_upay_subscription_status' => 'active', '_supcheckout_subscription_status' => ''));
        self::assertSame('', Keys::read_order_meta($order, '_upay_subscription_status'), 'order meta: a stored empty value under the new name wins when meta_exists() can tell');
        self::assertSame('active', Keys::read_order_meta(self::meta_order(array('_upay_subscription_status' => 'active')), '_upay_subscription_status'));

        $reader = static function ($key) {
            return array('_upay_retry_count' => '3', '_supcheckout_retry_count' => '')[$key] ?? '';
        };
        self::assertSame('3', Keys::read($reader, '_upay_retry_count'), 'without an existence check an empty single read counts as absent (documented)');
    }

    public function test_agreed_reads_return_one_value_or_a_conflict(): void {
        self::assertSame('d', Keys::get_option_agreed('upayments_token_identity_secret_v2', 'd'), 'neither: default');
        \update_option('supcheckout_token_identity_secret_v2', array('a'));
        self::assertSame(array('a'), Keys::get_option_agreed('upayments_token_identity_secret_v2', 'd'), 'only the new name');
        \update_option('upayments_token_identity_secret_v2', array('a'));
        self::assertSame(array('a'), Keys::get_option_agreed('upayments_token_identity_secret_v2', 'd'), 'identical copies');
        \update_option('upayments_token_identity_secret_v2', array('b'));
        self::assertSame(Keys::conflict(), Keys::get_option_agreed('upayments_token_identity_secret_v2', 'd'), 'different copies: conflict');
        self::state('cut_over');
        self::assertSame(Keys::conflict(), Keys::get_option_agreed('upayments_token_identity_secret_v2', 'd'), 'in every state');
    }

    public function test_update_copied_option_reports_success_only_when_every_name_was_written(): void {
        self::state('copying');
        self::assertTrue(Keys::update_copied_option('upay_billing_cycle_schema_version', '2', false));
        self::assertSame('2', $GLOBALS['supcheckout_test_options']['upay_billing_cycle_schema_version']);
        self::assertSame('2', $GLOBALS['supcheckout_test_options']['supcheckout_billing_cycle_schema_version']);

        $GLOBALS['supcheckout_test_update_option_result'] = false;
        self::assertFalse(Keys::update_copied_option('upay_billing_cycle_schema_version', '3', false), 'a failed write is reported');
        $GLOBALS['supcheckout_test_update_option_result'] = true;

        self::state('idle');
        self::assertTrue(Keys::update_copied_option('upay_billing_cycle_schema_version', '4', false));
        self::assertSame('2', $GLOBALS['supcheckout_test_options']['supcheckout_billing_cycle_schema_version'], 'idle writes the legacy name only');
    }

    public function test_a_value_written_in_copying_after_the_copy_is_read_correctly_after_cutover(): void {
        self::state('copying');
        $order = self::meta_order(array('_upay_last_billed_at' => '100'));
        // The copier copied this order: both names hold 100.
        $order->update_meta_data('_supcheckout_last_billed_at', '100');

        Keys::update_order_meta($order, '_upay_last_billed_at', '200');
        self::assertSame('200', Keys::read_order_meta($order, '_upay_last_billed_at'), 'copying: legacy read');

        self::state('cut_over');
        self::assertSame('200', Keys::read_order_meta($order, '_upay_last_billed_at'), 'cut_over: the new name holds the newer value, no stale copy');
    }

    public function test_a_marker_deleted_in_verified_stays_deleted_after_cutover(): void {
        self::state('verified');
        $order = self::meta_order(array(
            '_simplixpay_upayments_unverified_track_v1' => 't-1',
            '_supcheckout_unverified_track_v1' => 't-1',
            '_upay_credit_card_token' => 'card-A',
            '_supcheckout_card_token' => 'card-A',
        ));
        Keys::delete_order_meta($order, '_simplixpay_upayments_unverified_track_v1');
        Keys::delete_order_meta($order, '_upay_credit_card_token');

        self::state('cut_over');
        self::assertSame('', Keys::read_order_meta($order, '_simplixpay_upayments_unverified_track_v1'));
        self::assertSame('', Keys::read_order_meta($order, '_upay_credit_card_token'), 'a card-token snapshot does not resurrect');
    }

    public function test_rolled_back_writes_and_deletes_the_legacy_name_only(): void {
        self::state('rolled_back');
        $order = self::meta_order(array('_supcheckout_payment_id' => 'stale', 'UPayments_Result' => 'x', '_supcheckout_result' => 'x'));
        Keys::update_order_meta($order, 'UPayments_PaymentID', 'pay-9');
        Keys::delete_order_meta($order, 'UPayments_Result');
        self::assertSame('pay-9', $order->get_meta('UPayments_PaymentID'));
        self::assertSame('stale', $order->get_meta('_supcheckout_payment_id'));
        self::assertSame('x', $order->get_meta('_supcheckout_result'));
    }

    // --- After cutover: new name first, legacy fallback. ---

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_after_cutover_a_historical_order_keeps_its_order_total_and_admin_rows(): void {
        self::require_order_fixture();
        self::state('cut_over');

        $legacy_only = self::order(1, 'upayments', array('UPayments_PaymentID' => 'pay-1', 'UPayments_Result' => 'CAPTURED'));
        $both = self::order(2, 'supcheckout', array('UPayments_PaymentID' => 'pay-1', '_supcheckout_payment_id' => 'pay-2', '_supcheckout_result' => 'CAPTURED'));
        $rows = array('payment_method' => array('label' => 'Method', 'value' => 'UPayments'));

        $legacy_rows = OrderPresentation::add_order_item_totals($rows, $legacy_only, 'supcheckout');
        self::assertSame('pay-1', $legacy_rows['upayment_id']['value'], 'a historical upayments order keeps its payment rows');
        self::assertSame('CAPTURED', $legacy_rows['payment_status']['value']);
        self::assertSame('pay-2', OrderPresentation::add_order_item_totals($rows, $both, 'supcheckout')['upayment_id']['value']);
        $foreign = self::order(3, 'bacs', array('UPayments_PaymentID' => 'pay-3'));
        self::assertSame($rows, OrderPresentation::add_order_item_totals($rows, $foreign, 'supcheckout'), 'another gateway\'s order stays untouched');

        $gateway = self::gateway_instance();
        ob_start();
        $gateway->admin_order_details($legacy_only);
        $html = (string) ob_get_clean();
        self::assertStringContainsString('pay-1', $html, 'admin_order_details() renders the historical order');
        self::assertStringContainsString('CAPTURED', $html);
        ob_start();
        $gateway->admin_order_details($foreign);
        self::assertSame('', (string) ob_get_clean());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_after_cutover_only_our_gateway_class_is_found_never_the_official_object(): void {
        self::require_order_fixture();
        self::state('cut_over');
        $ours = self::gateway_instance();
        $official = (object) array('id' => 'upayments');

        self::assertSame($ours, Keys::find_gateway(array('supcheckout' => $ours, 'upayments' => $official)));
        self::assertSame($ours, Keys::find_gateway(array('upayments' => $ours)), 'ours, still registered under the legacy id');
        self::assertNull(Keys::find_gateway(array('upayments' => $official)), 'the official plugin\'s object at upayments is never ours');
        self::assertSame('upayments', Keys::find_gateway_id(array('bacs' => $official, 'upayments' => $ours)));
        self::assertTrue(Keys::is_our_gateway('supcheckout'));
        self::assertTrue(Keys::is_our_gateway('upayments'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_after_cutover_writes_and_names_follow_the_new_identity(): void {
        self::state('cut_over');
        self::assertSame('supcheckout', Keys::gateway_id());
        self::assertSame('supcheckout', Keys::callback_route());
        self::assertSame(array('upayments', 'supcheckout'), Keys::payment_method_query(), 'historical upayments orders stay in every order query');
        self::assertSame(array('woocommerce_supcheckout_settings', 'woocommerce_upayments_settings', 'woocommerce_your_gateway_id_settings'), Keys::settings_option_names());

        $saved_wpdb = $GLOBALS['wpdb'];
        $GLOBALS['wpdb'] = (object) array('prefix' => 'wp_');
        self::assertSame('wp_supcheckout_billing_attempts', Keys::table('upayments_billing_attempts'));
        $GLOBALS['wpdb'] = $saved_wpdb;

        \update_option('woocommerce_your_gateway_id_settings', array('enabled' => 'yes'));
        self::assertSame(array('enabled' => 'yes'), Keys::get_settings(array()), 'a 3.0.0 store with only the your_gateway_id option still resolves');

        self::state('finalized');
        self::assertSame(array('_supcheckout_payment_id'), Keys::write_keys('UPayments_PaymentID'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_after_cutover_queries_find_legacy_only_and_new_only_orders_but_not_stale_copies(): void {
        self::state('cut_over');

        $_GET = array('subscription_filter' => 'paused');
        $query = Presentation::filter_account_orders_query(array());
        $sweep = PaymentLifecycle::sweep_cursor_meta_query();

        $legacy_only = array('_upay_subscription_status' => 'paused', '_simplixpay_upayments_unverified_track_v1' => 't-1');
        $new_only = array('_supcheckout_subscription_status' => 'paused', '_supcheckout_status_track_v1' => 't-2');
        $stale_copy = array('_upay_subscription_status' => 'paused', '_supcheckout_subscription_status' => 'active');
        $neither = array('_upay_subscription_status' => 'active');

        self::assertTrue(self::meta_query_matches($query['meta_query'], $legacy_only));
        self::assertTrue(self::meta_query_matches($query['meta_query'], $new_only));
        self::assertFalse(self::meta_query_matches($query['meta_query'], $stale_copy), 'a legacy value the new name superseded never matches');
        self::assertFalse(self::meta_query_matches($query['meta_query'], $neither));
        self::assertTrue(self::meta_query_matches(array($sweep), $legacy_only));
        self::assertTrue(self::meta_query_matches(array($sweep), $new_only));
        self::assertFalse(self::meta_query_matches(array($sweep), $neither));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_after_cutover_a_historical_subscription_parent_still_qualifies(): void {
        self::require_order_fixture();
        require_once dirname(__DIR__, 2) . '/support/action-scheduler-stubs.php';
        self::state('cut_over');

        $meta = array('UPayments_AutoDeduction' => 'no', '_upay_subscription_status' => 'active',
            '_upay_subscription_plan' => 'monthly', '_upay_subscription_interval' => '1');
        self::assertTrue(HistoricalEnrollment::parent_qualifies(self::order(10, 'upayments', $meta, 'processing')), 'payment_method=upayments, legacy keys only');
        self::assertFalse(HistoricalEnrollment::parent_qualifies(self::order(11, 'upayments', $meta + array('_supcheckout_subscription_status' => 'cancelled'), 'processing')), 'the new name supersedes the stale legacy copy');
        self::assertTrue(HistoricalEnrollment::parent_qualifies(self::order(12, 'supcheckout', array('_supcheckout_auto_deduction' => 'no',
            '_supcheckout_subscription_status' => 'active', '_supcheckout_subscription_plan' => 'monthly', '_supcheckout_subscription_interval' => '1'), 'processing')));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_before_cutover_a_supcheckout_order_never_qualifies_as_ours(): void {
        self::require_order_fixture();
        require_once dirname(__DIR__, 2) . '/support/action-scheduler-stubs.php';
        $meta = array('UPayments_AutoDeduction' => 'no', '_upay_subscription_status' => 'active',
            '_upay_subscription_plan' => 'monthly', '_upay_subscription_interval' => '1');
        foreach (array('idle', 'copying', 'verified') as $status) {
            self::state($status);
            self::assertTrue(HistoricalEnrollment::parent_qualifies(self::order(20, 'upayments', $meta, 'processing')), $status);
            self::assertFalse(HistoricalEnrollment::parent_qualifies(self::order(21, 'supcheckout', $meta, 'processing')), "$status: supcheckout is rejected");

        }
    }

    public function test_before_cutover_the_payment_and_status_guards_reject_a_supcheckout_order(): void {
        $order = static function ($method) {
            return new class($method) {
                /** @var string */
                private $method;
                public function __construct($method) { $this->method = $method; }
                public function get_id() { return 5; }
                public function get_payment_method() { return $this->method; }
                public function get_meta($key) { return $key === 'UPayments_order_id' ? 'provider-1' : ''; }
            };
        };
        $gateway = (object) array();
        self::assertTrue(self::call_static(PaymentLifecycle::class, 'basic_order_preflight', array($order('upayments'), $gateway)));
        self::assertFalse(self::call_static(PaymentLifecycle::class, 'basic_order_preflight', array($order('supcheckout'), $gateway)), 'callback ownership');
        self::assertTrue(self::call_static(\Simplixi\SUPCheckout\Security\PublicOrderStatus::class, 'is_upayments_order', array($order('upayments'))));
        self::assertFalse(self::call_static(\Simplixi\SUPCheckout\Security\PublicOrderStatus::class, 'is_upayments_order', array($order('supcheckout'))), 'public status poll');
    }

    public function test_before_cutover_subscription_fields_reject_a_posted_supcheckout_method(): void {
        $fields = \Simplixi\SUPCheckout\Subscription\Checkout\Fields::class;
        self::assertTrue(self::call_static($fields, 'posted_method_is_ours', array(array('payment_method' => 'upayments'))));
        self::assertFalse(self::call_static($fields, 'posted_method_is_ours', array(array('payment_method' => 'supcheckout'))), 'request-controlled supcheckout is rejected');
        self::assertFalse(self::call_static($fields, 'posted_method_is_ours', array(array())));
        self::state('cut_over');
        self::assertTrue(self::call_static($fields, 'posted_method_is_ours', array(array('payment_method' => 'supcheckout'))));
    }

    // --- Locks across the switch window. ---

    public function test_in_copying_a_dual_acquirer_contends_with_a_legacy_only_holder(): void {
        $worker_a = OrderLock::acquire(77);
        self::assertIsString($worker_a);
        self::assertSame(array('simplixpay_upay_order_lock_v1_77'), self::lock_rows(), 'idle: legacy name only');

        self::state('copying');
        self::assertNull(OrderLock::acquire(77), 'worker B takes legacy first and must fail');
        self::assertNotContains('supcheckout_order_lock_v1_77', self::lock_rows(), 'no partial acquisition survives');

        OrderLock::release(77, $worker_a);
        $worker_b = OrderLock::acquire(77);
        self::assertIsString($worker_b);
        self::assertSame(array('simplixpay_upay_order_lock_v1_77', 'supcheckout_order_lock_v1_77'), self::lock_rows());

        self::state('cut_over');
        self::assertNull(OrderLock::acquire(77), 'a post-cutover worker contends on the same pair');
        OrderLock::release(77, $worker_b);
        self::assertSame(array(), self::lock_rows(), 'release frees both names');

        self::state('finalized');
        self::assertSame(array('supcheckout_order_lock_v1_77'), Keys::ephemeral_names('simplixpay_upay_order_lock_v1_77'));
        self::assertSame(array('sup_ctkboot_b1'), Keys::advisory_lock_names('upay_ctk_bootstrap_b1'), 'GET_LOCK names switch at Finalize');
        self::state('copying');
        self::assertSame(array('upay_ctk_bootstrap_b1'), Keys::advisory_lock_names('upay_ctk_bootstrap_b1'), 'never two GET_LOCK names at once (MySQL < 5.7.5)');
        self::state('invalid');
        self::assertCount(2, Keys::ephemeral_names('simplixpay_upay_order_lock_v1_77'), 'an unreadable state contends on both names');
    }

    public function test_in_copying_a_post_cutover_holder_blocks_a_legacy_first_worker_on_the_new_name(): void {
        self::state('copying');
        $GLOBALS['supcheckout_test_options']['supcheckout_order_lock_v1_78'] = (string) (time() + 45) . ':' . str_repeat('d', 32);

        self::assertNull(OrderLock::acquire(78));
        self::assertNotContains('simplixpay_upay_order_lock_v1_78', self::lock_rows(), 'the legacy name taken first is rolled back');
    }

    // --- Last-known-good name. ---

    public function test_last_known_good_name_is_recomputed_from_the_fingerprint_inputs(): void {
        $service = new \Simplixi\SUPCheckout\Provider\PaymentMethodAvailability(true, 'cert-key', static function () {
            return array();
        });
        $fingerprint = substr(hash_hmac('sha256', 'test|cert-key', \wp_salt('auth')), 0, 16);

        self::assertSame('supcheckout_pm_lkg_v1_' . $fingerprint, self::call_private($service, 'last_known_good_name'), 'identical to the value stored before Task 2');
        self::assertSame('upay_pm_v3_' . $fingerprint, self::call_private($service, 'transient_name'));
        self::assertSame('supcheckout_pmcache_v3_' . $fingerprint, Keys::ephemeral_prefixes()['upay_pm_v3_'] . $fingerprint, 'the renamed cache keeps the same fingerprint, so the LKG value is never orphaned');
    }

    // --- Helpers. ---

    /**
     * Persist a takeover record the way TakeoverState stores it and drop the cached
     * is_cut_over() answer, as a successful transition does.
     */
    private static function state(string $status): void {
        $GLOBALS['supcheckout_test_options'][TakeoverState::OPTION] = $status === 'invalid'
            ? '{not json'
            : (string) json_encode(array('status' => $status, 'cut_over_at' => 0, 'cursor' => 0, 'totals' => array()));
        TakeoverState::flush_cache();
        self::assertSame($status, Keys::takeover_status());
    }

    /** @return list<string> Lock option rows (the takeover record itself excluded). */
    private static function lock_rows(): array {
        $rows = array_values(array_filter(array_keys($GLOBALS['supcheckout_test_options']), static function ($name) {
            return $name !== TakeoverState::OPTION;
        }));
        sort($rows);
        return $rows;
    }

    private static function require_order_fixture(): void {
        require_once dirname(__DIR__, 2) . '/support/wc-order-revalidation-fixture.php';
    }

    /** @param array<string,mixed> $meta */
    private static function order(int $id, string $method, array $meta, string $status = 'pending'): \WC_Order {
        $order = new \WC_Order();
        $order->set_id($id);
        $order->set_payment_method($method);
        $order->set_status($status);
        $order->set_meta($meta);
        $order->set_items(array(array('type' => 'custom_type')));
        return $order;
    }

    /**
     * Executable wc_get_orders( calls in a PHP source (comments and strings ignored).
     * Every call must take a literal array, so its 'payment_method' filter is visible:
     * a call with a variable or any other expression fails the test.
     */
    private static function wc_get_orders_calls(string $source, string $file): int {
        $tokens = array_values(array_filter(token_get_all($source), static function ($token) {
            return !is_array($token) || !in_array($token[0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true);
        }));
        $calls = 0;
        foreach ($tokens as $i => $token) {
            if (!is_array($token) || $token[0] !== T_STRING || strtolower($token[1]) !== 'wc_get_orders'
                || !isset($tokens[$i + 1]) || $tokens[$i + 1] !== '('
            ) {
                continue;
            }
            $previous = $tokens[$i - 1] ?? null;
            if (is_array($previous) && in_array($previous[0], array(T_FUNCTION, T_OBJECT_OPERATOR, T_DOUBLE_COLON), true)) {
                continue;
            }
            $argument = $tokens[$i + 2] ?? null;
            self::assertTrue(
                (is_array($argument) && $argument[0] === T_ARRAY) || $argument === '[',
                $file . ':' . $token[2] . ' calls wc_get_orders() with a non-literal argument'
            );
            ++$calls;
        }
        return $calls;
    }

    /**
     * A WooCommerce-shaped order meta store (get_meta / update / add / delete).
     *
     * @param array<string,mixed> $meta
     * @return object
     */
    private static function meta_order(array $meta) {
        return new class($meta) {
            /** @var array<string,mixed> */
            private $meta;
            /** @param array<string,mixed> $meta */
            public function __construct(array $meta) { $this->meta = $meta; }
            /** @return mixed */
            public function get_meta($key) { return array_key_exists($key, $this->meta) ? $this->meta[$key] : ''; }
            /** @param mixed $value */
            public function update_meta_data($key, $value) { $this->meta[$key] = $value; }
            /** @param mixed $value */
            public function add_meta_data($key, $value, $unique = false) { $this->meta[$key] = $value; }
            public function delete_meta_data($key) { unset($this->meta[$key]); }
            public function meta_exists($key) { return array_key_exists($key, $this->meta); }
        };
    }

    /** @return \Simplixi\SUPCheckout\Gateway\Gateway */
    private static function gateway_instance() {
        if (!class_exists('WC_Payment_Gateway', false)) {
            eval('class WC_Payment_Gateway { public $id = ""; }');
        }
        $reflection = new \ReflectionClass(\Simplixi\SUPCheckout\Gateway\Gateway::class);
        return $reflection->newInstanceWithoutConstructor();
    }

    /** @return string|null */
    private static function posted_namespace($extensions) {
        return self::call_static(\Simplixi\SUPCheckout\Payment\CheckoutOrchestrator::class, 'posted_extension_namespace', array($extensions));
    }

    private static function log_option_reads(): void {
        $GLOBALS['supcheckout_test_dual_reads'] = array();
        // The takeover record itself (one cached read per request, Task 3) is not a data read.
        $GLOBALS['supcheckout_test_get_option_filter'] = static function ($name, $value) {
            if ($name !== TakeoverState::OPTION) {
                $GLOBALS['supcheckout_test_dual_reads'][] = $name;
            }
            return $value;
        };
    }

    /**
     * Minimal WP_Meta_Query evaluator (relation AND/OR, '=' value, EXISTS, NOT EXISTS).
     *
     * @param array<mixed>        $query
     * @param array<string,mixed> $meta
     */
    private static function meta_query_matches(array $query, array $meta): bool {
        $relation = isset($query['relation']) ? $query['relation'] : 'AND';
        $results = array();
        foreach ($query as $index => $clause) {
            if ($index === 'relation') {
                continue;
            }
            if (isset($clause['key'])) {
                $compare = isset($clause['compare']) ? $clause['compare'] : '=';
                $exists = array_key_exists($clause['key'], $meta);
                if ($compare === 'EXISTS') {
                    $results[] = $exists;
                } elseif ($compare === 'NOT EXISTS') {
                    $results[] = !$exists;
                } else {
                    $results[] = $exists && (string) $meta[$clause['key']] === (string) $clause['value'];
                }
            } else {
                $results[] = self::meta_query_matches($clause, $meta);
            }
        }
        return $relation === 'OR' ? in_array(true, $results, true) : !in_array(false, $results, true);
    }

    /**
     * @param array<int,mixed> $args
     * @return mixed
     */
    private static function call_static(string $class, string $method, array $args) {
        $reflection = new \ReflectionMethod($class, $method);
        if (\PHP_VERSION_ID < 80100) {
            $reflection->setAccessible(true);
        }
        return $reflection->invokeArgs(null, $args);
    }

    /** @return mixed */
    private static function call_private(object $object, string $method) {
        $reflection = new \ReflectionMethod($object, $method);
        if (\PHP_VERSION_ID < 80100) {
            $reflection->setAccessible(true);
        }
        return $reflection->invoke($object);
    }
}
