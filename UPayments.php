<?php
// Transitional bootstrap for installs activated before the main file became supcheckout.php.
// No plugin header on purpose: it is never listed as a second plugin. It moves this
// install's active entry to supcheckout.php once, then loads the real main file.
defined('ABSPATH') || exit;
require_once __DIR__ . '/src/Bootstrap/BasenameMigration.php';
\Simplixi\SUPCheckout\Bootstrap\BasenameMigration::run(__FILE__);
require_once __DIR__ . '/supcheckout.php';
