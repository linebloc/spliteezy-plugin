<?php

namespace Spliteezy\Api;

use Spliteezy\Core\CacheCompat;
use Spliteezy\Core\Options;
use Spliteezy\Core\VaryRenderer;

defined('ABSPATH') || exit;

/**
 * Local cache layer for the test manifest.
 *
 * The manifest is fetched from the Laravel API and stored in a transient
 * so that every page load does not hit the remote API. The cache is invalidated
 * automatically by TTL or explicitly when a test changes.
 */
class Manifest
{
    private const TRANSIENT_KEY = 'spliteezy_manifest';

    private const STATE_OPTION = 'spliteezy_manifest_state';

    private const LAST_GOOD_OPTION = 'spliteezy_manifest_last_good';

    private const TTL_SECONDS = 300; // 5 minutes

    /**
     * Returns null only when the API has never been reachable. A transient
     * API failure falls back to the last successful fetch instead of
     * reporting "no active tests".
     *
     * @return array<string, mixed>|null
     */
    public static function get(): ?array
    {
        $cached = get_transient(self::TRANSIENT_KEY);

        if (is_array($cached)) {
            return $cached;
        }

        $manifest = (new Client)->get_manifest();

        if (is_array($manifest)) {
            set_transient(self::TRANSIENT_KEY, $manifest, self::TTL_SECONDS);
            update_option(self::LAST_GOOD_OPTION, $manifest, false);
            self::purge_page_cache_if_changed($manifest);

            return $manifest;
        }

        $last_good = get_option(self::LAST_GOOD_OPTION, null);

        return is_array($last_good) ? $last_good : null;
    }

    /**
     * Purge affected pages from any full-page cache when the set of active
     * tests changes, so cached HTML never keeps serving a stale test.
     *
     * @param  array<string, mixed>  $manifest
     */
    private static function purge_page_cache_if_changed(array $manifest): void
    {
        $targets = [];
        $fingerprint = [];

        foreach ($manifest['tests'] ?? [] as $test) {
            $target_post_id = (int) ($test['target_post_id'] ?? 0);

            if ($target_post_id > 0) {
                $targets[] = $target_post_id;
            }

            $fingerprint[] = [
                'id' => $test['id'] ?? null,
                'status' => $test['status'] ?? null,
                'target' => $target_post_id,
                'variants' => array_map(
                    static function ($variant) {
                        return [
                            'id' => $variant['id'] ?? null,
                            'post_id' => $variant['post_id'] ?? null,
                            'weight' => $variant['weight'] ?? null,
                        ];
                    },
                    is_array($test['variants'] ?? null) ? $test['variants'] : []
                ),
            ];
        }

        // The delivery mode is part of the signature so a mode switch
        // triggers the same resync as a test change.
        $signature = md5((string) wp_json_encode(['mode' => CacheCompat::mode(), 'tests' => $fingerprint]));
        $previous = get_option(self::STATE_OPTION, []);
        $previous = is_array($previous) ? $previous : [];

        if (($previous['signature'] ?? null) === $signature) {
            return;
        }

        // Purge targets from before AND after the change.
        $previous_targets = array_map('intval', (array) ($previous['targets'] ?? []));

        foreach (array_unique(array_merge($previous_targets, $targets)) as $post_id) {
            CacheCompat::purge_post($post_id);
        }

        // Server mode excludes tested pages from NitroPack's cache; client
        // and vary modes need them cached, so their exclusion set is empty.
        $excluded_targets = CacheCompat::mode() === CacheCompat::MODE_SERVER ? $targets : [];
        $previous_excluded = array_map('intval', (array) ($previous['excluded_targets'] ?? $previous['targets'] ?? []));
        CacheCompat::sync_nitropack_excluded_urls($previous_excluded, $excluded_targets);

        // Vary mode keeps one variation-cookie registration per active test.
        $vary_cookies = [];

        if (CacheCompat::mode() === CacheCompat::MODE_VARY) {
            foreach ($manifest['tests'] ?? [] as $test) {
                if (($test['status'] ?? null) !== 'active' || empty($test['id'])) {
                    continue;
                }

                $variant_count = count(is_array($test['variants'] ?? null) ? $test['variants'] : []);

                if ($variant_count < 2) {
                    continue;
                }

                $vary_cookies[VaryRenderer::cookie_name((string) $test['id'])] = implode(',', range(0, $variant_count - 1));
            }
        }

        $previous_vary = array_map('strval', (array) ($previous['vary_cookies'] ?? []));
        CacheCompat::sync_nitropack_vary_cookies($previous_vary, $vary_cookies);

        if (! empty($vary_cookies) || ! empty($previous_vary)) {
            CacheCompat::refresh_wp_rocket_config();
        }

        update_option(self::STATE_OPTION, [
            'signature' => $signature,
            'targets' => $targets,
            'excluded_targets' => $excluded_targets,
            'vary_cookies' => array_keys($vary_cookies),
        ], false);
    }

    public static function flush(): void
    {
        delete_transient(self::TRANSIENT_KEY);
    }

    /**
     * IDs of active tests from the freshest locally-available manifest data.
     * Option/transient reads only — never an API call — so it is safe to
     * consult on every request (e.g. from cache-plugin filters).
     *
     * @return array<string>
     */
    public static function active_test_ids(): array
    {
        $manifest = get_transient(self::TRANSIENT_KEY);

        if (! is_array($manifest)) {
            $manifest = get_option(self::LAST_GOOD_OPTION, null);
        }

        $ids = [];

        foreach (is_array($manifest) ? ($manifest['tests'] ?? []) : [] as $test) {
            if (($test['status'] ?? null) === 'active' && ! empty($test['id'])) {
                $ids[] = (string) $test['id'];
            }
        }

        return $ids;
    }

    /**
     * Register the REST endpoint that allows the Spliteezy app to push
     * an instant cache invalidation whenever plan overrides change.
     *
     * Secured with a per-site HMAC token derived from the stored API key.
     */
    public static function register_flush_endpoint(): void
    {
        add_action('rest_api_init', function (): void {
            register_rest_route('spliteezy/v1', '/flush', [
                'methods' => 'POST',
                'callback' => [self::class, 'handle_flush_request'],
                'permission_callback' => '__return_true',
            ]);
        });
    }

    /**
     * @param  \WP_REST_Request<array<string, mixed>>  $request
     * @return \WP_REST_Response|\WP_Error
     */
    public static function handle_flush_request(\WP_REST_Request $request)
    {
        $api_key = Options::api_key();

        if (! $api_key) {
            return new \WP_Error('not_configured', 'Plugin not configured.', ['status' => 400]);
        }

        $token = (string) $request->get_header('X-Spliteezy-Token');
        $expected = hash_hmac('sha256', 'manifest_flush', $api_key);

        if (! hash_equals($expected, $token)) {
            return new \WP_Error('unauthorized', 'Invalid token.', ['status' => 403]);
        }

        self::flush();

        // Refetch immediately so the page-cache purge also happens right away.
        self::get();

        return rest_ensure_response(['flushed' => true]);
    }

    /**
     * Find the first active test matching the given post ID or URL.
     *
     * @return array<string, mixed>|null
     */
    public static function find_test_for_post(int $post_id, string $post_type): ?array
    {
        $manifest = self::get();
        $tests = is_array($manifest) ? ($manifest['tests'] ?? []) : [];

        foreach ($tests as $test) {
            if (! isset($test['status']) || $test['status'] !== 'active') {
                continue;
            }

            if (isset($test['target_post_id']) && (int) $test['target_post_id'] === $post_id) {
                return $test;
            }
        }

        return null;
    }
}
