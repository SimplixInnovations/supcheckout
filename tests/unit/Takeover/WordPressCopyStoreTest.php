<?php

namespace Simplixi\SUPCheckout\Tests\Takeover;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Simplixi\SUPCheckout\Subscription\Cron\CycleClaim;
use Simplixi\SUPCheckout\Takeover\CopyStore;
use Simplixi\SUPCheckout\Takeover\Keys;
use Simplixi\SUPCheckout\Takeover\WordPressCopyStore;

/**
 * 0b Task 6: the SQL the WordPress copy store sends. Real MySQL behaviour is the Task 12
 * migration matrix; this pins the statement shapes: prepared, identifiers through %i,
 * bounded, ascending, INSERT IGNORE for the copy, and the one CycleClaim schema.
 */
final class WordPressCopyStoreTest extends TestCase {
    /** @var object */
    private $db;

    protected function setUp(): void {
        \supcheckout_test_reset_wp_options();
        $this->db = new class() {
            public $prefix = 'wp_';
            public $posts = 'wp_posts';
            public $postmeta = 'wp_postmeta';
            public $usermeta = 'wp_usermeta';
            /** @var list<array{query:string,args:list<mixed>}> */
            public $prepared = array();
            /** @var list<string> */
            public $executed = array();
            /** @var list<mixed> */
            public $col = array();
            /** @var mixed */
            public $var = null;
            /** @var mixed */
            public $results = array();

            public function prepare($query, ...$args) {
                if (count($args) === 1 && is_array($args[0])) {
                    $args = $args[0];
                }
                $this->prepared[] = array('query' => (string) $query, 'args' => array_values($args));
                return 'prepared#' . (count($this->prepared) - 1);
            }
            public function esc_like($text) { return addcslashes((string) $text, '_%\\'); }
            public function get_col($sql) { $this->executed[] = $sql; return $this->col; }
            public function get_var($sql) { $this->executed[] = $sql; return $this->var; }
            public function get_results($sql, $output = null) { $this->executed[] = $sql; return $this->results; }
            public function query($sql) { $this->executed[] = $sql; return 1; }
            public function get_charset_collate() { return 'DEFAULT CHARSET=utf8mb4'; }
        };
        $GLOBALS['wpdb'] = $this->db;
    }

    protected function tearDown(): void {
        \supcheckout_test_reset_wp_options();
    }

    public function test_the_legacy_storage_order_selection_is_bounded_ascending_and_covers_both_gateways_and_every_legacy_key(): void {
        $this->db->col = array('7', '9', 'x', '0');

        $ids = (new WordPressCopyStore())->order_ids_after(5, 50);

        self::assertSame(array(7, 9), $ids, 'only positive numeric IDs');
        $statement = $this->db->prepared[0];
        self::assertStringContainsString('p.ID > %d', $statement['query']);
        self::assertStringContainsString("m.meta_key = '_payment_method'", $statement['query']);
        self::assertStringEndsWith('ORDER BY p.ID ASC LIMIT %d', $statement['query']);
        self::assertSame(array('wp_posts', 5, 'shop_order', 'wp_postmeta'), array_slice($statement['args'], 0, 4));
        self::assertSame(Keys::gateway_ids(), array_slice($statement['args'], 4, 2));
        self::assertSame(array_keys(Keys::order_meta()), array_slice($statement['args'], 6, count(Keys::order_meta())));
        self::assertSame(50, end($statement['args']));
        self::assertSame(substr_count($statement['query'], '%'), count($statement['args']), 'one argument per placeholder');
        self::assertSame(array('prepared#0'), $this->db->executed);
    }

    public function test_user_selection_matches_the_exact_keys_and_the_escaped_provenance_prefix(): void {
        (new WordPressCopyStore())->user_ids_after(10, 50);
        (new WordPressCopyStore())->legacy_user_meta_keys(11);

        foreach ($this->db->prepared as $statement) {
            self::assertContains('\\_upay\\_customer\\_token\\_v2\\_b%', $statement['args']);
            foreach (array_keys(Keys::user_meta()) as $key) {
                self::assertContains($key, $statement['args']);
            }
            self::assertSame(substr_count($statement['query'], '%'), count($statement['args']));
        }
        self::assertStringEndsWith('ORDER BY user_id ASC LIMIT %d', $this->db->prepared[0]['query']);
        self::assertStringContainsString('user_id = %d', $this->db->prepared[1]['query']);
    }

    public function test_product_selection_is_bounded_to_products_carrying_the_legacy_flag(): void {
        (new WordPressCopyStore())->product_ids_after(0, 50);

        $statement = $this->db->prepared[0];
        self::assertStringEndsWith('ORDER BY m.post_id ASC LIMIT %d', $statement['query']);
        self::assertSame(array('wp_postmeta', 'wp_posts', 0, '_upay_disable_subscription', 'product', 'product_variation', 50), $statement['args']);
    }

    public function test_the_table_copy_is_insert_ignore_by_key_range_with_identifiers_as_placeholders(): void {
        $columns = array('cycle_key', 'state');

        self::assertTrue((new WordPressCopyStore())->table_insert_missing($columns, 'aa', 'ff'));

        $statement = $this->db->prepared[0];
        self::assertSame('INSERT IGNORE INTO %i (%i, %i) SELECT %i, %i FROM %i WHERE %i > %s AND %i <= %s ORDER BY %i ASC', $statement['query']);
        self::assertSame(array('wp_supcheckout_billing_attempts', 'cycle_key', 'state', 'cycle_key', 'state', 'wp_upayments_billing_attempts', 'cycle_key', 'aa', 'cycle_key', 'ff', 'cycle_key'), $statement['args']);
    }

