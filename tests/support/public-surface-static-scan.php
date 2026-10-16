<?php
/**
 * Static half of the public-surface snapshot (tests/harness/public-surface-snapshot-harness.php).
 *
 * Tokenizes the main file(s), src/ and includes/ and records, with multiplicity:
 * - every add_action/add_filter call: hook, priority, accepted_args (hook_reference);
 * - every WP-Cron / Action Scheduler call: hook, group, argument shape and the
 *   literal unique flag (schedule_reference). Stored cron events and actions are
 *   matched by exact arguments, so the argument shape is part of the surface.
 *
 * Hook names and arguments resolve through string literals, class constants
 * (keyed by fully-qualified class name), local variable assignments, typed
 * parameters and methods that return an array literal. Anything else is recorded
 * as "<dynamic:...>" so it stays visible instead of being dropped.
 */

/** function => hook, args, group and unique argument indexes (null: no such argument). */
const SURFACE_SCAN_SCHEDULE_CALLS = array(
    'wp_schedule_event' => array(2, 3, null, null),
    'wp_schedule_single_event' => array(1, 2, null, null),
    'wp_next_scheduled' => array(0, 1, null, null),
    'wp_clear_scheduled_hook' => array(0, 1, null, null),
    'wp_unschedule_hook' => array(0, null, null, null),
    'wp_unschedule_event' => array(1, 2, null, null),
    'as_schedule_single_action' => array(1, 2, 3, 4),
    'as_schedule_recurring_action' => array(2, 3, 4, 5),
    'as_schedule_cron_action' => array(2, 3, 4, 5),
    'as_enqueue_async_action' => array(0, 1, 2, 3),
    'as_has_scheduled_action' => array(0, 1, 2, null),
    'as_next_scheduled_action' => array(0, 1, 2, null),
    'as_unschedule_action' => array(0, 1, 2, null),
    'as_unschedule_all_actions' => array(0, 1, 2, null),
);

/**
 * @param string $root Repository root.
 * @param array<int, string> $mains Absolute paths of the plugin main file(s).
 * @return array<int, array<string, mixed>>
 */
function surface_scan_static_entries($root, array $mains) {
    $paths = $mains;
    foreach (array('src', 'includes') as $dir) {
        if (!is_dir($root . '/' . $dir)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && substr($file->getFilename(), -4) === '.php') {
                $paths[] = str_replace('\\', '/', $file->getPathname());
            }
        }
    }
    sort($paths);
    $index = array('files' => array(), 'constants' => array(), 'methods' => array());
    foreach ($paths as $path) {
        surface_scan_index_file($index, $path);
    }
    $entries = array();
    foreach (array_keys($index['files']) as $path) {
        foreach (surface_scan_file_entries($index, $path) as $entry) {
            $entries[] = $entry;
        }
    }
    return $entries;
}

/**
 * @param array<string, mixed> $index
 * @param string $path
 * @return array<int, array<string, mixed>>
 */
function surface_scan_file_entries(array $index, $path) {
    $f = $index['files'][$path];
    $t = $f['t'];
    $entries = array();
    $count = count($t);
    for ($i = 0; $i < $count - 1; $i++) {
        if (!surface_scan_is_call($t, $i)) {
            continue;
        }
        $function = strtolower(ltrim($t[$i][1], '\\'));
        $args = surface_scan_split($t, $i + 1, $f['match'][$i + 1]);
        $arg = static function ($n) use ($args) {
            return ($n !== null && isset($args[$n])) ? $args[$n] : null;
        };
        if ($function === 'add_action' || $function === 'add_filter') {
            $priority = $arg(2);
            $accepted = $arg(3);
            $entries[] = array(
                'kind' => 'hook_reference',
                'type' => substr($function, 4),
                'hook' => surface_scan_string($index, $path, $arg(0)),
                'priority' => $priority === null ? 10 : surface_scan_scalar($t, $priority),
                'accepted_args' => $accepted === null ? 1 : surface_scan_scalar($t, $accepted),
            );
        } elseif (isset(SURFACE_SCAN_SCHEDULE_CALLS[$function])) {
            list($hook, $shape, $group, $unique) = SURFACE_SCAN_SCHEDULE_CALLS[$function];
            $entry = array('kind' => 'schedule_reference', 'function' => $function, 'hook' => surface_scan_string($index, $path, $arg($hook)));
            if ($group !== null) {
                $entry['group'] = $arg($group) === null ? '' : surface_scan_string($index, $path, $arg($group));
            }
            if ($shape !== null) {
                $entry['args'] = $arg($shape) === null ? '(omitted)' : surface_scan_shape($index, $path, $arg($shape), 0);
            }
            if ($unique !== null && $arg($unique) !== null) {
                $entry['unique'] = surface_scan_scalar($t, $arg($unique));
            }
            $entries[] = $entry;
        }
    }
    return $entries;
}

