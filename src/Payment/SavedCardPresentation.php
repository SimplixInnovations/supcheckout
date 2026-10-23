<?php

namespace Simplixi\SUPCheckout\Payment;

defined('ABSPATH') || exit;

final class SavedCardPresentation {
    /**
     * Return only the final four display digits from a provider card shape.
     *
     * Explicit provider last4 is preferred only when it is a string that
     * resolves to exactly four digits. Provider number is a string-only
     * fallback source; all non-digits and all but the final four digits are
     * discarded immediately.
     *
     * @param array<string, mixed> $card Provider saved-card shape.
     */
    public static function last_four(array $card): string {
        if (array_key_exists('last4', $card) && is_string($card['last4'])) {
            $digits = preg_replace('/\D+/', '', $card['last4']);
            if (is_string($digits) && strlen($digits) === 4) {
                return $digits;
            }
        }

        if (array_key_exists('number', $card) && is_string($card['number'])) {
            $digits = preg_replace('/\D+/', '', $card['number']);
            if (is_string($digits) && strlen($digits) >= 4) {
                return substr($digits, -4);
            }
        }

        return '';
    }

    /**
     * Return a provider card token only when it is already a strict,
     * whitespace-free string. Presentation code must never coerce token types.
     *
     * @param array<string, mixed> $card Provider saved-card shape.
     */
    public static function token(array $card): ?string {
        if (!isset($card['token']) || !is_string($card['token']) || $card['token'] === '') {
            return null;
        }

        return preg_match('/\s/', $card['token']) === 1 ? null : $card['token'];
    }

    /**
     * Return a short, digit-free provider brand suitable for presentation.
     *
     * @param array<string, mixed> $card Provider saved-card shape.
     */
    public static function safe_brand(array $card): string {
        if (!isset($card['brand']) || !is_string($card['brand'])) {
            return '';
        }

        $brand = trim($card['brand']);
        if ($brand === '') {
            return '';
        }

        return preg_match("/^[\p{L}][\p{L} .&'_-]{0,31}$/u", $brand) === 1 ? $brand : '';
    }

    /**
     * Build a bounded saved-card display label containing at most last four.
     *
     * The caller owns localization of the fallback label.
     *
     * @param array<string, mixed> $card Provider saved-card shape.
     */
    public static function label(array $card, string $fallback): string {
        $last_four = self::last_four($card);

        return $last_four === '' ? $fallback : '•••• ' . $last_four;
    }

    /**
     * Convert provider card data to the only shape permitted in Blocks
     * localized settings. Card number sources are intentionally omitted.
     *
     * @param array<string, mixed> $card Provider saved-card shape.
     * @return array{selection:string,label:string,brand:string}|null
     */
    public static function for_blocks(array $card, string $fallback, string $selection): ?array {
        if (self::token($card) === null || !SavedCardSelection::is_handle($selection)) {
            return null;
        }

        return array(
            'selection' => $selection,
            'label' => self::label($card, $fallback),
            'brand' => self::safe_brand($card),
        );
    }
}
