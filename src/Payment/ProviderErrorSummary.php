<?php

namespace Simplixi\SUPCheckout\Payment;

defined('ABSPATH') || exit;

/**
 * Merchant-safe summary of why UPayments rejected a request.
 *
 * The reason goes into a private order note, so it is reduced to the provider's
 * message and field errors, stripped of markup and control characters, redacted
 * of anything credential- or contact-shaped and bounded in length. Shoppers never
 * see it; they keep the generic checkout notice.
 */
final class ProviderErrorSummary {
    private const MAX_LENGTH = 240;
    private const MAX_PARTS = 8;
    private const MAX_DEPTH = 3;

    /**
     * @param mixed $body Raw provider response body.
     * @return string Sanitized reason, or '' when the body carries none.
     */
    public static function detail($body) {
        if (!is_string($body) || $body === '') {
            return '';
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return '';
        }
        $parts = array();
        foreach (array('message', 'error', 'errors', 'errorMessage') as $key) {
            if (array_key_exists($key, $decoded)) {
                self::collect($decoded[$key], '', $parts, 0);
            }
        }
        return self::sanitize(implode('; ', array_values(array_unique($parts))));
    }

    /**
     * @param mixed         $value Provider error value.
     * @param string        $field Field name the value belongs to, if any.
     * @param array<string> $parts Collected parts.
     * @param int           $depth Current nesting depth.
     * @return void
     */
    private static function collect($value, $field, array &$parts, $depth) {
        if ($depth > self::MAX_DEPTH || count($parts) >= self::MAX_PARTS) {
            return;
        }
        if (is_string($value) || is_int($value) || is_float($value)) {
            $text = trim((string) $value);
            if ($text !== '') {
                $parts[] = $field !== '' ? $field . ': ' . $text : $text;
            }
            return;
        }
        if (!is_array($value)) {
            return;
        }
        foreach ($value as $key => $item) {
            $child = is_string($key) ? $key : $field;
            self::collect($item, $child, $parts, $depth + 1);
        }
    }

    /**
     * @param string $text Raw reason text.
     * @return string
     */
    private static function sanitize($text) {
        $text = (string) preg_replace('/<[^>]*>/u', '', $text);
        $text = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text);
        $text = (string) preg_replace('/\bBearer\s+\S+/iu', 'Bearer [redacted]', $text);
        $text = (string) preg_replace('#\bhttps?://\S+#iu', '[url]', $text);
        $text = (string) preg_replace('/[^\s@]+@[^\s@]+\.[^\s@]+/u', '[email]', $text);
        $text = (string) preg_replace('/\b(?=[A-Za-z0-9_\-]*\d)[A-Za-z0-9_\-]{20,}\b/u', '[redacted]', $text);
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if (mb_strlen($text, 'UTF-8') > self::MAX_LENGTH) {
            $text = rtrim(mb_substr($text, 0, self::MAX_LENGTH, 'UTF-8')) . '…';
        }
        return $text;
    }

    private function __construct() {}
}
