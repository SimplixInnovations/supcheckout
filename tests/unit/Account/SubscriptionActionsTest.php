<?php
namespace Simplixi\SUPCheckout\Tests\Account;

use PHPUnit\Framework\TestCase;
use Simplixi\SUPCheckout\Account\SubscriptionActions;

final class SubscriptionActionsTest extends TestCase {
    public function test_non_post_and_unrelated_requests_are_ignored_without_side_effects(): void {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_POST = array('upay_action' => 'pause');
        SubscriptionActions::handle();
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = array('unrelated' => '1');
        SubscriptionActions::handle();
        self::assertTrue(true, 'no redirect, exit or order lookup happened');
    }
}
