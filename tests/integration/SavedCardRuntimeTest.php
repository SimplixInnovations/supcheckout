<?php
/**
 * Real-runtime saved-card/token-identity certification.
 */

require_once __DIR__ . '/bootstrap.php';

use Simplixi\SUPCheckout\Payment\SavedCardPresentation;
use Simplixi\SUPCheckout\Payment\SavedCardSelection;
use Simplixi\SUPCheckout\Token\CustomerTokenIdentity;

// Sub-project 0a Task 6: CustomerTokenIdentity moved from includes/ (eagerly required by the
// bootstrap) into src/, served lazily by the plugin's PSR-4 autoloader (the only autoloader in
// this runtime). It is a side-effect-free class declaration, so the rule is availability from
// the shipped package, not eager loading.
supcheckout_cert_assert(class_exists(CustomerTokenIdentity::class), 'customer token identity boundary is available through the production autoloader');
supcheckout_cert_assert(
    substr(str_replace('\\', '/', (string) (new ReflectionClass(CustomerTokenIdentity::class))->getFileName()), -strlen('/src/Token/CustomerTokenIdentity.php')) === '/src/Token/CustomerTokenIdentity.php',
    'customer token identity boundary is loaded from the packaged src/Token/CustomerTokenIdentity.php'
);
supcheckout_cert_assert(class_exists(SavedCardPresentation::class, false), 'saved-card presentation boundary is loaded by the production plugin bootstrap');
supcheckout_cert_assert(class_exists(SavedCardSelection::class, false), 'saved-card selection boundary is loaded by the production plugin bootstrap');
supcheckout_cert_assert('•••• 4242' === SavedCardPresentation::label(array('number' => '4111 1111 1111 4242'), 'Saved card'), 'saved-card presentation exposes only last four in packaged runtime');

delete_option(CustomerTokenIdentity::SECRET_OPTION);

$create_calls = 0;
$guest = CustomerTokenIdentity::get_or_establish_token(
    0,
    'certification-api-key',
    true,
    function ($candidate) use (&$create_calls) {
        ++$create_calls;
        return null;
    }
);
supcheckout_cert_assert(false === $guest['success'], 'guest token establishment is rejected');
supcheckout_cert_assert('not_logged_in' === $guest['reason'], 'guest token rejection reason is exact');
supcheckout_cert_assert(0 === $create_calls, 'guest token rejection occurs before provider transport');
supcheckout_cert_assert(false === get_option(CustomerTokenIdentity::SECRET_OPTION, false), 'guest rejection does not initialize token identity');

$user_id = wp_insert_user(array(
    'user_login' => 'supcheckout-cert-card-' . wp_generate_password(12, false, false),
    'user_pass'  => wp_generate_password(24, true, true),
    'user_email' => 'saved-card-' . wp_generate_password(8, false, false) . '@example.invalid',
));
supcheckout_cert_assert(!is_wp_error($user_id) && (int) $user_id > 0, 'saved-card certification user is created');
$user_id = (int) $user_id;

$create_calls = 0;
$identity = CustomerTokenIdentity::get_or_establish_token(
    $user_id,
    'certification-api-key',
    true,
    function ($candidate) use (&$create_calls) {
        ++$create_calls;
        return array(
            'transport_ok' => true,
            'http_status'  => 201,
            'curl_errno'   => 0,
            'body'         => wp_json_encode(array(
                'status' => true,
                'data'   => array('customerUniqueToken' => $candidate),
            )),
        );
    }
);

supcheckout_cert_assert(true === $identity['success'], 'authenticated user can establish canonical token identity');
supcheckout_cert_assert(true === $identity['established'], 'canonical token is newly established in the real WordPress user store');
supcheckout_cert_assert(1 === $create_calls, 'canonical token creation performs one bounded provider-callback invocation');
supcheckout_cert_assert(
    is_string($identity['token']) && CustomerTokenIdentity::is_valid_canonical_token($identity['token']),
    'established customer token satisfies the canonical grammar'
);
supcheckout_cert_assert(
    CustomerTokenIdentity::KIND_CANONICAL === $identity['kind'],
    'established token provenance is canonical'
);

