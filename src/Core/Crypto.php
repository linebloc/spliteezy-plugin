<?php

namespace Spliteezy\Core;

defined('ABSPATH') || exit;

/**
 * At-rest encryption for sensitive option values (the API key), using a
 * key derived from this WordPress install's own AUTH_KEY salt — nothing
 * new to configure or store, and moving the value to a different install
 * (different salts) simply makes it undecryptable rather than leaking it.
 */
class Crypto
{
    private const SODIUM_PREFIX = 'sdxb1:';

    private const OPENSSL_PREFIX = 'ossl1:';

    private const OPENSSL_CIPHER = 'aes-256-cbc';

    public static function encrypt(string $plaintext): string
    {
        if ($plaintext === '') {
            return '';
        }

        $key = self::derive_key();

        if (function_exists('sodium_crypto_secretbox')) {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $cipher = sodium_crypto_secretbox($plaintext, $nonce, $key);

            return self::SODIUM_PREFIX.base64_encode($nonce.$cipher);
        }

        if (function_exists('openssl_encrypt')) {
            $iv = random_bytes((int) openssl_cipher_iv_length(self::OPENSSL_CIPHER));
            $cipher = openssl_encrypt($plaintext, self::OPENSSL_CIPHER, $key, OPENSSL_RAW_DATA, $iv);

            if ($cipher !== false) {
                return self::OPENSSL_PREFIX.base64_encode($iv.$cipher);
            }
        }

        // Neither sodium nor openssl available (very rare hosting) — store
        // as-is rather than losing the stored connection entirely.
        return $plaintext;
    }

    /**
     * Decrypts a value produced by encrypt(). A value with no recognised
     * prefix is legacy plaintext (stored before this feature existed) and
     * is returned unchanged — see needs_migration().
     */
    public static function decrypt(string $stored): string
    {
        if ($stored === '') {
            return '';
        }

        if (str_starts_with($stored, self::SODIUM_PREFIX)) {
            if (! function_exists('sodium_crypto_secretbox_open')) {
                return '';
            }

            $raw = base64_decode(substr($stored, strlen(self::SODIUM_PREFIX)), true);

            if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
                return '';
            }

            $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $plain = sodium_crypto_secretbox_open($cipher, $nonce, self::derive_key());

            return $plain === false ? '' : $plain;
        }

        if (str_starts_with($stored, self::OPENSSL_PREFIX)) {
            if (! function_exists('openssl_decrypt')) {
                return '';
            }

            $raw = base64_decode(substr($stored, strlen(self::OPENSSL_PREFIX)), true);
            $iv_len = (int) openssl_cipher_iv_length(self::OPENSSL_CIPHER);

            if ($raw === false || strlen($raw) <= $iv_len) {
                return '';
            }

            $iv = substr($raw, 0, $iv_len);
            $cipher = substr($raw, $iv_len);
            $plain = openssl_decrypt($cipher, self::OPENSSL_CIPHER, self::derive_key(), OPENSSL_RAW_DATA, $iv);

            return $plain === false ? '' : $plain;
        }

        // No recognised prefix: legacy plaintext value stored before
        // encryption-at-rest was added.
        return $stored;
    }

    /**
     * True when a stored value was not produced by encrypt() — i.e. a
     * legacy plaintext value that should be re-saved through encrypt() on
     * next write to migrate it.
     */
    public static function needs_migration(string $stored): bool
    {
        return $stored !== ''
            && ! str_starts_with($stored, self::SODIUM_PREFIX)
            && ! str_starts_with($stored, self::OPENSSL_PREFIX);
    }

    /** 32-byte key derived from this install's own secret salt. */
    private static function derive_key(): string
    {
        return hash('sha256', wp_salt('auth'), true);
    }
}
