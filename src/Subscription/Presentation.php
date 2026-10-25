<?php

namespace Simplixi\SUPCheckout\Subscription;

use Simplixi\SUPCheckout\Subscription\Cron\Scheduler;
use Simplixi\SUPCheckout\Subscription\Helpers\Utils;
use Simplixi\SUPCheckout\Subscription\Scheduling\LifecycleScheduler;
use Simplixi\SUPCheckout\Takeover\Keys;

defined('ABSPATH') || exit;

/**
 * Inherited subscription product, admin and My Account presentation behavior.
 *
 * This class deliberately does not own Charge payloads, scheduler dispatch,
 * billing-attempt storage or subscription state mutation.
 */
final class Presentation {
    /** @return void */
    public static function register_product_class() {
        if (!class_exists('WooCommerce') || !class_exists('WC_Product_Simple')) {
            return;
        }
        require_once __DIR__ . '/WCProductCustomType.php';
    }

    /** @param array $types @return array */
    public static function add_custom_product_type($types) {
        $types['custom_type'] = __('Subscription Product', 'supcheckout');
        return $types;
    }

    /** @param string $classname @param string $product_type @return string */
    public static function map_custom_product_class($classname, $product_type) {
        if ($product_type === 'custom_type') {
            $classname = 'WCProductCustomType';
        }
        return $classname;
    }

    /** @return void */
    public static function custom_product_types() {
        if ('product' !== get_post_type()) {
            return;
        }
        ?>
        <script type='text/javascript'>
            jQuery( document ).ready( function() {
                jQuery( '.options_group.pricing' ).addClass( 'show_if_custom_type' );
                jQuery( '.inventory_options' ).addClass( 'show_if_custom_type' );
                jQuery( 'select#product-type' ).change();
                jQuery('.show_if_simple').addClass('show_if_custom_type');
            });
        </script>
        <?php
    }

    /** @param array $tabs @return array */
    public static function add_custom_data_tab($tabs) {
        $tabs['custom_settings'] = array(
            'label' => __('Custom Settings', 'supcheckout'),
            'target' => 'custom_product_data_panel',
            'class' => array('show_if_custom_type'),
            'priority' => 25,
        );
        return $tabs;
    }

    /** @return void */
    public static function add_custom_data_panel() {
        ?>
        <div id="custom_product_data_panel" class="panel woocommerce_options_panel hidden">
            <div class="options_group">
                <?php
                woocommerce_wp_text_input(array(
                    'id' => '_custom_field_id',
                    'label' => __('Custom Field', 'supcheckout'),
                    'placeholder' => __('Enter value here', 'supcheckout'),
                    'desc_tip' => 'true',
                    'description' => __('This is a description of the field.', 'supcheckout'),
                ));
                ?>
            </div>
        </div>
        <?php
    }

    /** @param mixed $post_id @return void */
    public static function save_custom_field_data($post_id) {
        if (!is_string($post_id) && !is_int($post_id)) {
            return;
        }
        $post_id = absint($post_id);
        if ($post_id <= 0) {
            return;
        }
        $nonce = isset($_POST['woocommerce_meta_nonce']) && is_string($_POST['woocommerce_meta_nonce'])
            ? sanitize_text_field(wp_unslash($_POST['woocommerce_meta_nonce']))
            : '';
        if ($nonce === '' || !wp_verify_nonce($nonce, 'woocommerce_save_data')) {
            return;
        }
        if (empty($_POST['post_ID'])
            || (!is_string($_POST['post_ID']) && !is_int($_POST['post_ID']))
            || absint($_POST['post_ID']) !== $post_id
        ) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        $custom_field_value = isset($_POST['_custom_field_id'])
            && is_string($_POST['_custom_field_id'])
            ? sanitize_text_field(wp_unslash($_POST['_custom_field_id']))
            : '';
        if ($custom_field_value !== '') {
            update_post_meta($post_id, '_custom_field_id', $custom_field_value);
        }
    }

    /** @return void */
    public static function display_custom_field_on_frontend() {
        global $product;
        if (!$product instanceof \WC_Product || !$product->is_type('custom_type')) {
            return;
        }
        $custom_data = get_post_meta($product->get_id(), '_custom_field_id', true);
        if (!empty($custom_data)) {
            echo '<div class="custom-product-info">';
            echo '<strong style="background: #ffcc00; padding: 5px 10px; border-radius: 3px;">' . esc_html($custom_data) . '</strong>';
            echo '</div>';
        }
    }

