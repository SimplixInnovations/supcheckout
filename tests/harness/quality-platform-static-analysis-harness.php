<?php

$q2_pass = 0;
$q2_fail = 0;
$q2_root = dirname(__DIR__, 2);

function q2_assert($condition, $label) {
    global $q2_pass, $q2_fail;

    if ($condition) {
        ++$q2_pass;
        echo "PASS: {$label}\n";
        return;
    }

    ++$q2_fail;
    echo "FAIL: {$label}\n";
}

function q2_read($root, $relative) {
    $contents = file_get_contents($root . '/' . $relative);
    q2_assert(is_string($contents), "source readable: {$relative}");
    return is_string($contents) ? $contents : '';
}

function q2_contains($source, $needle) {
    return strpos($source, $needle) !== false;
}


/**
 * Return plugin-owned translation calls whose text-domain argument is not the
 * canonical SUPCheckout literal.
 *
 * @param string $root Repository root.
 * @return array<int,array{path:string,line:int,function:string,domain:string}>
 */
function q2_supcheckout_i18n_violations($root) {
    $functions = array(
        '__' => 1,
        '_e' => 1,
        '_x' => 2,
        '_ex' => 2,
        '_n' => 3,
        '_nx' => 4,
        '_n_noop' => 2,
        '_nx_noop' => 3,
        'esc_html__' => 1,
        'esc_html_e' => 1,
        'esc_attr__' => 1,
        'esc_attr_e' => 1,
    );
    $paths = array('supcheckout.php');
    foreach (array('src', 'includes') as $directory) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
                $paths[] = str_replace('\\\\', '/', substr($file->getPathname(), strlen($root) + 1));
            }
        }
    }

    sort($paths);
    $violations = array();

    foreach ($paths as $relative) {
        $source = file_get_contents($root . '/' . $relative);
        if (!is_string($source)) {
            $violations[] = array(
                'path' => $relative,
                'line' => 0,
                'function' => 'read',
                'domain' => '<unreadable>',
            );
            continue;
        }

        $tokens = token_get_all($source);
        $count = count($tokens);
        for ($i = 0; $i < $count; ++$i) {
            if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_STRING) {
                continue;
            }

            $function = strtolower($tokens[$i][1]);
            if (!isset($functions[$function])) {
                continue;
            }

            $j = $i + 1;
            while (
                $j < $count
                && is_array($tokens[$j])
                && in_array($tokens[$j][0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)
            ) {
                ++$j;
            }
            if ($j >= $count || $tokens[$j] !== '(') {
                continue;
            }

            $args = array('');
            $arg_index = 0;
            $paren = 1;
            $square = 0;
            $brace = 0;

            for (++$j; $j < $count && $paren > 0; ++$j) {
                $token = $tokens[$j];
                $text = is_array($token) ? $token[1] : $token;

                if (!is_array($token)) {
                    if ($text === '(') {
                        ++$paren;
                    } elseif ($text === ')') {
                        --$paren;
                        if ($paren === 0) {
                            break;
                        }
                    } elseif ($text === '[') {
                        ++$square;
                    } elseif ($text === ']') {
                        --$square;
                    } elseif ($text === '{') {
                        ++$brace;
                    } elseif ($text === '}') {
                        --$brace;
                    } elseif ($text === ',' && $paren === 1 && $square === 0 && $brace === 0) {
                        ++$arg_index;
                        $args[$arg_index] = '';
                        continue;
                    }
                }

                $args[$arg_index] .= $text;
            }

            $domain_index = $functions[$function];
            $domain = isset($args[$domain_index]) ? trim($args[$domain_index]) : '<missing>';
            if ($domain !== "'supcheckout'" && $domain !== '"supcheckout"') {
                $violations[] = array(
                    'path' => $relative,
                    'line' => (int) $tokens[$i][2],
                    'function' => $function,
                    'domain' => $domain,
                );
            }
        }
    }

    return $violations;
}

