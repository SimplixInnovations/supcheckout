<?php
/**
 * Supplemental executable-binding guard for Architecture & Code-Quality.
 *
 * Static-only by design. This guard validates compatibility-critical global
 * hook/callback bindings and thin public wrappers without bootstrapping
 * WordPress or WooCommerce.
 */

$root = dirname(__DIR__, 2);
$pass = 0;
$fail = 0;

function arch2_assert($condition, $message)
{
    global $pass, $fail;
    if ($condition) {
        $pass++;
        echo "PASS: {$message}\n";
        return;
    }
    $fail++;
    echo "FAIL: {$message}\n";
}

function arch2_read($root, $path)
{
    $full = $root . '/' . $path;
    if (!is_file($full)) {
        return '';
    }
    $contents = file_get_contents($full);
    return is_string($contents) ? $contents : '';
}

function arch2_string_value($literal)
{
    if (!is_string($literal) || strlen($literal) < 2) {
        return null;
    }
    $quote = $literal[0];
    if (($quote !== "'" && $quote !== '"') || substr($literal, -1) !== $quote) {
        return null;
    }
    $body = substr($literal, 1, -1);
    if ($quote === "'") {
        return str_replace(array('\\\\', "\\'"), array('\\', "'"), $body);
    }
    return stripcslashes($body);
}

function arch2_tokens($source)
{
    if (!is_string($source) || $source === '') {
        return array();
    }

    $result = array();
    foreach (token_get_all($source) as $token) {
        if (!is_array($token)) {
            $result[] = array('id' => null, 'text' => $token);
            continue;
        }

        $id = $token[0];
        if ($id === T_WHITESPACE || $id === T_COMMENT || $id === T_DOC_COMMENT
            || $id === T_OPEN_TAG || $id === T_CLOSE_TAG) {
            continue;
        }

        $text = $token[1];
        if ($id === T_CONSTANT_ENCAPSED_STRING) {
            $decoded = arch2_string_value($text);
            $text = $decoded === null ? '__INVALID_STRING__' : $decoded;
        }

        $result[] = array('id' => $id, 'text' => $text);
    }

    return $result;
}

function arch2_has_namespace_declaration(array $tokens)
{
    foreach ($tokens as $token) {
        if ($token['id'] === T_NAMESPACE) {
            return true;
        }
    }
    return false;
}

function arch2_alt_start_indexes(array $tokens)
{
    $starts = array();
    $controlIds = array(T_IF, T_FOR, T_FOREACH, T_WHILE, T_SWITCH);
    $count = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        if (!in_array($tokens[$i]['id'], $controlIds, true)) {
            continue;
        }

        $paren = 0;
        $sawParen = false;
        for ($j = $i + 1; $j < $count; $j++) {
            $text = $tokens[$j]['text'];
            if ($text === '(') {
                $paren++;
                $sawParen = true;
                continue;
            }
            if ($text === ')') {
                if ($paren > 0) {
                    $paren--;
                }
                if ($sawParen && $paren === 0) {
                    $next = $j + 1;
                    if ($next < $count && $tokens[$next]['text'] === ':') {
                        $starts[$next] = true;
                    }
                    break;
                }
            }
            if (!$sawParen && ($text === ';' || $text === '{' || $text === ':')) {
                if ($text === ':') {
                    $starts[$j] = true;
                }
                break;
            }
        }
    }

    return $starts;
}

function arch2_is_alt_end($id)
{
    return in_array(
        $id,
        array(T_ENDIF, T_ENDFOR, T_ENDFOREACH, T_ENDWHILE, T_ENDSWITCH, T_ENDDECLARE),
        true
    );
}

function arch2_control_before_parentheses(array $tokens, $closeIndex)
{
    if ($closeIndex < 1 || !isset($tokens[$closeIndex]) || $tokens[$closeIndex]['text'] !== ')') {
        return false;
    }

    $depth = 1;
    for ($i = $closeIndex - 1; $i >= 0; $i--) {
        if ($tokens[$i]['text'] === ')') {
            $depth++;
            continue;
        }
        if ($tokens[$i]['text'] !== '(') {
            continue;
        }
        $depth--;
        if ($depth === 0) {
            return $i - 1 >= 0 ? $i - 1 : false;
        }
    }

    return false;
}

function arch2_is_direct_alt_declare_colon(array $tokens, $index)
{
    if ($index < 1 || !isset($tokens[$index]) || $tokens[$index]['text'] !== ':') {
        return false;
    }

    $ownerIndex = arch2_control_before_parentheses($tokens, $index - 1);
    return $ownerIndex !== false
        && $tokens[$ownerIndex]['id'] === T_DECLARE
        && arch2_is_direct_statement_start($tokens, $ownerIndex);
}

function arch2_is_label_colon(array $tokens, $index)
{
    if ($index < 1
        || !isset($tokens[$index], $tokens[$index - 1])
        || $tokens[$index]['text'] !== ':'
        || $tokens[$index - 1]['id'] !== T_STRING) {
        return false;
    }

    $labelIndex = $index - 1;
    if ($labelIndex === 0) {
        return true;
    }

    $beforeLabelIndex = $labelIndex - 1;
    $beforeLabel = $tokens[$beforeLabelIndex]['text'];
    return $beforeLabel === ';'
        || $beforeLabel === '{'
        || $beforeLabel === '}'
        || ($tokens[$beforeLabelIndex]['id'] === T_DO
            && arch2_is_direct_statement_start($tokens, $beforeLabelIndex))
        || ($beforeLabel === ':'
            && (arch2_is_label_colon($tokens, $beforeLabelIndex)
                || arch2_is_direct_alt_declare_colon($tokens, $beforeLabelIndex)));
}

function arch2_is_direct_statement_start(array $tokens, $index)
{
    if ($index === 0) {
        return true;
    }

    $previousIndex = $index - 1;
    $previous = $tokens[$previousIndex]['text'];
    if ($previous === ';'
        || $previous === '{'
        || $previous === '}'
        || ($tokens[$previousIndex]['id'] === T_DO
            && arch2_is_direct_statement_start($tokens, $previousIndex))
        || ($previous === ':'
            && (arch2_is_label_colon($tokens, $previousIndex)
                || arch2_is_direct_alt_declare_colon($tokens, $previousIndex)))) {
        return true;
    }

    $ownerIndex = arch2_control_before_parentheses($tokens, $previousIndex);
    return $ownerIndex !== false
        && $tokens[$ownerIndex]['id'] === T_DECLARE
        && arch2_is_direct_statement_start($tokens, $ownerIndex);
}

function arch2_is_direct_terminator(array $tokens, $index)
{
    if (!isset($tokens[$index]) || !arch2_is_direct_statement_start($tokens, $index)) {
        return false;
    }

    return in_array(
        $tokens[$index]['id'],
        array(T_RETURN, T_EXIT, T_THROW, T_GOTO),
        true
    );
}

function arch2_is_direct_unconditional_block_open(array $tokens, $index)
{
    if (!isset($tokens[$index]) || $tokens[$index]['text'] !== '{') {
        return false;
    }
    if (arch2_is_direct_statement_start($tokens, $index)) {
        return true;
    }

    $ownerIndex = $index - 1;
    if ($ownerIndex >= 0
        && in_array($tokens[$ownerIndex]['id'], array(T_DO, T_TRY, T_FINALLY), true)
        && arch2_is_direct_statement_start($tokens, $ownerIndex)) {
        return true;
    }

    $ownerIndex = arch2_control_before_parentheses($tokens, $index - 1);
    return $ownerIndex !== false
        && $tokens[$ownerIndex]['id'] === T_DECLARE
        && arch2_is_direct_statement_start($tokens, $ownerIndex);
}

function arch2_matching_brace(array $tokens, $openIndex)
{
    $depth = 1;
    $count = count($tokens);
    for ($i = $openIndex + 1; $i < $count; $i++) {
        if ($tokens[$i]['text'] === '{') {
            $depth++;
        } elseif ($tokens[$i]['text'] === '}') {
            $depth--;
            if ($depth === 0) {
                return $i;
            }
        }
    }

    return false;
}

