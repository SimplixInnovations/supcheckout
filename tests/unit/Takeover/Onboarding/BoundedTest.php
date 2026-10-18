<?php

namespace Simplixi\SUPCheckout\Tests\Takeover\Onboarding;

use PHPUnit\Framework\TestCase;
use Simplixi\SUPCheckout\Takeover\Onboarding\Bounded;

/** 0c Task 4: bounding of lists, strings and whole documents. */
final class BoundedTest extends TestCase {
    public function test_bounded_truncates_lists_to_20_with_a_marker(): void {
        $out = Bounded::value(range(1, 100));

        self::assertSame(21, count($out));
        self::assertSame(array('truncated' => 80), $out[20]);
        self::assertSame(range(1, 20), array_slice($out, 0, 20));
    }

    public function test_a_list_of_exactly_20_is_untouched(): void {
        self::assertSame(range(1, 20), Bounded::value(range(1, 20)));
    }

    public function test_strings_are_cut_to_500_characters_including_multibyte(): void {
        self::assertSame(500, strlen(Bounded::value(str_repeat('a', 600))));
        self::assertSame(500, mb_strlen(Bounded::value(str_repeat("\u{0639}", 600)), 'UTF-8'));
        self::assertSame('short', Bounded::value('short'));
    }

    public function test_nested_lists_and_strings_are_bounded_and_scalars_pass_through(): void {
        $out = Bounded::value(array('a' => array('b' => range(1, 30), 'c' => str_repeat('x', 501)), 'n' => 5, 'f' => 1.5, 'ok' => true, 'z' => null));

        self::assertCount(21, $out['a']['b']);
        self::assertSame(500, strlen($out['a']['c']));
        self::assertSame(array(5, 1.5, true, null), array($out['n'], $out['f'], $out['ok'], $out['z']));
    }

    public function test_bounding_twice_changes_nothing(): void {
        $once = Bounded::value(array('l' => range(1, 100), 's' => str_repeat('q', 900)));

        self::assertSame($once, Bounded::value($once));
    }

    public function test_bounding_a_marked_list_adds_to_the_marker_instead_of_counting_it(): void {
        $once = Bounded::value(range(1, 100));
        $more = array_merge(array_slice($once, 0, 20), array(21, 22), array($once[20]));

        self::assertSame(array('truncated' => 82), Bounded::value($more)[20]);
    }

    public function test_bounded_document_never_exceeds_32768_bytes(): void {
        $doc = array('report' => array());
        for ($i = 0; $i < 20; $i++) {
            $doc['report']['group_' . $i] = array_fill(0, 20, str_repeat('p', 500));
        }
        self::assertGreaterThan(32768, strlen((string) wp_json_encode(Bounded::value($doc))));

        $out = Bounded::document($doc);

        self::assertLessThanOrEqual(32768, strlen((string) wp_json_encode($out)));
        self::assertSame(array('report'), array_keys($out));
    }

    public function test_the_largest_list_is_replaced_by_a_marker_first(): void {
        $doc = array(
            'small' => array_fill(0, 3, 'k'),
            'big' => array_fill(0, 20, str_repeat('b', 500)),
            'mid_a' => array_fill(0, 20, str_repeat('c', 490)),
            'mid_b' => array_fill(0, 20, str_repeat('d', 480)),
            'mid_c' => array_fill(0, 20, str_repeat('e', 470)),
        );

        $out = Bounded::document($doc);

        self::assertSame(array('k', 'k', 'k'), $out['small']);
        self::assertSame(array(array('truncated' => 20)), $out['big']);
        self::assertCount(20, $out['mid_a']);
        self::assertLessThanOrEqual(32768, strlen((string) wp_json_encode($out)));
    }

    public function test_a_small_document_is_returned_unchanged(): void {
        $doc = array('a' => 1, 'b' => array('x', 'y'));

        self::assertSame($doc, Bounded::document($doc));
    }

    public function test_an_oversized_associative_map_fits_with_a_marker(): void {
        $doc = array();
        for ($i = 0; $i < 200; $i++) {
            $doc['key_' . $i] = array('name' => str_repeat('m', 400));
        }

        $out = Bounded::document($doc);

        self::assertLessThanOrEqual(32768, strlen((string) wp_json_encode($out)));
        self::assertMarked($out);
    }

    public function test_a_flat_map_of_many_scalars_fits_with_the_omitted_count(): void {
        $doc = array();
        for ($i = 0; $i < 5000; $i++) {
            $doc['key_' . $i] = str_repeat('v', 100);
        }

        $out = Bounded::document($doc);

        self::assertLessThanOrEqual(32768, strlen((string) wp_json_encode($out)));
        self::assertMarked($out);
        self::assertTrue($out['_truncated']);
        self::assertGreaterThan(4000, $out['_omitted']);
        self::assertSame(5000, $out['_omitted'] + count($out) - 2);
    }

    public function test_the_largest_map_entries_are_omitted_first(): void {
        $doc = array('small' => 'keep me', 'huge' => array('a' => str_repeat('h', 500), 'b' => array_fill(0, 20, str_repeat('h', 500))));
        for ($i = 0; $i < 30; $i++) {
            $doc['filler_' . $i] = array('x' => str_repeat('f', 450), 'y' => str_repeat('f', 450));
        }

        $out = Bounded::document($doc);

        self::assertLessThanOrEqual(32768, strlen((string) wp_json_encode($out)));
        self::assertSame('keep me', $out['small']);
    }

    public function test_a_map_whose_key_names_alone_exceed_the_limit_terminates_and_fits(): void {
        $doc = array();
        for ($i = 0; $i < 600; $i++) {
            $doc[$i . str_repeat('k', 300)] = 1;
        }

        $out = Bounded::document($doc);

        self::assertLessThanOrEqual(32768, strlen((string) wp_json_encode($out)));
        self::assertMarked($out);
        self::assertTrue($out['_truncated']);
        foreach (array_keys($out) as $key) {
            self::assertLessThanOrEqual(Bounded::KEY_LIMIT, strlen((string) $key));
        }
    }

    public function test_key_names_are_cut_to_the_key_limit(): void {
        $out = Bounded::value(array(str_repeat('k', 300) => 'v', 7 => 'w'));

        self::assertSame(array(str_repeat('k', Bounded::KEY_LIMIT), 7), array_keys($out));
    }

    /** @param array<array-key, mixed> $out */
    private static function assertMarked(array $out): void {
        self::assertStringContainsString('"_truncated":true', (string) wp_json_encode($out));
    }
}