    /** @param array $item_data @param array $cart_item @return array */
    public static function display_custom_data_in_cart($item_data, $cart_item) {
        if (!isset($cart_item['product_id'])
            || (!is_string($cart_item['product_id']) && !is_int($cart_item['product_id']))
        ) {
            return $item_data;
        }
        $product_id = absint($cart_item['product_id']);
        if ($product_id <= 0) {
            return $item_data;
        }
        $custom_value = get_post_meta($product_id, '_custom_field_id', true);
        if (!empty($custom_value)) {
            $item_data[] = array(
                'key' => __('Special Feature', 'supcheckout'),
                'value' => $custom_value,
                'display' => '',
            );
        }
        return $item_data;
    }

    /** @return void */
    public static function save_custom_data_to_order_items($item, $cart_item_key, $values, $order) {
        if (!is_object($item)
            || !method_exists($item, 'add_meta_data')
            || !isset($values['product_id'])
            || (!is_string($values['product_id']) && !is_int($values['product_id']))
        ) {
            return;
        }
        $product_id = absint($values['product_id']);
        if ($product_id <= 0) {
            return;
        }
        $custom_value = get_post_meta($product_id, '_custom_field_id', true);
        if (!empty($custom_value)) {
            $item->add_meta_data(__('Special Feature', 'supcheckout'), $custom_value);
        }
    }

    /** @return void */
    public static function render_admin_order_summary($order) {
        if (!$order instanceof \WC_Order) {
            return;
        }
        foreach ($order->get_items('line_item') as $item) {
            if (!is_object($item) || !method_exists($item, 'get_product')) {
                continue;
            }
            $product = $item->get_product();
            if ($product instanceof \WC_Product && $product->get_type() === 'custom_type') {
                // Render directly: constructing a gateway here would register every
                // gateway hook a second time and duplicate admin and thank-you output.
                self::render_admin_summary($order);
                return;
            }
        }
    }

    /** @return void */
    public static function render_admin_summary($order) {
        if (!$order instanceof \WC_Order) {
            return;
        }
        $plan = Keys::read_order_meta($order, '_upay_subscription_plan');
        $interval = (int) Keys::read_order_meta($order, '_upay_subscription_interval');
        if (!is_string($plan) || $plan === '' || $plan === 'one_time' || $interval <= 0) {
            return;
        }
        $auto_deduction = Keys::read_order_meta($order, 'UPayments_AutoDeduction');
        $last_billed = Keys::read_order_meta($order, '_upay_last_billed_at');
        $started_at = $order->get_date_paid() ?: $order->get_date_completed() ?: $order->get_date_created();
        $started_at = self::date_time_or_null($started_at);
        if (!$started_at) {
            return;
        }
        $timezone = wp_timezone();
        $last_billed_dt = !empty($last_billed) ? self::date_time_or_null($last_billed, $timezone) : null;
        if (!empty($last_billed) && !$last_billed_dt) {
            return;
        }
        $next_billing_dt = Scheduler::getNextBillingDate($started_at, $plan, $interval);
        if (!$next_billing_dt) {
            return;
        }
        $raw_status = Keys::read_order_meta($order, '_upay_subscription_status');
        $raw_status = is_string($raw_status) ? $raw_status : '';
        $status_label = self::subscription_status_label($raw_status);
        if ($raw_status === 'active' || $raw_status === 'paused' || $raw_status === 'cancelled') {
            $status = '<span class="upay-status-' . esc_attr($raw_status) . '">' . esc_html($status_label) . '</span>';
        } else {
            $status = esc_html($status_label);
        }

        $plan_labels = self::subscription_plan_labels();
        $interval_labels = self::subscription_interval_labels();
        $plan_label = isset($plan_labels[$plan]) ? $plan_labels[$plan] : ucfirst($plan);
        $interval_label = isset($interval_labels[$plan][$interval])
            ? $interval_labels[$plan][$interval]
            : sprintf(
                // translators: %d: subscription billing interval multiplier.
                __('Every %d billing period(s)', 'supcheckout'),
                $interval
            );

        echo '<div class="upay-subscription-summary">';
        echo '<h4>' . esc_html__('Subscription Details', 'supcheckout') . '</h4>';
        if ($auto_deduction === 'no') {
            echo '<p><strong>' . esc_html__('Subscription Status', 'supcheckout') . ':</strong> ' . wp_kses_post($status) . '</p>';
        }
        echo '<p><strong>' . esc_html__('Plan', 'supcheckout') . ':</strong> ' . esc_html($plan_label) . '</p>';
        echo '<p><strong>' . esc_html__('Interval', 'supcheckout') . ':</strong> ' . esc_html($interval_label) . '</p>';
        if ($auto_deduction === 'yes' && empty($last_billed_dt)) {
            echo '<p><strong>' . esc_html__('Auto Deduction Order', 'supcheckout') . ':</strong> ' . esc_html__('Yes', 'supcheckout') . '</p>';
        } else {
            if ($raw_status !== 'cancelled' && !AutoDeductResultVerifier::can_verify_success()) {
                // Fix A1-01: no automatic charge will happen, so no "Next Billing Date". The
                // box mirrors the parent's note: when an earlier automatic charge may have
                // debited the customer, check the UPayments dashboard before collecting.
                echo '<p><strong>' . esc_html__('Automatic Renewal', 'supcheckout') . ':</strong> '
                    . (self::earlier_charge_unconfirmed($order)
                        ? esc_html__('Paused. An earlier automatic charge could not be confirmed: check the UPayments dashboard before you collect this renewal manually or send a payment link.', 'supcheckout')
                        : esc_html__('Paused. Collect this renewal manually or send the customer a payment link.', 'supcheckout'))
                    . '</p>';
            } elseif ($raw_status !== 'cancelled') {
                echo '<p><strong>' . esc_html__('Next Billing Date', 'supcheckout') . ':</strong> ' . esc_html(self::format_date_time($next_billing_dt)) . '</p>';
            }
            if (!empty($last_billed_dt)) {
                echo '<p><strong>' . esc_html__('Last Billed at', 'supcheckout') . ':</strong> ' . esc_html(self::format_date_time($last_billed_dt)) . '</p>';
            }
        }
        echo '</div>';
    }

