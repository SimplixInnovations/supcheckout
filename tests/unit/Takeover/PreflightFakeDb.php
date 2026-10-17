<?php

namespace Simplixi\SUPCheckout\Tests\Takeover;

/**
 * Scripted read-only database for the Preflight tests.
 *
 * prepare() keeps the template and arguments apart so a test can assert both. Each
 * handler is matched by a substring of the template and returns the scripted result.
 * Every executed statement is recorded; an unmatched one returns null.
 */
final class PreflightFakeDb {
    /** @var string */
    public $prefix = 'wp_';
    /** @var string */
    public $options = 'wp_options';
    /** @var string */
    public $posts = 'wp_posts';
    /** @var string */
    public $postmeta = 'wp_postmeta';

    /** @var array<string, mixed> template substring => result (or callable(array $args): mixed) */
    public $handlers = array();

    /** @var list<array{method:string, query:string, args:list<mixed>}> */
    public $executed = array();

    /**
     * @param array<string, mixed> $handlers
     */
    public function __construct(array $handlers = array()) {
        $this->handlers = $handlers;
    }

    public function esc_like($text) {
        return addcslashes((string) $text, '_%\\');
    }

    public function prepare($query, ...$args) {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }
        return serialize(array('query' => (string) $query, 'args' => array_values($args)));
    }

    public function get_var($prepared) {
        return $this->run('get_var', $prepared);
    }

    public function get_col($prepared) {
        $result = $this->run('get_col', $prepared);
        return is_array($result) ? $result : array();
    }

    public function get_row($prepared, $output = 'OBJECT') {
        return $this->run('get_row', $prepared);
    }

    /** @return list<array{method:string, query:string, args:list<mixed>}> */
    public function executed_matching($needle) {
        return array_values(array_filter($this->executed, static function ($statement) use ($needle) {
            return strpos($statement['query'], $needle) !== false;
        }));
    }

    private function run($method, $prepared) {
        $statement = @unserialize((string) $prepared);
        if (!is_array($statement) || !isset($statement['query'])) {
            $statement = array('query' => (string) $prepared, 'args' => array());
        }
        $this->executed[] = array('method' => $method, 'query' => $statement['query'], 'args' => $statement['args']);
        foreach ($this->handlers as $needle => $result) {
            if (strpos($statement['query'], (string) $needle) !== false) {
                return is_callable($result) ? $result($statement['args']) : $result;
            }
        }
        return null;
    }
}
