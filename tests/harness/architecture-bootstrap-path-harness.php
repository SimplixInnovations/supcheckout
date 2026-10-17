<?php
/**
 * Architecture bootstrap/execution-path guard.
 *
 * Static-only by design. This complements the architecture foundation and
 * runtime-binding harnesses by proving that the legacy gateway class is
 * reachable from the registered plugins_loaded callback and that the
 * protected gateway ID assignment is a direct constructor statement.
 */

$root = dirname(__DIR__, 2);
$pass = 0;
$fail = 0;

function arch3_assert($condition, $message)
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

function arch3_read($root, $path)
{
    $full = $root . '/' . $path;
    if (!is_file($full)) {
        return '';
    }
    $contents = file_get_contents($full);
    return is_string($contents) ? $contents : '';
}

function arch3_string_value($literal)
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

function arch3_tokens($source)
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
            $decoded = arch3_string_value($text);
            $text = $decoded === null ? '__INVALID_STRING__' : $decoded;
        }

        $result[] = array('id' => $id, 'text' => $text);
    }

    return $result;
}

function arch3_has_namespace_declaration(array $tokens)
{
    foreach ($tokens as $token) {
        if ($token['id'] === T_NAMESPACE) {
            return true;
        }
    }
    return false;
}

function arch3_alt_start_indexes(array $tokens)
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

function arch3_is_alt_end($id)
{
    return in_array(
        $id,
        array(T_ENDIF, T_ENDFOR, T_ENDFOREACH, T_ENDWHILE, T_ENDSWITCH, T_ENDDECLARE),
        true
    );
}

function arch3_control_before_parentheses(array $tokens, $closeIndex)
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

function arch3_is_direct_alt_declare_colon(array $tokens, $index)
{
    if ($index < 1 || !isset($tokens[$index]) || $tokens[$index]['text'] !== ':') {
        return false;
    }

    $ownerIndex = arch3_control_before_parentheses($tokens, $index - 1);
    return $ownerIndex !== false
        && $tokens[$ownerIndex]['id'] === T_DECLARE
        && arch3_is_direct_statement_start($tokens, $ownerIndex);
}

function arch3_is_label_colon(array $tokens, $index)
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
            && arch3_is_direct_statement_start($tokens, $beforeLabelIndex))
        || ($beforeLabel === ':'
            && (arch3_is_label_colon($tokens, $beforeLabelIndex)
                || arch3_is_direct_alt_declare_colon($tokens, $beforeLabelIndex)));
}

function arch3_is_direct_statement_start(array $tokens, $index)
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
            && arch3_is_direct_statement_start($tokens, $previousIndex))
        || ($previous === ':'
            && (arch3_is_label_colon($tokens, $previousIndex)
                || arch3_is_direct_alt_declare_colon($tokens, $previousIndex)))) {
        return true;
    }

    $ownerIndex = arch3_control_before_parentheses($tokens, $previousIndex);
    return $ownerIndex !== false
        && $tokens[$ownerIndex]['id'] === T_DECLARE
        && arch3_is_direct_statement_start($tokens, $ownerIndex);
}

function arch3_is_direct_terminator(array $tokens, $index)
{
    if (!isset($tokens[$index]) || !arch3_is_direct_statement_start($tokens, $index)) {
        return false;
    }

    return in_array(
        $tokens[$index]['id'],
        array(T_RETURN, T_EXIT, T_THROW, T_GOTO),
        true
    );
}

function arch3_is_direct_unconditional_block_open(array $tokens, $index)
{
    if (!isset($tokens[$index]) || $tokens[$index]['text'] !== '{') {
        return false;
    }
    if (arch3_is_direct_statement_start($tokens, $index)) {
        return true;
    }

    $ownerIndex = $index - 1;
    if ($ownerIndex >= 0
        && in_array($tokens[$ownerIndex]['id'], array(T_DO, T_TRY, T_FINALLY), true)
        && arch3_is_direct_statement_start($tokens, $ownerIndex)) {
        return true;
    }

    $ownerIndex = arch3_control_before_parentheses($tokens, $index - 1);
    return $ownerIndex !== false
        && $tokens[$ownerIndex]['id'] === T_DECLARE
        && arch3_is_direct_statement_start($tokens, $ownerIndex);
}

function arch3_matching_brace(array $tokens, $openIndex)
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

