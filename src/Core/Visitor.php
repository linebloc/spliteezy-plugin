<?php

namespace Spliteezy\Core;

defined('ABSPATH') || exit;

/**
 * Manages the anonymous visitor ID stored in a first-party cookie.
 * The visitor ID is used for deterministic, stable variant assignment.
 */
class Visitor
{
    private const COOKIE_NAME = 'spliteezy_vid';

    private const COOKIE_TTL = YEAR_IN_SECONDS;

    private static ?string $id = null;

    public static function id(): string
    {
        if (self::$id !== null) {
            return self::$id;
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- the raw cookie is only pattern-checked here (^eezy_[a-f0-9]{32}$); it is unslashed and sanitized on the next line before storage.
        if (isset($_COOKIE[self::COOKIE_NAME]) && self::is_valid_id($_COOKIE[self::COOKIE_NAME])) {
            self::$id = sanitize_text_field(wp_unslash($_COOKIE[self::COOKIE_NAME]));

            return self::$id;
        }

        self::$id = self::generate_id();
        self::set_cookie(self::$id);

        return self::$id;
    }

    /**
     * Deterministically pick a variant index for a given test.
     * Returns a 0-based index into the test's variants array.
     */
    public static function assign_variant(string $test_id, array $weights): int
    {
        $visitor_id = self::id();
        $hash = crc32($visitor_id.'|'.$test_id);
        $bucket = abs($hash) % 10000;

        $cursor = 0;
        $total = array_sum($weights);

        foreach ($weights as $index => $weight) {
            $cursor += (int) round(($weight / $total) * 10000);
            if ($bucket < $cursor) {
                return (int) $index;
            }
        }

        return 0;
    }

    private static function generate_id(): string
    {
        return 'eezy_'.bin2hex(random_bytes(16));
    }

    private static function is_valid_id(string $value): bool
    {
        return (bool) preg_match('/^eezy_[a-f0-9]{32}$/', $value);
    }

    private static function set_cookie(string $id): void
    {
        // Headers may already be sent on some setups; only set if possible.
        if (headers_sent()) {
            return;
        }

        // Deliberately NOT HttpOnly — the cache-safe resolver script must
        // read it. The value is an anonymous random ID with no session linkage.
        setcookie(
            self::COOKIE_NAME,
            $id,
            [
                'expires' => time() + self::COOKIE_TTL,
                'path' => '/',
                'domain' => '',
                'secure' => is_ssl(),
                'httponly' => false,
                'samesite' => 'Lax',
            ]
        );
    }
}
