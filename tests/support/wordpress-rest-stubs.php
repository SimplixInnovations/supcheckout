<?php
/**
 * Minimal WordPress REST API stubs for the 0c Task 7 unit tests (tests/unit/Rest). Load with
 * require_once; every symbol is defined only when absent.
 *
 * - WP_Error, WP_REST_Request, WP_REST_Response and WP_REST_Server with the methods the
 *   routes use;
 * - register_rest_route() records each call in $GLOBALS['supcheckout_test_rest_routes'] and
 *   keeps the endpoints in $GLOBALS['supcheckout_test_rest_registry'];
 * - rest_do_request() dispatches like WP_REST_Server::dispatch(): route and method match,
 *   default params, the `required` check (rest_missing_callback_param), the type/enum
 *   validation and sanitization core runs for an arg with a `type` and no
 *   sanitize_callback (rest_invalid_param), the `rest_request_before_callbacks` filters,
 *   the permission_callback, the callback, and a WP_Error turned into a response. Like
 *   core, it does NOT apply `rest_post_dispatch`;
 * - supcheckout_test_rest_serve() is rest_do_request() followed by the `rest_post_dispatch`
 *   filters, as WP_REST_Server::serve_request() sends an HTTP response;
 * - filters are read from the add_filter() recorder ($GLOBALS['supcheckout_test_filter_calls'])
 *   and run in priority order with their accepted_args.
 */

if (!class_exists('WP_Error', false)) {
    class WP_Error {
        /** @var string */
        public $code;
        /** @var string */
        public $message;
        /** @var mixed */
        public $data;

        public function __construct($code = '', $message = '', $data = '') {
            $this->code = (string) $code;
            $this->message = (string) $message;
            $this->data = $data;
        }

        public function get_error_code() {
            return $this->code;
        }

        public function get_error_message() {
            return $this->message;
        }

        public function get_error_data() {
            return $this->data;
        }
    }
}

if (!class_exists('WP_REST_Server', false)) {
    class WP_REST_Server {
        const READABLE = 'GET';
        const CREATABLE = 'POST';
    }
}

if (!class_exists('WP_REST_Response', false)) {
    class WP_REST_Response {
        /** @var mixed */
        private $data;
        /** @var int */
        private $status;
        /** @var array<string, string> */
        private $headers = array();

        public function __construct($data = null, $status = 200, $headers = array()) {
            $this->data = $data;
            $this->status = (int) $status;
            foreach ($headers as $key => $value) {
                $this->header($key, $value);
            }
        }

        /** Canonicalized like WP_HTTP_Response::header(). */
        public function header($key, $value, $replace = true) {
            $key = str_replace(' ', '-', ucwords(str_replace('-', ' ', strtolower((string) $key))));
            if ($replace || !isset($this->headers[$key])) {
                $this->headers[$key] = (string) $value;
            } else {
                $this->headers[$key] .= ', ' . $value;
            }
        }

        public function get_headers() {
            return $this->headers;
        }

        public function get_status() {
            return $this->status;
        }

        public function set_status($status) {
            $this->status = (int) $status;
        }

        public function get_data() {
            return $this->data;
        }
    }
}

