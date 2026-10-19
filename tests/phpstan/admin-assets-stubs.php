<?php

/** Development-only WordPress script/style registration symbols for AdminAssets. */

/** @return bool */
function wp_register_style($handle, $src, $deps = array(), $ver = false, $media = 'all') {}

/** @return bool */
function wp_style_add_data($handle, $key, $value) {}

/** @return bool */
function wp_set_script_translations($handle, $domain = 'default', $path = '') {}

/** @return string|false */
function load_script_textdomain($handle, $domain = 'default', $path = '') {}

/** @return bool */
function wp_add_inline_script($handle, $data, $position = 'after') {}

/** @return mixed */
function wp_json_file_decode($filename, $options = array()) {}