    /**
     * Fix A1-01: tells store managers, on WooCommerce and Plugins screens, that automatic
     * subscription renewal charges are paused while "Enable Subscriptions" is on.
     *
     * @return void
     */
    public static function render_auto_renewal_paused_notice() {
        if (AutoDeductResultVerifier::can_verify_success() || !current_user_can('manage_woocommerce')) {
            return;
        }
        $settings = Keys::get_settings(array());
        if (!is_array($settings) || !isset($settings['enable_subscriptions']) || $settings['enable_subscriptions'] !== 'yes') {
            return;
        }
        if (!self::is_store_admin_screen()) {
            return;
        }
        echo '<div class="notice notice-warning"><p><strong>'
            . esc_html__('Automatic subscription renewals are paused.', 'supcheckout')
            . '</strong> '
            . esc_html(self::auto_renewal_paused_message())
            . '</p></div>';
    }

    /**
     * The paused statement shared by the admin notice and the "Enable Subscriptions"
     * setting description (fix A1-01). Unescaped: callers escape for their context.
     *
     * @return string
     */
    public static function auto_renewal_paused_message() {
        return __('SUPCheckout does not send automatic renewal charges until a verified UPayments capture contract is approved. Collect renewals manually or by payment link.', 'supcheckout');
    }

    /** @return mixed */
    public static function restrict_mixed_cart_products($passed, $product_id, $quantity, $domain) {
        if (!function_exists('WC') || !WC()->cart) {
            return $passed;
        }
        $product = wc_get_product($product_id);
        if (!$product) {
            return $passed;
        }
        $is_subscription_product = ($product->get_type() === 'custom_type');
        $cart_has_subscription = Utils::cartHasCustomType();
        $cart_has_normal = Utils::cartHasNormalProduct();
        if ($cart_has_subscription && !$is_subscription_product) {
            wc_add_notice(__('You can only add subscription products to the cart when a subscription item is present.', 'supcheckout'), 'error');
            return false;
        }
        if ($cart_has_normal && $is_subscription_product) {
            wc_add_notice(__('Subscription products cannot be added together with normal products. Please complete your current purchase first.', 'supcheckout'), 'error');
            return false;
        }
        return $passed;
    }

    /** @return void */
    public static function render_subscription_badge() {
        global $product;
        if (!$product instanceof \WC_Product || $product->get_type() !== 'custom_type') {
            return;
        }
        echo '<span class="upay-subscription-badge"><strong><span aria-hidden="true">🔁</span> ' . esc_html__('Subscription', 'supcheckout') . '</strong></span>';
    }