function arch3_direct_try_groups(array $tokens)
{
    $groups = array();
    $count = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        if ($tokens[$i]['id'] !== T_TRY
            || !isset($tokens[$i + 1])
            || $tokens[$i + 1]['text'] !== '{') {
            continue;
        }

        $tryClose = arch3_matching_brace($tokens, $i + 1);
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
            $catchClose = arch3_matching_brace($tokens, $cursor);
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
            $finallyClose = arch3_matching_brace($tokens, $finallyOpen);
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

function arch3_thrown_class(array $tokens, $index)
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

function arch3_catch_type_matches($thrownClass, $catchType)
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

function arch3_forward_goto_label(array $tokens, $index, $closeIndex)
{
    if (!isset($tokens[$index + 1]) || $tokens[$index + 1]['id'] !== T_STRING) {
        return false;
    }
    $target = $tokens[$index + 1]['text'];
    for ($i = $index + 2; $i < $closeIndex; $i++) {
        if ($tokens[$i]['id'] === T_STRING
            && $tokens[$i]['text'] === $target
            && isset($tokens[$i + 1])
            && arch3_is_label_colon($tokens, $i + 1)) {
            return $i;
        }
    }
    return false;
}

function arch3_direct_range_transfer(array $tokens, $openIndex, $closeIndex)
{
    $tokens = array_values(array_slice($tokens, $openIndex + 1, $closeIndex - $openIndex - 1));
    $altStarts = arch3_alt_start_indexes($tokens);
    $deferredTerminators = arch3_try_terminator_defer_map($tokens);
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
        if (arch3_is_alt_end($id)) {
            if ($altDepth > 0) {
                $altDepth--;
            }
            continue;
        }
        if ($text === '{') {
            if ($braceDepth === 0 && $altDepth === 0
                && arch3_is_direct_unconditional_block_open($tokens, $i)) {
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
        if ($braceDepth === 0 && $altDepth === 0 && arch3_is_direct_terminator($tokens, $i)) {
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
                $labelIndex = arch3_forward_goto_label($tokens, $i, $count);
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
                $transfer['throw_class'] = arch3_thrown_class($tokens, $i);
            }
            return $transfer;
        }
    }

    if (isset($terminationBoundaries[$count])) {
        return $terminationBoundaries[$count]['transfer'];
    }

    return array('kind' => 'fallthrough');
}

function arch3_try_terminator_defer_map(array $tokens)
{
    $deferred = array();
    $altStarts = arch3_alt_start_indexes($tokens);
    $groups = arch3_direct_try_groups($tokens);

    foreach ($groups as $group) {
        $braceDepth = 0;
        $altDepth = 0;
        for ($i = $group['open'] + 1; $i < $group['close']; $i++) {
            $id = $tokens[$i]['id'];
            $text = $tokens[$i]['text'];
            if (arch3_is_alt_end($id)) {
                if ($altDepth > 0) {
                    $altDepth--;
                }
                continue;
            }
            if ($text === '{') {
                if ($braceDepth === 0 && $altDepth === 0
                    && arch3_is_direct_unconditional_block_open($tokens, $i)) {
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
                || !arch3_is_direct_terminator($tokens, $i)
                || $id === T_EXIT) {
                continue;
            }

            if (array_key_exists($i, $deferred)
                && is_array($deferred[$i])
                && $deferred[$i]['kind'] !== 'pending') {
                continue;
            }
            if ($id === T_GOTO) {
                $labelIndex = arch3_forward_goto_label($tokens, $i, $group['close']);
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
                    : arch3_thrown_class($tokens, $i);
                foreach ($group['catches'] as $catch) {
                    $compatible = false;
                    foreach ($catch['types'] as $catchType) {
                        if (arch3_catch_type_matches($thrownClass, $catchType)) {
                            $compatible = true;
                            break;
                        }
                    }
                    if (!$compatible) {
                        continue;
                    }
                    $catchTransfer = arch3_direct_range_transfer($tokens, $catch['open'], $catch['close']);
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
            $throwClass = $id === T_THROW ? arch3_thrown_class($tokens, $i) : false;
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

function arch3_extract_body(array $tokens, $openBraceIndex)
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

function arch3_hook_call_matches(array $tokens, $index, $hookFunction, $hookName, $callbackName)
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

function arch3_direct_top_level_hook_callback(array $tokens, $hookFunction, $hookName, $callbackName)
{
    if (arch3_has_namespace_declaration($tokens)) {
        return array('found' => false, 'body' => array());
    }

    $altStarts = arch3_alt_start_indexes($tokens);
    $deferredTerminators = arch3_try_terminator_defer_map($tokens);
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

        if (arch3_is_alt_end($id)) {
            if ($altDepth > 0) {
                $altDepth--;
            }
            continue;
        }
        if ($text === '{') {
            if ($braceDepth === 0 && $altDepth === 0
                && arch3_is_direct_unconditional_block_open($tokens, $i)) {
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
        if (arch3_is_direct_terminator($tokens, $i)) {
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
        if (!arch3_hook_call_matches($tokens, $i, $hookFunction, $hookName, $callbackName)) {
            continue;
        }

        if (!arch3_is_direct_statement_start($tokens, $i)) {
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
                    'body' => arch3_extract_body($tokens, $j),
                );
            }
        }
    }

    return array('found' => false, 'body' => array());
}

function arch3_direct_class(array $ownerBody, $className)
{
    $altStarts = arch3_alt_start_indexes($ownerBody);
    $deferredTerminators = arch3_try_terminator_defer_map($ownerBody);
    $terminationBoundaries = array();
    $braceDepth = 0;
    $altDepth = 0;
    $count = count($ownerBody);

    for ($i = 0; $i < $count; $i++) {
        if (isset($terminationBoundaries[$i])) {
            return array('found' => false, 'body' => array());
        }
        $id = $ownerBody[$i]['id'];
        $text = $ownerBody[$i]['text'];

        if (arch3_is_alt_end($id)) {
            if ($altDepth > 0) {
                $altDepth--;
            }
            continue;
        }
        if ($text === '{') {
            if ($braceDepth === 0 && $altDepth === 0
                && arch3_is_direct_unconditional_block_open($ownerBody, $i)) {
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
        if ($braceDepth === 0 && $altDepth === 0 && arch3_is_direct_terminator($ownerBody, $i)) {
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
        if ($braceDepth !== 0 || $altDepth !== 0 || $id !== T_CLASS) {
            continue;
        }

        if (!arch3_is_direct_statement_start($ownerBody, $i)) {
            continue;
        }

        if (!isset($ownerBody[$i + 1])
            || $ownerBody[$i + 1]['id'] !== T_STRING
            || $ownerBody[$i + 1]['text'] !== $className) {
            continue;
        }

        for ($j = $i + 2; $j < $count; $j++) {
            if ($ownerBody[$j]['text'] === ';') {
                return array('found' => false, 'body' => array());
            }
            if ($ownerBody[$j]['text'] === '{') {
                return array(
                    'found' => true,
                    'body' => arch3_extract_body($ownerBody, $j),
                );
            }
        }
    }

    return array('found' => false, 'body' => array());
}

function arch3_direct_public_method(array $classBody, $methodName)
{
    $depth = 0;
    $count = count($classBody);

    for ($i = 0; $i < $count; $i++) {
        $text = $classBody[$i]['text'];
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
        if ($depth !== 0 || $classBody[$i]['id'] !== T_PUBLIC) {
            continue;
        }

        $functionIndex = null;
        for ($j = $i + 1; $j < $count; $j++) {
            if ($classBody[$j]['text'] === ';' || $classBody[$j]['text'] === '{') {
                break;
            }
            if ($classBody[$j]['id'] === T_FUNCTION) {
                $functionIndex = $j;
                break;
            }
        }

        if ($functionIndex === null
            || !isset($classBody[$functionIndex + 1])
            || $classBody[$functionIndex + 1]['id'] !== T_STRING
            || $classBody[$functionIndex + 1]['text'] !== $methodName) {
            continue;
        }

        for ($j = $functionIndex + 2; $j < $count; $j++) {
            if ($classBody[$j]['text'] === ';') {
                return array('found' => false, 'body' => array());
            }
            if ($classBody[$j]['text'] === '{') {
                return array(
                    'found' => true,
                    'body' => arch3_extract_body($classBody, $j),
                );
            }
        }
    }

    return array('found' => false, 'body' => array());
}

function arch3_has_direct_gateway_id_assignment(array $body)
{
    $expected = array(
        array(T_VARIABLE, '$this'),
        array(T_OBJECT_OPERATOR, '->'),
        array(T_STRING, 'id'),
        array(null, '='),
        array(T_CONSTANT_ENCAPSED_STRING, 'upayments'),
        array(null, ';'),
    );
    $need = count($expected);

    return arch3_has_direct_statement($body, static function (array $tokens, $i) use ($expected, $need) {
        if ($i + $need > count($tokens)) {
            return 'stop';
        }
        foreach ($expected as $offset => $want) {
            $actual = $tokens[$i + $offset];
            if ($actual['id'] !== $want[0] || $actual['text'] !== $want[1]) {
                return false;
            }
        }
        return true;
    });
}

/**
 * Walks the direct (top-level, reachable) statements of $body and asks $matcher
 * about each statement start: true = found, 'stop' = give up, false = continue.
 */
function arch3_has_direct_statement(array $body, callable $matcher)
{
    $altStarts = arch3_alt_start_indexes($body);
    $deferredTerminators = arch3_try_terminator_defer_map($body);
    $terminationBoundaries = array();
    $braceDepth = 0;
    $altDepth = 0;
    $count = count($body);

    for ($i = 0; $i < $count; $i++) {
        if (isset($terminationBoundaries[$i])) {
            return false;
        }
        $id = $body[$i]['id'];
        $text = $body[$i]['text'];

        if (arch3_is_alt_end($id)) {
            if ($altDepth > 0) {
                $altDepth--;
            }
            continue;
        }
        if ($text === '{') {
            if ($braceDepth === 0 && $altDepth === 0
                && arch3_is_direct_unconditional_block_open($body, $i)) {
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
        if (arch3_is_direct_terminator($body, $i)) {
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
                        return false;
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
            return false;
        }

        if (!arch3_is_direct_statement_start($body, $i)) {
            continue;
        }
        $verdict = $matcher($body, $i);
        if ($verdict === 'stop') {
            return false;
        }
        if ($verdict === true) {
            return true;
        }
    }

    return false;
}

/**
 * Concatenated token texts of the statement starting at $index, up to and
 * including its terminating top-level ';' (string literals appear decoded).
 */
function arch3_statement_text(array $tokens, $index)
{
    $text = '';
    $depth = 0;
    $count = count($tokens);
    for ($i = $index; $i < $count; $i++) {
        $piece = $tokens[$i]['text'];
        $text .= $piece;
        if ($piece === '(' || $piece === '[' || $piece === '{') {
            $depth++;
        } elseif ($piece === ')' || $piece === ']' || $piece === '}') {
            $depth--;
        } elseif ($piece === ';' && $depth === 0) {
            return $text;
        }
    }

    return $text;
}

/**
 * Whether $statement (compact token text, see arch3_statement_text) is a direct,
 * unconditional and reachable statement of $body, under the same terminator rules
 * as the protected gateway ID assignment.
 */
function arch3_has_direct_statement_text(array $body, $statement)
{
    return arch3_has_direct_statement($body, static function (array $tokens, $i) use ($statement) {
        return arch3_statement_text($tokens, $i) === $statement;
    });
}

/**
 * Class body of a top-level class declared directly in a file; a leading `final`
 * or `abstract` modifier does not affect reachability, so it is ignored.
 */
function arch3_direct_file_class(array $tokens, $className)
{
    $filtered = array();
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        if (in_array($tokens[$i]['id'], array(T_FINAL, T_ABSTRACT), true)
            && isset($tokens[$i + 1]) && $tokens[$i + 1]['id'] === T_CLASS) {
            continue;
        }
        $filtered[] = $tokens[$i];
    }

    return arch3_direct_class($filtered, $className);
}

$gateway = arch3_read($root, 'supcheckout.php');
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
arch3_assert($coexistenceWrapped, 'main file uses exactly the approved official-plugin coexistence wrapper');
$gatewayUnwrapped = $coexistenceWrapped
    ? substr($gateway, 0, $coexistenceOpenAt) . substr($gateway, $coexistenceOpenAt + strlen($coexistenceOpen), -strlen($coexistenceClose)) . "
"
    : $gateway;
$gatewayTokens = arch3_tokens($gatewayUnwrapped);

$bootstrap = arch3_direct_top_level_hook_callback(
    $gatewayTokens,
    'add_action',
    'plugins_loaded',
    'woocommerceUpaymentsInit'
);
// Sub-project 0a Task 3: the gateway class is no longer declared inside the
// plugins_loaded callback. The same reachability rule (direct, unconditional,
// executable at every step) now follows the composition-root path instead:
// main file -> Plugin::boot() -> GatewayRegistration::register() ->
// woocommerce_payment_gateways -> Gateway::class -> src/Gateway/Gateway.php.
$pluginTokens = arch3_tokens(arch3_read($root, 'src/Plugin.php'));
$registrationTokens = arch3_tokens(arch3_read($root, 'src/Gateway/GatewayRegistration.php'));
$gatewayClassTokens = arch3_tokens(arch3_read($root, 'src/Gateway/Gateway.php'));
$pluginClass = arch3_direct_file_class($pluginTokens, 'Plugin');
$pluginBoot = $pluginClass['found']
    ? arch3_direct_public_method($pluginClass['body'], 'boot')
    : array('found' => false, 'body' => array());
$registrationClass = arch3_direct_file_class($registrationTokens, 'GatewayRegistration');
$registrationRegister = $registrationClass['found']
    ? arch3_direct_public_method($registrationClass['body'], 'register')
    : array('found' => false, 'body' => array());
$registrationFilter = $registrationClass['found']
    ? arch3_direct_public_method($registrationClass['body'], 'filter_gateways')
    : array('found' => false, 'body' => array());
$gatewayClass = arch3_direct_file_class($gatewayClassTokens, 'Gateway');
$constructor = $gatewayClass['found']
    ? arch3_direct_public_method($gatewayClass['body'], '__construct')
    : array('found' => false, 'body' => array());
$filterText = '';
foreach ($registrationFilter['body'] as $filterToken) {
    $filterText .= $filterToken['text'];
}

arch3_assert($gateway !== '', 'supcheckout.php is readable');
arch3_assert(!arch3_has_namespace_declaration($gatewayTokens), 'legacy main file remains global for bootstrap callbacks');
arch3_assert($bootstrap['found'], 'plugins_loaded directly registers and owns woocommerceUpaymentsInit');
arch3_assert(
    arch3_has_direct_statement_text($gatewayTokens, '\\Simplixi\\SUPCheckout\\Plugin::boot(__FILE__);'),
    'main file directly and unconditionally calls Plugin::boot(__FILE__)'
);
// Plugin::boot() registers the 'upayments' gateway, which the official plugin also
// owns until 0b: the call must sit inside the official-plugin pause guard, once.
$bootCall = '\\Simplixi\\SUPCheckout\\Plugin::boot(__FILE__);';
$bootAt = strpos($gateway, $bootCall);
$guardCloseAt = strlen($gateway) - strlen($coexistenceClose);
arch3_assert(
    $coexistenceWrapped
        && substr_count($gateway, $bootCall) === 1
        && $bootAt !== false
        && $bootAt > $coexistenceOpenAt + strlen($coexistenceOpen)
        && $bootAt < $guardCloseAt,
    'Plugin::boot(__FILE__) is called exactly once, inside the official-plugin pause guard'
);
arch3_assert(
    $pluginBoot['found'] && arch3_has_direct_statement_text($pluginBoot['body'], 'GatewayRegistration::register();'),
    'Plugin::boot directly and unconditionally calls GatewayRegistration::register()'
);
arch3_assert(
    $registrationRegister['found']
        && arch3_has_direct_statement_text($registrationRegister['body'], 'add_filter(woocommerce_payment_gateways,array(self::class,filter_gateways));'),
    'GatewayRegistration::register directly and unconditionally registers filter_gateways on woocommerce_payment_gateways'
);
arch3_assert(
    $registrationFilter['found']
        && strpos($filterText, 'if(!in_array(Gateway::class,$methods,true)){$methods[]=Gateway::class;}') !== false
        // A return is itself a terminator, so it is pinned as the body's final statement.
        && substr($filterText, -strlen('return$methods;')) === 'return$methods;',
    'filter_gateways hands WooCommerce the namespaced Gateway class'
);
arch3_assert($gatewayClass['found'], 'src/Gateway/Gateway.php directly and unconditionally declares the Gateway class');
arch3_assert($constructor['found'], 'Gateway constructor remains a direct public method');
arch3_assert(
    $constructor['found'] && arch3_has_direct_gateway_id_assignment($constructor['body']),
    'gateway ID upayments is an unconditional direct constructor statement'
);

$missingHookFixture = <<<'PHP'
<?php
function woocommerceUpaymentsInit() {
    class WC_Upayments {
        public function __construct() { $this->id = 'upayments'; }
    }
}
PHP;
arch3_assert(
    !arch3_direct_top_level_hook_callback(
        arch3_tokens($missingHookFixture),
        'add_action',
        'plugins_loaded',
        'woocommerceUpaymentsInit'
    )['found'],
    'bootstrap guard rejects unregistered init callback'
);

$nestedClassFixture = <<<'PHP'
<?php
add_action('plugins_loaded', 'woocommerceUpaymentsInit');
function woocommerceUpaymentsInit() {
    if (false) {
        class WC_Upayments {
            public function __construct() { $this->id = 'upayments'; }
        }
    }
}
PHP;
$nestedBootstrap = arch3_direct_top_level_hook_callback(
    arch3_tokens($nestedClassFixture),
    'add_action',
    'plugins_loaded',
    'woocommerceUpaymentsInit'
);
arch3_assert(
    $nestedBootstrap['found']
        && !arch3_direct_class($nestedBootstrap['body'], 'WC_Upayments')['found'],
    'bootstrap guard rejects gateway class hidden in dead braced control flow'
);

$bracedIdFixture = <<<'PHP'
<?php
add_action('plugins_loaded', 'woocommerceUpaymentsInit');
function woocommerceUpaymentsInit() {
    class WC_Upayments {
        public function __construct() {
            if (false) { $this->id = 'upayments'; }
        }
    }
}
PHP;
$bracedBootstrap = arch3_direct_top_level_hook_callback(
    arch3_tokens($bracedIdFixture),
    'add_action',
    'plugins_loaded',
    'woocommerceUpaymentsInit'
);
$bracedClass = arch3_direct_class($bracedBootstrap['body'], 'WC_Upayments');
$bracedConstructor = arch3_direct_public_method($bracedClass['body'], '__construct');
arch3_assert(
    $bracedConstructor['found'] && !arch3_has_direct_gateway_id_assignment($bracedConstructor['body']),
    'constructor guard rejects gateway ID hidden in braced conditional'
);

$bracelessIdFixture = <<<'PHP'
<?php
add_action('plugins_loaded', 'woocommerceUpaymentsInit');
function woocommerceUpaymentsInit() {
    class WC_Upayments {
        public function __construct() {
            if (false) $this->id = 'upayments';
        }
    }
}
PHP;
$bracelessBootstrap = arch3_direct_top_level_hook_callback(
    arch3_tokens($bracelessIdFixture),
    'add_action',
    'plugins_loaded',
    'woocommerceUpaymentsInit'
);
$bracelessClass = arch3_direct_class($bracelessBootstrap['body'], 'WC_Upayments');
$bracelessConstructor = arch3_direct_public_method($bracelessClass['body'], '__construct');
arch3_assert(
    $bracelessConstructor['found'] && !arch3_has_direct_gateway_id_assignment($bracelessConstructor['body']),
    'constructor guard rejects gateway ID hidden in brace-less conditional'
);

$alternativeIdFixture = <<<'PHP'
<?php
add_action('plugins_loaded', 'woocommerceUpaymentsInit');
function woocommerceUpaymentsInit() {
    class WC_Upayments {
        public function __construct() {
            if (false):
                $this->id = 'upayments';
            endif;
        }
    }
}
PHP;
$alternativeBootstrap = arch3_direct_top_level_hook_callback(
    arch3_tokens($alternativeIdFixture),
    'add_action',
    'plugins_loaded',
    'woocommerceUpaymentsInit'
);
$alternativeClass = arch3_direct_class($alternativeBootstrap['body'], 'WC_Upayments');
$alternativeConstructor = arch3_direct_public_method($alternativeClass['body'], '__construct');
arch3_assert(
    $alternativeConstructor['found'] && !arch3_has_direct_gateway_id_assignment($alternativeConstructor['body']),
    'constructor guard rejects gateway ID hidden in alternative-syntax conditional'
);

$terminatorFixtures = array(
    'return' => array('statement' => 'return;', 'label' => ''),
    'exit' => array('statement' => 'exit;', 'label' => ''),
    'throw' => array('statement' => 'throw new RuntimeException("halt");', 'label' => ''),
    'goto' => array('statement' => 'goto arch3_after;', 'label' => 'arch3_after: ;'),
    'label-prefixed return' => array('statement' => 'arch3_stage: return;', 'label' => ''),
    'label-prefixed exit' => array('statement' => 'arch3_stage: exit;', 'label' => ''),
    'label-prefixed throw' => array('statement' => 'arch3_stage: throw new RuntimeException("halt");', 'label' => ''),
    'label-prefixed goto' => array(
        'statement' => 'arch3_stage: goto arch3_after;',
        'label' => 'arch3_after: ;',
    ),
);

foreach ($terminatorFixtures as $name => $fixture) {
    $topLevelSource = "<?php\n"
        . $fixture['statement'] . "\n"
        . "add_action('plugins_loaded', 'woocommerceUpaymentsInit');\n"
        . "function woocommerceUpaymentsInit() {\n"
        . "    class WC_Upayments {\n"
        . "        public function __construct() { \$this->id = 'upayments'; }\n"
        . "    }\n"
        . "}\n"
        . $fixture['label'] . "\n";
    arch3_assert(
        !arch3_direct_top_level_hook_callback(
            arch3_tokens($topLevelSource),
            'add_action',
            'plugins_loaded',
            'woocommerceUpaymentsInit'
        )['found'],
        "bootstrap guard rejects {$name}-terminated registration path"
    );

    $classSource = "<?php\n"
        . "add_action('plugins_loaded', 'woocommerceUpaymentsInit');\n"
        . "function woocommerceUpaymentsInit() {\n"
        . "    " . $fixture['statement'] . "\n"
        . "    class WC_Upayments {\n"
        . "        public function __construct() { \$this->id = 'upayments'; }\n"
        . "    }\n"
        . "    " . $fixture['label'] . "\n"
        . "}\n";
    $classBootstrap = arch3_direct_top_level_hook_callback(
        arch3_tokens($classSource),
        'add_action',
        'plugins_loaded',
        'woocommerceUpaymentsInit'
    );
    arch3_assert(
        $classBootstrap['found']
            && !arch3_direct_class($classBootstrap['body'], 'WC_Upayments')['found'],
        "bootstrap guard rejects {$name}-terminated gateway-class path"
    );

    $constructorSource = "<?php\n"
        . "add_action('plugins_loaded', 'woocommerceUpaymentsInit');\n"
        . "function woocommerceUpaymentsInit() {\n"
        . "    class WC_Upayments {\n"
        . "        public function __construct() {\n"
        . "            " . $fixture['statement'] . "\n"
        . "            \$this->id = 'upayments';\n"
        . "            " . $fixture['label'] . "\n"
        . "        }\n"
        . "    }\n"
        . "}\n";
    $constructorBootstrap = arch3_direct_top_level_hook_callback(
        arch3_tokens($constructorSource),
        'add_action',
        'plugins_loaded',
        'woocommerceUpaymentsInit'
    );
    $terminatedClass = arch3_direct_class($constructorBootstrap['body'], 'WC_Upayments');
    $terminatedConstructor = arch3_direct_public_method($terminatedClass['body'], '__construct');
    arch3_assert(
        $terminatedConstructor['found']
            && !arch3_has_direct_gateway_id_assignment($terminatedConstructor['body']),
        "constructor guard rejects gateway ID after direct {$name} terminator"
    );
}

foreach ($terminatorFixtures as $name => $fixture) {
    $wrappedPaths = array(
        'unconditional-block' => "{\n" . $fixture['statement'] . "\n}\n",
        'mandatory-do-block' => "do {\n" . $fixture['statement'] . "\n} while (false);\n",
    );

    foreach ($wrappedPaths as $pathName => $wrappedStatement) {

    $topLevelSource = "<?php\n"
        . $wrappedStatement
        . "add_action('plugins_loaded', 'woocommerceUpaymentsInit');\n"
        . "function woocommerceUpaymentsInit() {\n"
        . "    class WC_Upayments {\n"
        . "        public function __construct() { \$this->id = 'upayments'; }\n"
        . "    }\n"
        . "}\n"
        . $fixture['label'] . "\n";
    arch3_assert(
        !arch3_direct_top_level_hook_callback(
            arch3_tokens($topLevelSource),
            'add_action',
            'plugins_loaded',
            'woocommerceUpaymentsInit'
        )['found'],
        "bootstrap guard rejects {$pathName} {$name}-terminated registration path"
    );

    $classSource = "<?php\n"
        . "add_action('plugins_loaded', 'woocommerceUpaymentsInit');\n"
        . "function woocommerceUpaymentsInit() {\n"
        . "    " . str_replace("\n", "\n    ", $wrappedStatement)
        . "class WC_Upayments {\n"
        . "        public function __construct() { \$this->id = 'upayments'; }\n"
        . "    }\n"
        . "    " . $fixture['label'] . "\n"
        . "}\n";
    $classBootstrap = arch3_direct_top_level_hook_callback(
        arch3_tokens($classSource),
        'add_action',
        'plugins_loaded',
        'woocommerceUpaymentsInit'
    );
    arch3_assert(
        $classBootstrap['found']
            && !arch3_direct_class($classBootstrap['body'], 'WC_Upayments')['found'],
        "bootstrap guard rejects {$pathName} {$name}-terminated gateway-class path"
    );

    $constructorSource = "<?php\n"
        . "add_action('plugins_loaded', 'woocommerceUpaymentsInit');\n"
        . "function woocommerceUpaymentsInit() {\n"
        . "    class WC_Upayments {\n"
        . "        public function __construct() {\n"
        . "            " . str_replace("\n", "\n            ", $wrappedStatement)
        . "\$this->id = 'upayments';\n"
        . "            " . $fixture['label'] . "\n"
        . "        }\n"
        . "    }\n"
        . "}\n";
    $constructorBootstrap = arch3_direct_top_level_hook_callback(
        arch3_tokens($constructorSource),
        'add_action',
        'plugins_loaded',
        'woocommerceUpaymentsInit'
    );
    $wrappedClass = arch3_direct_class($constructorBootstrap['body'], 'WC_Upayments');
    $wrappedConstructor = arch3_direct_public_method($wrappedClass['body'], '__construct');
    arch3_assert(
        $wrappedConstructor['found']
            && !arch3_has_direct_gateway_id_assignment($wrappedConstructor['body']),
        "constructor guard rejects gateway ID after {$pathName} {$name} terminator"
    );
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
    $topLevelSource = "<?php\n"
        . $path
        . "add_action('plugins_loaded', 'woocommerceUpaymentsInit');\n"
        . "function woocommerceUpaymentsInit() {\n"
        . "    class WC_Upayments {\n"
        . "        public function __construct() { \$this->id = 'upayments'; }\n"
        . "    }\n"
        . "}\n";
    arch3_assert(
        !arch3_direct_top_level_hook_callback(
            arch3_tokens($topLevelSource),
            'add_action',
            'plugins_loaded',
            'woocommerceUpaymentsInit'
        )['found'],
        "bootstrap guard rejects registration after terminator in {$pathName}"
    );

    $classSource = "<?php\n"
        . "add_action('plugins_loaded', 'woocommerceUpaymentsInit');\n"
        . "function woocommerceUpaymentsInit() {\n"
        . "    " . str_replace("\n", "\n    ", $path)
        . "class WC_Upayments {\n"
        . "        public function __construct() { \$this->id = 'upayments'; }\n"
        . "    }\n"
        . "}\n";
    $classBootstrap = arch3_direct_top_level_hook_callback(
        arch3_tokens($classSource),
        'add_action',
        'plugins_loaded',
        'woocommerceUpaymentsInit'
    );
    arch3_assert(
        $classBootstrap['found']
            && !arch3_direct_class($classBootstrap['body'], 'WC_Upayments')['found'],
        "bootstrap guard rejects gateway class after terminator in {$pathName}"
    );

    $constructorSource = "<?php\n"
        . "add_action('plugins_loaded', 'woocommerceUpaymentsInit');\n"
        . "function woocommerceUpaymentsInit() {\n"
        . "    class WC_Upayments {\n"
        . "        public function __construct() {\n"
        . "            " . str_replace("\n", "\n            ", $path)
        . "\$this->id = 'upayments';\n"
        . "        }\n"
        . "    }\n"
        . "}\n";
    $constructorBootstrap = arch3_direct_top_level_hook_callback(
        arch3_tokens($constructorSource),
        'add_action',
        'plugins_loaded',
        'woocommerceUpaymentsInit'
    );
    $compoundClass = arch3_direct_class($constructorBootstrap['body'], 'WC_Upayments');
    $compoundConstructor = arch3_direct_public_method($compoundClass['body'], '__construct');
    arch3_assert(
        $compoundConstructor['found']
            && !arch3_has_direct_gateway_id_assignment($compoundConstructor['body']),
        "constructor guard rejects gateway ID after terminator in {$pathName}"
    );
}

$nestedBareBlockFixture = <<<'PHP'
<?php
{
    {
        return;
    }
}
add_action('plugins_loaded', 'woocommerceUpaymentsInit');
function woocommerceUpaymentsInit() {
    class WC_Upayments {
        public function __construct() { $this->id = 'upayments'; }
    }
}
PHP;
arch3_assert(
    !arch3_direct_top_level_hook_callback(
        arch3_tokens($nestedBareBlockFixture),
        'add_action',
        'plugins_loaded',
        'woocommerceUpaymentsInit'
    )['found'],
    'bootstrap guard rejects registration after nested unconditional-block terminator'
);

$reachableBareBlockFixture = <<<'PHP'
<?php
{
    add_action('plugins_loaded', 'woocommerceUpaymentsInit');
    function woocommerceUpaymentsInit() {
        {
            class WC_Upayments {
                public function __construct() {
                    {
                        $this->id = 'upayments';
                    }
                }
            }
        }
    }
}
PHP;
$reachableBareBootstrap = arch3_direct_top_level_hook_callback(
    arch3_tokens($reachableBareBlockFixture),
    'add_action',
    'plugins_loaded',
    'woocommerceUpaymentsInit'
);
$reachableBareClass = $reachableBareBootstrap['found']
    ? arch3_direct_class($reachableBareBootstrap['body'], 'WC_Upayments')
    : array('found' => false, 'body' => array());
$reachableBareConstructor = $reachableBareClass['found']
    ? arch3_direct_public_method($reachableBareClass['body'], '__construct')
    : array('found' => false, 'body' => array());
arch3_assert(
    $reachableBareConstructor['found']
        && arch3_has_direct_gateway_id_assignment($reachableBareConstructor['body']),
    'bootstrap guard accepts reachable protected path inside unconditional blocks'
);

$reachableDoBlockFixture = <<<'PHP'
<?php
do {
    add_action('plugins_loaded', 'woocommerceUpaymentsInit');
    function woocommerceUpaymentsInit() {
        do {
            class WC_Upayments {
                public function __construct() {
                    do {
                        $this->id = 'upayments';
                    } while (false);
                }
            }
        } while (false);
    }
} while (false);
PHP;
$reachableDoBootstrap = arch3_direct_top_level_hook_callback(
    arch3_tokens($reachableDoBlockFixture),
    'add_action',
    'plugins_loaded',
    'woocommerceUpaymentsInit'
);
$reachableDoClass = $reachableDoBootstrap['found']
    ? arch3_direct_class($reachableDoBootstrap['body'], 'WC_Upayments')
    : array('found' => false, 'body' => array());
$reachableDoConstructor = $reachableDoClass['found']
    ? arch3_direct_public_method($reachableDoClass['body'], '__construct')
    : array('found' => false, 'body' => array());
arch3_assert(
    $reachableDoConstructor['found']
        && arch3_has_direct_gateway_id_assignment($reachableDoConstructor['body']),
    'bootstrap guard accepts reachable protected path inside mandatory do blocks'
);

$reachableCompoundFixture = <<<'PHP'
<?php
try {
    add_action('plugins_loaded', 'woocommerceUpaymentsInit');
    function woocommerceUpaymentsInit() {
        declare(ticks=1) {
            class WC_Upayments {
                public function __construct() {
                    declare(ticks=1):
                        $this->id = 'upayments';
                    enddeclare;
                }
            }
        }
    }
} finally {}
PHP;
$reachableCompoundBootstrap = arch3_direct_top_level_hook_callback(
    arch3_tokens($reachableCompoundFixture),
    'add_action',
    'plugins_loaded',
    'woocommerceUpaymentsInit'
);
$reachableCompoundClass = $reachableCompoundBootstrap['found']
    ? arch3_direct_class($reachableCompoundBootstrap['body'], 'WC_Upayments')
    : array('found' => false, 'body' => array());
$reachableCompoundConstructor = $reachableCompoundClass['found']
    ? arch3_direct_public_method($reachableCompoundClass['body'], '__construct')
    : array('found' => false, 'body' => array());
arch3_assert(
    $reachableCompoundConstructor['found']
        && arch3_has_direct_gateway_id_assignment($reachableCompoundConstructor['body']),
    'bootstrap guard accepts reachable protected path inside mandatory try and declare paths'
);

$caughtThrowFixture = <<<'PHP'
<?php
try { throw new RuntimeException('caught'); }
catch (RuntimeException $error) {}
add_action('plugins_loaded', 'woocommerceUpaymentsInit');
function woocommerceUpaymentsInit() {
    try { throw new RuntimeException('caught'); }
    catch (RuntimeException $error) {}
    class WC_Upayments {
        public function __construct() {
            try { throw new RuntimeException('caught'); }
            catch (RuntimeException $error) {}
            $this->id = 'upayments';
        }
    }
}
PHP;
$caughtThrowBootstrap = arch3_direct_top_level_hook_callback(
    arch3_tokens($caughtThrowFixture),
    'add_action',
    'plugins_loaded',
    'woocommerceUpaymentsInit'
);
$caughtThrowClass = $caughtThrowBootstrap['found']
    ? arch3_direct_class($caughtThrowBootstrap['body'], 'WC_Upayments')
    : array('found' => false, 'body' => array());
$caughtThrowConstructor = $caughtThrowClass['found']
    ? arch3_direct_public_method($caughtThrowClass['body'], '__construct')
    : array('found' => false, 'body' => array());
arch3_assert($caughtThrowBootstrap['found'], 'bootstrap guard accepts registration after a caught direct throw');
arch3_assert($caughtThrowClass['found'], 'bootstrap guard accepts gateway class after a caught direct throw');
arch3_assert(
    $caughtThrowConstructor['found']
        && arch3_has_direct_gateway_id_assignment($caughtThrowConstructor['body']),
    'constructor guard accepts gateway ID after a caught direct throw'
);

$finallyReachabilityFixture = <<<'PHP'
<?php
try { return; }
finally {
    add_action('plugins_loaded', 'woocommerceUpaymentsInit');
    function woocommerceUpaymentsInit() {
        try { return; }
        finally {
            class WC_Upayments {
                public function __construct() {
                    try { return; }
                    finally { $this->id = 'upayments'; }
                }
            }
        }
    }
}
PHP;
$finallyBootstrap = arch3_direct_top_level_hook_callback(
    arch3_tokens($finallyReachabilityFixture),
    'add_action',
    'plugins_loaded',
    'woocommerceUpaymentsInit'
);
$finallyClass = $finallyBootstrap['found']
    ? arch3_direct_class($finallyBootstrap['body'], 'WC_Upayments')
    : array('found' => false, 'body' => array());
$finallyConstructor = $finallyClass['found']
    ? arch3_direct_public_method($finallyClass['body'], '__construct')
    : array('found' => false, 'body' => array());
arch3_assert($finallyBootstrap['found'], 'bootstrap guard accepts registration reached through finally');
arch3_assert($finallyClass['found'], 'bootstrap guard accepts gateway class reached through finally');
arch3_assert(
    $finallyConstructor['found'] && arch3_has_direct_gateway_id_assignment($finallyConstructor['body']),
    'constructor guard accepts gateway ID reached through finally'
);

$arch3StageResults = function ($before, $after) {
    $topLevelSource = "<?php\n"
        . $before
        . "add_action('plugins_loaded', 'woocommerceUpaymentsInit');\n"
        . "function woocommerceUpaymentsInit() {\n"
        . "    class WC_Upayments {\n"
        . "        public function __construct() { \$this->id = 'upayments'; }\n"
        . "    }\n"
        . "}\n"
        . $after;
    $topLevel = arch3_direct_top_level_hook_callback(
        arch3_tokens($topLevelSource),
        'add_action',
        'plugins_loaded',
        'woocommerceUpaymentsInit'
    );

    $classSource = "<?php\n"
        . "add_action('plugins_loaded', 'woocommerceUpaymentsInit');\n"
        . "function woocommerceUpaymentsInit() {\n"
        . "    " . str_replace("\n", "\n    ", $before)
        . "class WC_Upayments {\n"
        . "        public function __construct() { \$this->id = 'upayments'; }\n"
        . "    }\n"
        . "    " . str_replace("\n", "\n    ", $after)
        . "}\n";
    $classBootstrap = arch3_direct_top_level_hook_callback(
        arch3_tokens($classSource),
        'add_action',
        'plugins_loaded',
        'woocommerceUpaymentsInit'
    );
    $class = $classBootstrap['found']
        ? arch3_direct_class($classBootstrap['body'], 'WC_Upayments')
        : array('found' => false, 'body' => array());

    $constructorSource = "<?php\n"
        . "add_action('plugins_loaded', 'woocommerceUpaymentsInit');\n"
        . "function woocommerceUpaymentsInit() {\n"
        . "    class WC_Upayments {\n"
        . "        public function __construct() {\n"
        . "            " . str_replace("\n", "\n            ", $before)
        . "\$this->id = 'upayments';\n"
        . "            " . str_replace("\n", "\n            ", $after)
        . "        }\n"
        . "    }\n"
        . "}\n";
    $constructorBootstrap = arch3_direct_top_level_hook_callback(
        arch3_tokens($constructorSource),
        'add_action',
        'plugins_loaded',
        'woocommerceUpaymentsInit'
    );
    $constructorClass = $constructorBootstrap['found']
        ? arch3_direct_class($constructorBootstrap['body'], 'WC_Upayments')
        : array('found' => false, 'body' => array());
    $constructor = $constructorClass['found']
        ? arch3_direct_public_method($constructorClass['body'], '__construct')
        : array('found' => false, 'body' => array());

    return array(
        'registration' => $topLevel['found'],
        'class' => $class['found'],
        'id' => $constructor['found'] && arch3_has_direct_gateway_id_assignment($constructor['body']),
    );
};

$mismatchedCatchResults = $arch3StageResults(
    "try { throw new RuntimeException('uncaught'); }\ncatch (InvalidArgumentException \$error) {}\n",
    ''
);
arch3_assert(!$mismatchedCatchResults['registration'], 'bootstrap guard rejects type-incompatible caught registration');
arch3_assert(!$mismatchedCatchResults['class'], 'bootstrap guard rejects type-incompatible caught gateway class');
arch3_assert(!$mismatchedCatchResults['id'], 'constructor guard rejects type-incompatible caught gateway ID');

$gotoSkipResults = $arch3StageResults(
    "try {\n    goto arch3_skip_stage;\n",
    "    arch3_skip_stage: ;\n}\nfinally {}\n"
);
arch3_assert(!$gotoSkipResults['registration'], 'bootstrap guard rejects registration skipped by try goto');
arch3_assert(!$gotoSkipResults['class'], 'bootstrap guard rejects gateway class skipped by try goto');
arch3_assert(!$gotoSkipResults['id'], 'constructor guard rejects gateway ID skipped by try goto');

$finallyOverrideResults = $arch3StageResults(
    "try {\n    try { return; }\n    finally { throw new RuntimeException('override'); }\n}\ncatch (RuntimeException \$error) {}\n",
    ''
);
arch3_assert($finallyOverrideResults['registration'], 'bootstrap guard accepts registration after caught finally override');
arch3_assert($finallyOverrideResults['class'], 'bootstrap guard accepts gateway class after caught finally override');
arch3_assert($finallyOverrideResults['id'], 'constructor guard accepts gateway ID after caught finally override');

$orderedCatchResults = $arch3StageResults(
    "try { throw new RuntimeException('caught first'); }\n"
        . "catch (Exception \$error) { return; }\n"
        . "catch (RuntimeException \$error) {}\n",
    ''
);
arch3_assert(!$orderedCatchResults['registration'], 'bootstrap guard honors first compatible catch for registration');
arch3_assert(!$orderedCatchResults['class'], 'bootstrap guard honors first compatible catch for gateway class');
arch3_assert(!$orderedCatchResults['id'], 'constructor guard honors first compatible catch for gateway ID');

$terminatingInnerCatchResults = $arch3StageResults(
    "try {\n"
        . "    try { throw new RuntimeException('caught inside'); }\n"
        . "    catch (RuntimeException \$error) { return; }\n"
        . "}\n"
        . "catch (RuntimeException \$error) {}\n",
    ''
);
arch3_assert(!$terminatingInnerCatchResults['registration'], 'bootstrap guard propagates inner catch return past outer catch');
arch3_assert(!$terminatingInnerCatchResults['class'], 'bootstrap guard propagates inner class catch return past outer catch');
arch3_assert(!$terminatingInnerCatchResults['id'], 'constructor guard propagates inner catch return past outer catch');

$nestedTerminatingCatchResults = $arch3StageResults(
    "try {\n"
        . "    try { throw new RuntimeException('caught outside'); }\n"
        . "    catch (RuntimeException \$outerError) {\n"
        . "        try { throw new RuntimeException('caught inside'); }\n"
        . "        catch (RuntimeException \$innerError) { return; }\n"
        . "    }\n"
        . "}\n"
        . "catch (RuntimeException \$error) {}\n",
    ''
);
arch3_assert(!$nestedTerminatingCatchResults['registration'], 'bootstrap guard resolves nested catch return before outer registration catch');
arch3_assert(!$nestedTerminatingCatchResults['class'], 'bootstrap guard resolves nested catch return before outer class catch');
arch3_assert(!$nestedTerminatingCatchResults['id'], 'constructor guard resolves nested catch return before outer ID catch');

$nestedReplacementThrowResults = $arch3StageResults(
    "try {\n"
        . "    try { throw new RuntimeException('caught outside'); }\n"
        . "    catch (RuntimeException \$outerError) {\n"
        . "        try { throw new RuntimeException('caught inside'); }\n"
        . "        catch (RuntimeException \$innerError) { throw new InvalidArgumentException('replacement'); }\n"
        . "    }\n"
        . "}\n"
        . "catch (InvalidArgumentException \$error) {}\n",
    ''
);
arch3_assert($nestedReplacementThrowResults['registration'], 'bootstrap guard resolves nested replacement throw before registration');
arch3_assert($nestedReplacementThrowResults['class'], 'bootstrap guard resolves nested replacement throw before gateway class');
arch3_assert($nestedReplacementThrowResults['id'], 'constructor guard resolves nested replacement throw before gateway ID');

$compatibleRethrowResults = $arch3StageResults(
    "try {\n"
        . "    try { throw new RuntimeException('caught inside'); }\n"
        . "    catch (RuntimeException \$error) { throw new InvalidArgumentException('replacement'); }\n"
        . "}\n"
        . "catch (InvalidArgumentException \$error) {}\n",
    ''
);
arch3_assert($compatibleRethrowResults['registration'], 'bootstrap guard accepts replacement throw caught before registration');
arch3_assert($compatibleRethrowResults['class'], 'bootstrap guard accepts replacement throw caught before gateway class');
arch3_assert($compatibleRethrowResults['id'], 'constructor guard accepts replacement throw caught before gateway ID');

$incompatibleRethrowResults = $arch3StageResults(
    "try {\n"
        . "    try { throw new RuntimeException('caught inside'); }\n"
        . "    catch (RuntimeException \$error) { throw new InvalidArgumentException('replacement'); }\n"
        . "}\n"
        . "catch (RuntimeException \$error) {}\n",
    ''
);
arch3_assert(!$incompatibleRethrowResults['registration'], 'bootstrap guard rejects replacement throw at incompatible registration catch');
arch3_assert(!$incompatibleRethrowResults['class'], 'bootstrap guard rejects replacement throw at incompatible class catch');
arch3_assert(!$incompatibleRethrowResults['id'], 'constructor guard rejects replacement throw at incompatible ID catch');

$forwardGotoCatchResults = $arch3StageResults(
    "try { throw new RuntimeException('caught'); }\n"
        . "catch (RuntimeException \$error) {\n"
        . "    goto arch3_resume_catch;\n"
        . "    return;\n"
        . "    arch3_resume_catch: ;\n"
        . "}\n",
    ''
);
arch3_assert($forwardGotoCatchResults['registration'], 'bootstrap guard accepts forward catch goto before registration');
arch3_assert($forwardGotoCatchResults['class'], 'bootstrap guard accepts forward catch goto before gateway class');
arch3_assert($forwardGotoCatchResults['id'], 'constructor guard accepts forward catch goto before gateway ID');

$localCatchFinallyResults = $arch3StageResults(
    "try { return; }\n"
        . "finally {\n"
        . "    try { throw new RuntimeException('caught locally'); }\n"
        . "    catch (RuntimeException \$error) {}\n"
        . "}\n",
    ''
);
arch3_assert(!$localCatchFinallyResults['registration'], 'bootstrap guard preserves return across local finally catch');
arch3_assert(!$localCatchFinallyResults['class'], 'bootstrap guard preserves class return across local finally catch');
arch3_assert(!$localCatchFinallyResults['id'], 'constructor guard preserves return across local finally catch');

$nestedLocalCatchFinallyResults = $arch3StageResults(
    "try { return; }\n"
        . "finally {\n"
        . "    try {\n"
        . "        try {}\n"
        . "        finally { throw new RuntimeException('caught locally'); }\n"
        . "    }\n"
        . "    catch (RuntimeException \$error) {}\n"
        . "}\n",
    ''
);
arch3_assert(!$nestedLocalCatchFinallyResults['registration'], 'bootstrap guard preserves return across nested local finally catch');
arch3_assert(!$nestedLocalCatchFinallyResults['class'], 'bootstrap guard preserves class return across nested local finally catch');
arch3_assert(!$nestedLocalCatchFinallyResults['id'], 'constructor guard preserves return across nested local finally catch');

$conditionalTerminatorFixture = <<<'PHP'
<?php
if (false) { return; }
add_action('plugins_loaded', 'woocommerceUpaymentsInit');
function woocommerceUpaymentsInit() {
    if (false) return;
    class WC_Upayments {
        public function __construct() {
            if (false):
                return;
            endif;
            $this->id = 'upayments';
        }
    }
}
PHP;
$conditionalBootstrap = arch3_direct_top_level_hook_callback(
    arch3_tokens($conditionalTerminatorFixture),
    'add_action',
    'plugins_loaded',
    'woocommerceUpaymentsInit'
);
$conditionalClass = $conditionalBootstrap['found']
    ? arch3_direct_class($conditionalBootstrap['body'], 'WC_Upayments')
    : array('found' => false, 'body' => array());
$conditionalConstructor = $conditionalClass['found']
    ? arch3_direct_public_method($conditionalClass['body'], '__construct')
    : array('found' => false, 'body' => array());
arch3_assert($conditionalBootstrap['found'], 'bootstrap guard accepts registration after conditional return');
arch3_assert($conditionalClass['found'], 'bootstrap guard accepts gateway class after conditional return');
arch3_assert(
    $conditionalConstructor['found']
        && arch3_has_direct_gateway_id_assignment($conditionalConstructor['body']),
    'constructor guard accepts direct gateway ID after conditional return'
);

$validFixture = <<<'PHP'
<?php
add_action('plugins_loaded', 'woocommerceUpaymentsInit');
function woocommerceUpaymentsInit() {
    if (!class_exists('WooCommerce')) { return; }
    class WC_Upayments {
        public function __construct() {
            $this->id = 'upayments';
        }
    }
}
PHP;
$validBootstrap = arch3_direct_top_level_hook_callback(
    arch3_tokens($validFixture),
    'add_action',
    'plugins_loaded',
    'woocommerceUpaymentsInit'
);
$validClass = arch3_direct_class($validBootstrap['body'], 'WC_Upayments');
$validConstructor = arch3_direct_public_method($validClass['body'], '__construct');
arch3_assert($validBootstrap['found'], 'bootstrap guard accepts direct plugins_loaded registration/callback');
arch3_assert($validClass['found'], 'bootstrap guard accepts direct gateway class ownership');
arch3_assert(
    $validConstructor['found'] && arch3_has_direct_gateway_id_assignment($validConstructor['body']),
    'constructor guard accepts direct protected gateway ID assignment'
);

// Sensitivity of the composition-root statement guard (Sub-project 0a Task 3).
$statementTokens = function ($body) {
    return arch3_tokens("<?php\n" . $body);
};
arch3_assert(
    arch3_has_direct_statement_text($statementTokens("if (\$booted) { return; }\nGatewayRegistration::register();\n"), 'GatewayRegistration::register();'),
    'statement guard accepts a direct registration after a conditional return'
);
arch3_assert(
    !arch3_has_direct_statement_text($statementTokens("if (\$enabled) { GatewayRegistration::register(); }\n"), 'GatewayRegistration::register();'),
    'statement guard rejects a registration hidden in a conditional'
);
arch3_assert(
    !arch3_has_direct_statement_text($statementTokens("return;\nGatewayRegistration::register();\n"), 'GatewayRegistration::register();'),
    'statement guard rejects a registration after a direct return'
);
arch3_assert(
    !arch3_has_direct_statement_text($statementTokens("\$x = function () { GatewayRegistration::register(); };\n"), 'GatewayRegistration::register();'),
    'statement guard rejects a registration inside a nested closure'
);
arch3_assert(
    arch3_direct_file_class(arch3_tokens("<?php\nnamespace A;\ndefined('ABSPATH') || exit;\nfinal class Plugin {}\n"), 'Plugin')['found'],
    'file class guard accepts a final class after the ABSPATH guard'
);
arch3_assert(
    !arch3_direct_file_class(arch3_tokens("<?php\nnamespace A;\nif (false) { final class Plugin {} }\n"), 'Plugin')['found'],
    'file class guard rejects a conditionally declared class'
);

printf("\nArchitecture Bootstrap Paths: %d PASS / %d FAIL\n", $pass, $fail);
echo 'HARNESS-COMPLETE: ' . basename(__FILE__) . "\n";
exit($fail === 0 ? 0 : 1);
