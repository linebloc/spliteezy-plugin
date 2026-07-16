<?php

namespace Spliteezy\Core;

use Spliteezy\Api\Manifest;

defined('ABSPATH') || exit;

/**
 * Backend variant assignment. Hooks `template_redirect` (before any output)
 * to swap the original post's content for the assigned variant's. URL, post
 * ID, comments, SEO metadata, and template all stay from the original — no
 * redirect, no flicker.
 */
class Assignor
{
    /**
     * Currently active test data for this request.
     *
     * @var array<string, mixed>|null
     */
    private ?array $active_test = null;

    /**
     * Assigned variant index (0 = control).
     */
    private int $assigned_index = 0;

    public function register(): void
    {
        if (! Options::is_configured()) {
            return;
        }

        // Run before template selection so the content swap is in place early.
        add_action('template_redirect', [$this, 'assign'], 1);
    }

    public function assign(): void
    {
        // Only run on singular front-end pages.
        if (is_admin() || ! is_singular()) {
            return;
        }

        $post = get_queried_object();

        if (! ($post instanceof \WP_Post)) {
            return;
        }

        // Skip if this post type is not opted-in.
        $enabled = Options::enabled_post_types();
        if (! in_array($post->post_type, $enabled, true)) {
            return;
        }

        // Skip variant posts — they are not test targets themselves.
        if (get_post_meta($post->ID, '_spliteezy_variant', true)) {
            return;
        }

        $test = Manifest::find_test_for_post($post->ID, $post->post_type);

        if ($test === null) {
            return;
        }

        $variants = $test['variants'] ?? [];
        $weights = array_column($variants, 'weight');

        if (empty($weights)) {
            return;
        }

        // Cache-safe delivery: embed variants, assign in the browser.
        if (CacheCompat::mode() === CacheCompat::MODE_CLIENT) {
            (new ClientRenderer($test, $post))->register();
            $this->inject_client_context($test, $post->ID);

            return;
        }

        // Cache-per-variant delivery: variants are keyed by URL parameter
        // (works under every cache layer) with cookie-vary as a direct-hit
        // optimization where the cache supports it.
        if (CacheCompat::mode() === CacheCompat::MODE_VARY) {
            $this->assign_vary($test, $post, $variants);

            return;
        }

        // Per-visitor HTML must never be frozen into a page cache — flag
        // only this URL as uncacheable.
        CacheCompat::bypass_page($post->ID);

        // Bots and crawlers always see the control.
        if ($this->is_bot()) {
            return;
        }

        $this->active_test = $test;
        $this->assigned_index = Visitor::assign_variant((string) $test['id'], $weights);

        // Index 0 is always the control — no content swap needed.
        if ($this->assigned_index === 0) {
            $this->inject_context($test, 0, $post->ID);

            return;
        }

        $variant = $variants[$this->assigned_index] ?? null;
        $variant_pid = $variant['post_id'] ?? null;
        $variant_post = $variant_pid ? get_post((int) $variant_pid) : null;

        // Unusable variant post (deleted, unpublished): serve and track the
        // control — an untracked fallback would silently starve the variant
        // arm of all its traffic.
        if (! ($variant_post instanceof \WP_Post) || $variant_post->post_status !== 'publish') {
            $this->inject_context($test, 0, $post->ID);

            return;
        }

        $this->inject_variant_content($post, $variant_post);
        $this->inject_context($test, $this->assigned_index, (int) $variant_pid);
    }

    /**
     * Vary flow: `?eezy_v=N` renders that variant (cacheable everywhere);
     * no parameter and no cookie renders the control plus the bootstrap
     * (also cacheable everywhere); a bare assignment cookie renders its
     * variant directly, guarded by vary_page(). PHP never assigns or sets
     * cookies here — a cacheable response must carry no per-visitor state.
     *
     * @param  array<string, mixed>  $test
     * @param  array<int, array<string, mixed>>  $variants
     */
    private function assign_vary(array $test, \WP_Post $post, array $variants): void
    {
        $param_index = VaryRenderer::bucket_from_param(count($variants));

        if ($param_index !== null) {
            VaryRenderer::register_url_cleanup();
            $this->render_vary_index($test, $post, $variants, $param_index);

            return;
        }

        $cookie_index = VaryRenderer::bucket_from_cookie((string) $test['id'], count($variants));

        if ($cookie_index === null) {
            (new VaryRenderer($test, $post))->register_bootstrap();
            $this->inject_vary_context($test, 0, $post->ID);

            return;
        }

        CacheCompat::vary_page($post->ID);
        $this->render_vary_index($test, $post, $variants, $cookie_index);
    }

    /**
     * Render a vary-mode variant by index (0 = control). An unusable variant
     * post serves and tracks the control rather than dropping tracking.
     *
     * @param  array<string, mixed>  $test
     * @param  array<int, array<string, mixed>>  $variants
     */
    private function render_vary_index(array $test, \WP_Post $post, array $variants, int $index): void
    {
        if ($index === 0) {
            $this->inject_vary_context($test, 0, $post->ID);

            return;
        }

        $variant_pid = $variants[$index]['post_id'] ?? null;
        $variant_post = $variant_pid ? get_post((int) $variant_pid) : null;

        if (! ($variant_post instanceof \WP_Post) || $variant_post->post_status !== 'publish') {
            $this->inject_vary_context($test, 0, $post->ID);

            return;
        }

        $this->inject_variant_content($post, $variant_post);
        $this->inject_vary_context($test, $index, (int) $variant_pid);
    }