/**
 * @param array<int, array{0: int|null, 1: string}> $t
 * @param int $i
 * @return bool
 */
function surface_scan_is_call(array $t, $i) {
    $fq = defined('T_NAME_FULLY_QUALIFIED') ? T_NAME_FULLY_QUALIFIED : -1;
    if (($t[$i][0] !== T_STRING && $t[$i][0] !== $fq) || $t[$i + 1][1] !== '(') {
        return false;
    }
    $name = strtolower(ltrim($t[$i][1], '\\'));
    if ($name !== 'add_action' && $name !== 'add_filter' && !isset(SURFACE_SCAN_SCHEDULE_CALLS[$name])) {
        return false;
    }
    $previous = $i > 0 ? $t[$i - 1][0] : null;
    $nullsafe = defined('T_NULLSAFE_OBJECT_OPERATOR') ? T_NULLSAFE_OBJECT_OPERATOR : -1;
    return !in_array($previous, array(T_OBJECT_OPERATOR, $nullsafe, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_CONST), true);
}

/**
 * Tokenize one file and index its namespace, imports, classes, functions,
 * string class constants and array-returning methods.
 *
 * @param array<string, mixed> $index
 * @param string $path
 * @return void
 */
function surface_scan_index_file(array &$index, $path) {
    $t = array();
    foreach (token_get_all((string) file_get_contents($path)) as $token) {
        $token = is_array($token) ? array($token[0], $token[1]) : array(null, $token);
        if (!in_array($token[0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_CLOSE_TAG, T_INLINE_HTML), true)) {
            $t[] = $token;
        }
    }
    $match = array();
    $stack = array();
    foreach ($t as $i => $token) {
        if (in_array($token[1], array('(', '[', '{', '${'), true) || $token[0] === T_CURLY_OPEN) {
            $stack[] = $i;
        } elseif (in_array($token[1], array(')', ']', '}'), true) && $stack !== array()) {
            $open = array_pop($stack);
            $match[$open] = $i;
            $match[$i] = $open;
        }
    }
    $f = array('t' => $t, 'match' => $match, 'namespace' => '', 'imports' => array(), 'classes' => array(), 'functions' => array());
    $depth = 0;
    $count = count($t);
    for ($i = 0; $i < $count; $i++) {
        $id = $t[$i][0];
        $text = $t[$i][1];
        if ($text === '{' || $text === '${' || $id === T_CURLY_OPEN) {
            $depth++;
        } elseif ($text === '}') {
            $depth--;
        } elseif ($id === T_NAMESPACE && $depth === 0 && surface_scan_is_name($t, $i + 1)) {
            list($f['namespace']) = surface_scan_read_name($t, $i + 1);
        } elseif ($id === T_USE && $depth === 0 && $t[$i + 1][1] !== '(' && !in_array($t[$i + 1][0], array(T_FUNCTION, T_CONST), true)) {
            surface_scan_read_imports($t, $i + 1, $f['imports']);
        } elseif (in_array($id, array(T_CLASS, T_INTERFACE, T_TRAIT), true) && $i > 0
            && !in_array($t[$i - 1][0], array(T_DOUBLE_COLON, T_NEW), true) && $t[$i + 1][0] === T_STRING
        ) {
            $open = $i + 2;
            while ($open < $count && $t[$open][1] !== '{') {
                $open++;
            }
            $fq = ltrim($f['namespace'] . '\\' . $t[$i + 1][1], '\\');
            $f['classes'][] = array('fq' => $fq, 'open' => $open, 'close' => isset($match[$open]) ? $match[$open] : $count - 1);
        } elseif ($id === T_FUNCTION && !($i > 0 && $t[$i - 1][0] === T_USE)) {
            $function = surface_scan_read_function($t, $match, $i);
            $function['class'] = surface_scan_class_at($f, $i);
            $f['functions'][] = $function;
        }
    }
    foreach ($f['classes'] as $class) {
        for ($i = $class['open']; $i < $class['close']; $i++) {
            if ($t[$i][0] === T_CONST && isset($t[$i + 4]) && $t[$i + 2][1] === '='&& $t[$i + 3][0] === T_CONSTANT_ENCAPSED_STRING && $t[$i + 4][1] === ';') {
                $index['constants'][$class['fq']][$t[$i + 1][1]] = surface_scan_decode($t[$i + 3][1]);
            }
        }
    }
    foreach ($f['functions'] as $function) {
        if ($function['class'] !== '' && $function['name'] !== '{closure}' && $function['open'] !== null) {
            $index['methods'][$function['class']][strtolower($function['name'])] = array('path' => $path, 'return' => surface_scan_return_array($t, $match, $function));
        }
    }
    $index['files'][$path] = $f;
}