function arch2_direct_try_groups(array $tokens)
{
    $groups = array();
    $count = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        if ($tokens[$i]['id'] !== T_TRY
            || !isset($tokens[$i + 1])
            || $tokens[$i + 1]['text'] !== '{') {
            continue;
        }

        $tryClose = arch2_matching_brace($tokens, $i + 1);
        if ($tryClose === false) {
            continue;
        }

        $cursor = $tryClose + 1;
        $groupEnd = $tryClose;
        $hasCatch = false;
        $catches = array();
        $finallyOpen = false;
        $finallyClose = false;
        while ($cursor < $count && $tokens[$cursor]['id'] === T_CATCH) {
            $catchIndex = $cursor;
            $hasCatch = true;
            while ($cursor < $count && $tokens[$cursor]['text'] !== '{') {
                $cursor++;
            }
            if ($cursor >= $count) {
                break;
            }
            $catchClose = arch2_matching_brace($tokens, $cursor);
            if ($catchClose === false) {
                break;
            }
            $types = array();
            for ($j = $catchIndex + 1; $j < $cursor; $j++) {
                if (in_array($tokens[$j]['id'], array(T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED), true)) {
                    $types[] = strtolower(ltrim($tokens[$j]['text'], '\\'));
                }
            }
            $catches[] = array('open' => $cursor, 'close' => $catchClose, 'types' => $types);
            $groupEnd = $catchClose;
            $cursor = $catchClose + 1;
        }

        if ($cursor < $count && $tokens[$cursor]['id'] === T_FINALLY
            && isset($tokens[$cursor + 1]) && $tokens[$cursor + 1]['text'] === '{') {
            $finallyOpen = $cursor + 1;
            $finallyClose = arch2_matching_brace($tokens, $finallyOpen);
            if ($finallyClose !== false) {
                $groupEnd = $finallyClose;
            }
        }

        $groups[] = array(
            'open' => $i + 1,
            'close' => $tryClose,
            'end' => $groupEnd,
            'has_catch' => $hasCatch,
            'catches' => $catches,
            'finally_open' => $finallyOpen,
            'finally_close' => $finallyClose,
        );
    }

    usort($groups, function ($left, $right) {
        return ($left['close'] - $left['open']) <=> ($right['close'] - $right['open']);
    });
    return $groups;
}

function arch2_thrown_class(array $tokens, $index)
{
    if (!isset($tokens[$index + 2]) || $tokens[$index + 1]['id'] !== T_NEW) {
        return false;
    }
    $id = $tokens[$index + 2]['id'];
    if (!in_array($id, array(T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED), true)) {
        return false;
    }
    return strtolower(ltrim($tokens[$index + 2]['text'], '\\'));
}

function arch2_catch_type_matches($thrownClass, $catchType)
{
    if ($thrownClass === false || $catchType === '') {
        return false;
    }
    if ($thrownClass === $catchType) {
        return true;
    }
    return (class_exists($thrownClass, false) || interface_exists($thrownClass, false))
        && (class_exists($catchType, false) || interface_exists($catchType, false))
        && is_a($thrownClass, $catchType, true);
}

function arch2_forward_goto_label(array $tokens, $index, $closeIndex)
{
    if (!isset($tokens[$index + 1]) || $tokens[$index + 1]['id'] !== T_STRING) {
        return false;
    }
    $target = $tokens[$index + 1]['text'];
    for ($i = $index + 2; $i < $closeIndex; $i++) {
        if ($tokens[$i]['id'] === T_STRING
            && $tokens[$i]['text'] === $target
            && isset($tokens[$i + 1])
            && arch2_is_label_colon($tokens, $i + 1)) {
            return $i;
        }
    }
    return false;
}

function arch2_direct_range_transfer(array $tokens, $openIndex, $closeIndex)
{
    $tokens = array_values(array_slice($tokens, $openIndex + 1, $closeIndex - $openIndex - 1));
    $altStarts = arch2_alt_start_indexes($tokens);
    $deferredTerminators = arch2_try_terminator_defer_map($tokens);
    $terminationBoundaries = array();
    $braceDepth = 0;
    $altDepth = 0;
    $count = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        if (isset($terminationBoundaries[$i])) {
            return $terminationBoundaries[$i]['transfer'];
        }
        $id = $tokens[$i]['id'];
        $text = $tokens[$i]['text'];
        if (arch2_is_alt_end($id)) {
            if ($altDepth > 0) {
                $altDepth--;
            }
            continue;
        }
        if ($text === '{') {
            if ($braceDepth === 0 && $altDepth === 0
                && arch2_is_direct_unconditional_block_open($tokens, $i)) {
                continue;
            }
            $braceDepth++;
            continue;
        }
        if ($text === '}') {
            if ($braceDepth > 0) {
                $braceDepth--;
            }
            continue;
        }
        if (isset($altStarts[$i])) {
            $altDepth++;
            continue;
        }
        if ($braceDepth === 0 && $altDepth === 0 && arch2_is_direct_terminator($tokens, $i)) {
            if (array_key_exists($i, $deferredTerminators)) {
                $deferred = $deferredTerminators[$i];
                if ($deferred['kind'] === 'goto') {
                    $i = $deferred['skip_to'] - 1;
                    continue;
                }
                if ($deferred['kind'] === 'caught') {
                    foreach ($terminationBoundaries as $boundary => $pending) {
                        if (array_intersect(
                            $pending['finally_scopes'],
                            $deferred['override_finally_scopes']
                        )) {
                            unset($terminationBoundaries[$boundary]);
                        }
                    }
                    continue;
                }
                if ($deferred['kind'] === 'terminal') {
                    return array('kind' => $deferred['transfer_kind']);
                }
                $transfer = array('kind' => $deferred['transfer_kind']);
                if ($deferred['transfer_kind'] === 'throw') {
                    $transfer['throw_class'] = $deferred['throw_class'];
                }
                $boundary = $deferred['boundary'];
                $existingScopes = isset($terminationBoundaries[$boundary])
                    ? $terminationBoundaries[$boundary]['finally_scopes']
                    : array();
                $terminationBoundaries[$boundary] = array(
                    'finally_scopes' => array_values(array_unique(array_merge(
                        $existingScopes,
                        $deferred['finally_scopes']
                    ))),
                    'transfer' => $transfer,
                );
                continue;
            }
            if ($id === T_GOTO) {
                $labelIndex = arch2_forward_goto_label($tokens, $i, $count);
                if ($labelIndex !== false) {
                    $i = $labelIndex - 1;
                    continue;
                }
            }
            $transfer = array(
                'kind' => $id === T_RETURN
                    ? 'return'
                    : ($id === T_EXIT ? 'exit' : ($id === T_THROW ? 'throw' : 'goto')),
            );
            if ($id === T_THROW) {
                $transfer['throw_class'] = arch2_thrown_class($tokens, $i);
            }
            return $transfer;
        }
    }

    if (isset($terminationBoundaries[$count])) {
        return $terminationBoundaries[$count]['transfer'];
    }

    return array('kind' => 'fallthrough');
}

