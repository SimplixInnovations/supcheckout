<?php

namespace Simplixi\SUPCheckout\Tests\Governance;

use PHPUnit\Framework\TestCase;

final class ReleaseReadinessEnterpriseContractTest extends TestCase {
    public function test_destructive_subscription_action_uses_owned_inline_confirmation(): void {
        $source = self::read_repository_file('src/Subscription/Presentation.php');

        self::assertStringNotContainsString('confirm(', $source);
        self::assertStringContainsString('upay-unsubscribe-confirmation', $source);
        self::assertStringContainsString("esc_html_e('Confirm unsubscribe', 'supcheckout')", $source);
    }

    public function test_customer_facing_subscription_and_checkout_copy_is_localized(): void {
        $presentation = self::read_repository_file('src/Subscription/Presentation.php');
        $template = self::read_repository_file('templates/new-design-form.php');

        foreach (array(
            '<strong>Subscription Status:</strong>',
            '<strong>Plan:</strong>',
            '<strong>Interval:</strong>',
            '<strong>Auto Deduction Order:</strong>',
            '<strong>Next Billing Date:</strong>',
            '<strong>Last Billed at:</strong>',
            '<label for="subscription_filter">Select Order Type:</label>',
            '<option value="">All orders</option>',
            '>Active subscriptions</option>',
            '>Paused subscriptions</option>',
            '>Cancelled subscriptions</option>',
            '<strong>🔁 Subscription</strong>',
        ) as $raw_copy) {
            self::assertStringNotContainsString($raw_copy, $presentation, $raw_copy);
        }

        self::assertStringContainsString("__('Subscription Status', 'supcheckout')", $presentation);
        self::assertStringContainsString("__('Select Order Type:', 'supcheckout')", $presentation);
        self::assertStringContainsString("__('All orders', 'supcheckout')", $presentation);
        self::assertStringContainsString("__('Confirm unsubscribe', 'supcheckout')", $presentation);

        self::assertStringNotContainsString(
            '>For faster and more secure checkout. Save your card details.',
            $template
        );
        self::assertStringContainsString(
            "esc_html_e('For faster and more secure checkout. Save your card details.', 'supcheckout')",
            $template
        );
    }

    public function test_classic_checkout_javascript_copy_is_server_localized(): void {
        $gateway = self::read_repository_file('UPayments.php');
        $assets = self::read_repository_file('src/Gateway/CheckoutAssets.php');
        $new_design = self::read_repository_file('assets/js/new-upay.js');
        $subscription = self::read_repository_file('assets/js/subscription-checkout.js');

        foreach (array($gateway, $assets) as $source) {
            self::assertStringContainsString("'supCheckoutI18n'", $source);
            self::assertStringContainsString("'loginRequired'", $source);
            self::assertStringContainsString("'i18n'", $source);
            self::assertStringContainsString("'oneTime'", $source);
            self::assertStringContainsString("'selectInterval'", $source);
        }

        self::assertStringContainsString('supCheckoutI18n.loginRequired', $new_design);
        self::assertStringContainsString('wcUser.i18n', $subscription);
    }

    public function test_blocks_checkout_uses_server_localized_interaction_copy(): void {
        $php = self::read_repository_file('includes/class-wc-gateway-upayments-blocks.php');
        $js = self::read_repository_file('assets/js/upayments-block.js');

        self::assertStringContainsString("'saved_card_selected'", $php);
        self::assertStringContainsString("'interval_labels'", $php);
        self::assertStringContainsString('translation.saved_card_selected', $js);
        self::assertStringContainsString('translation.interval_labels', $js);
        self::assertStringNotContainsString('showToast("Saved card selected")', $js);
    }


    public function test_classic_payment_icons_are_decorative_when_visible_labels_exist(): void {
        $new_template = self::read_repository_file('templates/new-design-form.php');
        $old_template = self::read_repository_file('templates/old-design-form.php');

        self::assertStringNotContainsString('alt="<?php echo esc_attr($value_string); ?>"', $new_template);
        self::assertStringNotContainsString('title="<?php echo esc_attr($value_string); ?>"', $new_template);
        self::assertStringNotContainsString('alt="' . '$value_attr' . '"', $old_template);
        self::assertStringNotContainsString('title="' . '$value_attr' . '"', $old_template);
    }

    public function test_remaining_runtime_ui_copy_uses_the_plugin_text_domain(): void {
        $gateway = self::read_repository_file('UPayments.php');
        $migration = self::read_repository_file('src/Migration/MigrationAdmin.php');

        self::assertStringNotContainsString('<span>Pay securely with <img', $gateway);
        self::assertStringContainsString("esc_html__('Pay securely with', 'supcheckout')", $gateway);
        self::assertStringContainsString(
            "__('Migration request rejected: %s', 'supcheckout')",
            $migration
        );
    }

    public function test_living_release_docs_narrow_the_core_provider_blocker(): void {
        $paths = array(
            'docs/project/START-HERE.md',
            'docs/project/PROJECT-STATUS.md',
            'docs/project/OWNER-HANDOFF.md',
            'docs/project/NEW-CHAT-HANDOFF.md',
            'docs/project/R6-COVERAGE-MATRIX.md',
            '.ai-architect/architecture-contract.yaml',
        );

        foreach ($paths as $path) {
            $content = self::read_repository_file($path);
            self::assertStringContainsString(
                'PRODUCTION_AUTH_CONTRACT_FOR_CHARGE_AND_TRACK_ID_STATUS',
                $content,
                $path . ' must record the narrowed first-release provider blocker'
            );
        }

        foreach (array(
            'docs/project/PROJECT-STATUS.md',
            'docs/project/NEW-CHAT-HANDOFF.md',
            'docs/project/OWNER-HANDOFF.md',
        ) as $path) {
            $content = self::read_repository_file($path);
            self::assertStringContainsString(
                'saved-card/token persistence: FUTURE FEATURE GATE',
                $content,
                $path
            );
            self::assertStringContainsString(
                'auto-deduct capture/cycle identity: FUTURE RECURRING GATE',
                $content,
                $path
            );
        }
    }

    public function test_premium_design_and_ux_governance_is_durable(): void {
        $design = self::read_repository_file('DESIGN.md');
        $ux = self::read_repository_file('UX-CONTRACT.md');
        $manifest = self::read_repository_file('premium-ui.json');

        self::assertStringContainsString('SUPCheckout for UPayments Design System', $design);
        self::assertStringContainsString('WooCommerce-native payment instrument panel', $design);
        self::assertStringContainsString('## Canonical UI Map', $ux);
        self::assertStringContainsString('| Select/Listbox | Native HTML/WooCommerce select |', $ux);
        self::assertStringContainsString('native JS dialogs are prohibited', $ux);
        self::assertStringContainsString('"profile": "product-admin"', $manifest);
        self::assertStringContainsString('"canonicalMap": "UX-CONTRACT.md"', $manifest);
    }

    private static function read_repository_file(string $path): string {
        $root = dirname(__DIR__, 3);
        $full_path = $root . '/' . $path;
        self::assertFileExists($full_path, 'Required release-readiness file is missing: ' . $path);

        $content = file_get_contents($full_path);
        self::assertIsString($content, 'Required release-readiness file is unreadable: ' . $path);

        return $content;
    }
}