/**
 * @param array<int, array{0: int|null, 1: string}> $t
 * @param array<int, int> $match
 * @param int $i T_FUNCTION position.
 * @return array<string, mixed>
 */
function surface_scan_read_function(array $t, array $match, $i) {
    $j = $i + 1;
    if ($t[$j][1] === '&') {
        $j++;
    }
    $name = $t[$j][0] === T_STRING ? $t[$j][1] : '{closure}';
    $paren = $name === '{closure}' ? $j : $j + 1;
    $params = array();
    if ($t[$paren][1] === '(' && isset($match[$paren])) {
        foreach (surface_scan_split($t, $paren, $match[$paren]) as $param) {
            $type = '';
            for ($k = $param[0]; $k <= $param[1]; $k++) {
                if ($t[$k][0] === T_VARIABLE) {
                    $params[$t[$k][1]] = $type === '' ? 'mixed' : $type;
                    break;
                }
                if (!in_array($t[$k][1], array('&', '...'), true) && !in_array(strtolower($t[$k][1]), array('public', 'protected', 'private', 'readonly'), true)) {
                    $type .= $t[$k][1];
                }
            }
        }
    }
    $open = null;
    $close = null;
    for ($k = isset($match[$paren]) ? $match[$paren] + 1 : $paren; isset($t[$k]); $k++) {
        if ($t[$k][1] === ';') {
            break;
        }
        if ($t[$k][1] === '(' && isset($match[$k])) {
            $k = $match[$k];
            continue;
        }
        if ($t[$k][1] === '{') {
            $open = $k;
            $close = isset($match[$k]) ? $match[$k] : null;
            break;
        }
    }
    return array('name' => $name, 'params' => $params, 'open' => $open, 'close' => $close);
}

/**
 * Range of the array literal a method returns directly, if any.
 *
 * @param array<int, array{0: int|null, 1: string}> $t
 * @param array<int, int> $match
 * @param array<string, mixed> $function
 * @return array{0: int, 1: int}|null
 */
function surface_scan_return_array(array $t, array $match, array $function) {
    for ($k = $function['open'] + 1; $k < $function['close']; $k++) {
        if ($t[$k][0] !== T_RETURN) {
            continue;
        }
        $start = $k + 1;
        $end = surface_scan_array_end($t, $match, $start);
        if ($end !== null && $t[$end + 1][1] === ';') {
            return array($start, $end);
        }
        return null;
    }
    return null;
}

/**
 * @param array<int, array{0: int|null, 1: string}> $t
 * @param array<int, int> $match
 * @param int $start
 * @return int|null End position when an array literal starts at $start.
 */
function surface_scan_array_end(array $t, array $match, $start) {
    if ($t[$start][0] === T_ARRAY && $t[$start + 1][1] === '(' && isset($match[$start + 1])) {
        return $match[$start + 1];
    }
    if ($t[$start][1] === '[' && isset($match[$start])) {
        return $match[$start];
    }
    return null;
}