$customer_token = $identity['token'];
$card_token = 'card-certification-token-a';
$retrieve_calls = 0;
$cards = CustomerTokenIdentity::get_saved_cards_for_current_user(
    $user_id,
    'certification-api-key',
    true,
    function ($token) use (&$retrieve_calls, $customer_token, $card_token) {
        ++$retrieve_calls;
        supcheckout_cert_assert($customer_token === $token, 'saved-card retrieval uses the exact canonical customer token');
        return array(
            'result' => 'success',
            'data'   => array(array('token' => $card_token, 'last4' => '4242')),
        );
    }
);

supcheckout_cert_assert(is_array($cards) && 'success' === $cards['result'], 'saved cards load for valid current provenance');
supcheckout_cert_assert(1 === $retrieve_calls, 'valid saved-card retrieval performs exactly one callback');

$selection = SavedCardSelection::create($card_token, $user_id, 'certification-api-key', true);
supcheckout_cert_assert(
    is_string($selection) && 1 === preg_match('/^sc1_[0-9a-f]{64}$/D', $selection),
    'saved-card browser selection is an opaque canonical handle'
);
supcheckout_cert_assert(
    false === strpos($selection, $card_token),
    'saved-card browser selection does not expose the provider card token'
);
supcheckout_cert_assert(
    $card_token === SavedCardSelection::resolve(
        $selection,
        $cards['data'],
        $user_id,
        'certification-api-key',
        true
    ),
    'opaque saved-card selection resolves only against fresh provider cards'
);

$membership_calls = 0;
$membership_reader = function ($token) use (&$membership_calls, $customer_token, $card_token) {
    ++$membership_calls;
    supcheckout_cert_assert($customer_token === $token, 'card membership lookup is bound to the exact customer token');
    return array(
        'result' => 'success',
        'data'   => array(
            array('token' => $card_token),
            array('token' => 'card-certification-token-b'),
        ),
    );
};

supcheckout_cert_assert(
    CustomerTokenIdentity::verify_card_membership($card_token, $customer_token, $membership_reader),
    'exact selected-card membership is accepted'
);
supcheckout_cert_assert(
    !CustomerTokenIdentity::verify_card_membership('foreign-card-token', $customer_token, $membership_reader),
    'foreign selected-card token is rejected'
);
supcheckout_cert_assert(2 === $membership_calls, 'membership checks each use one fresh bounded retrieval');

$meta_key = CustomerTokenIdentity::get_user_meta_key((string) get_current_blog_id(), $identity['scope']);
supcheckout_cert_assert(is_string($meta_key) && '' !== $meta_key, 'canonical provenance meta key is derived for the active scope');
$original_record = get_user_meta($user_id, $meta_key, true);
supcheckout_cert_assert(is_array($original_record), 'canonical provenance is persisted as one structured user-meta record');

$invalid_record = $original_record;
$invalid_record['source'] = 'tampered-certification-source';
supcheckout_cert_assert(
    false !== update_user_meta($user_id, $meta_key, $invalid_record),
    'certification can inject malformed provenance for fail-closed runtime proof'
);

$blocked_calls = 0;
$blocked = CustomerTokenIdentity::get_saved_cards_for_current_user(
    $user_id,
    'certification-api-key',
    true,
    function ($token) use (&$blocked_calls) {
        ++$blocked_calls;
        return array('result' => 'success', 'data' => array());
    }
);
supcheckout_cert_assert(null === $blocked, 'invalid provenance fails closed before saved-card retrieval');
supcheckout_cert_assert(0 === $blocked_calls, 'invalid provenance never reaches provider-card retrieval');

update_user_meta($user_id, $meta_key, $original_record);
wp_delete_user($user_id);
delete_option(CustomerTokenIdentity::SECRET_OPTION);

supcheckout_cert_note('saved-card/tokenization runtime certification complete');
