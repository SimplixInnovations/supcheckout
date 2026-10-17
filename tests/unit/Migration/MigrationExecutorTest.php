<?php

namespace Simplixi\SUPCheckout\Tests\Migration;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Simplixi\SUPCheckout\Migration\MigrationExecutor;
use Simplixi\SUPCheckout\Migration\MigrationPreflight;
use Simplixi\SUPCheckout\Token\CustomerTokenIdentity;

final class MigrationExecutorTest extends TestCase {
    protected function setUp(): void {
        \supcheckout_test_reset_migration_core();
    }

    public function test_clean_user_is_idempotent_without_lock_or_mutation(): void {
        $result = MigrationExecutor::execute(1, 'api-key', false, false);

        self::assertTrue($result['success']);
        self::assertSame('already_clean', $result['reason']);
        self::assertSame(MigrationPreflight::CLEAN, $result['classification']);
        self::assertTrue($result['idempotent']);
        self::assertFalse($result['lock_acquired']);
        self::assertSame(array(), $GLOBALS['supcheckout_test_migration_core']['user_meta']);
    }

    public function test_dry_run_migratable_user_performs_no_identity_mutation(): void {
        $GLOBALS['supcheckout_test_status_orders'][10] = new \SUPCheckout_Test_Migration_Core_Order(10, 1, '12345678');

        $result = MigrationExecutor::execute(1, 'api-key', false, true);

        self::assertTrue($result['success']);
        self::assertSame('dry_run_migratable', $result['reason']);
        self::assertSame(MigrationPreflight::MIGRATABLE, $result['classification']);
        self::assertFalse($result['lock_acquired']);
        self::assertArrayNotHasKey(CustomerTokenIdentity::SECRET_OPTION, $GLOBALS['supcheckout_test_options']);
        self::assertSame(array(), $GLOBALS['supcheckout_test_migration_core']['provenance']);
        self::assertSame(array(), $GLOBALS['supcheckout_test_migration_core']['user_meta']);
        self::assertStringNotContainsString('12345678', json_encode($result));
    }

    public function test_existing_secret_legacy_orphan_migrates_to_exact_legacy_provenance(): void {
        $secret = \supcheckout_test_migration_secret();
        $scope = \supcheckout_test_migration_scope('api-key', false, $secret);
        $GLOBALS['supcheckout_test_options'][CustomerTokenIdentity::SECRET_OPTION] = $secret;

        $order = new \SUPCheckout_Test_Migration_Core_Order(10, 1, '12345678');
        $order->meta['_upay_customer_token_kind_v1'] = array(CustomerTokenIdentity::KIND_LEGACY_COMPAT);
        $order->meta['_upay_customer_token_scope_v1'] = array($scope);
        $order->meta['_upay_customer_token_generation_v1'] = array($secret['generation_id']);
        $GLOBALS['supcheckout_test_status_orders'][10] = $order;

        $result = MigrationExecutor::execute(1, 'api-key', false, false);

        self::assertTrue($result['success']);
        self::assertSame('migrated', $result['reason']);
        self::assertSame(MigrationPreflight::CLEAN, $result['classification']);
        self::assertTrue($result['lock_acquired']);
        self::assertTrue($result['migrated']);
        self::assertTrue($result['provenance_created']);
        self::assertTrue($result['ledger_written']);
        self::assertSame(hash('sha256', '12345678'), $result['token_digest']);

        $record = $GLOBALS['supcheckout_test_migration_core']['provenance'][1][$scope][$secret['generation_id']];
        self::assertSame(CustomerTokenIdentity::KIND_LEGACY_COMPAT, $record['kind']);
        self::assertSame(CustomerTokenIdentity::SOURCE_LEGACY_VERIFIED_CAPTURE, $record['source']);
        self::assertSame('12345678', $record['token']);

        $encoded = json_encode($result);
        self::assertIsString($encoded);
        self::assertStringNotContainsString('12345678', $encoded);
        self::assertStringNotContainsString('api-key', $encoded);
    }

    public function test_invalid_inputs_fail_closed_before_preflight(): void {
        self::assertSame('invalid_user_id', MigrationExecutor::execute(0, 'api-key', false, false)['reason']);
        self::assertSame('invalid_api_key', MigrationExecutor::execute(1, '', false, false)['reason']);
        self::assertSame('invalid_input', MigrationExecutor::execute(1, 'api-key', 'no', false)['reason']);
    }

    public function test_public_boundary_remains_final_and_static(): void {
        $reflection = new ReflectionClass(MigrationExecutor::class);
        $public = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

        self::assertTrue($reflection->isFinal());
        self::assertSame(array('execute'), array_map(static function (ReflectionMethod $method) {
            return $method->getName();
        }, $public));
        self::assertTrue($public[0]->isStatic());
        self::assertFalse($reflection->isInstantiable());
    }
}