/**
 * Split a bracketed range into top-level comma-separated [start, end] ranges.
 *
 * @param array<int, array{0: int|null, 1: string}> $t
 * @param int $open
 * @param int $close
 * @return array<int, array{0: int, 1: int}>
 */
function surface_scan_split(array $t, $open, $close) {
    $parts = array();
    $start = $open + 1;
    $depth = 0;
    for ($k = $open + 1; $k <= $close; $k++) {
        $text = $t[$k][1];
        if (in_array($text, array('(', '[', '{', '${'), true) || $t[$k][0] === T_CURLY_OPEN) {
            $depth++;
        } elseif (in_array($text, array(')', ']', '}'), true) && $k !== $close) {
            $depth--;
        } elseif (($text === ',' && $depth === 0) || $k === $close) {
            if ($k > $start) {
                $parts[] = array($start, $k - 1);
            }
            $start = $k + 1;
        }
    }
    return $parts;
}

/**
 * @param array<string, mixed> $index
 * @param string $path
 * @param array{0: int, 1: int}|null $range
 * @return string
 */
function surface_scan_string(array $index, $path, $range) {
    if ($range === null) {
        return '(omitted)';
    }
    $t = $index['files'][$path]['t'];
    if ($range[0] === $range[1] && $t[$range[0]][0] === T_CONSTANT_ENCAPSED_STRING) {
        return surface_scan_decode($t[$range[0]][1]);
    }
    $constant = surface_scan_constant($index, $path, $range);
    return $constant !== null ? $constant : '<dynamic:' . surface_scan_text($t, $range) . '>';
}

/**
 * Owner::NAME resolved to a string class constant, or null.
 *
 * @param array<string, mixed> $index
 * @param string $path
 * @param array{0: int, 1: int} $range
 * @return string|null
 */
function surface_scan_constant(array $index, $path, array $range) {
    $t = $index['files'][$path]['t'];
    $colon = $range[1] - 1;
    if ($colon <= $range[0] || $t[$colon][0] !== T_DOUBLE_COLON || $t[$range[1]][0] !== T_STRING) {
        return null;
    }
    $class = surface_scan_resolve_class($index['files'][$path], surface_scan_text($t, array($range[0], $colon - 1)), $range[0]);
    return isset($index['constants'][$class][$t[$range[1]][1]]) ? $index['constants'][$class][$t[$range[1]][1]] : null;
}

/**
 * Normalised argument shape, e.g. "[parent_order_id:int,cycle_due_gmt:int]".
 *
 * @param array<string, mixed> $index
 * @param string $path
 * @param array{0: int, 1: int} $range
 * @param int $depth
 * @return string
 */