if (!class_exists('WP_REST_Request', false)) {
    class WP_REST_Request implements ArrayAccess {
        /** @var string */
        private $method;
        /** @var string */
        private $route;
        /** @var array<string, array<array-key, mixed>|null> */
        private $params = array('JSON' => null, 'POST' => array(), 'GET' => array(), 'URL' => array(), 'defaults' => array());
        /** @var array<string, string> */
        private $headers = array();
        /** @var array<string, mixed> */
        private $attributes = array();

        /**
         * Test convenience: the third argument seeds the parameters (core takes the
         * attributes there; rest_do_request() sets them with set_attributes()).
         */
        public function __construct($method = '', $route = '', $params = array()) {
            $this->method = strtoupper((string) $method);
            $this->route = (string) $route;
            $this->params['POST'] = (array) $params;
        }

        public function get_method() {
            return $this->method;
        }

        public function get_route() {
            return $this->route;
        }

        public static function canonicalize_header_name($key) {
            return str_replace('-', '_', strtolower((string) $key));
        }

        public function set_header($key, $value) {
            $this->headers[self::canonicalize_header_name($key)] = (string) $value;
        }

        public function get_header($key) {
            $key = self::canonicalize_header_name($key);
            return isset($this->headers[$key]) ? $this->headers[$key] : null;
        }

        /** Test convenience: the decoded JSON body (core parses it from set_body()). */
        public function set_json_params($params) {
            $this->params['JSON'] = $params;
        }

        public function get_json_params() {
            return $this->params['JSON'];
        }

        public function set_body_params($params) {
            $this->params['POST'] = (array) $params;
        }

        public function get_body_params() {
            return $this->params['POST'];
        }

        public function set_query_params($params) {
            $this->params['GET'] = (array) $params;
        }

        public function get_query_params() {
            return $this->params['GET'];
        }

        public function set_default_params($params) {
            $this->params['defaults'] = (array) $params;
        }

        public function set_attributes($attributes) {
            $this->attributes = (array) $attributes;
        }

        public function get_attributes() {
            return $this->attributes;
        }

        /** @return list<string> Core's order: JSON (when present), POST, GET, URL, defaults. */
        public function parameter_order() {
            $order = array();
            if ($this->params['JSON'] !== null) {
                $order[] = 'JSON';
            }
            return array_merge($order, array('POST', 'GET', 'URL', 'defaults'));
        }

        public function get_param($key) {
            foreach ($this->parameter_order() as $type) {
                if (is_array($this->params[$type]) && array_key_exists($key, $this->params[$type])) {
                    return $this->params[$type][$key];
                }
            }
            return null;
        }

        public function get_params() {
            $out = array();
            foreach (array_reverse($this->parameter_order()) as $type) {
                if (is_array($this->params[$type])) {
                    $out = array_merge($out, $this->params[$type]);
                }
            }
            return $out;
        }

        /** Like core: the first parameter type holding $key, else the first in order. */
        public function set_param($key, $value) {
            foreach ($this->parameter_order() as $type) {
                if (is_array($this->params[$type]) && array_key_exists($key, $this->params[$type])) {
                    $this->params[$type][$key] = $value;
                    return;
                }
            }
            $order = $this->parameter_order();
            $this->params[$order[0]][$key] = $value;
        }

        #[\ReturnTypeWillChange]
        public function offsetExists($offset) {
            return $this->get_param($offset) !== null;
        }

        #[\ReturnTypeWillChange]
        public function offsetGet($offset) {
            return $this->get_param($offset);
        }

        #[\ReturnTypeWillChange]
        public function offsetSet($offset, $value) {
            $this->set_param($offset, $value);
        }

        #[\ReturnTypeWillChange]
        public function offsetUnset($offset) {
            foreach (array_keys($this->params) as $type) {
                if (is_array($this->params[$type])) {
                    unset($this->params[$type][$offset]);
                }
            }
        }

        /** Test seam for supcheckout_test_rest_sanitize(): replace one sanitized value in place. */
        public function replace_param($type, $key, $value) {
            $this->params[$type][$key] = $value;
        }

        /** @return array<string, array<array-key, mixed>|null> */
        public function raw_params() {
            return $this->params;
        }
    }
}

if (!function_exists('supcheckout_test_reset_rest')) {
    function supcheckout_test_reset_rest() {
        $GLOBALS['supcheckout_test_rest_routes'] = array();
        $GLOBALS['supcheckout_test_rest_registry'] = array();
    }
}

if (!function_exists('register_rest_route')) {
    function register_rest_route($route_namespace, $route, $args = array(), $override = false) {
        $GLOBALS['supcheckout_test_rest_routes'][] = array($route_namespace, $route, $args, $override);
        $endpoints = isset($args['callback']) || isset($args['methods']) ? array($args) : $args;
        $full = '/' . trim((string) $route_namespace, '/') . '/' . ltrim((string) $route, '/');
        foreach ($endpoints as $endpoint) {
            $endpoint = array_merge(array('methods' => 'GET', 'callback' => null, 'args' => array()), (array) $endpoint);
            $GLOBALS['supcheckout_test_rest_registry'][$full][] = $endpoint;
        }
        return true;
    }
}

if (!function_exists('rest_authorization_required_code')) {
    function rest_authorization_required_code() {
        return is_user_logged_in() ? 403 : 401;
    }
}

if (!function_exists('rest_is_boolean')) {
    function rest_is_boolean($value) {
        if (is_bool($value)) {
            return true;
        }
        if (is_string($value)) {
            return in_array(strtolower($value), array('false', 'true', '0', '1'), true);
        }
        if (is_int($value)) {
            return in_array($value, array(0, 1), true);
        }
        return false;
    }
}

if (!function_exists('rest_sanitize_boolean')) {
    function rest_sanitize_boolean($value) {
        if (is_string($value)) {
            $value = strtolower($value);
            if (in_array($value, array('false', '0'), true)) {
                $value = false;
            }
        }
        return (bool) $value;
    }
}

if (!function_exists('rest_url')) {
    function rest_url($path = '') {
        return 'https://shop.test/wp-json/' . ltrim((string) $path, '/');
    }
}

if (!function_exists('supcheckout_test_rest_filters')) {
    /**
     * Run the recorded filters of $hook in priority order.
     *
     * @param string $hook  Hook.
     * @param mixed  $value First argument.
     * @param mixed  ...$args Further arguments.
     * @return mixed
     */
    function supcheckout_test_rest_filters($hook, $value, ...$args) {
        $calls = array_values(array_filter(isset($GLOBALS['supcheckout_test_filter_calls']) ? $GLOBALS['supcheckout_test_filter_calls'] : array(), static function ($call) use ($hook) {
            return $call[0] === $hook;
        }));
        usort($calls, static function ($a, $b) {
            return $a[2] <=> $b[2];
        });
        foreach ($calls as $call) {
            $value = call_user_func_array($call[1], array_slice(array_merge(array($value), $args), 0, max(1, (int) $call[3])));
        }
        return $value;
    }
}

