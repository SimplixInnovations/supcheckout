<?php

namespace Simplixi\SUPCheckout\Tests\Takeover;

/**
 * Shaped like WooCommerce's WC_Meta_Data: get_meta($key, false) returns these objects,
 * never the bare values, so a copier must unwrap ->value.
 */
final class FakeMetaData {
    /** @var int */
    public $id;
    /** @var string */
    public $key;
    /** @var mixed */
    public $value;

    /** @param mixed $value */
    public function __construct(int $id, string $key, $value) {
        $this->id = $id;
        $this->key = $key;
        $this->value = $value;
    }

    /** @return array{id:int,key:string,value:mixed} */
    public function get_data(): array {
        return array('id' => $this->id, 'key' => $this->key, 'value' => $this->value);
    }
}