$composer = json_decode(q2_read($q2_root, 'composer.json'), true);
$phpstan = q2_read($q2_root, 'phpstan.neon.dist');
$tests = q2_read($q2_root, 'tests/unit/Payment/CheckoutPayloadTest.php');
$checkout = q2_read($q2_root, 'src/Payment/CheckoutPayload.php');
$runtime = q2_read($q2_root, 'supcheckout.php');
$workflow = q2_read($q2_root, '.github/workflows/quality-gates.yml');
$quality_record = q2_read($q2_root, 'docs/project/QUALITY-PLATFORM.md');
$status = q2_read($q2_root, 'docs/project/PROJECT-STATUS.md');
$readme = q2_read($q2_root, 'README.md');
$handoff = q2_read($q2_root, 'docs/project/NEW-CHAT-HANDOFF.md');
$playbook = q2_read($q2_root, 'docs/project/MASTER-ENGINEERING-PLAYBOOK.md');

q2_assert(is_array($composer), 'Composer manifest is valid JSON');
q2_assert(isset($composer['require']) && $composer['require'] === array('php' => '>=7.4'), 'Composer still has no production package dependency');
q2_assert(isset($composer['config']['allow-plugins']) && $composer['config']['allow-plugins'] === false, 'Composer plugin execution remains disabled');
q2_assert(!q2_contains($runtime, 'vendor/autoload.php'), 'runtime still does not load development Composer code');

q2_assert(q2_contains($phpstan, 'level: 5'), 'PHPStan level remains explicit');
q2_assert(q2_contains($phpstan, 'phpVersion: 70400'), 'PHPStan target remains the declared PHP floor');
foreach (array(
    'src/Payment/CheckoutPayload.php',
    'src/Payment/ProviderResult.php',
    'src/Provider/EndpointResolver.php',
) as $path) {
    q2_assert(q2_contains($phpstan, $path), "PHPStan owns characterized module: {$path}");
}
q2_assert(!q2_contains($phpstan, 'baseline'), 'Q2 expansion remains baseline-free');

foreach (array(
    '@param mixed $a First defensive boundary value.',
    '@param mixed $amount_str Defensive boundary input;',
    '@param mixed $payload_json Encoded payload with sentinels.',
    '@param mixed $uri Raw REQUEST_URI boundary value.',
    '@param mixed $is_rest_request REST_REQUEST state.',
    '@param mixed $qty        Strict positive integer quantity boundary value.',
    '@param mixed $numer_str Strict positive integer digit-string boundary value.',
    '@param mixed $value Provider-bound text candidate.',
) as $boundary_doc) {
    q2_assert(q2_contains($checkout, $boundary_doc), "defensive boundary typing is explicit: {$boundary_doc}");
}
q2_assert(!q2_contains($checkout, 'strlen($amount_str) === 0'), 'amount token has no analyzer-proven unreachable empty-length guard');
q2_assert(!q2_contains($checkout, "if (!is_string(\$token) || \$token === '')"), 'verified token list has no analyzer-proven unreachable type guard');
q2_assert(!q2_contains($checkout, '$route === null'), 'string-only route normalizer has no analyzer-proven unreachable null guard');

q2_assert(substr_count($tests, 'public function test_') >= 16, 'CheckoutPayload PHPUnit characterization has at least sixteen focused tests');
foreach (array(
    'field_present(',
    'parse_save_card_strict(',
    'parse_payment_source_strict(',
    'is_valid_subscription_interval(',
    'validate_provider_positive_decimal(',
    'validate_provider_nonnegative_decimal(',
    'compute_provider_unit_price_decimal(',
    'digit_long_divide(',
    'digit_long_divide_remainder(',
    'canonicalize_provider_decimal_string(',
    'inject_amount_token_into_payload_json(',
    'get_max_length_for_sentinel(',
    'normalize_store_api_route(',
    'is_store_api_checkout_request(',
    'truncate_provider_text(',
) as $method_call) {
    q2_assert(q2_contains($tests, 'CheckoutPayload::' . $method_call), "PHPUnit characterizes CheckoutPayload::{$method_call}");
}

