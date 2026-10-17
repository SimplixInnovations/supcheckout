<?php

namespace Simplixi\SUPCheckout\Tests\Migration;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Simplixi\SUPCheckout\Migration\MigrationPreflight;
use Simplixi\SUPCheckout\Token\CustomerTokenIdentity;

final class MigrationPreflightTest extends TestCase {
    protected function setUp(): void {
        \supcheckout_test_reset_migration_core();
    }

    public function test_invalid_inputs_fail_closed_before_history_access(): void {
        self::assertSame('invalid_user_id', MigrationPreflight::inspect(0, 'api-key', false)['reason']);
        self::assertSame('invalid_api_key', MigrationPreflight::inspect(1, '', false)['reason']);
        self::assertSame('invalid_test_mode', MigrationPreflight::inspect(1, 'api-key', 'no')['reason']);
        self::assertSame(array(), $GLOBALS['supcheckout_test_status_orders']);
    }

    public function test_fresh_user_is_clean_and_unscoped_legacy_history_is_migratable(): void {
        $fresh = MigrationPreflight::inspect(1, 'api-key', false);
        self::assertSame(MigrationPreflight::CLEAN, $fresh['classification']);
        self::assertSame('no_migration_required', $fresh['reason']);

        $GLOBALS['supcheckout_test_status_orders'][10] = new \SUPCheckout_Test_Migration_Core_Order(10, 1, '12345678');
        $legacy = MigrationPreflight::inspect(1, 'api-key', false);

        self::assertSame(MigrationPreflight::MIGRATABLE, $legacy['classification']);
        self::assertSame('attributable_legacy_identity', $legacy['reason']);
        self::assertSame('12345678', $legacy['migration']['token']);
        self::assertSame(CustomerTokenIdentity::KIND_LEGACY_COMPAT, $legacy['migration']['kind']);
        self::assertSame(CustomerTokenIdentity::SOURCE_LEGACY_VERIFIED_CAPTURE, $legacy['migration']['source']);
        self::assertTrue($legacy['migration']['requires_secret_creation']);
        self::assertSame(hash('sha256', '12345678'), $legacy['token_digest']);
    }

    public function test_cross_user_token_conflict_remains_blocked(): void {
        $GLOBALS['supcheckout_test_status_orders'][10] = new \SUPCheckout_Test_Migration_Core_Order(10, 1, '12345678');
        $GLOBALS['supcheckout_test_status_orders'][11] = new \SUPCheckout_Test_Migration_Core_Order(11, 2, '12345678');

        $result = MigrationPreflight::inspect(1, 'api-key', false);

        self::assertSame(MigrationPreflight::BLOCKED, $result['classification']);
        self::assertSame('cross_user_token_conflict', $result['reason']);
    }

    public function test_terminal_newline_order_identifier_is_rejected_as_indeterminate(): void {
        $GLOBALS['supcheckout_test_status_orders'][10] = new \SUPCheckout_Test_Migration_Core_Order(10, 1, '12345678');
        $GLOBALS['supcheckout_test_migration_core']['order_ids_override'] = array("10\n");

        $result = MigrationPreflight::inspect(1, 'api-key', false);

        self::assertSame(MigrationPreflight::INDETERMINATE, $result['classification']);
        self::assertSame('invalid_order_id', $result['reason']);
    }

    public function test_terminal_newline_generation_is_not_accepted_as_identity_context(): void {
        $secret = \supcheckout_test_migration_secret();
        $GLOBALS['supcheckout_test_options'][CustomerTokenIdentity::SECRET_OPTION] = $secret;
        $GLOBALS['supcheckout_test_migration_core']['context_override'] = array(
            'state' => CustomerTokenIdentity::SECRET_VALID,
            'scope' => \supcheckout_test_migration_scope('api-key', false, $secret),
            'generation_id' => str_repeat('b', 32) . "\n",
        );

        $result = MigrationPreflight::inspect(1, 'api-key', false);

        self::assertSame(MigrationPreflight::INDETERMINATE, $result['classification']);
        self::assertSame('identity_context_unavailable', $result['reason']);
    }

    public function test_public_boundary_remains_final_and_static(): void {
        $reflection = new ReflectionClass(MigrationPreflight::class);
        $public = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

        self::assertTrue($reflection->isFinal());
        self::assertSame(array('inspect'), array_map(static function (ReflectionMethod $method) {
            return $method->getName();
        }, $public));
        self::assertTrue($public[0]->isStatic());
        self::assertFalse($reflection->isInstantiable());
    }
}
