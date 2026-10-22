<?php

namespace Simplixi\SUPCheckout\Tests\Subscription;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Simplixi\SUPCheckout\Subscription\Presentation;
use Simplixi\SUPCheckout\Subscription\Helpers\Utils;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PresentationTest extends TestCase {
    protected function setUp(): void {
        require_once dirname(__DIR__, 2) . '/fixtures/subscription-presentation-runtime.php';
        \supcheckout_test_reset_subscription_presentation();
        \Simplixi\SUPCheckout\Gateway\Gateway::$summary_orders = array();
        \Simplixi\SUPCheckout\Gateway\Gateway::$constructed = 0;
        Utils::$custom = false;
        Utils::$normal = false;
    }

    public function test_product_type_selector_mapping_and_admin_schema_remain_exact(): void {
        self::assertSame(
            array('simple' => 'Simple', 'custom_type' => 'Subscription Product'),
            Presentation::add_custom_product_type(array('simple' => 'Simple'))
        );
        self::assertSame('WCProductCustomType', Presentation::map_custom_product_class('WC_Product_Simple', 'custom_type'));
        self::assertSame('OtherClass', Presentation::map_custom_product_class('OtherClass', 'simple'));
        self::assertSame(array(
            'label' => 'Custom Settings',
            'target' => 'custom_product_data_panel',
            'class' => array('show_if_custom_type'),
            'priority' => 25,
        ), Presentation::add_custom_data_tab(array())['custom_settings']);

        ob_start();
        Presentation::add_custom_data_panel();
        $panel = ob_get_clean();

        self::assertStringContainsString('id="custom_product_data_panel"', $panel);
        self::assertSame(array(array(
            'id' => '_custom_field_id',
            'label' => 'Custom Field',
            'placeholder' => 'Enter value here',
            'desc_tip' => 'true',
            'description' => 'This is a description of the field.',
        )), $GLOBALS['supcheckout_test_subscription_presentation']['field_args']);
    }

    public function test_product_class_registration_remains_guarded_and_loads_exact_legacy_type(): void {
        Presentation::register_product_class();

        self::assertTrue(class_exists('WCProductCustomType', false));
        self::assertSame('custom_type', (new \WCProductCustomType())->get_type());
    }

    public function test_product_meta_write_requires_exact_nonce_post_and_capability_boundaries(): void {
        $_POST = array(
            'woocommerce_meta_nonce' => 'valid',
            'post_ID' => '12',
            '_custom_field_id' => ' <b>Gold</b> ',
        );

        $GLOBALS['supcheckout_test_subscription_presentation']['nonce_valid'] = false;
        Presentation::save_custom_field_data(12);
        self::assertSame(array(), $GLOBALS['supcheckout_test_subscription_presentation']['meta_writes']);

        $GLOBALS['supcheckout_test_subscription_presentation']['nonce_valid'] = true;
        $_POST['post_ID'] = '13';
        Presentation::save_custom_field_data(12);
        self::assertSame(array(), $GLOBALS['supcheckout_test_subscription_presentation']['meta_writes']);

        $_POST['post_ID'] = '12';
        $GLOBALS['supcheckout_test_subscription_presentation']['capability_allowed'] = false;
        Presentation::save_custom_field_data(12);
        self::assertSame(array(), $GLOBALS['supcheckout_test_subscription_presentation']['meta_writes']);

        $GLOBALS['supcheckout_test_subscription_presentation']['capability_allowed'] = true;
        Presentation::save_custom_field_data(12);
        self::assertSame(array(array(12, '_custom_field_id', 'Gold')), $GLOBALS['supcheckout_test_subscription_presentation']['meta_writes']);
        self::assertSame(array(
            array('edit_post', 12),
            array('edit_post', 12),
        ), $GLOBALS['supcheckout_test_subscription_presentation']['capability_calls']);
    }

    public function test_malformed_product_meta_request_fails_closed_without_writing(): void {
        $_POST = array(
            'woocommerce_meta_nonce' => array('invalid-shape'),
            'post_ID' => array('12'),
            '_custom_field_id' => array('Gold'),
        );

        Presentation::save_custom_field_data(12);
        Presentation::save_custom_field_data(array(12));

        self::assertSame(array(), $GLOBALS['supcheckout_test_subscription_presentation']['meta_writes']);
        self::assertSame(array(), $GLOBALS['supcheckout_test_subscription_presentation']['capability_calls']);
    }

    public function test_frontend_value_is_escaped_and_non_product_globals_are_ignored(): void {
        $GLOBALS['supcheckout_test_subscription_presentation']['meta'][12]['_custom_field_id'] = '<b>Gold</b>';
        $GLOBALS['product'] = new \WC_Product();
        $GLOBALS['product']->type = 'custom_type';

        ob_start();
        Presentation::display_custom_field_on_frontend();
        $output = ob_get_clean();

        self::assertStringContainsString('&lt;b&gt;Gold&lt;/b&gt;', $output);
        self::assertStringNotContainsString('<b>Gold</b>', $output);

        $GLOBALS['product'] = new \stdClass();
        ob_start();
        Presentation::display_custom_field_on_frontend();
        self::assertSame('', ob_get_clean());
    }

    public function test_cart_and_order_item_meta_preserve_valid_shape_and_ignore_malformed_payloads(): void {
        $GLOBALS['supcheckout_test_subscription_presentation']['meta'][12]['_custom_field_id'] = 'Gold';

        self::assertSame(array(array(
            'key' => 'Special Feature',
            'value' => 'Gold',
            'display' => '',
        )), Presentation::display_custom_data_in_cart(array(), array('product_id' => 12)));
        self::assertSame(array('existing'), Presentation::display_custom_data_in_cart(array('existing'), array()));
        self::assertSame(array('existing'), Presentation::display_custom_data_in_cart(array('existing'), array('product_id' => array(12))));

        $item = new \SUPCheckout_Test_Presentation_Item();
        Presentation::save_custom_data_to_order_items($item, 'cart-key', array('product_id' => 12), null);
        Presentation::save_custom_data_to_order_items($item, 'cart-key', array(), null);
        Presentation::save_custom_data_to_order_items($item, 'cart-key', array('product_id' => array(12)), null);

        self::assertSame(array(array('Special Feature', 'Gold')), $item->writes);
    }

    public function test_admin_order_summary_ignores_missing_products_and_renders_once_per_order(): void {
        $subscription = new \WC_Product();
        $subscription->type = 'custom_type';
        $order = $this->subscriptionOrder();
        $order->items = array(
            new \SUPCheckout_Test_Presentation_Item(null),
            new \SUPCheckout_Test_Presentation_Item($subscription),
            new \SUPCheckout_Test_Presentation_Item($subscription),
        );

        ob_start();
        Presentation::render_admin_order_summary($order);
        $rendered = ob_get_clean();

        ob_start();
        Presentation::render_admin_summary($order);
        $expected = ob_get_clean();

        self::assertNotSame('', $expected);
        self::assertSame($expected, $rendered);
        // A second gateway object would re-register every gateway hook and duplicate
        // the admin payment box, thank-you section and order totals rows.
        self::assertSame(0, \Simplixi\SUPCheckout\Gateway\Gateway::$constructed);
    }

    public function test_mixed_cart_validation_preserves_both_exact_rejection_contracts(): void {
        $product = new \WC_Product();
        $GLOBALS['supcheckout_test_subscription_presentation']['product'] = $product;
        Utils::$custom = true;

        self::assertFalse(Presentation::restrict_mixed_cart_products(true, 12, 1, 'upayments'));
        self::assertSame(array(array(
            'You can only add subscription products to the cart when a subscription item is present.',
            'error',
        )), $GLOBALS['supcheckout_test_subscription_presentation']['notices']);

        $product->type = 'custom_type';
        Utils::$custom = false;
        Utils::$normal = true;
        $GLOBALS['supcheckout_test_subscription_presentation']['notices'] = array();

        self::assertFalse(Presentation::restrict_mixed_cart_products(true, 12, 1, 'upayments'));
        self::assertSame(array(array(
            'Subscription products cannot be added together with normal products. Please complete your current purchase first.',
            'error',
        )), $GLOBALS['supcheckout_test_subscription_presentation']['notices']);
    }

    public function test_account_query_accepts_only_exact_known_subscription_statuses(): void {
        $original = array('limit' => 10);
        foreach (array(array('paused'), '<b>paused</b>', 'unknown', 'paused!', '') as $invalid) {
            $_GET = array('subscription_filter' => $invalid);
            self::assertSame($original, Presentation::filter_account_orders_query($original));
        }

        $_GET = array('subscription_filter' => 'paused');
        self::assertSame(array(
            'limit' => 10,
            'meta_query' => array(array(
                'key' => '_upay_subscription_status',
                'value' => 'paused',
            )),
        ), Presentation::filter_account_orders_query($original));
    }

    public function test_account_filter_preserves_only_supplied_page_identity_and_ignores_malformed_status(): void {
        $_GET = array(
            'page_id' => '<script>12</script>',
            'subscription_filter' => array('paused'),
        );

        ob_start();
        Presentation::render_account_orders_filter();
        $output = ob_get_clean();

        self::assertStringContainsString('name="page_id" value="12"', $output);
        self::assertStringNotContainsString('<script>', $output);
        self::assertStringNotContainsString('selected="selected"', $output);

        $_GET = array('subscription_filter' => 'active');
        ob_start();
        Presentation::render_account_orders_filter();
        $without_page_id = ob_get_clean();

        self::assertStringNotContainsString('name="page_id"', $without_page_id);
        self::assertStringNotContainsString('value="12"', $without_page_id);
        self::assertMatchesRegularExpression('/value="active"\s+selected="selected"/', $without_page_id);
    }

    public function test_account_columns_labels_and_status_output_remain_escaped(): void {
        self::assertSame(array(
            'order-number' => 'Order',
            'order-status' => 'Woo Status',
            'order_type' => 'Type',
            'order_status' => 'Status',
            'order-total' => 'Total',
        ), Presentation::filter_account_orders_columns(array(
            'order-number' => 'Order',
            'order-status' => 'Woo Status',
            'order-total' => 'Total',
        )));

        $order = new \WC_Order();
        $order->meta['_upay_subscription_status'] = 'active"><script>x</script>';
        ob_start();
        Presentation::render_account_subscription_status($order);
        $output = ob_get_clean();

        self::assertStringNotContainsString('<script>', $output);
        self::assertStringContainsString('&lt;script&gt;', $output);

        $order->meta['_upay_subscription_status'] = array('active');
        ob_start();
        Presentation::render_account_subscription_status($order);
        self::assertSame('—', ob_get_clean());
    }

    public function test_owned_manual_account_details_preserve_actions_and_nonce_identities(): void {
        $order = $this->subscriptionOrder();
        $order->meta['_upay_subscription_status'] = 'paused';

        ob_start();
        Presentation::render_account_order_details($order);
        $output = ob_get_clean();

        self::assertStringContainsString('woocommerce-subscription-details', $output);
        self::assertStringContainsString('name="upay_action" value="unsubscribe"', $output);
        self::assertStringContainsString('name="upay_action" value="resume"', $output);
        self::assertSame(array(
            array('upay_unsubscribe_44', '_wpnonce', false),
            array('upay_resume_44', '_wpnonce', false),
        ), $GLOBALS['supcheckout_test_subscription_presentation']['nonce_fields']);
    }

    public function test_account_details_fail_closed_for_other_cancelled_auto_and_malformed_orders(): void {
        $other = $this->subscriptionOrder();
        $other->user_id = 8;
        self::assertSame('', $this->accountOutput($other));

        $cancelled = $this->subscriptionOrder();
        $cancelled->meta['_upay_subscription_status'] = 'cancelled';
        self::assertSame('', $this->accountOutput($cancelled));

        $auto = $this->subscriptionOrder();
        $auto->meta['UPayments_AutoDeduction'] = 'yes';
        $auto_output = $this->accountOutput($auto);
        self::assertStringContainsString('woocommerce-subscription-details', $auto_output);
        self::assertStringNotContainsString('name="upay_action"', $auto_output);

        $malformed = $this->subscriptionOrder();
        $malformed->meta['_upay_subscription_plan'] = array('monthly');
        $malformed->created = 'not-a-date';
        self::assertSame('', $this->accountOutput($malformed));
    }

    public function test_admin_summary_escapes_values_handles_invalid_dates_and_hides_cancelled_next_date(): void {
        $order = $this->subscriptionOrder();
        $order->meta['_upay_subscription_plan'] = '<script>monthly</script>';
        ob_start();
        Presentation::render_admin_summary($order);
        $escaped = ob_get_clean();
        self::assertStringNotContainsString('<script>', $escaped);

        $order = $this->subscriptionOrder();
        $order->meta['_upay_last_billed_at'] = 'not-a-date';
        ob_start();
        Presentation::render_admin_summary($order);
        self::assertSame('', ob_get_clean());

        $order = $this->subscriptionOrder();
        $order->meta['_upay_subscription_status'] = 'cancelled';
        ob_start();
        Presentation::render_admin_summary($order);
        $cancelled = ob_get_clean();
        self::assertStringNotContainsString('Next Billing Date:', $cancelled);
    }

    /** Fix A1-01: no "Next Billing Date" as if a charge will happen while auto-charges are paused. */
    public function test_admin_summary_shows_automatic_renewal_paused_instead_of_a_next_billing_date(): void {
        $order = $this->subscriptionOrder();
        $order->meta['_supcheckout_auto_renewal_paused_note_v1'] = '1';
        ob_start();
        Presentation::render_admin_summary($order);
        $html = ob_get_clean();

        self::assertStringContainsString('upay-subscription-summary', $html);
        self::assertStringNotContainsString('Next Billing Date', $html);
        self::assertStringContainsString(
            '<p><strong>Automatic Renewal:</strong> Paused. Collect this renewal manually or send the customer a payment link.</p>',
            $html
        );
    }

    /** Review I1: the admin box mirrors the "check the dashboard first" note. */
    public function test_admin_summary_warns_to_check_the_dashboard_when_an_earlier_charge_is_unconfirmed(): void {
        $expected = '<p><strong>Automatic Renewal:</strong> Paused. An earlier automatic charge could not be confirmed: '
            . 'check the UPayments dashboard before you collect this renewal manually or send a payment link.</p>';

        $noted = $this->subscriptionOrder();
        $noted->meta['_supcheckout_auto_renewal_paused_note_v1'] = 'unconfirmed';
        ob_start();
        Presentation::render_admin_summary($noted);
        self::assertStringContainsString($expected, (string) ob_get_clean(), 'the parent carries the unconfirmed note');

        $unread = $this->subscriptionOrder();
        $GLOBALS['wpdb'] = null;
        ob_start();
        Presentation::render_admin_summary($unread);
        self::assertStringContainsString($expected, (string) ob_get_clean(), 'no note yet and the billing history cannot be read: fail closed');
    }

    public function test_account_details_show_automatic_renewal_paused_instead_of_a_next_billing_date(): void {
        $html = $this->accountOutput($this->subscriptionOrder());

        self::assertStringContainsString('woocommerce-subscription-details', $html);
        self::assertStringNotContainsString('Next Billing Date', $html);
        self::assertStringContainsString('Automatic Renewal</th>', $html);
        self::assertStringContainsString('Paused. Renewals are not charged to your card automatically.</td>', $html);
    }

    public function test_paused_renewal_notice_renders_for_store_managers_on_store_screens(): void {
        \update_option('woocommerce_upayments_settings', array('enable_subscriptions' => 'yes'));
        foreach (array('woocommerce_page_wc-settings', 'plugins') as $screen) {
            $GLOBALS['supcheckout_test_current_screen'] = (object) array('id' => $screen);
            ob_start();
            Presentation::render_auto_renewal_paused_notice();
            self::assertSame(
                '<div class="notice notice-warning"><p><strong>Automatic subscription renewals are paused.</strong> '
                . Presentation::auto_renewal_paused_message() . '</p></div>',
                ob_get_clean(),
                $screen
            );
        }
        self::assertSame('manage_woocommerce', $GLOBALS['supcheckout_test_subscription_presentation']['capability_calls'][0][0]);
    }

    /** Review M5: one shared paused statement for the notice and the setting. */
    public function test_the_paused_statement_is_one_shared_string(): void {
        self::assertSame(
            'SUPCheckout does not send automatic renewal charges until UPayments confirms how to verify them. Collect renewals manually or by payment link.',
            Presentation::auto_renewal_paused_message()
        );
    }

    public function test_paused_renewal_notice_is_silent_without_capability_setting_or_store_screen(): void {
        $GLOBALS['supcheckout_test_current_screen'] = (object) array('id' => 'woocommerce_page_wc-settings');
        \update_option('woocommerce_upayments_settings', array('enable_subscriptions' => 'no'));
        self::assertSame('', $this->noticeOutput(), 'subscriptions off');

        \update_option('woocommerce_upayments_settings', array('enable_subscriptions' => 'yes'));
        $GLOBALS['supcheckout_test_subscription_presentation']['capability_allowed'] = false;
        self::assertSame('', $this->noticeOutput(), 'no manage_woocommerce');

        $GLOBALS['supcheckout_test_subscription_presentation']['capability_allowed'] = true;
        $GLOBALS['supcheckout_test_current_screen'] = (object) array('id' => 'edit-post');
        self::assertSame('', $this->noticeOutput(), 'not a WooCommerce or plugins screen');

        $GLOBALS['supcheckout_test_current_screen'] = null;
        self::assertSame('', $this->noticeOutput(), 'no screen');
    }

    public function test_paused_renewal_notice_escapes_every_string(): void {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Subscription/Presentation.php');
        $start = strpos($source, 'public static function render_auto_renewal_paused_notice');
        self::assertIsInt($start);
        $body = substr($source, $start, (int) strpos($source, "\n    }\n", $start) - $start);
        preg_match_all("/echo\\s+(.+?);/s", $body, $echoes);
        self::assertNotSame(array(), $echoes[1]);
        foreach ($echoes[1] as $expression) {
            $without_escaped = preg_replace("/esc_html__\\('(?:[^'\\\\]|\\\\.)*', 'supcheckout'\\)|esc_html\\(self::auto_renewal_paused_message\\(\\)\\)/", '', $expression);
            self::assertDoesNotMatchRegularExpression("/__\\(|\\$/", (string) $without_escaped, 'only esc_html__() copy and literal markup are echoed: ' . $expression);
        }
    }

    public function test_class_is_final_with_only_the_frozen_public_static_boundary(): void {
        $reflection = new ReflectionClass(Presentation::class);
        $public = array_map(static function (ReflectionMethod $method) {
            return $method->getName();
        }, $reflection->getMethods(ReflectionMethod::IS_PUBLIC));
        sort($public);

        self::assertTrue($reflection->isFinal());
        self::assertSame(array(
            'add_custom_data_panel',
            'add_custom_data_tab',
            'add_custom_product_type',
            'auto_renewal_paused_message',
            'custom_product_types',
            'display_custom_data_in_cart',
            'display_custom_field_on_frontend',
            'filter_account_orders_columns',
            'filter_account_orders_query',
            'map_custom_product_class',
            'register_product_class',
            'render_account_order_details',
            'render_account_order_type',
            'render_account_orders_filter',
            'render_account_subscription_status',
            'render_admin_order_summary',
            'render_admin_summary',
            'render_auto_renewal_paused_notice',
            'render_subscription_badge',
            'restrict_mixed_cart_products',
            'save_custom_data_to_order_items',
            'save_custom_field_data',
        ), $public);
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            self::assertTrue($method->isStatic(), $method->getName() . ' must remain static.');
        }
    }

    private function subscriptionOrder(): \WC_Order {
        $order = new \WC_Order();
        $order->meta = array(
            '_upay_subscription_plan' => 'monthly',
            '_upay_subscription_interval' => 2,
            '_upay_subscription_status' => 'active',
            'UPayments_AutoDeduction' => 'no',
        );
        return $order;
    }

    private function noticeOutput(): string {
        ob_start();
        Presentation::render_auto_renewal_paused_notice();
        return (string) ob_get_clean();
    }

    private function accountOutput(\WC_Order $order): string {
        ob_start();
        Presentation::render_account_order_details($order);
        return ob_get_clean();
    }
}