    public function test_refresh_replaces_exactly_the_listed_keys_from_the_legacy_table(): void {
        (new WordPressCopyStore())->table_replace(array('cycle_key', 'state'), array('k1', 'k2'));

        $statement = $this->db->prepared[0];
        self::assertSame('REPLACE INTO %i (%i, %i) SELECT %i, %i FROM %i WHERE %i IN (%s, %s)', $statement['query']);
        self::assertSame(array('wp_supcheckout_billing_attempts', 'cycle_key', 'state', 'cycle_key', 'state', 'wp_upayments_billing_attempts', 'cycle_key', 'k1', 'k2'), $statement['args']);
    }

    public function test_orphan_deletion_targets_only_new_rows_without_a_legacy_row(): void {
        $store = new WordPressCopyStore();

        self::assertTrue($store->table_delete_orphans('aa', 'ff'));
        self::assertTrue($store->table_delete_orphans('ff', null));

        self::assertSame('DELETE n FROM %i n LEFT JOIN %i l ON l.cycle_key = n.cycle_key WHERE n.cycle_key > %s AND n.cycle_key <= %s AND l.cycle_key IS NULL', $this->db->prepared[0]['query']);
        self::assertSame(array('wp_supcheckout_billing_attempts', 'wp_upayments_billing_attempts', 'aa', 'ff'), $this->db->prepared[0]['args']);
        self::assertSame('DELETE n FROM %i n LEFT JOIN %i l ON l.cycle_key = n.cycle_key WHERE n.cycle_key > %s AND l.cycle_key IS NULL', $this->db->prepared[1]['query']);
        self::assertSame(array('wp_supcheckout_billing_attempts', 'wp_upayments_billing_attempts', 'ff'), $this->db->prepared[1]['args']);
    }

    public function test_checksums_cover_every_column_with_null_flags(): void {
        $this->db->results = array(array('row_key' => 'k1', 'row_sum' => str_repeat('a', 32)));

        $sums = (new WordPressCopyStore())->table_checksums(CopyStore::TABLE_NEW, array('cycle_key', 'payment_id'), '', 'zz');

        self::assertSame(array('k1' => str_repeat('a', 32)), $sums);
        $statement = $this->db->prepared[0];
        self::assertStringContainsString("MD5(CONCAT_WS('|', HEX(%i), ISNULL(%i), HEX(%i), ISNULL(%i)))", $statement['query']);
        self::assertStringEndsWith('AND %i <= %s ORDER BY %i ASC', $statement['query']);
        self::assertSame(array('cycle_key', 'cycle_key', 'cycle_key', 'payment_id', 'payment_id', 'wp_supcheckout_billing_attempts', 'cycle_key', '', 'cycle_key', 'zz', 'cycle_key'), $statement['args']);

        (new WordPressCopyStore())->table_checksums(CopyStore::TABLE_LEGACY, array('cycle_key'), 'k1', null);
        self::assertStringEndsWith('ORDER BY %i ASC LIMIT %d', $this->db->prepared[1]['query'], 'an open range is still bounded');
    }

    public function test_keys_and_counts_read_the_named_role_and_refuse_anything_else(): void {
        $store = new WordPressCopyStore();
        $this->db->var = '42';
        self::assertSame(42, $store->table_count(CopyStore::TABLE_LEGACY));
        self::assertSame(array('wp_upayments_billing_attempts'), $this->db->prepared[0]['args']);

        $store->table_keys_after(CopyStore::TABLE_LEGACY, 'k', 500);
        self::assertSame('SELECT %i FROM %i WHERE %i > %s ORDER BY %i ASC LIMIT %d', $this->db->prepared[1]['query']);
        self::assertSame(500, end($this->db->prepared[1]['args']));

        $this->expectException(\InvalidArgumentException::class);
        $store->table_count('wp_users');
    }

    public function test_stale_claims_count_only_legacy_claimed_rows_older_than_the_bound(): void {
        $this->db->var = 'wp_upayments_billing_attempts';
        (new WordPressCopyStore())->table_stale_claims('2026-10-17 00:00:00');
        $statement = end($this->db->prepared);
        self::assertSame('SELECT COUNT(*) FROM %i WHERE state = %s AND updated_gmt < %s', $statement['query']);
        self::assertSame(array('wp_upayments_billing_attempts', 'claimed', '2026-10-17 00:00:00'), $statement['args']);
    }

    public function test_an_unsafe_column_name_makes_the_column_list_unreadable(): void {
        $this->db->col = array('cycle_key', 'state`; DROP');

        self::assertSame(array(), (new WordPressCopyStore())->table_columns(CopyStore::TABLE_LEGACY));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_the_new_table_is_created_from_the_cycle_claim_schema(): void {
        $GLOBALS['supcheckout_test_dbdelta'] = array();
        eval('function dbDelta($sql) { $GLOBALS["supcheckout_test_dbdelta"][] = $sql; return array(); }');
        $this->db->var = 'wp_supcheckout_billing_attempts';

        self::assertTrue((new WordPressCopyStore())->create_new_table());

        self::assertSame(array(CycleClaim::schema_sql('wp_supcheckout_billing_attempts', 'DEFAULT CHARSET=utf8mb4')), $GLOBALS['supcheckout_test_dbdelta']);
        self::assertStringContainsString('PRIMARY KEY  (cycle_key)', $GLOBALS['supcheckout_test_dbdelta'][0]);
    }
}
