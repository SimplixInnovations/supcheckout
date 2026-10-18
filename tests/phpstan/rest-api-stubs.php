<?php

/**
 * Development-only WordPress REST API symbols for the bounded PHPStan scope (0c Task 7).
 */

class WP_Error {
    /**
     * @param string|int $code
     * @param string     $message
     * @param mixed      $data
     */
    public function __construct($code = '', $message = '', $data = '') {}

    /** @return string|int */
    public function get_error_code() {}

    /** @return string */
    public function get_error_message() {}

    /** @return mixed */
    public function get_error_data() {}
}

class WP_REST_Server {}

class WP_REST_Response {
    /**
     * @param mixed                 $data
     * @param int                   $status
     * @param array<string, string> $headers
     */
    public function __construct($data = null, $status = 200, $headers = array()) {}

    /**
     * @param string $key
     * @param string $value
     * @param bool   $replace
     * @return void
     */
    public function header($key, $value, $replace = true) {}

    /** @return array<string, string> */
    public function get_headers() {}

    /** @return int */
    public function get_status() {}

    /** @return mixed */
    public function get_data() {}
}

class WP_REST_Request {
    /** @return string */
    public function get_method() {}

    /** @return string */
    public function get_route() {}

    /**
     * @param string $key
     * @return string|null
     */
    public function get_header($key) {}

    /**
     * @param string $key
     * @return mixed
     */
    public function get_param($key) {}

    /** @return array<string, mixed> */
    public function get_params() {}

    /** @return array<array-key, mixed>|null */
    public function get_json_params() {}

    /** @return array<string, mixed> */
    public function get_body_params() {}

    /** @return array<string, mixed> */
    public function get_query_params() {}
}

/**
 * @param string                                          $route_namespace
 * @param string                                          $route
 * @param array<int|string, mixed>                        $args
 * @param bool                                            $override
 * @return bool
 */
function register_rest_route($route_namespace, $route, $args = array(), $override = false) {}

/** @return int */
function rest_authorization_required_code() {}

/**
 * @param string $path
 * @return string
 */
function rest_url($path = '') {}
