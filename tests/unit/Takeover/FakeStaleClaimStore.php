<?php

namespace Simplixi\SUPCheckout\Tests\Takeover;

use Simplixi\SUPCheckout\Takeover\StaleClaimStore;

/**
 * In-memory billing-attempts table for the stale-claims tests: rows keyed by cycle_key,
 * each with state, owner_token and updated_gmt. release() applies exactly the CAS the
 * WordPress store's DELETE applies.
 */
final class FakeStaleClaimStore implements StaleClaimStore {
    /** @var array<string, array<string, mixed>> */
    public $rows = array();
    /** @var callable|null function (array $row): void, called before each release CAS. */
    public $on_release = null;
    /** @var list<string> */
    public $released = array();

    public function add(string $key, string $state, string $owner, int $updated_at): void {
        $this->rows[$key] = array(
            'cycle_key' => $key,
            'parent_order_id' => 7,
            'owner_token' => $owner,
            'state' => $state,
            'updated_gmt' => gmdate('Y-m-d H:i:s', $updated_at),
            'payment_id' => null,
        );
    }

    public function stale_claimed($before_gmt, $limit) {
        $rows = array();
        foreach ($this->rows as $row) {
            if ($row['state'] === 'claimed' && strcmp((string) $row['updated_gmt'], (string) $before_gmt) < 0) {
                $rows[] = array('cycle_key' => (string) $row['cycle_key'], 'owner_token' => (string) $row['owner_token'], 'updated_gmt' => (string) $row['updated_gmt']);
            }
        }
        usort($rows, static function ($a, $b) {
            return strcmp($a['updated_gmt'] . $a['cycle_key'], $b['updated_gmt'] . $b['cycle_key']);
        });
        return array_slice($rows, 0, (int) $limit);
    }

    public function release(array $row, $before_gmt) {
        if (is_callable($this->on_release)) {
            call_user_func($this->on_release, $row);
        }
        $key = (string) $row['cycle_key'];
        if (!isset($this->rows[$key])) {
            return false;
        }
        $stored = $this->rows[$key];
        if ($stored['state'] !== 'claimed'
            || $stored['owner_token'] !== $row['owner_token']
            || $stored['updated_gmt'] !== $row['updated_gmt']
            || strcmp((string) $stored['updated_gmt'], (string) $before_gmt) >= 0
        ) {
            return false;
        }
        unset($this->rows[$key]);
        $this->released[] = $key;
        return true;
    }
}
