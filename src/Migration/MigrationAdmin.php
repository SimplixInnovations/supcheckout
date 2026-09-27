<?php
namespace Simplixi\SUPCheckout\Migration;

defined('ABSPATH') || exit;

/** WooCommerce admin operational surface for explicit Phase 9I migration. */
final class MigrationAdmin {
    const PAGE_SLUG = 'simplixpay-upayments-migration';
    const CAPABILITY = 'manage_woocommerce';
    const NONCE_ACTION = 'simplixpay_upayments_migration_run';
    const NONCE_FIELD = 'simplixpay_upayments_nonce';

    public static function register() {
        add_submenu_page(
            'woocommerce',
            __('SUPCheckout for UPayments Migration', 'supcheckout'),
            __('SUPCheckout Migration', 'supcheckout'),
            self::CAPABILITY,
            self::PAGE_SLUG,
            array(__CLASS__, 'render')
        );
    }

    public static function render() {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to run SUPCheckout migration tools.', 'supcheckout'));
        }

        $result = null;
        $error = null;
        $form = array(
            'user_ids' => '',
            'offset' => '0',
            'limit' => (string) MigrationBatch::DEFAULT_LIMIT,
            'migration_action' => 'preflight',
            'resume' => 'no',
        );

        $request_method = '';
        if (isset($_SERVER['REQUEST_METHOD']) && is_string($_SERVER['REQUEST_METHOD'])) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Server method bytes must remain exact for the fail-closed allowlist.
            $request_method = $_SERVER['REQUEST_METHOD'];
        }
        if ($request_method === 'POST') {
            check_admin_referer(self::NONCE_ACTION, self::NONCE_FIELD);
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The strict ID parser must reject, not normalize, malformed input.
            $form['user_ids'] = isset($_POST['user_ids']) && is_string($_POST['user_ids']) ? wp_unslash($_POST['user_ids']) : '';
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- strictInt() validates canonical decimal input without lossy normalization.
            $form['offset'] = isset($_POST['offset']) && is_string($_POST['offset']) ? wp_unslash($_POST['offset']) : '0';
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- strictInt() validates canonical decimal input without lossy normalization.
            $form['limit'] = isset($_POST['limit']) && is_string($_POST['limit']) ? wp_unslash($_POST['limit']) : (string) MigrationBatch::DEFAULT_LIMIT;
            $form['migration_action'] = 'preflight';
            if (isset($_POST['migration_action']) && is_string($_POST['migration_action'])) {
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- parseForm() applies an exact preflight/execute allowlist.
                $form['migration_action'] = wp_unslash($_POST['migration_action']);
            }
            $form['resume'] = isset($_POST['resume']) && $_POST['resume'] === 'yes' ? 'yes' : 'no';

            $request = self::parseForm($form);
            if (!$request['ok']) {
                $error = $request['reason'];
            } elseif ($form['migration_action'] === 'execute'
                && (!isset($_POST['confirm_execute']) || $_POST['confirm_execute'] !== 'yes')
            ) {
                $error = 'explicit_execute_confirmation_required';
            } else {
                $settings = MigrationSettings::resolve();
                if (empty($settings['ok'])) {
                    $error = $settings['reason'];
                } else {
                    $dry_run = ($form['migration_action'] !== 'execute');
                    $offset = $request['offset'];
                    $resume_info = null;
                    if (!empty($request['resume'])) {
                        $resume_info = MigrationBatch::resumeOffset(
                            $request['user_ids'],
                            $settings['api_key'],
                            $settings['is_test_mode'],
                            $dry_run
                        );
                        if (empty($resume_info['ok'])) {
                            $error = $resume_info['reason'];
                        } else {
                            $offset = $resume_info['offset'];
                        }
                    }

                    if ($error === null) {
                        $result = array(
                            'settings' => MigrationSettings::redact($settings),
                            'resume' => $resume_info,
                            'batch' => MigrationBatch::run(
                                $request['user_ids'],
                                $settings['api_key'],
                                $settings['is_test_mode'],
                                $dry_run,
                                $offset,
                                $request['limit']
                            ),
                        );
                    }
                }
            }
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('SUPCheckout for UPayments — Historical Identity Migration', 'supcheckout') . '</h1>';
        echo '<p>' . esc_html__('Run a bounded identity-nonmutating preflight first. Dry-run records only redacted operations-result checkpoints; execute mode creates only verified legacy provenance. Neither path contacts UPayments.', 'supcheckout') . '</p>';
        echo '<p><strong>' . esc_html__('Credentials are read from the existing UPayments gateway settings and are never displayed or submitted by this form.', 'supcheckout') . '</strong></p>';

        if ($error !== null) {
            // translators: %s: internal fail-closed migration rejection reason code.
            $error_message = sprintf(__('Migration request rejected: %s', 'supcheckout'), $error);
            echo '<div class="notice notice-error"><p>' . esc_html($error_message) . '</p></div>';
        }
        if (is_array($result)) {
            $encoded = function_exists('wp_json_encode') ? wp_json_encode($result, JSON_PRETTY_PRINT) : json_encode($result, JSON_PRETTY_PRINT);
            if (is_string($encoded)) {
                echo '<h2>' . esc_html__('Result', 'supcheckout') . '</h2>';
                echo '<pre style="max-width:100%;overflow:auto;background:#fff;padding:12px;border:1px solid #ccd0d4">' . esc_html($encoded) . '</pre>';
            }
        }

        echo '<form method="post" novalidate>';
        wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD);
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row"><label for="supcheckout-user-ids">' . esc_html__('User IDs', 'supcheckout') . '</label></th><td>';
        echo '<textarea id="supcheckout-user-ids" name="user_ids" rows="5" cols="60" class="large-text code" style="resize:none;" required>' . esc_textarea($form['user_ids']) . '</textarea>';
        echo '<p class="description">' . esc_html__('Comma or whitespace separated positive customer IDs. Maximum 500 IDs per submitted list.', 'supcheckout') . '</p></td></tr>';
        echo '<tr><th scope="row"><label for="supcheckout-offset">' . esc_html__('Explicit offset', 'supcheckout') . '</label></th><td>';
        echo '<input id="supcheckout-offset" name="offset" type="number" min="0" step="1" value="' . esc_attr($form['offset']) . '">';
        echo '<p class="description">' . esc_html__('Use an explicit offset to deliberately re-evaluate a known position. Leave at 0 when using durable resume.', 'supcheckout') . '</p></td></tr>';
        echo '<tr><th scope="row">' . esc_html__('Durable resume', 'supcheckout') . '</th><td>';
        echo '<label><input type="checkbox" name="resume" value="yes" ' . checked($form['resume'], 'yes', false) . '> ' . esc_html__('Resume from the first user without a matching durable operations-result checkpoint.', 'supcheckout') . '</label>';
        echo '<p class="description">' . esc_html__('Resume is credential/mode/list scoped and cannot be combined with a nonzero explicit offset.', 'supcheckout') . '</p></td></tr>';
        echo '<tr><th scope="row"><label for="supcheckout-limit">' . esc_html__('Batch limit', 'supcheckout') . '</label></th><td>';
        echo '<input id="supcheckout-limit" name="limit" type="number" min="1" max="' . esc_attr((string) MigrationBatch::MAX_LIMIT) . '" step="1" value="' . esc_attr($form['limit']) . '"></td></tr>';
        echo '<tr><th scope="row">' . esc_html__('Mode', 'supcheckout') . '</th><td>';
        echo '<label><input type="radio" name="migration_action" value="preflight" ' . checked($form['migration_action'], 'preflight', false) . '> ' . esc_html__('Dry-run preflight + result checkpoint', 'supcheckout') . '</label><br>';
        echo '<label><input type="radio" name="migration_action" value="execute" ' . checked($form['migration_action'], 'execute', false) . '> ' . esc_html__('Execute verified migrations', 'supcheckout') . '</label><br>';
        echo '<label><input type="checkbox" name="confirm_execute" value="yes"> ' . esc_html__('I explicitly confirm execute mode. This is required only when Execute is selected.', 'supcheckout') . '</label>';
        echo '</td></tr>';
        echo '</tbody></table>';
        submit_button(__('Run migration batch', 'supcheckout'));
        echo '</form></div>';
    }

    private static function parseForm($form) {
        if (!is_array($form)) {
            return array('ok' => false, 'reason' => 'form_malformed');
        }
        if (!isset($form['migration_action']) || ($form['migration_action'] !== 'preflight' && $form['migration_action'] !== 'execute')) {
            return array('ok' => false, 'reason' => 'action_invalid');
        }
        $parsed = MigrationBatch::parseUserIds(isset($form['user_ids']) ? $form['user_ids'] : null);
        if (!$parsed['ok']) {
            return array('ok' => false, 'reason' => $parsed['reason']);
        }
        $offset = self::strictInt(isset($form['offset']) ? $form['offset'] : null, true);
        if ($offset === null) {
            return array('ok' => false, 'reason' => 'invalid_offset');
        }
        $resume = isset($form['resume']) && $form['resume'] === 'yes';
        if ($resume && $offset !== 0) {
            return array('ok' => false, 'reason' => 'resume_with_offset_invalid');
        }
        $limit = self::strictInt(isset($form['limit']) ? $form['limit'] : null, false);
        if ($limit === null || $limit > MigrationBatch::MAX_LIMIT) {
            return array('ok' => false, 'reason' => 'invalid_limit');
        }
        return array(
            'ok' => true,
            'reason' => 'valid',
            'user_ids' => $parsed['user_ids'],
            'offset' => $offset,
            'limit' => $limit,
            'resume' => $resume,
        );
    }

    private static function strictInt($value, $allow_zero) {
        if (is_int($value)) {
            $parsed = $value;
        } elseif (is_string($value) && preg_match('/^(?:0|[1-9][0-9]*)\z/', $value) === 1) {
            if (strlen($value) > strlen((string) PHP_INT_MAX)
                || (strlen($value) === strlen((string) PHP_INT_MAX) && strcmp($value, (string) PHP_INT_MAX) > 0)
            ) {
                return null;
            }
            $parsed = (int) $value;
        } else {
            return null;
        }
        return $allow_zero ? ($parsed >= 0 ? $parsed : null) : ($parsed > 0 ? $parsed : null);
    }

    private function __construct() {
    }
}