q2_assert(q2_contains($workflow, 'quality-platform-static-analysis-harness.php'), 'Q2 harness is mandatory in Quality Gates');
q2_assert(q2_contains($workflow, 'if: ${{ always() }}'), 'protected H12 aggregator still runs after upstream failure');
q2_assert(q2_contains($workflow, 'QUALITY_PLATFORM_RESULT: ${{ needs.quality-platform.result }}'), 'protected H12 aggregator still reads quality result');
q2_assert(q2_contains($workflow, 'PHP_SYNTAX_RESULT: ${{ needs.php-syntax-compatibility.result }}'), 'protected H12 aggregator still reads syntax result');

q2_assert(
    q2_contains($quality_record, 'Q2 is DONE / VERIFIED') &&
    q2_contains($quality_record, 'Q16 is DONE / VERIFIED'),
    'quality record closes Q2 and advances beyond it'
);
foreach (array(
    'c2c30f90688747a523301cb776ed920ef39063f3',
    '3550fdbb0810af26808851e24e39a6130725e8db',
    'Quality Gates run #182',
    '356680b9fe8a2724e778d40386ca182247715249',
    'Quality Gates run #183',
    'implementation branch deleted',
) as $closure_evidence) {
    q2_assert(q2_contains($quality_record, $closure_evidence), "Q2 closure evidence is pinned: {$closure_evidence}");
}
q2_assert(q2_contains($status, '| Quality Platform Q1-Q19 | **DONE / VERIFIED — permanently closed at Q19** |'), 'project status records the closed Quality Platform Q1-Q19');
q2_assert(q2_contains($readme, 'docs/project/PROJECT-STATUS.md'), 'README delegates current engineering state to project status');
q2_assert(!q2_contains($handoff, 'CURRENT / Q1**'), 'handoff program sequence rejects alternate stale Q1 gate marker without matching Q12');
q2_assert(!q2_contains($playbook, 'CURRENT / Q1**'), 'master playbook phase ordering rejects alternate stale Q1 gate marker without matching Q12');
q2_assert(q2_contains($workflow, "reject_across_live_records 'CURRENT / Q1**'"), 'Governance rejects alternate stale Q1 gate marker without matching Q12');



/*
 * SUPCheckout identity-migration invariant.
 *
 * The historical Q2 harness remains permanent and now also protects the
 * canonical first-party translation/metadata boundary established before the
 * first public release.
 */
$q2_i18n_violations = q2_supcheckout_i18n_violations($q2_root);
foreach ($q2_i18n_violations as $violation) {
    q2_assert(
        false,
        sprintf(
            'SUPCheckout translation domain is canonical: %s:%d %s() domain=%s',
            $violation['path'],
            $violation['line'],
            $violation['function'],
            $violation['domain']
        )
    );
}
q2_assert(count($q2_i18n_violations) === 0, 'all plugin-owned translation calls use literal supcheckout domain');

q2_assert(
    q2_contains($runtime, 'Plugin Name: SUPCheckout for UPayments'),
    'plugin header exposes canonical SUPCheckout product name'
);
q2_assert(
    q2_contains($runtime, 'Text Domain: supcheckout'),
    'plugin header exposes canonical SUPCheckout text domain'
);
q2_assert(
    !q2_contains($runtime, 'Domain Path:'),
    'plugin header does not advertise a non-packaged languages directory'
);
q2_assert(
    is_file($q2_root . '/readme.txt'),
    'canonical WordPress readme.txt exists for SUPCheckout'
);
q2_assert(
    q2_contains($readme, '<h1 align="center">SUPCheckout for UPayments</h1>'),
    'README current product heading is canonical SUPCheckout'
);

echo "\nQ2 Checkout Payload Analysis: {$q2_pass} PASS / {$q2_fail} FAIL\n";
exit($q2_fail === 0 ? 0 : 1);