    /** @return void */
    public static function render_account_order_details($order) {
        if (!$order instanceof \WC_Order || !is_user_logged_in()) {
            return;
        }
        if ((int) $order->get_user_id() !== get_current_user_id()) {
            return;
        }
        if (Keys::read_order_meta($order, '_upay_subscription_status') === 'cancelled') {
            return;
        }
        $plan = Keys::read_order_meta($order, '_upay_subscription_plan');
        $interval = (int) Keys::read_order_meta($order, '_upay_subscription_interval');
        if (!is_string($plan) || $plan === '' || $interval <= 0) {
            return;
        }
        $started_at = $order->get_date_paid() ?: $order->get_date_completed() ?: $order->get_date_created();
        $started_at = self::date_time_or_null($started_at);
        if (!$started_at) {
            return;
        }
        $last_billed = Keys::read_order_meta($order, '_upay_last_billed_at');
        $last_billed_dt = !empty($last_billed) ? self::date_time_or_null($last_billed, wp_timezone()) : null;
        if (!empty($last_billed) && !$last_billed_dt) {
            return;
        }
        $next_billing_dt = Scheduler::getNextBillingDate($started_at, $plan, $interval);
        if (!$next_billing_dt) {
            return;
        }
        $plan_labels = self::subscription_plan_labels();
        $interval_labels = self::subscription_interval_labels();
        ?>
        <section class="woocommerce-subscription-details">
            <h2><?php esc_html_e('Subscription Details', 'supcheckout'); ?></h2>
            <table class="shop_table shop_table_responsive" style="border: 1px solid;">
                <tbody>
                    <tr><th style="border: 1px solid;"><?php esc_html_e('Plan', 'supcheckout'); ?></th><td style="border: 1px solid;"><?php echo esc_html(isset($plan_labels[$plan]) ? $plan_labels[$plan] : ucfirst($plan)); ?></td></tr>
                    <tr><th style="border: 1px solid;"><?php esc_html_e('Interval', 'supcheckout'); ?></th><td style="border: 1px solid;"><?php echo esc_html(isset($interval_labels[$plan][$interval]) ? $interval_labels[$plan][$interval] : $interval); ?></td></tr>
                    <tr><th style="border: 1px solid;"><?php esc_html_e('Started On', 'supcheckout'); ?></th><td style="border: 1px solid;"><?php echo esc_html(self::format_date_time($started_at)); ?></td></tr>
                    <?php if (Keys::read_order_meta($order, 'UPayments_AutoDeduction') !== 'yes') { ?>
                        <tr><th style="border: 1px solid;"><?php esc_html_e('Last Billed On', 'supcheckout'); ?></th><td style="border: 1px solid;"><?php echo esc_html($last_billed_dt ? self::format_date_time($last_billed_dt) : '-'); ?></td></tr>
                        <?php if (!AutoDeductResultVerifier::can_verify_success()) { ?>
                            <tr><th style="border: 1px solid;"><?php esc_html_e('Automatic Renewal', 'supcheckout'); ?></th><td style="border: 1px solid;"><?php esc_html_e('Paused. Renewals are not charged to your card automatically.', 'supcheckout'); ?></td></tr>
                        <?php } else { ?>
                            <tr><th style="border: 1px solid;"><?php esc_html_e('Next Billing Date', 'supcheckout'); ?></th><td style="border: 1px solid;"><?php echo esc_html($next_billing_dt->format('Y-m-d H:i:s')); ?></td></tr>
                        <?php } ?>
                    <?php } ?>
                </tbody>
            </table>
        </section>
        <?php
        if (Keys::read_order_meta($order, 'UPayments_AutoDeduction') === 'yes') {
            return;
        }
        $status = Keys::read_order_meta($order, '_upay_subscription_status') ?: 'active';
        $action = $status === 'paused' ? 'resume' : 'pause';
        $label = $status === 'paused'
            ? __('Resume Subscription', 'supcheckout')
            : __('Pause Subscription', 'supcheckout');
        $form_action = wc_get_account_endpoint_url('view-order') . $order->get_id();
        ?>
        <details class="upay-unsubscribe-confirmation">
            <summary class="button upay-unsubscribe-button"><?php esc_html_e('Unsubscribe', 'supcheckout'); ?></summary>
            <div class="upay-unsubscribe-confirmation__body" role="group" aria-label="<?php echo esc_attr(__('Confirm unsubscribe', 'supcheckout')); ?>">
                <p><?php esc_html_e('Are you sure you want to unsubscribe? This stops future scheduled renewals for this subscription.', 'supcheckout'); ?></p>
                <form method="post" class="upay-subscription-actions" action="<?php echo esc_url($form_action); ?>">
                    <input type="hidden" name="upay_action" value="unsubscribe" />
                    <input type="hidden" name="order_id" value="<?php echo esc_attr((string) $order->get_id()); ?>" />
                    <?php wp_nonce_field('upay_unsubscribe_' . $order->get_id(), '_wpnonce', false); ?>
                    <button type="submit" class="button upay-unsubscribe-confirm-button"><?php esc_html_e('Confirm unsubscribe', 'supcheckout'); ?></button>
                </form>
            </div>
        </details>
        <form method="post" class="upay-subscription-actions" action="<?php echo esc_url($form_action); ?>">
            <input type="hidden" name="upay_action" value="<?php echo esc_attr($action); ?>" />
            <input type="hidden" name="order_id" value="<?php echo esc_attr((string) $order->get_id()); ?>" />
            <?php wp_nonce_field('upay_' . $action . '_' . $order->get_id(), '_wpnonce', false); ?>
            <button type="submit" class="button upay-pause-resume-button"><?php echo esc_html($label); ?></button>
        </form>
        <?php
    }

