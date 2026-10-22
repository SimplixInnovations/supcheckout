<?php

namespace Simplixi\SUPCheckout\Tests\Admin;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Simplixi\SUPCheckout\Admin\GatewaySettings;

/**
 * A2-01: the stored UPayments API key (a live bearer credential) is write-only.
 *
 * - The settings screen renders the `api_key` field empty, with a "saved" statement tied to
 *   it by aria-describedby (the SP1 SecretInput pattern: a saved secret is never in the DOM).
 * - A blank submitted key keeps the stored key; `api_key_missing` applies only when no key
 *   is stored.
 * - The WooCommerce REST read (`wc/v3/payment_gateways/<id>`) never returns the value, and a
 *   REST write that sends it back blank keeps the stored key.
 *
 * The option name `woocommerce_upayments_settings` and the field key `api_key` are protected
 * and unchanged. Separate processes: the WooCommerce settings API stand-in is a global class.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class GatewayApiKeyWriteOnlyTest extends TestCase {
    private const SECRET = 'SENTINEL_KEY_123';

    protected function setUp(): void {
        \supcheckout_test_reset_gateway_settings();
        \supcheckout_test_reset_wp_options();
    }

    public function test_a_blank_submitted_key_keeps_the_stored_key(): void {
        foreach (array(array(), array('woocommerce_upayments_api_key' => ''), array('woocommerce_upayments_api_key' => '   ')) as $post) {
            $kept = GatewaySettings::keep_saved_api_key($post, 'upayments', 'stored\\key');
            self::assertSame('stored\\\\key', $kept['woocommerce_upayments_api_key'], 'the stored key is posted back slashed, as WordPress posts it');
            self::assertFalse(GatewaySettings::prepare_post_data($kept, 'upayments')['api_key_missing']);
        }
    }

    public function test_a_new_key_replaces_the_stored_one_and_no_stored_key_is_still_missing(): void {
        $post = array('woocommerce_supcheckout_api_key' => 'new-key');
        self::assertSame($post, GatewaySettings::keep_saved_api_key($post, 'supcheckout', 'stored-key'));

        foreach (array('', '   ', null, array('x')) as $stored) {
            $kept = GatewaySettings::keep_saved_api_key(array('woocommerce_upayments_api_key' => ''), 'upayments', $stored);
            self::assertTrue(GatewaySettings::prepare_post_data($kept, 'upayments')['api_key_missing'], 'api_key_missing applies when no key is stored');
        }
    }

    public function test_the_settings_screen_never_renders_the_stored_key(): void {
        $gateway = self::gateway(array('api_key' => self::SECRET, 'title' => 'UPayments'));
        $fields = GatewaySettings::fields('upayments', 'UPayments', 'Provider description');

        $html = $gateway->generate_password_html('api_key', $fields['api_key']);

        self::assertStringNotContainsString(self::SECRET, $html, 'the stored key is never in the page');
        self::assertStringContainsString('type="password"', $html);
        self::assertStringContainsString('value=""', $html);
        self::assertStringContainsString('autocomplete="new-password"', $html);
        self::assertStringContainsString('id="woocommerce_upayments_api_key-saved"', $html);
        self::assertStringContainsString('aria-describedby="woocommerce_upayments_api_key-saved"', $html);
        self::assertStringContainsString('A key is saved. For security it is never shown. Leave this field empty to keep it, or enter a new key to replace it.', $html);
        self::assertSame(self::SECRET, $gateway->settings['api_key'], 'the loaded settings are restored after rendering');
        self::assertSame(self::SECRET, $gateway->get_option('api_key'));
    }

    public function test_with_no_stored_key_the_field_keeps_its_help_and_no_saved_statement(): void {
        $gateway = self::gateway(array('title' => 'UPayments'));
        $fields = GatewaySettings::fields('upayments', 'UPayments', 'Provider description');

        $html = $gateway->generate_password_html('api_key', $fields['api_key']);

        self::assertStringNotContainsString('A key is saved', $html);
        self::assertStringContainsString('value=""', $html);
        self::assertStringContainsString('Copy/paste values from UPayments dashboard', $html);
    }

    public function test_other_password_fields_render_as_woocommerce_renders_them(): void {
        $gateway = self::gateway(array('other_secret' => 'visible-by-design'));

        $html = $gateway->generate_password_html('other_secret', array('title' => 'Other'));

        self::assertStringContainsString('value="visible-by-design"', $html);
    }

    public function test_the_rest_read_blanks_the_key_for_our_gateway_only(): void {
        $data = array('id' => 'upayments', 'settings' => array('api_key' => array('id' => 'api_key', 'type' => 'password', 'value' => self::SECRET), 'title' => array('value' => 'UPayments')));

        $ours = GatewaySettings::mask_rest_api_key(self::response($data), (object) array('id' => 'upayments'));
        $GLOBALS['supcheckout_test_options'][\Simplixi\SUPCheckout\Takeover\TakeoverState::OPTION] = (string) json_encode(array('status' => 'cut_over', 'cut_over_at' => 1, 'cursor' => 0, 'totals' => array()));
        \Simplixi\SUPCheckout\Takeover\TakeoverState::flush_cache();
        $after_cutover = GatewaySettings::mask_rest_api_key(self::response($data), (object) array('id' => 'supcheckout'));
        $theirs = GatewaySettings::mask_rest_api_key(self::response($data), (object) array('id' => 'stripe'));

        self::assertSame('', $ours->get_data()['settings']['api_key']['value']);
        self::assertSame('UPayments', $ours->get_data()['settings']['title']['value']);
        self::assertSame('', $after_cutover->get_data()['settings']['api_key']['value']);
        self::assertSame(self::SECRET, $theirs->get_data()['settings']['api_key']['value'], 'another gateway is untouched');
        self::assertStringNotContainsString(self::SECRET, (string) json_encode($ours->get_data()));
    }

    public function test_a_rest_write_that_sends_the_key_back_blank_keeps_the_stored_key(): void {
        $GLOBALS['supcheckout_test_options']['woocommerce_upayments_settings'] = array('api_key' => self::SECRET, 'enabled' => 'yes');
        $gateway = new class {
            public $id = 'upayments';
            public function get_option_key() { return 'woocommerce_upayments_settings'; }
        };

        $kept = GatewaySettings::keep_saved_api_key_on_rest_save(array('api_key' => '', 'enabled' => 'no'), $gateway);
        $replaced = GatewaySettings::keep_saved_api_key_on_rest_save(array('api_key' => 'new-key', 'enabled' => 'yes'), $gateway);

        self::assertSame(array('api_key' => self::SECRET, 'enabled' => 'no'), $kept);
        self::assertSame(array('api_key' => 'new-key', 'enabled' => 'yes'), $replaced);
    }

    /**
     * The get_data()/set_data() surface of WP_HTTP_Response (the shared REST stub has no setter).
     *
     * @param array<string, mixed> $data
     */
    private static function response(array $data): object {
        return new class($data) {
            /** @var mixed */
            private $data;
            /** @param mixed $data */
            public function __construct($data) { $this->data = $data; }
            /** @return mixed */
            public function get_data() { return $this->data; }
            /** @param mixed $data */
            public function set_data($data): void { $this->data = $data; }
        };
    }

    /**
     * A Gateway whose WC_Settings_API is a faithful stand-in for WooCommerce 10.8's
     * get_option(), generate_text_html() and generate_password_html().
     *
     * @param array<string, string> $settings Loaded settings.
     */
    private static function gateway(array $settings): \Simplixi\SUPCheckout\Gateway\Gateway {
        if (!class_exists('WC_Payment_Gateway', false)) {
            eval(<<<'PHP'
class WC_Payment_Gateway {
    public $id = 'upayments';
    public $plugin_id = 'woocommerce_';
    public $settings = array();
    public $form_fields = array();
    public function init_settings() {}
    public function get_form_fields() { return $this->form_fields; }
    public function get_field_key($key) { return $this->plugin_id . $this->id . '_' . $key; }
    public function get_option($key, $empty_value = null) {
        if (!isset($this->settings[$key])) {
            $this->settings[$key] = '';
        }
        return $this->settings[$key];
    }
    public function get_tooltip_html($data) {
        $tip = true === $data['desc_tip'] ? $data['description'] : (!empty($data['desc_tip']) ? $data['desc_tip'] : '');
        return $tip === '' ? '' : '<span class="woocommerce-help-tip" data-tip="' . esc_attr($tip) . '"></span>';
    }
    public function get_description_html($data) {
        if (true === $data['desc_tip']) { $description = ''; }
        elseif (!empty($data['desc_tip'])) { $description = $data['description']; }
        elseif (!empty($data['description'])) { $description = $data['description']; }
        else { $description = ''; }
        // WordPress's wp_kses_post() keeps <span> and its id (a global attribute); the shared
        // test stub of wp_kses_post() does not list <span>, so the stand-in allows it here.
        return $description ? '<p class="description">' . strip_tags((string) $description, '<a><abbr><b><br><code><em><i><strong><span>') . '</p>' . "\n" : '';
    }
    public function get_custom_attribute_html($data) {
        $html = array();
        foreach ((array) $data['custom_attributes'] as $attribute => $value) {
            $html[] = esc_attr($attribute) . '="' . esc_attr($value) . '"';
        }
        return implode(' ', $html);
    }
    public function generate_text_html($key, $data) {
        $field_key = $this->get_field_key($key);
        $data = array_merge(array('title' => '', 'disabled' => false, 'class' => '', 'css' => '', 'placeholder' => '', 'type' => 'text', 'desc_tip' => false, 'description' => '', 'custom_attributes' => array()), $data);
        return '<tr valign="top"><th scope="row" class="titledesc"><label for="' . esc_attr($field_key) . '">' . wp_kses_post($data['title']) . ' ' . $this->get_tooltip_html($data) . '</label></th>'
            . '<td class="forminp"><fieldset><legend class="screen-reader-text"><span>' . wp_kses_post($data['title']) . '</span></legend>'
            . '<input class="input-text regular-input ' . esc_attr($data['class']) . '" type="' . esc_attr($data['type']) . '" name="' . esc_attr($field_key) . '" id="' . esc_attr($field_key) . '" style="' . esc_attr($data['css']) . '" value="' . esc_attr($this->get_option($key)) . '" placeholder="' . esc_attr($data['placeholder']) . '" ' . $this->get_custom_attribute_html($data) . ' />'
            . $this->get_description_html($data) . '</fieldset></td></tr>';
    }
    public function generate_password_html($key, $data) {
        $data['type'] = 'password';
        return $this->generate_text_html($key, $data);
    }
}
PHP
            );
        }
        $gateway = new class extends \Simplixi\SUPCheckout\Gateway\Gateway {
            public function __construct() {}
        };
        $gateway->settings = $settings;
        return $gateway;
    }
}