function arch2_try_terminator_defer_map(array $tokens)
{
    $deferred = array();
    $altStarts = arch2_alt_start_indexes($tokens);
    $groups = arch2_direct_try_groups($tokens);

    foreach ($groups as $group) {
        $braceDepth = 0;
        $altDepth = 0;
        for ($i = $group['open'] + 1; $i < $group['close']; $i++) {
            $id = $tokens[$i]['id'];
            $text = $tokens[$i]['text'];
            if (arch2_is_alt_end($id)) {
                if ($altDepth > 0) {
                    $altDepth--;
                }
                continue;
            }
            if ($text === '{') {
                if ($braceDepth === 0 && $altDepth === 0
                    && arch2_is_direct_unconditional_block_open($tokens, $i)) {
                    continue;
                }
                $braceDepth++;
                continue;
            }
            if ($text === '}') {
                if ($braceDepth > 0) {
                    $braceDepth--;
                }
                continue;
            }
            if (isset($altStarts[$i])) {
                $altDepth++;
                continue;
            }
            if ($braceDepth !== 0 || $altDepth !== 0
                || !arch2_is_direct_terminator($tokens, $i)
                || $id === T_EXIT) {
                continue;
            }

            if (array_key_exists($i, $deferred)
                && is_array($deferred[$i])
                && $deferred[$i]['kind'] !== 'pending') {
                continue;
            }
            if ($id === T_GOTO) {
                $labelIndex = arch2_forward_goto_label($tokens, $i, $group['close']);
                if ($labelIndex !== false) {
                    $deferred[$i] = array('kind' => 'goto', 'skip_to' => $labelIndex);
                }
                continue;
            }
            $pendingThrow = array_key_exists($i, $deferred)
                && is_array($deferred[$i])
                && $deferred[$i]['kind'] === 'pending'
                && $deferred[$i]['transfer_kind'] === 'throw';
            if ($id === T_THROW && $group['has_catch']
                && (!array_key_exists($i, $deferred) || $pendingThrow)) {
                $thrownClass = $pendingThrow
                    ? $deferred[$i]['throw_class']
                    : arch2_thrown_class($tokens, $i);
                foreach ($group['catches'] as $catch) {
                    $compatible = false;
                    foreach ($catch['types'] as $catchType) {
                        if (arch2_catch_type_matches($thrownClass, $catchType)) {
                            $compatible = true;
                            break;
                        }
                    }
                    if (!$compatible) {
                        continue;
                    }
                    $catchTransfer = arch2_direct_range_transfer($tokens, $catch['open'], $catch['close']);
                    if ($catchTransfer['kind'] === 'fallthrough') {
                        $overrideFinallyScopes = array();
                        foreach ($groups as $finallyGroup) {
                            if ($finallyGroup['finally_open'] !== false
                                && $finallyGroup['finally_open'] < $i
                                && $i < $finallyGroup['finally_close']
                                && $group['open'] < $finallyGroup['open']) {
                                $overrideFinallyScopes[] = $finallyGroup['finally_close'];
                            }
                        }
                        $deferred[$i] = array(
                            'kind' => 'caught',
                            'override_finally_scopes' => array_values(array_unique($overrideFinallyScopes)),
                        );
                        continue 2;
                    }
                    if ($catchTransfer['kind'] === 'exit' || $catchTransfer['kind'] === 'goto') {
                        $deferred[$i] = array(
                            'kind' => 'terminal',
                            'transfer_kind' => $catchTransfer['kind'],
                        );
                        continue 2;
                    }
                    $finallyScopes = $group['finally_close'] !== false
                        ? array($group['finally_close'])
                        : array();
                    $deferred[$i] = array(
                        'kind' => 'pending',
                        'boundary' => $group['end'] + 1,
                        'finally_scopes' => $finallyScopes,
                        'transfer_kind' => $catchTransfer['kind'],
                    );
                    if ($catchTransfer['kind'] === 'throw') {
                        $deferred[$i]['throw_class'] = $catchTransfer['throw_class'];
                    }
                    continue 2;
                }
            }

            $boundary = $group['end'] + 1;
            $finallyScopes = array();
            $transferKind = $id === T_THROW ? 'throw' : 'return';
            $throwClass = $id === T_THROW ? arch2_thrown_class($tokens, $i) : false;
            if (array_key_exists($i, $deferred)
                && is_array($deferred[$i])
                && $deferred[$i]['kind'] === 'pending') {
                $boundary = max($boundary, $deferred[$i]['boundary']);
                $finallyScopes = $deferred[$i]['finally_scopes'];
                $transferKind = $deferred[$i]['transfer_kind'];
                if ($transferKind === 'throw') {
                    $throwClass = $deferred[$i]['throw_class'];
                }
            }
            if ($group['finally_close'] !== false) {
                $finallyScopes[] = $group['finally_close'];
            }
            $deferred[$i] = array(
                'kind' => 'pending',
                'boundary' => $boundary,
                'finally_scopes' => array_values(array_unique($finallyScopes)),
                'transfer_kind' => $transferKind,
            );
            if ($transferKind === 'throw') {
                $deferred[$i]['throw_class'] = $throwClass;
            }
        }
    }

    return $deferred;
}

function arch2_hook_call_matches(array $tokens, $index, $hookFunction, $hookName, $callbackName)
{
    $expected = array(
        array(T_STRING, $hookFunction),
        array(null, '('),
        array(T_CONSTANT_ENCAPSED_STRING, $hookName),
        array(null, ','),
        array(T_CONSTANT_ENCAPSED_STRING, $callbackName),
        array(null, ')'),
        array(null, ';'),
    );

    if ($index + count($expected) > count($tokens)) {
        return false;
    }

    foreach ($expected as $offset => $want) {
        $actual = $tokens[$index + $offset];
        if ($actual['id'] !== $want[0] || $actual['text'] !== $want[1]) {
            return false;
        }
    }

    return true;
}

function arch2_extract_body(array $tokens, $openBraceIndex)
{
    $depth = 1;
    $body = array();
    $count = count($tokens);

    for ($i = $openBraceIndex + 1; $i < $count; $i++) {
        $text = $tokens[$i]['text'];
        if ($text === '{') {
            $depth++;
        } elseif ($text === '}') {
            $depth--;
            if ($depth === 0) {
                return $body;
            }
        }
        $body[] = $tokens[$i];
    }

    return array();
}

function arch2_without_nested_functions(array $tokens)
{
    $result = array();
    $count = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        if ($tokens[$i]['id'] === T_FUNCTION) {
            $j = $i + 1;
            while ($j < $count && $tokens[$j]['text'] !== '{' && $tokens[$j]['text'] !== ';') {
                $j++;
            }
            if ($j < $count && $tokens[$j]['text'] === '{') {
                $depth = 1;
                $j++;
                while ($j < $count && $depth > 0) {
                    if ($tokens[$j]['text'] === '{') {
                        $depth++;
                    } elseif ($tokens[$j]['text'] === '}') {
                        $depth--;
                    }
                    $j++;
                }
            } elseif ($j < $count) {
                $j++;
            }
            $i = $j - 1;
            continue;
        }

        if (defined('T_FN') && $tokens[$i]['id'] === constant('T_FN')) {
            $j = $i + 1;
            $paren = 0;
            $bracket = 0;
            $brace = 0;

            while ($j < $count) {
                $text = $tokens[$j]['text'];
                if ($text === '(') {
                    $paren++;
                } elseif ($text === ')') {
                    if ($paren > 0) {
                        $paren--;
                    }
                } elseif ($text === '[') {
                    $bracket++;
                } elseif ($text === ']') {
                    if ($bracket > 0) {
                        $bracket--;
                    }
                } elseif ($text === '{') {
                    $brace++;
                } elseif ($text === '}') {
                    if ($brace > 0) {
                        $brace--;
                    }
                } elseif (($text === ';' || $text === ',')
                    && $paren === 0 && $bracket === 0 && $brace === 0) {
                    break;
                }
                $j++;
            }

            $i = $j;
            continue;
        }

        $result[] = $tokens[$i];
    }

    return $result;
}

