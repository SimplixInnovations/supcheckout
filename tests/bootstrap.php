<?php

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

require __DIR__ . '/support/wordpress-option-store.php';
require __DIR__ . '/support/wordpress-http.php';
require __DIR__ . '/support/wordpress-availability.php';
require __DIR__ . '/support/wordpress-gateway-settings.php';
require __DIR__ . '/support/wordpress-public-order-status.php';
require __DIR__ . '/support/wordpress-migration-bootstrap.php';
require __DIR__ . '/support/wordpress-migration-admin.php';
require __DIR__ . '/support/wordpress-migration-core.php';
require __DIR__ . '/support/wordpress-subscription-presentation.php';
require __DIR__ . '/support/wordpress-plugin-registry.php';
require dirname(__DIR__) . '/vendor/autoload.php';
