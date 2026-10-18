<?php

namespace Simplixi\SUPCheckout\Tests\Takeover;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Simplixi\SUPCheckout\Takeover\CachePurger;

/**
 * 0c Task 2: the cache layers the takeover purges at `caches_flushed`. Every test runs in
 * a separate process because the vendor functions are declared per test (a declared
 * function cannot be undeclared) and the hook API stub is loaded only here.
 */
final class CachePurgerTest extends TestCase {
    private static function boot(): void {
        require_once dirname(__DIR__, 2) . '/support/cache-purge-wp-stubs.php';
        $GLOBALS['supcheckout_test_hook_calls'] = array();
        $GLOBALS['supcheckout_test_multisite'] = false;
        $GLOBALS['cp_calls'] = array();
    }

    /**
     * Declare a vendor function that records its arguments under $name and returns null
     * (or throws when $throws).
     */
    private static function vendor(string $name, bool $throws = false): void {
        $body = '$GLOBALS["cp_calls"][] = array(' . var_export($name, true) . ', func_get_args());'
            . ($throws ? ' throw new \RuntimeException("vendor boom");' : '');
        eval('function ' . $name . '() { ' . $body . ' }');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_only_the_object_cache_is_flushed_when_no_cache_plugin_is_present(): void {
        self::boot();

        self::assertSame(array('ran' => array('object_cache'), 'manual' => array(), 'failed' => array()), CachePurger::purge_all());
        self::assertSame(1, $GLOBALS['supcheckout_test_object_flushes']);
        self::assertSame(array(), CachePurger::detected());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_litespeed_gets_the_documented_action_with_a_reason(): void {
        self::boot();
        $GLOBALS['ls'] = array();
        add_action('litespeed_purge_all', static function ($reason) {
            $GLOBALS['ls'][] = $reason;
        });

        $report = CachePurger::purge_all();

        self::assertSame(array('SUPCheckout takeover'), $GLOBALS['ls']);
        self::assertSame(array('litespeed', 'object_cache'), $report['ran']);
        self::assertSame(array('litespeed'), CachePurger::detected());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_a_throwing_vendor_purge_is_reported_failed_and_the_rest_still_run(): void {
        self::boot();
        self::vendor('w3tc_flush_all', true);
        self::vendor('rocket_clean_domain');

        $report = CachePurger::purge_all();

        self::assertSame(array('w3tc'), $report['failed']);
        self::assertSame(array('wp_rocket', 'object_cache'), $report['ran']);
        self::assertSame(array(), $report['manual']);
        self::assertSame(1, $GLOBALS['supcheckout_test_object_flushes'], 'the object cache still flushes after a vendor failure');
        self::assertSame(array('wp_rocket', 'w3tc'), CachePurger::detected(), 'detection follows the fixed order, whatever the call outcome');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_a_vendor_failure_never_leaks_its_message_into_the_report(): void {
        self::boot();
        self::vendor('w3tc_flush_all', true);

        self::assertStringNotContainsString('boom', (string) json_encode(CachePurger::purge_all()));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_the_cloudflare_plugin_is_reported_manual_and_never_called(): void {
        self::boot();
        eval('namespace Cloudflare\APO\WordPress; final class Hooks {}');

        $report = CachePurger::purge_all();

        self::assertSame(array('cloudflare'), $report['manual']);
        self::assertSame(array('object_cache'), $report['ran']);
        self::assertSame(array(), $report['failed']);
        self::assertSame(array('cloudflare'), CachePurger::detected());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_breeze_gets_its_clear_all_action_only_when_registered(): void {
        self::boot();
        $without = CachePurger::purge_all();
        self::assertNotContains('breeze', $without['ran']);
        self::assertNotContains('breeze', CachePurger::detected());

        $GLOBALS['breeze_calls'] = 0;
        add_action('breeze_clear_all_cache', static function () {
            ++$GLOBALS['breeze_calls'];
        });
        $with = CachePurger::purge_all();

        self::assertSame(1, $GLOBALS['breeze_calls']);
        self::assertSame(array('breeze', 'object_cache'), $with['ran']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_sg_optimizer_purge_everything_is_called_when_defined(): void {
        self::boot();
        self::vendor('sg_cachepress_purge_everything');

        $report = CachePurger::purge_all();

        self::assertSame(array(array('sg_cachepress_purge_everything', array())), $GLOBALS['cp_calls']);
        self::assertSame(array('sg_optimizer', 'object_cache'), $report['ran']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_super_cache_clears_only_the_current_blog_on_multisite(): void {
        self::boot();
        self::vendor('wp_cache_clear_cache');
        $GLOBALS['supcheckout_test_multisite'] = true;

        $report = CachePurger::purge_all();

        self::assertSame(array(array('wp_cache_clear_cache', array(get_current_blog_id()))), $GLOBALS['cp_calls']);
        self::assertNotSame(0, get_current_blog_id());
        self::assertSame(array('wp_super_cache', 'object_cache'), $report['ran']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_super_cache_clears_everything_on_a_single_site(): void {
        self::boot();
        self::vendor('wp_cache_clear_cache');

        CachePurger::purge_all();

        self::assertSame(array(array('wp_cache_clear_cache', array(0))), $GLOBALS['cp_calls']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_rocket_clean_domain_is_called_without_a_language(): void {
        self::boot();
        self::vendor('rocket_clean_domain');

        CachePurger::purge_all();

        self::assertSame(array(array('rocket_clean_domain', array())), $GLOBALS['cp_calls']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_every_layer_runs_in_the_documented_order(): void {
        self::boot();
        $order = array();
        add_action('litespeed_purge_all', static function () use (&$order) {
            $order[] = 'litespeed';
        });
        add_action('breeze_clear_all_cache', static function () use (&$order) {
            $order[] = 'breeze';
        });
        foreach (array('rocket_clean_domain', 'w3tc_flush_all', 'wp_cache_clear_cache', 'sg_cachepress_purge_everything') as $function) {
            self::vendor($function);
        }
        eval('namespace Cloudflare\APO\WordPress; final class Hooks {}');

        $report = CachePurger::purge_all();

        self::assertSame(array('litespeed', 'wp_rocket', 'w3tc', 'wp_super_cache', 'breeze', 'sg_optimizer', 'object_cache'), $report['ran']);
        self::assertSame(array('cloudflare'), $report['manual']);
        self::assertSame(array(), $report['failed']);
        self::assertSame(array('litespeed', 'breeze'), $order);
        self::assertSame(array('litespeed', 'wp_rocket', 'w3tc', 'wp_super_cache', 'breeze', 'sg_optimizer', 'cloudflare'), CachePurger::detected());
    }
}