    /**
     * Tracker context for vary mode. Carries no visitor_id — the HTML is
     * cached and shared, so the tracker resolves the visitor from the
     * first-party cookie instead.
     */
    private function inject_vary_context(array $test, int $variant_index, int $post_id): void
    {
        add_filter(
            'spliteezy_tracker_context',
            static function () use ($test, $variant_index, $post_id): array {
                return [
                    'test_id' => $test['id'],
                    'variant_index' => $variant_index,
                    'variant_id' => $test['variants'][$variant_index]['id'] ?? null,
                    'post_id' => $post_id,
                ];
            }
        );
    }

    /**
     * Replace only the post content with the variant's content.
     * Everything else (ID, comments, SEO, template) stays from the original.
     */
    private function inject_variant_content(\WP_Post $original, \WP_Post $variant): void
    {
        global $wp_query, $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the backend swap works by pointing WordPress's own query globals at the variant content.

        $original->post_content = $variant->post_content;
        $original->post_title = $variant->post_title;

        // Keep WP internals consistent with the modified original.
        $wp_query->posts = [$original];
        $wp_query->post = $original;
        $wp_query->post_count = 1;
        $post = $original; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the content swap must be visible to every subsequent template read.

        setup_postdata($original);

        // Prime all variant meta into the object cache in one query.
        get_post_meta($variant->ID);

        $original_id = $original->ID;
        $variant_id = $variant->ID;

        // Redirect meta reads for the original to the variant when the
        // variant has its own value (covers SEO plugins, ACF, etc.).
        // Keyless reads (get_post_meta with no key — WooCommerce product
        // data, ACF get_fields) receive the merged set with variant values
        // winning. Elementor's element cache holds the original's rendered
        // HTML, so it must always miss while the swap is active.
        $meta_filter = null;
        $meta_filter = static function ($value, $post_id, $meta_key, $single) use (&$meta_filter, $original_id, $variant_id) {
            if ($post_id !== $original_id || strpos($meta_key, '_edit_') === 0) {
                return $value;
            }

            if ($meta_key === '_elementor_element_cache') {
                return false;
            }

            if ($meta_key === '') {
                remove_filter('get_post_metadata', $meta_filter, 10);
                $original_meta = get_post_meta($original_id);
                add_filter('get_post_metadata', $meta_filter, 10, 4);

                return array_merge(
                    is_array($original_meta) ? $original_meta : [],
                    get_post_meta($variant_id)
                );
            }

            if (metadata_exists('post', $variant_id, $meta_key)) {
                return get_post_meta($variant_id, $meta_key, $single);
            }

            return $value;
        };

        add_filter('get_post_metadata', $meta_filter, 10, 4);

        // Never let the variant's render be cached under the original.
        $cache_write_guard = static function ($check, $object_id, $meta_key) use ($original_id) {
            if ($object_id === $original_id && $meta_key === '_elementor_element_cache') {
                return false;
            }

            return $check;
        };

        add_filter('update_post_metadata', $cache_write_guard, 10, 3);
        add_filter('add_post_metadata', $cache_write_guard, 10, 3);
    }

    /**
     * Tracker context for cache-safe delivery — carries no per-visitor data
     * so the HTML stays cacheable.
     */
    private function inject_client_context(array $test, int $post_id): void
    {
        add_filter(
            'spliteezy_tracker_context',
            static function () use ($test, $post_id): array {
                return [
                    'client_side' => true,
                    'test_id' => $test['id'],
                    'post_id' => $post_id,
                ];
            }
        );
    }

    /**
     * Expose test context to the tracker JS (printed by Tracker as an
     * optimizer-excluded inline config script).
     */
    private function inject_context(array $test, int $variant_index, int $post_id): void
    {
        add_filter(
            'spliteezy_tracker_context',
            function () use ($test, $variant_index, $post_id): array {
                return [
                    'test_id' => $test['id'],
                    'variant_index' => $variant_index,
                    'variant_id' => $test['variants'][$variant_index]['id'] ?? null,
                    'visitor_id' => Visitor::id(),
                    'post_id' => $post_id,
                ];
            }
        );
    }

    /**
     * Simple bot detection — avoids polluting test data with crawler traffic.
     */
    private function is_bot(): bool
    {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- User-Agent read for bot detection only; unslashed, sanitized and lowercased inline, matched against fixed substrings, never stored or output.
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? strtolower(sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT']))) : '';

        foreach (['bot', 'crawler', 'spider', 'slurp', 'googlebot', 'bingbot', 'facebookexternalhit', 'headlesschrome', 'prerender'] as $fragment) {
            if (strpos($ua, $fragment) !== false) {
                return true;
            }
        }

        return false;
    }
}
