<?php
/**
 * Certification-only must-use plugin: offers SUPCHECKOUT_CERT_UPDATE_PACKAGE as an
 * available update for the pre-rename supcheckout/UPayments.php basename, the way an
 * update server would, so the bulk / one-click upgrader path can be exercised.
 *
 * Copied into wp-content/mu-plugins by tests/integration/in-place-update-paths.sh
 * and removed right after. Never shipped.
 */

defined('ABSPATH') || exit;

add_filter('site_transient_update_plugins', static function ($value) {
    $package = getenv('SUPCHECKOUT_CERT_UPDATE_PACKAGE');
    if (!is_string($package) || $package === '' || !is_file($package)) {
        return $value;
    }
    $value = is_object($value) ? $value : new stdClass();
    $value->response = isset($value->response) && is_array($value->response) ? $value->response : array();
    $value->response['supcheckout/UPayments.php'] = (object) array(
        'id' => 'supcheckout',
        'slug' => 'supcheckout',
        'plugin' => 'supcheckout/UPayments.php',
        'new_version' => '0.1.1',
        'url' => '',
        'package' => $package,
    );
    return $value;
});