function surface_scan_shape(array $index, $path, array $range, $depth) {
    $f = $index['files'][$path];
    $t = $f['t'];
    list($s, $e) = $range;
    if ($depth > 4) {
        return '<dynamic:' . surface_scan_text($t, $range) . '>';
    }
    if (surface_scan_array_end($t, $f['match'], $s) === $e) {
        $open = $t[$s][0] === T_ARRAY ? $s + 1 : $s;
        $elements = array();
        $next = 0;
        foreach (surface_scan_split($t, $open, $e) as $element) {
            $arrow = null;
            for ($k = $element[0]; $k <= $element[1]; $k++) {
                if ($t[$k][1] === '(' || $t[$k][1] === '[') {
                    $k = $f['match'][$k];
                } elseif ($t[$k][0] === T_DOUBLE_ARROW) {
                    $arrow = $k;
                    break;
                }
            }
            if ($arrow === null) {
                $key = (string) $next++;
                $value = $element;
            } else {
                $key_range = array($element[0], $arrow - 1);
                $key = surface_scan_string($index, $path, $key_range);
                if ($key_range[0] === $key_range[1] && $t[$key_range[0]][0] === T_LNUMBER) {
                    $key = $t[$key_range[0]][1];
                    $next = (int) $key + 1;
                }
                $value = array($arrow + 1, $element[1]);
            }
            $elements[] = $key . ':' . surface_scan_type($index, $path, $value, $depth + 1);
        }
        return '[' . implode(',', $elements) . ']';
    }
    if ($s === $e && $t[$s][0] === T_VARIABLE) {
        $assignment = surface_scan_assignment($f, $t[$s][1], $s);
        if ($assignment !== null && surface_scan_mutated($f, $t[$s][1], $assignment[1] + 1, $s)) {
            return '<dynamic:' . $t[$s][1] . ' changed after assignment>';
        }
        if ($assignment !== null) {
            return surface_scan_shape($index, $path, $assignment, $depth + 1);
        }
        $function = surface_scan_function_at($f, $s);
        if ($function !== null && isset($function['params'][$t[$s][1]])) {
            return '<param:' . $function['params'][$t[$s][1]] . ' ' . $t[$s][1] . '>';
        }
    }
    // Owner::method(...) whose body returns an array literal.
    if ($t[$e][1] === ')' && isset($f['match'][$e]) && $f['match'][$e] - 2 > $s
        && $t[$f['match'][$e] - 1][0] === T_STRING && $t[$f['match'][$e] - 2][0] === T_DOUBLE_COLON
    ) {
        $call = $f['match'][$e];
        $class = surface_scan_resolve_class($f, surface_scan_text($t, array($s, $call - 3)), $s);
        $method = strtolower($t[$call - 1][1]);
        if (isset($index['methods'][$class][$method]['return'])) {
            $target = $index['methods'][$class][$method];
            return surface_scan_shape($index, $target['path'], $target['return'], $depth + 1);
        }
    }
    return '<dynamic:' . surface_scan_text($t, $range) . '>';
}

/**
 * @param array<string, mixed> $index
 * @param string $path
 * @param array{0: int, 1: int} $range
 * @param int $depth
 * @return string
 */
function surface_scan_type(array $index, $path, array $range, $depth) {
    $f = $index['files'][$path];
    $t = $f['t'];
    list($s, $e) = $range;
    $casts = array(T_INT_CAST => 'int', T_STRING_CAST => 'string', T_BOOL_CAST => 'bool', T_DOUBLE_CAST => 'float', T_ARRAY_CAST => 'array');
    if ($t[$s][0] !== null && isset($casts[$t[$s][0]])) {
        return $casts[$t[$s][0]];
    }
    if ($s === $e) {
        $literals = array(T_LNUMBER => 'int', T_DNUMBER => 'float', T_CONSTANT_ENCAPSED_STRING => 'string');
        if ($t[$s][0] !== null && isset($literals[$t[$s][0]])) {
            return $literals[$t[$s][0]];
        }
        if (in_array(strtolower($t[$s][1]), array('true', 'false'), true)) {
            return 'bool';
        }
        if ($t[$s][0] === T_VARIABLE && $depth <= 4) {
            $assignment = surface_scan_assignment($f, $t[$s][1], $s);
            if ($assignment !== null && surface_scan_mutated($f, $t[$s][1], $assignment[1] + 1, $s)) {
                return 'expr(' . $t[$s][1] . ' changed after assignment)';
            }
            if ($assignment !== null) {
                return surface_scan_type($index, $path, $assignment, $depth + 1);
            }
            $function = surface_scan_function_at($f, $s);
            if ($function !== null && isset($function['params'][$t[$s][1]])) {
                return $function['params'][$t[$s][1]];
            }
        }
    }
    if (surface_scan_array_end($t, $f['match'], $s) === $e) {
        return 'array';
    }
    $int_functions = array('absint', 'intval', 'count', 'strlen');
    $is_call = $t[$s][0] === T_STRING && isset($t[$s + 1]) && $t[$s + 1][1] === '(' && isset($f['match'][$s + 1]) && $f['match'][$s + 1] === $e;
    if ($is_call && in_array(strtolower($t[$s][1]), $int_functions, true)) {
        return 'int';
    }
    // max()/min() over integers stays an integer.
    if ($is_call && in_array(strtolower($t[$s][1]), array('max', 'min'), true) && $depth <= 4) {
        $types = array();
        foreach (surface_scan_split($t, $s + 1, $e) as $operand) {
            $types[surface_scan_type($index, $path, $operand, $depth + 1)] = true;
        }
        if (array_keys($types) === array('int')) {
            return 'int';
        }
    }
    return 'expr(' . surface_scan_text($t, $range) . ')';
}