function arch2_direct_top_level_hook_callback(array $tokens, $hookFunction, $hookName, $callbackName)
{
    if (arch2_has_namespace_declaration($tokens)) {
        return array('found' => false, 'body' => array());
    }

    $altStarts = arch2_alt_start_indexes($tokens);
    $deferredTerminators = arch2_try_terminator_defer_map($tokens);
    $terminationBoundaries = array();
    $braceDepth = 0;
    $altDepth = 0;
    $count = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        if (isset($terminationBoundaries[$i])) {
            return array('found' => false, 'body' => array());
        }
        $id = $tokens[$i]['id'];
        $text = $tokens[$i]['text'];

        if (arch2_is_alt_end($id)) {
            if ($altDepth > 0) {
                $altDepth--;
            }
            continue;
        }
        if ($text === '{') {
            if ($braceDepth === 0 && $altDepth === 0
                && arch2_is_direct_unconditional_block_open($tokens, $i)) {
                continue;
            }
            $braceDepth++;
            continue;
        }
        if ($text === '}') {
            if ($braceDepth > 0) {
                $braceDepth--;
            }
            continue;
        }
        if (isset($altStarts[$i])) {
            $altDepth++;
            continue;
        }
        if ($braceDepth !== 0 || $altDepth !== 0) {
            continue;
        }
        if (arch2_is_direct_terminator($tokens, $i)) {
            if (array_key_exists($i, $deferredTerminators)) {
                if (is_array($deferredTerminators[$i])) {
                    if ($deferredTerminators[$i]['kind'] === 'goto') {
                        $i = $deferredTerminators[$i]['skip_to'] - 1;
                    } elseif ($deferredTerminators[$i]['kind'] === 'caught') {
                        foreach ($terminationBoundaries as $boundary => $finallyScopes) {
                            if (array_intersect(
                                $finallyScopes,
                                $deferredTerminators[$i]['override_finally_scopes']
                            )) {
                                unset($terminationBoundaries[$boundary]);
                            }
                        }
                    } elseif ($deferredTerminators[$i]['kind'] === 'terminal') {
                        return array('found' => false, 'body' => array());
                    } else {
                        $boundary = $deferredTerminators[$i]['boundary'];
                        $existingScopes = isset($terminationBoundaries[$boundary])
                            ? $terminationBoundaries[$boundary]
                            : array();
                        $terminationBoundaries[$boundary] = array_values(array_unique(array_merge(
                            $existingScopes,
                            $deferredTerminators[$i]['finally_scopes']
                        )));
                    }
                    continue;
                }
                if ($deferredTerminators[$i] !== false) {
                    $terminationBoundaries[$deferredTerminators[$i]] = true;
                }
                continue;
            }
            return array('found' => false, 'body' => array());
        }
        if (!arch2_hook_call_matches($tokens, $i, $hookFunction, $hookName, $callbackName)) {
            continue;
        }

        if (!arch2_is_direct_statement_start($tokens, $i)) {
            continue;
        }

        $functionIndex = $i + 7;
        if (!isset($tokens[$functionIndex], $tokens[$functionIndex + 1])
            || $tokens[$functionIndex]['id'] !== T_FUNCTION
            || $tokens[$functionIndex + 1]['id'] !== T_STRING
            || $tokens[$functionIndex + 1]['text'] !== $callbackName) {
            continue;
        }

        for ($j = $functionIndex + 2; $j < $count; $j++) {
            if ($tokens[$j]['text'] === ';') {
                return array('found' => false, 'body' => array());
            }
            if ($tokens[$j]['text'] === '{') {
                return array(
                    'found' => true,
                    'body' => arch2_without_nested_functions(arch2_extract_body($tokens, $j)),
                );
            }
        }
    }

    return array('found' => false, 'body' => array());
}

function arch2_sequence_index(array $tokens, array $sequence)
{
    $count = count($tokens);
    $need = count($sequence);

    if ($need === 0 || $count < $need) {
        return false;
    }

    for ($i = 0; $i <= $count - $need; $i++) {
        $ok = true;
        for ($j = 0; $j < $need; $j++) {
            $want = $sequence[$j];
            if ($tokens[$i + $j]['id'] !== $want[0] || $tokens[$i + $j]['text'] !== $want[1]) {
                $ok = false;
                break;
            }
        }
        if ($ok) {
            return $i;
        }
    }

    return false;
}

function arch2_has_sequence(array $tokens, array $sequence)
{
    return arch2_sequence_index($tokens, $sequence) !== false;
}

function arch2_gateway_callback_returns_registered_methods(array $body)
{
    $expected = array(
        array(T_VARIABLE, '$methods'),
        array(null, '['),
        array(null, ']'),
        array(null, '='),
        array(T_CONSTANT_ENCAPSED_STRING, 'WC_UPayments'),
        array(null, ';'),
        array(T_RETURN, 'return'),
        array(T_VARIABLE, '$methods'),
        array(null, ';'),
    );

    if (count($body) !== count($expected)) {
        return false;
    }

    foreach ($expected as $index => $want) {
        if ($body[$index]['id'] !== $want[0] || $body[$index]['text'] !== $want[1]) {
            return false;
        }
    }

    return true;
}

function arch2_class_body_tokens(array $tokens, $className)
{
    $count = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        if ($tokens[$i]['id'] !== T_CLASS) {
            continue;
        }

        $nameIndex = $i + 1;
        if (!isset($tokens[$nameIndex])
            || $tokens[$nameIndex]['id'] !== T_STRING
            || $tokens[$nameIndex]['text'] !== $className) {
            continue;
        }

        for ($j = $nameIndex + 1; $j < $count; $j++) {
            if ($tokens[$j]['text'] === ';') {
                break;
            }
            if ($tokens[$j]['text'] === '{') {
                return arch2_extract_body($tokens, $j);
            }
        }
    }

    return array();
}

function arch2_direct_public_method(array $classTokens, $methodName)
{
    $depth = 0;
    $count = count($classTokens);

    for ($i = 0; $i < $count; $i++) {
        $text = $classTokens[$i]['text'];

        if ($text === '{') {
            $depth++;
            continue;
        }
        if ($text === '}') {
            if ($depth > 0) {
                $depth--;
            }
            continue;
        }
        if ($depth !== 0 || $classTokens[$i]['id'] !== T_PUBLIC) {
            continue;
        }

        $functionIndex = null;
        for ($j = $i + 1; $j < $count; $j++) {
            if ($classTokens[$j]['text'] === ';' || $classTokens[$j]['text'] === '{') {
                break;
            }
            if ($classTokens[$j]['id'] === T_FUNCTION) {
                $functionIndex = $j;
                break;
            }
        }

        if ($functionIndex === null
            || !isset($classTokens[$functionIndex + 1])
            || $classTokens[$functionIndex + 1]['id'] !== T_STRING
            || $classTokens[$functionIndex + 1]['text'] !== $methodName) {
            continue;
        }

        for ($j = $functionIndex + 2; $j < $count; $j++) {
            if ($classTokens[$j]['text'] === ';') {
                return array('found' => false, 'body' => array());
            }
            if ($classTokens[$j]['text'] === '{') {
                return array(
                    'found' => true,
                    'body' => arch2_without_nested_functions(arch2_extract_body($classTokens, $j)),
                );
            }
        }
    }

    return array('found' => false, 'body' => array());
}

function arch2_code_without_strings(array $tokens)
{
    $code = '';
    foreach ($tokens as $token) {
        if ($token['id'] === T_CONSTANT_ENCAPSED_STRING) {
            $code .= '__STRING__';
        } else {
            $code .= $token['text'];
        }
    }
    return $code;
}

$gateway = arch2_read($root, 'supcheckout.php');
// Owner-approved exception (official UPayments coexistence): the main file may wrap its
// declarations in exactly one condition, the official-plugin pause guard, opened right
// after the conflict class is loaded and closed by the file's final endif. Everything
// inside is then held to the same direct, unconditional rules as before; any other
// wrapper, or a guard anywhere else, leaves the source unchanged and fails below.
$coexistenceGuard = "require_once __DIR__ . '/src/Gateway/OfficialPluginConflict.php';

";
$coexistenceOpen = "if ( ! \Simplixi\SUPCheckout\Gateway\OfficialPluginConflict::pause_if_active( __FILE__ ) ) :
";
$coexistenceClose = "

