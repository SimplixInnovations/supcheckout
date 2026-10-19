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
 */

defined('ABSPATH') || exit;

if (!defined('SUPCHECKOUT_UI_GALLERY') || true !== SUPCHECKOUT_UI_GALLERY) {
    return;
}

const SUPCHECKOUT_UI_GALLERY_SLUG = 'supcheckout-ui-gallery';
const SUPCHECKOUT_UI_SHELL_SLUG = 'supcheckout-ui-shell';
const SUPCHECKOUT_UI_GALLERY_HANDLE = 'supcheckout-ui-gallery';
const SUPCHECKOUT_UI_GALLERY_SCHEMES = array('fresh', 'light', 'modern', 'blue', 'coffee', 'ectoplasm', 'midnight', 'ocean', 'sunrise');

/** @return string Absolute path of the SUPCheckout main file inside wp-content/plugins. */
function supcheckout_ui_gallery_plugin_file() {
    return WP_PLUGIN_DIR . '/supcheckout/supcheckout.php';
}

/** @return bool Whether the current user's admin locale is right-to-left Arabic. */
function supcheckout_ui_gallery_is_arabic_user() {
    return is_user_logged_in() && 0 === strpos(get_user_locale(), 'ar');
}

/** Forces RTL for Arabic users; idempotent, so it can run at several points. */
function supcheckout_ui_gallery_force_rtl() {
    if (!supcheckout_ui_gallery_is_arabic_user()) {
        return;
    }
    if (isset($GLOBALS['wp_locale']) && $GLOBALS['wp_locale'] instanceof WP_Locale) {
        $GLOBALS['wp_locale']->text_direction = 'rtl';
    }
    if (isset($GLOBALS['wp_styles']) && $GLOBALS['wp_styles'] instanceof WP_Styles) {
        $GLOBALS['wp_styles']->text_direction = 'rtl';
    }
}
add_action('after_setup_theme', 'supcheckout_ui_gallery_force_rtl', 0);
add_action('init', 'supcheckout_ui_gallery_force_rtl', 0);
add_action('admin_enqueue_scripts', 'supcheckout_ui_gallery_force_rtl', 0);

/**
 * A language pack translates the core "ltr" (context "text direction") string;
 * wp.i18n.isRTL() reads that. Mirror it for the forced-RTL Arabic user.
 */
add_action('admin_enqueue_scripts', static function () {
    if (supcheckout_ui_gallery_is_arabic_user()) {
        wp_add_inline_script('wp-i18n', 'wp.i18n.setLocaleData({"text direction\u0004ltr":["rtl"]});');
    }
}, 1);

/** @return string The validated `theme` query preference. */
function supcheckout_ui_gallery_theme() {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only test page view state.
    $theme = isset($_GET['theme']) ? sanitize_key(wp_unslash($_GET['theme'])) : 'system';
    return in_array($theme, array('light', 'dark', 'system'), true) ? $theme : 'system';
}

/** @return string|null The validated `sup_admin_color` scheme on the gallery page, if any. */
function supcheckout_ui_gallery_requested_scheme() {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only test page view state.
    if (!isset($_GET['page'], $_GET['sup_admin_color'])
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only test page view state.
        || SUPCHECKOUT_UI_GALLERY_SLUG !== sanitize_key(wp_unslash($_GET['page']))
    ) {
        return null;
    }
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only test page view state.
    $scheme = sanitize_key(wp_unslash($_GET['sup_admin_color']));
    return in_array($scheme, SUPCHECKOUT_UI_GALLERY_SCHEMES, true) ? $scheme : null;
}

add_filter('get_user_option_admin_color', static function ($color) {
    $scheme = supcheckout_ui_gallery_requested_scheme();
    return null === $scheme ? $color : $scheme;
});

add_action('admin_menu', static function () {
    if (!class_exists('Simplixi\\SUPCheckout\\Admin\\AdminAssets')) {
        return;
    }
    $assets = new \Simplixi\SUPCheckout\Admin\AdminAssets(supcheckout_ui_gallery_plugin_file());

    $shell = add_submenu_page(
        '',
        'SUPCheckout',
        '',
        'manage_woocommerce',
        SUPCHECKOUT_UI_SHELL_SLUG,
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
        SUPCHECKOUT_UI_GALLERY_SLUG,
        static function () {
            printf(
                '<div class="wrap"><div id="supcheckout-ui-gallery-root" data-theme="%s"></div></div>',
                esc_attr(supcheckout_ui_gallery_theme())
            );
        }
    );
    if (!is_string($gallery)) {
        return;
    }

    add_action('admin_enqueue_scripts', static function ($hook_suffix) use ($gallery) {
        if ($gallery !== $hook_suffix) {
            return;
        }
        $build = dirname(supcheckout_ui_gallery_plugin_file()) . '/tests/admin-ui/gallery/build/';
        $manifest = wp_json_file_decode($build . 'index.asset.json', array('associative' => true));
        if (!is_array($manifest) || !isset($manifest['dependencies'], $manifest['version'])) {
            wp_die(esc_html('The UI gallery build is missing: run npm run build:gallery.'));
        }
        $url = static function ($file) {
            return plugins_url('tests/admin-ui/gallery/build/' . $file, supcheckout_ui_gallery_plugin_file());
        };
        wp_enqueue_style(SUPCHECKOUT_UI_GALLERY_HANDLE, $url('index.css'), array(), $manifest['version']);
        wp_style_add_data(SUPCHECKOUT_UI_GALLERY_HANDLE, 'rtl', 'replace');
        // --wp-admin-theme-color, where WordPress defines it, for the scheme fixture test.
        if (wp_style_is('wp-base-styles', 'registered')) {
            wp_enqueue_style('wp-base-styles');
        }
        wp_enqueue_script(
            SUPCHECKOUT_UI_GALLERY_HANDLE,
            $url('index.js'),
            $manifest['dependencies'],
            $manifest['version'],
            array('in_footer' => true)
        );
    });

    add_filter('admin_body_class', static function ($classes) use ($gallery) {
        return isset($GLOBALS['hook_suffix']) && $gallery === $GLOBALS['hook_suffix']
            ? $classes . ' supcheckout-admin-page'
            : $classes;
    });
});