/**
 * RHS range of the nearest preceding "$name = ...;" in the enclosing function.
 *
 * @param array<string, mixed> $f
 * @param string $name
 * @param int $position
 * @return array{0: int, 1: int}|null
 */
function surface_scan_assignment(array $f, $name, $position) {
    $t = $f['t'];
    $function = surface_scan_function_at($f, $position);
    $floor = $function === null ? 0 : $function['open'];
    for ($k = $position - 1; $k > $floor; $k--) {
        if ($t[$k][0] !== T_VARIABLE || $t[$k][1] !== $name || $t[$k + 1][1] !== '=') {
            continue;
        }
        for ($end = $k + 2; isset($t[$end]) && $t[$end][1] !== ';'; $end++) {
            if (isset($f['match'][$end]) && $f['match'][$end] > $end) {
                $end = $f['match'][$end];
            }
        }
        return array($k + 2, $end - 1);
    }
    return null;
}

/**
 * Whether $name is written between its assignment and its use: an offset write
 * ($v[] = / $v['k'] = / $v[0] .= ...), a compound assignment ($v += ...), a by-reference
 * array function (array_push/array_unshift/array_splice/array_pop/array_shift), unset($v[...])
 * or an explicit reference (&$v). Reads such as $x = $v[0] are not writes.
 *
 * @param array<string, mixed> $f
 * @param string $name
 * @param int $from First token after the assignment.
 * @param int $to Token of the use.
 * @return bool
 */
function surface_scan_mutated(array $f, $name, $from, $to) {
    $t = $f['t'];
    $assignments = array('=', T_PLUS_EQUAL, T_MINUS_EQUAL, T_MUL_EQUAL, T_DIV_EQUAL, T_CONCAT_EQUAL, T_MOD_EQUAL,
        T_AND_EQUAL, T_OR_EQUAL, T_XOR_EQUAL, T_SL_EQUAL, T_SR_EQUAL, T_POW_EQUAL, T_COALESCE_EQUAL);
    $is_assignment = static function ($token) use ($assignments) {
        return in_array($token[0] === null ? $token[1] : $token[0], $assignments, true);
    };
    $by_reference = array('array_push', 'array_unshift', 'array_splice', 'array_pop', 'array_shift', 'unset', 'settype');
    for ($k = $from; $k < $to; $k++) {
        if ($t[$k][0] !== T_VARIABLE || $t[$k][1] !== $name) {
            continue;
        }
        $next = $k + 1;
        while (isset($t[$next]) && $t[$next][1] === '[' && isset($f['match'][$next])) {
            $next = $f['match'][$next] + 1;
        }
        if (isset($t[$next]) && $is_assignment($t[$next]) && ($next > $k + 1 || $t[$next][1] !== '=')) {
            return true;
        }
        if ($k > 0 && $t[$k - 1][1] === '&') {
            return true;
        }
        for ($p = $k - 1; $p > $from && $t[$p][1] !== ';'; $p--) {
            if ($t[$p][1] === '(' && $p > 0 && in_array(strtolower(ltrim($t[$p - 1][1], chr(92))), $by_reference, true)
                && isset($f['match'][$p]) && $f['match'][$p] > $k
            ) {
                return true;
            }
        }
    }
    return false;
}

/**
 * @param array<string, mixed> $f
 * @param int $position
 * @return array<string, mixed>|null Innermost function whose body contains $position.
 */
function surface_scan_function_at(array $f, $position) {
    $found = null;
    foreach ($f['functions'] as $function) {
        if ($function['open'] !== null && $function['open'] < $position && $position < $function['close']
            && ($found === null || $function['open'] > $found['open'])
        ) {
            $found = $function;
        }
    }
    return $found;
}

/**
 * @param array<string, mixed> $f
 * @param int $position
 * @return string Fully-qualified class enclosing $position, or ''.
 */