endif;
";
$coexistenceOpenAt = strpos($gateway, $coexistenceOpen);
$coexistenceGuardAt = strpos($gateway, $coexistenceGuard);
$coexistenceWrapped = substr_count($gateway, $coexistenceOpen) === 1
    && substr_count($gateway, 'OfficialPluginConflict::pause_if_active(') === 1
    && $coexistenceGuardAt !== false
    && $coexistenceOpenAt !== false
    && $coexistenceGuardAt < $coexistenceOpenAt
    && strpos(substr($gateway, $coexistenceGuardAt + strlen($coexistenceGuard), $coexistenceOpenAt - $coexistenceGuardAt - strlen($coexistenceGuard)), ';') === false
    && substr($gateway, -strlen($coexistenceClose)) === $coexistenceClose
    && substr_count($gateway, "
endif;") === 1;
arch2_assert($coexistenceWrapped, 'main file uses exactly the approved official-plugin coexistence wrapper');
$gatewayUnwrapped = $coexistenceWrapped
    ? substr($gateway, 0, $coexistenceOpenAt) . substr($gateway, $coexistenceOpenAt + strlen($coexistenceOpen), -strlen($coexistenceClose)) . "
"
    : $gateway;
$gatewayTokens = arch2_tokens($gatewayUnwrapped);
$subscriptionComposition = arch2_read($root, 'src/Subscription/Composition.php');

$gatewayRegistration = arch2_direct_top_level_hook_callback(
    $gatewayTokens,
    'add_filter',
    'woocommerce_payment_gateways',
    'addUpaymentsGatewayClass'
);
$availabilityRegistration = arch2_direct_top_level_hook_callback(
    $gatewayTokens,
    'add_filter',
    'woocommerce_available_payment_gateways',
    'enableUpaymentsGateway'
);
arch2_assert($gateway !== '', 'supcheckout.php is readable');
arch2_assert(!arch2_has_namespace_declaration($gatewayTokens), 'legacy main file remains in the global namespace');
arch2_assert($gatewayRegistration['found'], 'WooCommerce gateway registration is a direct executable global hook/callback pair');
arch2_assert(
    arch2_gateway_callback_returns_registered_methods($gatewayRegistration['body']),
    'gateway registration callback is exactly WC_UPayments append then methods return'
);
arch2_assert($availabilityRegistration['found'], 'availability registration is a direct executable global hook/callback pair');
arch2_assert(
    strpos($subscriptionComposition, "add_action('woocommerce_process_product_meta', 'saveCustomFieldData')") !== false,
    'subscription product-meta registration retains its legacy callback identity in the A4 composition boundary'
);

$gatewayClass = arch2_class_body_tokens($gatewayTokens, 'WC_Upayments');
$statusMethod = arch2_direct_public_method($gatewayClass, 'get_payment_staus');
$statusDelegation = '\\Simplixi\\SUPCheckout\\Security\\PublicOrderStatus::handle();';
arch2_assert($statusMethod['found'], 'historical public status-poll wrapper remains executable');
arch2_assert(
    $statusMethod['found'] && arch2_code_without_strings($statusMethod['body']) === $statusDelegation,
    'historical public status-poll wrapper directly delegates only to PublicOrderStatus'
);

$alternativeIfFixture = <<<'PHP'
<?php
if (false):
    echo 'dead';
    add_filter("woocommerce_available_payment_gateways", "enableUpaymentsGateway");
    function enableUpaymentsGateway($available_gateways) { return $available_gateways; }
endif;
PHP;
arch2_assert(
    !arch2_direct_top_level_hook_callback(
        arch2_tokens($alternativeIfFixture),
        'add_filter',
        'woocommerce_available_payment_gateways',
        'enableUpaymentsGateway'
    )['found'],
    'matcher rejects alternative-syntax if callback pair'
);

$alternativeForeachFixture = <<<'PHP'
<?php
foreach (array() as $x):
    echo 'dead';
    add_filter("woocommerce_payment_gateways", "addUpaymentsGatewayClass");
    function addUpaymentsGatewayClass($methods) { $methods[] = "WC_UPayments"; return $methods; }
endforeach;
PHP;
arch2_assert(
    !arch2_direct_top_level_hook_callback(
        arch2_tokens($alternativeForeachFixture),
        'add_filter',
        'woocommerce_payment_gateways',
        'addUpaymentsGatewayClass'
    )['found'],
    'matcher rejects alternative-syntax foreach callback pair'
);

$bracedFixture = <<<'PHP'
<?php
if (false) {
    add_action('woocommerce_process_product_meta', 'saveCustomFieldData');
    function saveCustomFieldData($post_id) {}
}
PHP;
arch2_assert(
    !arch2_direct_top_level_hook_callback(
        arch2_tokens($bracedFixture),
        'add_action',
        'woocommerce_process_product_meta',
        'saveCustomFieldData'
    )['found'],
    'matcher rejects braced conditional callback pair'
);

$bracelessFixture = <<<'PHP'
<?php
if (false)
    add_filter("woocommerce_payment_gateways", "addUpaymentsGatewayClass");
function addUpaymentsGatewayClass($methods) { $methods[] = "WC_UPayments"; return $methods; }
PHP;
arch2_assert(
    !arch2_direct_top_level_hook_callback(
        arch2_tokens($bracelessFixture),
        'add_filter',
        'woocommerce_payment_gateways',
        'addUpaymentsGatewayClass'
    )['found'],
    'matcher rejects brace-less conditional registration'
);

$namespaceFixture = <<<'PHP'
<?php
namespace Simplixi\SUPCheckout;
add_filter("woocommerce_payment_gateways", "addUpaymentsGatewayClass");
function addUpaymentsGatewayClass($methods) { $methods[] = "WC_UPayments"; return $methods; }
PHP;
$namespaceTokens = arch2_tokens($namespaceFixture);
arch2_assert(arch2_has_namespace_declaration($namespaceTokens), 'namespace fixture is recognized as namespaced');
arch2_assert(
    !arch2_direct_top_level_hook_callback(
        $namespaceTokens,
        'add_filter',
        'woocommerce_payment_gateways',
        'addUpaymentsGatewayClass'
    )['found'],
    'matcher rejects string callbacks moved into an unbracketed namespace'
);

$inertFixture = <<<'PHP'
<?php
// add_filter("woocommerce_payment_gateways", "addUpaymentsGatewayClass");
$dead = 'add_action("woocommerce_process_product_meta", "saveCustomFieldData");';
function addUpaymentsGatewayClass($methods) { return $methods; }
function saveCustomFieldData($post_id) {}
PHP;
arch2_assert(
    !arch2_direct_top_level_hook_callback(
        arch2_tokens($inertFixture),
        'add_filter',
        'woocommerce_payment_gateways',
        'addUpaymentsGatewayClass'
    )['found'],
    'matcher ignores inert hook text'
);

$arrowFixture = <<<'PHP'
<?php
add_filter("woocommerce_payment_gateways", "addUpaymentsGatewayClass");
function addUpaymentsGatewayClass($methods) {
    $unused = fn() => $methods[] = "WC_UPayments";
    return $methods;
}
PHP;
$arrowGateway = arch2_direct_top_level_hook_callback(
    arch2_tokens($arrowFixture),
    'add_filter',
    'woocommerce_payment_gateways',
    'addUpaymentsGatewayClass'
);
arch2_assert(
    $arrowGateway['found'] && !arch2_gateway_callback_returns_registered_methods($arrowGateway['body']),
    'matcher strips arrow-function gateway append from callback body'
);

$conditionalAppendFixture = <<<'PHP'
<?php
add_filter("woocommerce_payment_gateways", "addUpaymentsGatewayClass");
function addUpaymentsGatewayClass($methods) {
    if (false) {
        $methods[] = "WC_UPayments";
    }
    return $methods;
}
PHP;
$conditionalAppend = arch2_direct_top_level_hook_callback(
    arch2_tokens($conditionalAppendFixture),
    'add_filter',
    'woocommerce_payment_gateways',
    'addUpaymentsGatewayClass'
);
arch2_assert(
    $conditionalAppend['found']
        && !arch2_gateway_callback_returns_registered_methods($conditionalAppend['body']),
    'gateway semantic guard rejects conditional or unreachable append path'
);

$returnBeforeAppendFixture = <<<'PHP'
<?php
add_filter("woocommerce_payment_gateways", "addUpaymentsGatewayClass");
function addUpaymentsGatewayClass($methods) {
    return $methods;
    $methods[] = "WC_UPayments";
}
PHP;
$returnBeforeAppend = arch2_direct_top_level_hook_callback(
    arch2_tokens($returnBeforeAppendFixture),
    'add_filter',
    'woocommerce_payment_gateways',
    'addUpaymentsGatewayClass'
);
arch2_assert(
    $returnBeforeAppend['found']
        && !arch2_gateway_callback_returns_registered_methods($returnBeforeAppend['body']),
    'gateway semantic guard rejects append after return'
);

$overwriteFixture = <<<'PHP'
<?php
add_filter("woocommerce_payment_gateways", "addUpaymentsGatewayClass");
function addUpaymentsGatewayClass($methods) {
    $methods[] = "WC_UPayments";
    $methods = array();
    return $methods;
}
PHP;
$overwriteGateway = arch2_direct_top_level_hook_callback(
    arch2_tokens($overwriteFixture),
    'add_filter',
    'woocommerce_payment_gateways',
    'addUpaymentsGatewayClass'
);
arch2_assert(
    $overwriteGateway['found']
        && !arch2_gateway_callback_returns_registered_methods($overwriteGateway['body']),
    'gateway semantic guard rejects overwritten methods array before return'
);

$statusInertFixture = <<<'PHP'
<?php
class WC_Upayments {
    public function get_payment_staus() {
        // \Simplixi\SUPCheckout\Security\PublicOrderStatus::handle();
        $dead = '\Simplixi\SUPCheckout\Security\PublicOrderStatus::handle();';
        $nested = function () {
            \Simplixi\SUPCheckout\Security\PublicOrderStatus::handle();
        };
    }
}
PHP;
$statusInertTokens = arch2_tokens($statusInertFixture);
$statusInertClass = arch2_class_body_tokens($statusInertTokens, 'WC_Upayments');
$statusInertMethod = arch2_direct_public_method($statusInertClass, 'get_payment_staus');
arch2_assert(
    $statusInertMethod['found']
        && arch2_code_without_strings($statusInertMethod['body']) !== $statusDelegation,
    'status delegation guard ignores comment, string and nested-callable copies'
);

$terminatorFixtures = array(
    'return' => array('statement' => 'return;', 'label' => ''),
    'exit' => array('statement' => 'exit;', 'label' => ''),
    'throw' => array('statement' => 'throw new RuntimeException("halt");', 'label' => ''),
    'goto' => array('statement' => 'goto arch2_after;', 'label' => 'arch2_after: ;'),
    'label-prefixed return' => array('statement' => 'arch2_stage: return;', 'label' => ''),
    'label-prefixed exit' => array('statement' => 'arch2_stage: exit;', 'label' => ''),
    'label-prefixed throw' => array(
        'statement' => 'arch2_stage: throw new RuntimeException("halt");',
        'label' => '',
    ),
    'label-prefixed goto' => array(
        'statement' => 'arch2_stage: goto arch2_after;',
        'label' => 'arch2_after: ;',
    ),
);
$protectedRegistrations = array(
    'gateway' => array(
        'hook_function' => 'add_filter',
        'hook_name' => 'woocommerce_payment_gateways',
        'callback_name' => 'addUpaymentsGatewayClass',
        'callback_body' => '$methods[] = "WC_UPayments"; return $methods;',
    ),
    'availability' => array(
        'hook_function' => 'add_filter',
        'hook_name' => 'woocommerce_available_payment_gateways',
        'callback_name' => 'enableUpaymentsGateway',
        'callback_body' => 'return $available_gateways;',
    ),
    'product-meta' => array(
        'hook_function' => 'add_action',
        'hook_name' => 'woocommerce_process_product_meta',
        'callback_name' => 'saveCustomFieldData',
        'callback_body' => 'return $post_id;',
    ),
);

foreach ($terminatorFixtures as $terminatorName => $fixture) {
    foreach ($protectedRegistrations as $stageName => $registration) {
        $registrationSource = $registration['hook_function'] . "('" . $registration['hook_name'] . "', '"
            . $registration['callback_name'] . "');\n"
            . 'function ' . $registration['callback_name'] . '($value) {'
            . $registration['callback_body'] . "}\n";
        $paths = array(
            'direct' => $fixture['statement'] . "\n",
            'unconditional-block' => "{\n" . $fixture['statement'] . "\n}\n",
            'mandatory-do-block' => "do {\n" . $fixture['statement'] . "\n} while (false);\n",
        );

        foreach ($paths as $pathName => $path) {
            $terminatedSource = "<?php\n"
                . $path
                . $registrationSource
                . $fixture['label'] . "\n";
            arch2_assert(
                !arch2_direct_top_level_hook_callback(
                    arch2_tokens($terminatedSource),
                    $registration['hook_function'],
                    $registration['hook_name'],
                    $registration['callback_name']
                )['found'],
                "matcher rejects {$stageName} registration after {$pathName} {$terminatorName} terminator"
            );
        }
    }
}

$mandatoryCompoundTerminators = array(
    'brace-less do body' => "do return; while (false);\n",
    'try body' => "try { return; } finally {}\n",
    'finally body' => "try {} finally { return; }\n",
    'braced declare body' => "declare(ticks=1) { return; }\n",
    'alternative-syntax declare body' => "declare(ticks=1): return; enddeclare;\n",
    'brace-less declare body' => "declare(ticks=1) return;\n",
);

foreach ($mandatoryCompoundTerminators as $pathName => $path) {
    foreach ($protectedRegistrations as $stageName => $registration) {
        $terminatedSource = "<?php\n"
            . $path
            . $registration['hook_function'] . "('" . $registration['hook_name'] . "', '"
            . $registration['callback_name'] . "');\n"
            . 'function ' . $registration['callback_name'] . '($value) {'
            . $registration['callback_body'] . "}\n";
        arch2_assert(
            !arch2_direct_top_level_hook_callback(
                arch2_tokens($terminatedSource),
                $registration['hook_function'],
                $registration['hook_name'],
                $registration['callback_name']
            )['found'],
            "matcher rejects {$stageName} registration after terminator in {$pathName}"
        );
    }
}

$nestedBareBlockFixture = <<<'PHP'
<?php
{
    {
        return;
    }
}
add_filter("woocommerce_payment_gateways", "addUpaymentsGatewayClass");
function addUpaymentsGatewayClass($methods) { $methods[] = "WC_UPayments"; return $methods; }
PHP;
arch2_assert(
    !arch2_direct_top_level_hook_callback(
        arch2_tokens($nestedBareBlockFixture),
        'add_filter',
        'woocommerce_payment_gateways',
        'addUpaymentsGatewayClass'
    )['found'],
    'matcher rejects gateway registration after nested unconditional-block terminator'
);

$conditionalTerminatorFixture = <<<'PHP'
<?php
if (false) { return; }
add_filter("woocommerce_payment_gateways", "addUpaymentsGatewayClass");
function addUpaymentsGatewayClass($methods) { $methods[] = "WC_UPayments"; return $methods; }
if (false) exit;
add_filter("woocommerce_available_payment_gateways", "enableUpaymentsGateway");
function enableUpaymentsGateway($available_gateways) { return $available_gateways; }
if (false):
    throw new RuntimeException("halt");
endif;
add_action('woocommerce_process_product_meta', 'saveCustomFieldData');
function saveCustomFieldData($post_id) { return $post_id; }
PHP;
$conditionalTokens = arch2_tokens($conditionalTerminatorFixture);
foreach ($protectedRegistrations as $stageName => $registration) {
    arch2_assert(
        arch2_direct_top_level_hook_callback(
            $conditionalTokens,
            $registration['hook_function'],
            $registration['hook_name'],
            $registration['callback_name']
        )['found'],
        "matcher accepts {$stageName} registration after conditional terminator"
    );
}

$reachableBareBlockFixture = <<<'PHP'
<?php
{
    add_filter("woocommerce_payment_gateways", "addUpaymentsGatewayClass");
    function addUpaymentsGatewayClass($methods) { $methods[] = "WC_UPayments"; return $methods; }
}
PHP;
$reachableBareBlock = arch2_direct_top_level_hook_callback(
    arch2_tokens($reachableBareBlockFixture),
    'add_filter',
    'woocommerce_payment_gateways',
    'addUpaymentsGatewayClass'
);
arch2_assert(
    $reachableBareBlock['found']
        && arch2_gateway_callback_returns_registered_methods($reachableBareBlock['body']),
    'matcher accepts reachable gateway registration inside unconditional block'
);

$reachableDoBlockFixture = <<<'PHP'
<?php
do {
    add_filter("woocommerce_payment_gateways", "addUpaymentsGatewayClass");
    function addUpaymentsGatewayClass($methods) { $methods[] = "WC_UPayments"; return $methods; }
} while (false);
PHP;
$reachableDoBlock = arch2_direct_top_level_hook_callback(
    arch2_tokens($reachableDoBlockFixture),
    'add_filter',
    'woocommerce_payment_gateways',
    'addUpaymentsGatewayClass'
);
arch2_assert(
    $reachableDoBlock['found']
        && arch2_gateway_callback_returns_registered_methods($reachableDoBlock['body']),
    'matcher accepts reachable gateway registration inside mandatory do block'
);

$reachableCompoundFixture = <<<'PHP'
<?php
try {
    declare(ticks=1):
        add_filter("woocommerce_payment_gateways", "addUpaymentsGatewayClass");
        function addUpaymentsGatewayClass($methods) { $methods[] = "WC_UPayments"; return $methods; }
    enddeclare;
} finally {}
PHP;
$reachableCompound = arch2_direct_top_level_hook_callback(
    arch2_tokens($reachableCompoundFixture),
    'add_filter',
    'woocommerce_payment_gateways',
    'addUpaymentsGatewayClass'
);
arch2_assert(
    $reachableCompound['found']
        && arch2_gateway_callback_returns_registered_methods($reachableCompound['body']),
    'matcher accepts reachable gateway registration inside mandatory try and declare paths'
);

$caughtThrowFixture = <<<'PHP'
<?php
try { throw new RuntimeException('caught'); }
catch (RuntimeException $error) {}
add_filter("woocommerce_payment_gateways", "addUpaymentsGatewayClass");
function addUpaymentsGatewayClass($methods) { $methods[] = "WC_UPayments"; return $methods; }
PHP;
$caughtThrow = arch2_direct_top_level_hook_callback(
    arch2_tokens($caughtThrowFixture),
    'add_filter',
    'woocommerce_payment_gateways',
    'addUpaymentsGatewayClass'
);
arch2_assert(
    $caughtThrow['found'] && arch2_gateway_callback_returns_registered_methods($caughtThrow['body']),
    'matcher accepts gateway registration after a caught direct throw'
);

$enclosingCatchFixture = <<<'PHP'
<?php
try {
    try { throw new RuntimeException('caught'); }
    finally {}
}
catch (RuntimeException $error) {}
add_filter("woocommerce_payment_gateways", "addUpaymentsGatewayClass");
function addUpaymentsGatewayClass($methods) { $methods[] = "WC_UPayments"; return $methods; }
PHP;
$enclosingCatch = arch2_direct_top_level_hook_callback(
    arch2_tokens($enclosingCatchFixture),
    'add_filter',
    'woocommerce_payment_gateways',
    'addUpaymentsGatewayClass'
);
arch2_assert(
    $enclosingCatch['found'] && arch2_gateway_callback_returns_registered_methods($enclosingCatch['body']),
    'matcher accepts gateway registration after throw caught by enclosing try group'
);

$terminatingCatchFixture = <<<'PHP'
<?php
try { throw new RuntimeException('caught'); }
catch (RuntimeException $error) { return; }
add_filter("woocommerce_payment_gateways", "addUpaymentsGatewayClass");
function addUpaymentsGatewayClass($methods) { $methods[] = "WC_UPayments"; return $methods; }
PHP;
arch2_assert(
    !arch2_direct_top_level_hook_callback(
        arch2_tokens($terminatingCatchFixture),
        'add_filter',
        'woocommerce_payment_gateways',
        'addUpaymentsGatewayClass'
    )['found'],
    'matcher rejects gateway registration after caught throw with terminating catch'
);

$forwardGotoCatchFixture = <<<'PHP'
<?php
try { throw new RuntimeException('caught'); }
catch (RuntimeException $error) {
    goto arch2_resume_catch;
    return;
    arch2_resume_catch: ;
}
add_filter("woocommerce_payment_gateways", "addUpaymentsGatewayClass");
function addUpaymentsGatewayClass($methods) { $methods[] = "WC_UPayments"; return $methods; }
PHP;
$forwardGotoCatch = arch2_direct_top_level_hook_callback(
    arch2_tokens($forwardGotoCatchFixture),
    'add_filter',
    'woocommerce_payment_gateways',
    'addUpaymentsGatewayClass'
);
arch2_assert(
    $forwardGotoCatch['found']
        && arch2_gateway_callback_returns_registered_methods($forwardGotoCatch['body']),
    'matcher accepts gateway registration after caught throw with forward catch goto'
);

$mismatchedCatchFixture = <<<'PHP'
<?php
try { throw new RuntimeException('uncaught'); }
catch (InvalidArgumentException $error) {}
add_filter("woocommerce_payment_gateways", "addUpaymentsGatewayClass");
function addUpaymentsGatewayClass($methods) { $methods[] = "WC_UPayments"; return $methods; }
PHP;
arch2_assert(
    !arch2_direct_top_level_hook_callback(
        arch2_tokens($mismatchedCatchFixture),
        'add_filter',
        'woocommerce_payment_gateways',
        'addUpaymentsGatewayClass'
    )['found'],
    'matcher rejects gateway registration after type-incompatible catch'
);

$gotoSkipFixture = <<<'PHP'
<?php
try {
    goto arch2_skip_registration;
    add_filter("woocommerce_payment_gateways", "addUpaymentsGatewayClass");
    function addUpaymentsGatewayClass($methods) { $methods[] = "WC_UPayments"; return $methods; }
    arch2_skip_registration: ;
}
finally {}
PHP;
arch2_assert(
    !arch2_direct_top_level_hook_callback(
        arch2_tokens($gotoSkipFixture),
        'add_filter',
        'woocommerce_payment_gateways',
        'addUpaymentsGatewayClass'
    )['found'],
    'matcher rejects gateway registration skipped by forward goto in try body'
);

$finallyOverrideFixture = <<<'PHP'
<?php
try {
    try { return; }
    finally { throw new RuntimeException('override'); }
}
catch (RuntimeException $error) {}
add_filter("woocommerce_payment_gateways", "addUpaymentsGatewayClass");
function addUpaymentsGatewayClass($methods) { $methods[] = "WC_UPayments"; return $methods; }
PHP;
$finallyOverride = arch2_direct_top_level_hook_callback(
    arch2_tokens($finallyOverrideFixture),
    'add_filter',
    'woocommerce_payment_gateways',
    'addUpaymentsGatewayClass'
);
arch2_assert(
    $finallyOverride['found'] && arch2_gateway_callback_returns_registered_methods($finallyOverride['body']),
    'matcher accepts gateway registration after caught finally override'
);

$orderedCatchFixture = <<<'PHP'
<?php
try { throw new RuntimeException('caught first'); }
catch (Exception $error) { return; }
catch (RuntimeException $error) {}
add_filter("woocommerce_payment_gateways", "addUpaymentsGatewayClass");
function addUpaymentsGatewayClass($methods) { $methods[] = "WC_UPayments"; return $methods; }
PHP;
arch2_assert(
    !arch2_direct_top_level_hook_callback(
        arch2_tokens($orderedCatchFixture),
        'add_filter',
        'woocommerce_payment_gateways',
        'addUpaymentsGatewayClass'
    )['found'],
    'matcher uses only the first compatible catch in runtime order'
);

$terminatingInnerCatchFixture = <<<'PHP'
<?php
try {
    try { throw new RuntimeException('caught inside'); }
    catch (RuntimeException $error) { return; }
}
catch (RuntimeException $error) {}
add_filter("woocommerce_payment_gateways", "addUpaymentsGatewayClass");
function addUpaymentsGatewayClass($methods) { $methods[] = "WC_UPayments"; return $methods; }
PHP;
arch2_assert(
    !arch2_direct_top_level_hook_callback(
        arch2_tokens($terminatingInnerCatchFixture),
        'add_filter',
        'woocommerce_payment_gateways',
        'addUpaymentsGatewayClass'
    )['found'],
    'matcher propagates return selected by inner catch past matching outer catch'
);

$nestedTerminatingCatchFixture = <<<'PHP'
<?php
try {
    try { throw new RuntimeException('caught outside'); }
    catch (RuntimeException $outerError) {
        try { throw new RuntimeException('caught inside'); }
        catch (RuntimeException $innerError) { return; }
    }
}
catch (RuntimeException $error) {}
add_filter("woocommerce_payment_gateways", "addUpaymentsGatewayClass");
function addUpaymentsGatewayClass($methods) { $methods[] = "WC_UPayments"; return $methods; }
PHP;
arch2_assert(
    !arch2_direct_top_level_hook_callback(
        arch2_tokens($nestedTerminatingCatchFixture),
        'add_filter',
        'woocommerce_payment_gateways',
        'addUpaymentsGatewayClass'
    )['found'],
    'matcher resolves nested catch return before matching enclosing catch'
);

$nestedReplacementThrowFixture = <<<'PHP'
<?php
try {
    try { throw new RuntimeException('caught outside'); }
    catch (RuntimeException $outerError) {
        try { throw new RuntimeException('caught inside'); }
        catch (RuntimeException $innerError) { throw new InvalidArgumentException('replacement'); }
    }
}
catch (InvalidArgumentException $error) {}
add_filter("woocommerce_payment_gateways", "addUpaymentsGatewayClass");
function addUpaymentsGatewayClass($methods) { $methods[] = "WC_UPayments"; return $methods; }
PHP;
$nestedReplacementThrow = arch2_direct_top_level_hook_callback(
    arch2_tokens($nestedReplacementThrowFixture),
    'add_filter',
    'woocommerce_payment_gateways',
    'addUpaymentsGatewayClass'
);
arch2_assert(
    $nestedReplacementThrow['found']
        && arch2_gateway_callback_returns_registered_methods($nestedReplacementThrow['body']),
    'matcher resolves nested replacement throw before compatible enclosing catch'
);

$compatibleRethrowFixture = <<<'PHP'
<?php
try {
    try { throw new RuntimeException('caught inside'); }
    catch (RuntimeException $error) { throw new InvalidArgumentException('replacement'); }
}
catch (InvalidArgumentException $error) {}
add_filter("woocommerce_payment_gateways", "addUpaymentsGatewayClass");
function addUpaymentsGatewayClass($methods) { $methods[] = "WC_UPayments"; return $methods; }
PHP;
$compatibleRethrow = arch2_direct_top_level_hook_callback(
    arch2_tokens($compatibleRethrowFixture),
    'add_filter',
    'woocommerce_payment_gateways',
    'addUpaymentsGatewayClass'
);
arch2_assert(
    $compatibleRethrow['found']
        && arch2_gateway_callback_returns_registered_methods($compatibleRethrow['body']),
    'matcher propagates replacement throw to compatible outer catch'
);

$incompatibleRethrowFixture = <<<'PHP'
<?php
try {
    try { throw new RuntimeException('caught inside'); }
    catch (RuntimeException $error) { throw new InvalidArgumentException('replacement'); }
}
catch (RuntimeException $error) {}
add_filter("woocommerce_payment_gateways", "addUpaymentsGatewayClass");
function addUpaymentsGatewayClass($methods) { $methods[] = "WC_UPayments"; return $methods; }
PHP;
arch2_assert(
    !arch2_direct_top_level_hook_callback(
        arch2_tokens($incompatibleRethrowFixture),
        'add_filter',
        'woocommerce_payment_gateways',
        'addUpaymentsGatewayClass'
    )['found'],
    'matcher rejects replacement throw at incompatible outer catch'
);

$localCatchFinallyFixture = <<<'PHP'
<?php
try { return; }
finally {
    try { throw new RuntimeException('caught locally'); }
    catch (RuntimeException $error) {}
}
add_filter("woocommerce_payment_gateways", "addUpaymentsGatewayClass");
function addUpaymentsGatewayClass($methods) { $methods[] = "WC_UPayments"; return $methods; }
PHP;
arch2_assert(
    !arch2_direct_top_level_hook_callback(
        arch2_tokens($localCatchFinallyFixture),
        'add_filter',
        'woocommerce_payment_gateways',
        'addUpaymentsGatewayClass'
    )['found'],
    'matcher preserves pending return across locally caught throw in finally'
);

$nestedLocalCatchFinallyFixture = <<<'PHP'
<?php
try { return; }
finally {
    try {
        try {}
        finally { throw new RuntimeException('caught locally'); }
    }
    catch (RuntimeException $error) {}
}
add_filter("woocommerce_payment_gateways", "addUpaymentsGatewayClass");
function addUpaymentsGatewayClass($methods) { $methods[] = "WC_UPayments"; return $methods; }
PHP;
arch2_assert(
    !arch2_direct_top_level_hook_callback(
        arch2_tokens($nestedLocalCatchFinallyFixture),
        'add_filter',
        'woocommerce_payment_gateways',
        'addUpaymentsGatewayClass'
    )['found'],
    'matcher preserves pending return across nested locally caught finally throw'
);

$finallyRegistrationFixture = <<<'PHP'
<?php
try { return; }
finally {
    add_filter("woocommerce_payment_gateways", "addUpaymentsGatewayClass");
    function addUpaymentsGatewayClass($methods) { $methods[] = "WC_UPayments"; return $methods; }
}
PHP;
$finallyRegistration = arch2_direct_top_level_hook_callback(
    arch2_tokens($finallyRegistrationFixture),
    'add_filter',
    'woocommerce_payment_gateways',
    'addUpaymentsGatewayClass'
);
arch2_assert(
    $finallyRegistration['found']
        && arch2_gateway_callback_returns_registered_methods($finallyRegistration['body']),
    'matcher accepts gateway registration reached through finally after return'
);

$nestedFinallyFixture = <<<'PHP'
<?php
try {
    try { return; }
    finally {}
}
finally {
    add_filter("woocommerce_payment_gateways", "addUpaymentsGatewayClass");
    function addUpaymentsGatewayClass($methods) { $methods[] = "WC_UPayments"; return $methods; }
}
PHP;
$nestedFinally = arch2_direct_top_level_hook_callback(
    arch2_tokens($nestedFinallyFixture),
    'add_filter',
    'woocommerce_payment_gateways',
    'addUpaymentsGatewayClass'
);
arch2_assert(
    $nestedFinally['found'] && arch2_gateway_callback_returns_registered_methods($nestedFinally['body']),
    'matcher accepts gateway registration through enclosing finally after nested return'
);

$exitFinallyFixture = <<<'PHP'
<?php
try { exit; }
finally {
    add_filter("woocommerce_payment_gateways", "addUpaymentsGatewayClass");
    function addUpaymentsGatewayClass($methods) { $methods[] = "WC_UPayments"; return $methods; }
}
PHP;
arch2_assert(
    !arch2_direct_top_level_hook_callback(
        arch2_tokens($exitFinallyFixture),
        'add_filter',
        'woocommerce_payment_gateways',
        'addUpaymentsGatewayClass'
    )['found'],
    'matcher rejects finally registration bypassed by direct exit'
);

$validFixture = <<<'PHP'
<?php
add_filter("woocommerce_payment_gateways", "addUpaymentsGatewayClass");
function addUpaymentsGatewayClass($methods) { $methods[] = "WC_UPayments"; return $methods; }
add_action('woocommerce_process_product_meta', 'saveCustomFieldData');
function saveCustomFieldData($post_id) { return $post_id; }
PHP;
$validTokens = arch2_tokens($validFixture);
$validGateway = arch2_direct_top_level_hook_callback(
    $validTokens,
    'add_filter',
    'woocommerce_payment_gateways',
    'addUpaymentsGatewayClass'
);
$validProduct = arch2_direct_top_level_hook_callback(
    $validTokens,
    'add_action',
    'woocommerce_process_product_meta',
    'saveCustomFieldData'
);
arch2_assert($validGateway['found'], 'matcher accepts direct top-level gateway registration/callback');
arch2_assert(
    $validGateway['found'] && arch2_gateway_callback_returns_registered_methods($validGateway['body']),
    'gateway semantic guard accepts exact append-then-return callback'
);
arch2_assert($validProduct['found'], 'matcher accepts direct top-level product-meta registration/callback');

printf("\nArchitecture Runtime Bindings: %d PASS / %d FAIL\n", $pass, $fail);
echo 'HARNESS-COMPLETE: ' . basename(__FILE__) . "\n";
exit($fail === 0 ? 0 : 1);