if (!function_exists('supcheckout_test_rest_error_response')) {
    function supcheckout_test_rest_error_response(WP_Error $error) {
        $data = $error->get_error_data();
        $status = is_array($data) && isset($data['status']) ? (int) $data['status'] : 500;
        $body = array('code' => $error->get_error_code(), 'message' => $error->get_error_message(), 'data' => $data);
        return new WP_REST_Response($body, $status);
    }
}

if (!function_exists('supcheckout_test_rest_valid')) {
    /** The subset of rest_validate_value_from_schema() the routes' args use: boolean, string, enum. */
    function supcheckout_test_rest_valid($value, array $arg) {
        $type = isset($arg['type']) ? $arg['type'] : null;
        if ($type === 'boolean' && !rest_is_boolean($value)) {
            return false;
        }
        if ($type === 'string' && !is_string($value)) {
            return false;
        }
        if (isset($arg['enum']) && !in_array($value, $arg['enum'], true)) {
            return false;
        }
        return true;
    }
}

if (!function_exists('supcheckout_test_rest_sanitize')) {
    /**
     * WP_REST_Request::sanitize_params(): every param with an arg that has a type and no
     * sanitize_callback goes through rest_parse_request_arg() (validate, then sanitize),
     * defaults included.
     */
    function supcheckout_test_rest_sanitize(WP_REST_Request $request, array $args) {
        $invalid = array();
        foreach ($request->raw_params() as $type => $values) {
            if (!is_array($values)) {
                continue;
            }
            foreach ($values as $key => $value) {
                if (!isset($args[$key]) || !is_array($args[$key]) || array_key_exists('sanitize_callback', $args[$key]) || empty($args[$key]['type'])) {
                    continue;
                }
                if (!supcheckout_test_rest_valid($value, $args[$key])) {
                    $invalid[$key] = $key . ' is not valid.';
                    continue;
                }
                $request->replace_param($type, $key, $args[$key]['type'] === 'boolean' ? rest_sanitize_boolean($value) : $value);
            }
        }
        if ($invalid !== array()) {
            return new WP_Error('rest_invalid_param', 'Invalid parameter(s): ' . implode(', ', array_keys($invalid)), array('status' => 400, 'params' => $invalid));
        }
        return true;
    }
}

if (!function_exists('rest_do_request')) {
    function rest_do_request($request) {
        $registry = isset($GLOBALS['supcheckout_test_rest_registry']) ? $GLOBALS['supcheckout_test_rest_registry'] : array();
        $route = $request->get_route();
        $handler = null;
        foreach (isset($registry[$route]) ? $registry[$route] : array() as $endpoint) {
            $methods = array_map('trim', explode(',', strtoupper((string) $endpoint['methods'])));
            if (in_array($request->get_method(), $methods, true)) {
                $handler = $endpoint;
                break;
            }
        }
        if ($handler === null) {
            return supcheckout_test_rest_error_response(new WP_Error('rest_no_route', 'No route was found matching the URL and request method.', array('status' => 404)));
        }

        $args = is_array($handler['args']) ? $handler['args'] : array();
        $request->set_attributes($handler);
        $defaults = array();
        foreach ($args as $key => $arg) {
            if (is_array($arg) && array_key_exists('default', $arg)) {
                $defaults[$key] = $arg['default'];
            }
        }
        $request->set_default_params($defaults);

        $response = null;
        $required = array();
        foreach ($args as $key => $arg) {
            if (is_array($arg) && isset($arg['required']) && $arg['required'] === true && $request->get_param($key) === null) {
                $required[] = $key;
            }
        }
        if ($required !== array()) {
            $response = new WP_Error('rest_missing_callback_param', 'Missing parameter(s): ' . implode(', ', $required), array('status' => 400, 'params' => $required));
        } else {
            $sanitized = supcheckout_test_rest_sanitize($request, $args);
            if ($sanitized instanceof WP_Error) {
                $response = $sanitized;
            }
        }

        $response = supcheckout_test_rest_filters('rest_request_before_callbacks', $response, $handler, $request);
        if (!$response instanceof WP_Error && !empty($handler['permission_callback'])) {
            $permission = call_user_func($handler['permission_callback'], $request);
            if ($permission instanceof WP_Error) {
                $response = $permission;
            } elseif ($permission === false || $permission === null) {
                $response = new WP_Error('rest_forbidden', 'Sorry, you are not allowed to do that.', array('status' => rest_authorization_required_code()));
            }
        }
        if (!$response instanceof WP_Error) {
            $response = call_user_func($handler['callback'], $request);
        }
        if ($response instanceof WP_Error) {
            return supcheckout_test_rest_error_response($response);
        }
        return $response instanceof WP_REST_Response ? $response : new WP_REST_Response($response);
    }
}

if (!function_exists('supcheckout_test_rest_serve')) {
    /** WP_REST_Server::serve_request(): dispatch, then the `rest_post_dispatch` filters. */
    function supcheckout_test_rest_serve(WP_REST_Request $request) {
        return supcheckout_test_rest_filters('rest_post_dispatch', rest_do_request($request), new WP_REST_Server(), $request);
    }
}
