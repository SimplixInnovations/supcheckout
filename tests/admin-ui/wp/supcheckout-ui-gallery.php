<?php
/**
 * Plugin Name: SUPCheckout UI gallery (test only)
 * Description: Test-only mu-plugin for the Admin UI Certification browser lane. Never shipped.
 *
 * Inert unless wp-config.php defines SUPCHECKOUT_UI_GALLERY as true. Then it:
 * - registers two hidden pages gated on manage_woocommerce:
 *   - admin.php?page=supcheckout-ui-gallery renders every specimen from the
 *     test-only gallery build (tests/admin-ui/gallery/build);
 *   - admin.php?page=supcheckout-ui-shell mounts the shipped app bundle
 *     through the real Simplixi\SUPCheckout\Admin\AdminAssets;
 * - forces RTL for users whose locale is Arabic, without a language pack (the
 *   RTL Tester technique: $wp_locale and $wp_styles text direction);
 * - on the gallery page only, lets `sup_admin_color=<core scheme>` choose the
 *   admin color scheme for that request, so scheme tests never mutate users.
 *
 * Everything lives in closures inside one immediately invoked function, so the
 * file declares no global function, class or constant: PHP hoists top-level
 * declarations past an early `return`, and a copied file must stay inert
 * (tests/unit/Admin/GalleryMuPluginInertTest.php).
 */

defined('ABSPATH') || exit;

if (!defined('SUPCHECKOUT_UI_GALLERY') || true !== SUPCHECKOUT_UI_GALLERY) {
    return;
}

(static function () {
    $gallery_slug = 'supcheckout-ui-gallery';
    $shell_slug = 'supcheckout-ui-shell';
    $handle = 'supcheckout-ui-gallery';
    $schemes = array('fresh', 'light', 'modern', 'blue', 'coffee', 'ectoplasm', 'midnight', 'ocean', 'sunrise');
    $plugin_file = WP_PLUGIN_DIR . '/supcheckout/supcheckout.php';

    $is_arabic_user = static function () {
        return is_user_logged_in() && 0 === strpos(get_user_locale(), 'ar');
    };

    // Idempotent, so it runs at several points of the request.
    $force_rtl = static function () use ($is_arabic_user) {
        if (!$is_arabic_user()) {
            return;
        }
        if (isset($GLOBALS['wp_locale']) && $GLOBALS['wp_locale'] instanceof WP_Locale) {
            $GLOBALS['wp_locale']->text_direction = 'rtl';
        }
        if (isset($GLOBALS['wp_styles']) && $GLOBALS['wp_styles'] instanceof WP_Styles) {
            $GLOBALS['wp_styles']->text_direction = 'rtl';
        }
    };
    add_action('after_setup_theme', $force_rtl, 0);
    add_action('init', $force_rtl, 0);
    add_action('admin_enqueue_scripts', $force_rtl, 0);

    // A language pack translates the core "ltr" (context "text direction")
    // string; wp.i18n.isRTL() reads that. Mirror it for the Arabic user.
    add_action('admin_enqueue_scripts', static function () use ($is_arabic_user) {
        if ($is_arabic_user()) {
            wp_add_inline_script('wp-i18n', 'wp.i18n.setLocaleData({"text direction\u0004ltr":["rtl"]});');
        }
    }, 1);

    $theme = static function () {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only test page view state.
        $value = isset($_GET['theme']) ? sanitize_key(wp_unslash($_GET['theme'])) : 'system';
        return in_array($value, array('light', 'dark', 'system'), true) ? $value : 'system';
    };

    $requested_scheme = static function () use ($gallery_slug, $schemes) {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only test page view state.
        if (!isset($_GET['page'], $_GET['sup_admin_color'])
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only test page view state.
            || $gallery_slug !== sanitize_key(wp_unslash($_GET['page']))
        ) {
            return null;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only test page view state.
        $scheme = sanitize_key(wp_unslash($_GET['sup_admin_color']));
        return in_array($scheme, $schemes, true) ? $scheme : null;
    };

    add_filter('get_user_option_admin_color', static function ($color) use ($requested_scheme) {
        $scheme = $requested_scheme();
        return null === $scheme ? $color : $scheme;
    });

    // On the gallery page only, `sup_core_commands=off` drops WordPress core's
    // command palette, so the browser lane can exercise the SUPCheckout
    // palette's own Mod+K binding, which yields whenever core's palette exists.
    add_action('admin_enqueue_scripts', static function () use ($gallery_slug) {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only test page view state.
        if (!isset($_GET['page'], $_GET['sup_core_commands'])
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only test page view state.
            || $gallery_slug !== sanitize_key(wp_unslash($_GET['page']))
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only test page view state.
            || 'off' !== sanitize_key(wp_unslash($_GET['sup_core_commands']))
        ) {
            return;
        }
        foreach (array('wp-core-commands', 'wp-commands') as $core_handle) {
            wp_dequeue_script($core_handle);
            wp_dequeue_style($core_handle);
        }
    }, PHP_INT_MAX);

    add_action('admin_menu', static function () use ($plugin_file, $shell_slug, $gallery_slug, $handle, $theme) {
        if (!class_exists('Simplixi\\SUPCheckout\\Admin\\AdminAssets')) {
            return;
        }
        $assets = new \Simplixi\SUPCheckout\Admin\AdminAssets($plugin_file);

        $shell = add_submenu_page(
            '',
            'SUPCheckout',
            '',
            'manage_woocommerce',
            $shell_slug,
            static function () use ($assets) {
                echo '<div class="wrap">';
                echo wp_kses($assets->root_markup(), array('div' => array('id' => true)));
                echo '</div>';
            }
        );
        if (is_string($shell)) {
            $assets->hook($shell);
        }

        $gallery = add_submenu_page(
            '',
            'SUPCheckout UI gallery',
            '',
            'manage_woocommerce',
            $gallery_slug,
            static function () use ($theme) {
                printf(
                    '<div class="wrap"><div id="supcheckout-ui-gallery-root" data-theme="%s"></div></div>',
                    esc_attr($theme())
                );
            }
        );
        if (!is_string($gallery)) {
            return;
        }

        add_action('admin_enqueue_scripts', static function ($hook_suffix) use ($gallery, $plugin_file, $handle) {
            if ($gallery !== $hook_suffix) {
                return;
            }
            $build = dirname($plugin_file) . '/tests/admin-ui/gallery/build/';
            $manifest = wp_json_file_decode($build . 'index.asset.json', array('associative' => true));
            if (!is_array($manifest) || !isset($manifest['dependencies'], $manifest['version'])) {
                wp_die(esc_html('The UI gallery build is missing: run npm run build:gallery.'));
            }
            $url = static function ($file) use ($plugin_file) {
                return plugins_url('tests/admin-ui/gallery/build/' . $file, $plugin_file);
            };
            wp_enqueue_style($handle, $url('index.css'), array(), $manifest['version']);
            wp_style_add_data($handle, 'rtl', 'replace');
            // --wp-admin-theme-color, where WordPress defines it, for the scheme fixture test.
            if (wp_style_is('wp-base-styles', 'registered')) {
                wp_enqueue_style('wp-base-styles');
            }
            wp_enqueue_script(
                $handle,
                $url('index.js'),
                $manifest['dependencies'],
                $manifest['version'],
                array('in_footer' => true)
            );
        });

        add_filter('admin_body_class', static function ($classes) use ($gallery) {
            return is_string($classes) && isset($GLOBALS['hook_suffix']) && $gallery === $GLOBALS['hook_suffix']
                ? $classes . ' supcheckout-admin-page'
                : $classes;
        });
    });
})();