    /** @return void */
    public static function render_account_orders_filter() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only account orders filter.
        $current = self::subscription_filter($_GET);
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only account pagination identity.
        $page_id = self::request_text($_GET, 'page_id');
        ?>
        <form method="get" class="upay-orders-filter" action="<?php echo esc_url(add_query_arg(null, null)); ?>">
            <?php if ($page_id !== '') { ?>
                <input type="hidden" name="page_id" value="<?php echo esc_attr($page_id); ?>">
            <?php } ?>
            <input type="hidden" name="orders" value="">
            <label for="subscription_filter"><?php echo esc_html__('Select Order Type:', 'supcheckout'); ?></label>
            <select id="subscription_filter" name="subscription_filter" onchange="this.form.submit()">
                <option value=""><?php echo esc_html__('All orders', 'supcheckout'); ?></option>
                <option value="active" <?php selected($current, 'active'); ?>><?php echo esc_html__('Active subscriptions', 'supcheckout'); ?></option>
                <option value="paused" <?php selected($current, 'paused'); ?>><?php echo esc_html__('Paused subscriptions', 'supcheckout'); ?></option>
                <option value="cancelled" <?php selected($current, 'cancelled'); ?>><?php echo esc_html__('Cancelled subscriptions', 'supcheckout'); ?></option>
            </select>
        </form>
        <?php
    }

    /** @param array $args @return array */
    public static function filter_account_orders_query($args) {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only account orders query filter.
        $filter = self::subscription_filter($_GET);
        if ($filter === '') {
            return $args;
        }
        if (!isset($args['meta_query']) || !is_array($args['meta_query'])) {
            $args['meta_query'] = array();
        }
        $args['meta_query'][] = Keys::meta_clause_any(array('key' => '_upay_subscription_status', 'value' => $filter));
        return $args;
    }

    /** @param array $columns @return array */
    public static function filter_account_orders_columns($columns) {
        $new_columns = array();
        foreach ($columns as $key => $label) {
            $new_columns[$key] = $label;
            if ($key === 'order-status') {
                $new_columns['order_type'] = __('Type', 'supcheckout');
                $new_columns['order_status'] = __('Status', 'supcheckout');
            }
        }
        return $new_columns;
    }

    /** @return void */
    public static function render_account_order_type($order) {
        if (!$order instanceof \WC_Order) {
            return;
        }
        echo Keys::read_order_meta($order, 'UPayments_AutoDeduction') === 'yes'
            ? esc_html__('Auto Deduction', 'supcheckout')
            : esc_html__('Regular', 'supcheckout');
    }

    /** @return void */
    public static function render_account_subscription_status($order) {
        if (!$order instanceof \WC_Order) {
            return;
        }
        $status = Keys::read_order_meta($order, '_upay_subscription_status');
        if (!is_string($status) || $status === '') {
            echo '—';
            return;
        }
        echo '<span class="upay-status upay-status-' . esc_attr($status) . '">' . esc_html(self::subscription_status_label($status)) . '</span>';
    }

    /** @return array<string, string> */
    private static function subscription_plan_labels() {
        return array(
            'daily' => __('Daily', 'supcheckout'),
            'weekly' => __('Weekly', 'supcheckout'),
            'monthly' => __('Monthly', 'supcheckout'),
            'quarterly' => __('Quarterly', 'supcheckout'),
            'yearly' => __('Yearly', 'supcheckout'),
        );
    }

    /** @return array<string, array<int, string>> */
    private static function subscription_interval_labels() {
        return array(
            'daily' => array(1 => __('Every Day', 'supcheckout')),
            'weekly' => array(
                1 => __('Every Week', 'supcheckout'),
                2 => __('Every 2 Weeks', 'supcheckout'),
                3 => __('Every 3 Weeks', 'supcheckout'),
            ),
            'monthly' => array(
                1 => __('Every Month', 'supcheckout'),
                2 => __('Every 2 Months', 'supcheckout'),
            ),
            'quarterly' => array(
                1 => __('Every Quarter', 'supcheckout'),
                2 => __('Every 2 Quarters', 'supcheckout'),
                3 => __('Every 3 Quarters', 'supcheckout'),
            ),
            'yearly' => array(1 => __('Every Year', 'supcheckout')),
        );
    }

    /**
     * The parent's paused note decides; before the parent has one, the read-only billing
     * history lookup does (it fails closed).
     *
     * @param \WC_Order $order Subscription parent.
     * @return bool
     */
    private static function earlier_charge_unconfirmed($order) {
        $flag = LifecycleScheduler::paused_note_flag($order);
        if ($flag !== '') {
            return $flag === LifecycleScheduler::PAUSED_NOTE_UNCONFIRMED;
        }
        return LifecycleScheduler::has_unconfirmed_charge($order);
    }

    /** @return bool True on a WooCommerce admin screen or the Plugins screen. */
    private static function is_store_admin_screen() {
        if (!function_exists('get_current_screen')) {
            return false;
        }
        $screen = get_current_screen();
        // @phpstan-ignore isset.property (the global current screen can be any object another plugin set, so a missing id must read as no screen)
        $screen_id = is_object($screen) && isset($screen->id) ? $screen->id : null;
        if (!is_string($screen_id)) {
            return false;
        }
        if ($screen_id === 'plugins') {
            return true;
        }
        return function_exists('wc_get_screen_ids') && in_array($screen_id, (array) wc_get_screen_ids(), true);
    }

    /** @param string $status @return string */
    private static function subscription_status_label($status) {
        $labels = array(
            'active' => __('Active', 'supcheckout'),
            'paused' => __('Paused', 'supcheckout'),
            'cancelled' => __('Cancelled', 'supcheckout'),
        );
        return isset($labels[$status]) ? $labels[$status] : ucfirst($status);
    }

    /** @param \DateTimeInterface|null $date_time @return string */
    private static function format_date_time($date_time) {
        if (!$date_time instanceof \DateTimeInterface) {
            return '';
        }

        $fallback_format = 'Y-m-d H:i:s';
        if (!function_exists('get_option') || !function_exists('wp_date')) {
            return $date_time->format($fallback_format);
        }

        $date_format = get_option('date_format');
        $time_format = get_option('time_format');
        $date_format = is_string($date_format) && $date_format !== '' ? $date_format : 'Y-m-d';
        $time_format = is_string($time_format) && $time_format !== '' ? $time_format : 'H:i:s';

        return wp_date(
            $date_format . ' ' . $time_format,
            $date_time->getTimestamp(),
            $date_time->getTimezone()
        );
    }

    /** @param mixed $value @param \DateTimeZone|null $timezone @return \DateTime|null */
    private static function date_time_or_null($value, $timezone = null) {
        if ($value instanceof \DateTime) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return new \DateTime($value, $timezone);
        } catch (\Exception $exception) {
            return null;
        }
    }

    /** @param array $request @param string $key @param string $default @return string */
    private static function request_text($request, $key, $default = '') {
        if (!isset($request[$key]) || !is_string($request[$key])) {
            return $default;
        }
        return sanitize_text_field(wp_unslash($request[$key]));
    }

    /** @param array $request @return string */
    private static function subscription_filter($request) {
        if (!isset($request['subscription_filter']) || !is_string($request['subscription_filter'])) {
            return '';
        }
        $raw_filter = wp_unslash($request['subscription_filter']);
        $filter = sanitize_key($raw_filter);
        if ($filter !== $raw_filter) {
            return '';
        }
        return in_array($filter, array('active', 'paused', 'cancelled'), true) ? $filter : '';
    }
}
