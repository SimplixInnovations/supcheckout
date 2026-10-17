<?php

namespace Simplixi\SUPCheckout\Tests\Takeover;

/**
 * A WooCommerce CRUD object (order or product) for the takeover copier tests.
 *
 * Meta changes apply in memory and persist only on save(), as in WooCommerce. A save
 * armed to fail discards the in-memory changes and throws, which is what a process
 * dying before save() leaves behind: nothing. get_meta($key, false) returns
 * FakeMetaData objects exactly like WC_Meta_Data.
 */
final class FakeCrudObject {
    /** @var int */
    private static $next_meta_id = 1;

    /** @var int */
    private $id;
    /** @var string */
    private $payment_method;
    /** @var string */
    private $status;
    /** @var list<FakeMetaData> In-memory rows. */
    private $rows = array();
    /** @var list<FakeMetaData> Persisted rows. */
    private $persisted = array();

    /** @var int */
    public $saves = 0;
    /** @var bool */
    public $fail_next_save = false;
    /** @var list<array{0:string,1:string}> Every meta mutation: [operation, key]. */
    public $writes = array();
    /** @var list<string> Every setter called on a non-meta field. */
    public $field_writes = array();
    /** @var bool True if an object was ever passed as a meta value. */
    public $object_value_written = false;
    /** @var list<array{0:string,1:mixed}> get_meta calls: [key, context]. */
    public $reads = array();
    /** @var callable|null function (FakeCrudObject $object): void, an unlocked writer at save() time. */
    public $on_save = null;

    /** @param array<string, list<mixed>> $meta Key => values, in row order. */
    public function __construct(int $id, string $payment_method = 'upayments', array $meta = array(), string $status = 'processing') {
        $this->id = $id;
        $this->payment_method = $payment_method;
        $this->status = $status;
        foreach ($meta as $key => $values) {
            foreach ($values as $value) {
                $this->rows[] = new FakeMetaData(self::$next_meta_id++, (string) $key, $value);
            }
        }
        $this->persisted = $this->copy_rows($this->rows);
    }

    public function get_id(): int {
        return $this->id;
    }

    public function get_payment_method(): string {
        return $this->payment_method;
    }

    public function set_payment_method($method): void {
        $this->field_writes[] = 'payment_method';
        $this->payment_method = (string) $method;
    }

    public function get_status(): string {
        return $this->status;
    }

    public function set_status($status): void {
        $this->field_writes[] = 'status';
        $this->status = (string) $status;
    }

    /**
     * @param mixed $key
     * @param mixed $single
     * @param mixed $context
     * @return mixed
     */
    public function get_meta($key = '', $single = true, $context = 'view') {
        $this->reads[] = array((string) $key, $context);
        $found = array();
        foreach ($this->rows as $row) {
            if ($row->key === $key) {
                $found[] = clone $row;
            }
        }
        if ($single) {
            return $found === array() ? '' : $found[0]->value;
        }
        return $found;
    }

    /** @param mixed $value */
    public function add_meta_data($key, $value, $unique = false): void {
        $this->writes[] = array('add', (string) $key);
        if (is_object($value)) {
            $this->object_value_written = true;
        }
        if ($unique && $this->get_meta($key, false) !== array()) {
            return;
        }
        $this->rows[] = new FakeMetaData(self::$next_meta_id++, (string) $key, $value);
    }

    /** @param mixed $value */
    public function update_meta_data($key, $value): void {
        $this->writes[] = array('update', (string) $key);
        if (is_object($value)) {
            $this->object_value_written = true;
        }
        $this->remove((string) $key);
        $this->rows[] = new FakeMetaData(self::$next_meta_id++, (string) $key, $value);
    }

    public function delete_meta_data($key): void {
        $this->writes[] = array('delete', (string) $key);
        $this->remove((string) $key);
    }

    public function save(): int {
        if (is_callable($this->on_save)) {
            call_user_func($this->on_save, $this);
        }
        if ($this->fail_next_save) {
            $this->fail_next_save = false;
            $this->rows = $this->copy_rows($this->persisted);
            throw new \RuntimeException('synthetic save failure');
        }
        $this->persisted = $this->copy_rows($this->rows);
        ++$this->saves;
        return $this->id;
    }

    /** @return list<mixed> Persisted values of one key, in row order. */
    public function persisted_values(string $key): array {
        $values = array();
        foreach ($this->persisted as $row) {
            if ($row->key === $key) {
                $values[] = $row->value;
            }
        }
        return $values;
    }

    /** @return list<string> Distinct keys written (add, update or delete). */
    public function written_keys(): array {
        return array_values(array_unique(array_map(static function ($write) {
            return $write[1];
        }, $this->writes)));
    }

    /** Add a row without WC_Meta_Data's get_data() (the copier must fail closed on it). */
    public function inject_unwrappable_row(string $key, string $value): void {
        $row = (object) array('id' => 0, 'key' => $key, 'value' => $value);
        $this->rows[] = $row;
        $this->persisted[] = clone $row;
    }

    /**
     * Change persisted meta as another writer would (runtime, the official plugin).
     *
     * @param list<mixed> $values
     */
    public function external_write(string $key, array $values): void {
        $this->remove($key);
        foreach ($values as $value) {
            $this->rows[] = new FakeMetaData(self::$next_meta_id++, $key, $value);
        }
        $this->persisted = $this->copy_rows($this->rows);
    }

    private function remove(string $key): void {
        $this->rows = array_values(array_filter($this->rows, static function ($row) use ($key) {
            return $row->key !== $key;
        }));
    }

    /**
     * @param list<FakeMetaData> $rows
     * @return list<FakeMetaData>
     */
    private function copy_rows(array $rows): array {
        return array_map(static function ($row) {
            return clone $row;
        }, $rows);
    }
}