function surface_scan_class_at(array $f, $position) {
    $found = '';
    foreach ($f['classes'] as $class) {
        if ($class['open'] < $position && $position < $class['close']) {
            $found = $class['fq'];
        }
    }
    return $found;
}

/**
 * @param array<string, mixed> $f
 * @param string $name Class reference as written.
 * @param int $position
 * @return string Fully-qualified class name.
 */
function surface_scan_resolve_class(array $f, $name, $position) {
    if (in_array(strtolower($name), array('self', 'static'), true)) {
        return surface_scan_class_at($f, $position);
    }
    if ($name !== '' && $name[0] === '\\') {
        return ltrim($name, '\\');
    }
    $parts = explode('\\', $name);
    $first = strtolower($parts[0]);
    if (isset($f['imports'][$first])) {
        $parts[0] = $f['imports'][$first];
        return implode('\\', $parts);
    }
    return ltrim($f['namespace'] . '\\' . $name, '\\');
}

/**
 * @param array<int, array{0: int|null, 1: string}> $t
 * @param int $i
 * @return bool
 */
function surface_scan_is_name(array $t, $i) {
    $ids = array(T_STRING, T_NS_SEPARATOR);
    foreach (array('T_NAME_QUALIFIED', 'T_NAME_FULLY_QUALIFIED', 'T_NAME_RELATIVE') as $constant) {
        if (defined($constant)) {
            $ids[] = constant($constant);
        }
    }
    return isset($t[$i]) && in_array($t[$i][0], $ids, true);
}

/**
 * @param array<int, array{0: int|null, 1: string}> $t
 * @param int $i
 * @return array{0: string, 1: int} Name and the position after it.
 */
function surface_scan_read_name(array $t, $i) {
    $name = '';
    while (surface_scan_is_name($t, $i)) {
        $name .= $t[$i][1];
        $i++;
    }
    return array(ltrim($name, '\\'), $i);
}

/**
 * @param array<int, array{0: int|null, 1: string}> $t
 * @param int $i
 * @param array<string, string> $imports
 * @return void
 */
function surface_scan_read_imports(array $t, $i, array &$imports) {
    while (isset($t[$i])) {
        list($name, $i) = surface_scan_read_name($t, $i);
        $alias = substr((string) strrchr('\\' . $name, '\\'), 1);
        if ($t[$i][0] === T_AS) {
            $alias = $t[$i + 1][1];
            $i += 2;
        }
        if ($name !== '') {
            $imports[strtolower($alias)] = $name;
        }
        if ($t[$i][1] !== ',') {
            return;
        }
        $i++;
    }
}

/**
 * @param array<int, array{0: int|null, 1: string}> $t
 * @param array{0: int, 1: int} $range
 * @return int|bool|string Integer or boolean literal, or the expression text.
 */
function surface_scan_scalar(array $t, array $range) {
    if ($range[0] === $range[1] && $t[$range[0]][0] === T_LNUMBER) {
        return (int) $t[$range[0]][1];
    }
    if ($range[0] === $range[1] && in_array(strtolower($t[$range[0]][1]), array('true', 'false'), true)) {
        return strtolower($t[$range[0]][1]) === 'true';
    }
    return surface_scan_text($t, $range);
}

/**
 * Source text of a range, whitespace normalised.
 *
 * @param array<int, array{0: int|null, 1: string}> $t
 * @param array{0: int, 1: int} $range
 * @return string
 */
function surface_scan_text(array $t, array $range) {
    $text = '';
    for ($k = $range[0]; $k <= $range[1]; $k++) {
        $word = preg_match('/^[\\w$\\\\]/', $t[$k][1]) === 1;
        $text .= ($word && preg_match('/[\\w$]$/', $text) === 1 ? ' ' : '') . $t[$k][1];
    }
    return $text;
}

/**
 * @param string $literal Quoted PHP string literal.
 * @return string
 */
function surface_scan_decode($literal) {
    $body = substr($literal, 1, -1);
    return $literal[0] === "'" ? str_replace(array("\\'", '\\\\'), array("'", '\\'), $body) : stripcslashes($body);
}
