<?php

namespace Spliteezy\Core;

use Spliteezy\Api\Manifest;

defined('ABSPATH') || exit;

/**
 * Page-cache compatibility layer: detection, delivery-mode resolution,
 * per-URL cache bypass for pages with a running test, and purging when
 * tests change.
 */
class CacheCompat
{
    public const MODE_SERVER = 'server';

    public const MODE_CLIENT = 'client';

    public const MODE_VARY = 'vary';

    /**
     * Returns the name of an active full-page cache (plugin or host),
     * or null when none is detected.
     */
    public static function detect(): ?string
    {
        if (defined('WP_ROCKET_VERSION')) {
            return 'WP Rocket';
        }

        if (defined('W3TC_VERSION')) {
            return 'W3 Total Cache';
        }

        if (function_exists('wp_cache_clear_cache')) {
            return 'WP Super Cache';
        }

        if (class_exists('LiteSpeed_Cache') || defined('LSCWP_V')) {
            return 'LiteSpeed Cache';
        }

        if (defined('WPFC_MAIN_PATH')) {
            return 'WP Fastest Cache';
        }

        if (function_exists('sg_cachepress_purge_cache') || class_exists('SiteGround_Optimizer\Loader\Loader')) {
            return 'SiteGround Optimizer';
        }

        if (defined('WPCACHEHOME')) {
            return 'WP Super Cache';
        }

        if (class_exists('Cache_Enabler')) {
            return 'Cache Enabler';
        }

        if (defined('BREEZE_VERSION')) {
            return 'Breeze';
        }

        if (class_exists('WP_Optimize') && function_exists('wpo_cache_flush')) {
            return 'WP-Optimize';
        }

        if (defined('NITROPACK_VERSION')) {
            return 'NitroPack';
        }

        if (class_exists('WpeCommon')) {
            return 'WP Engine cache';
        }

        if (defined('KINSTAMU_VERSION') || isset($_SERVER['KINSTA_CACHE_ZONE'])) {
            return 'Kinsta cache';
        }

        if (isset($_SERVER['IS_PRESSABLE']) || defined('IS_PRESSABLE')) {
            return 'Pressable cache';
        }

        if (class_exists('\Flywheel\Plugin')) {
            return 'Flywheel cache';
        }

        if (defined('SPINUPWP_CACHE_PATH') || class_exists('SpinupWp\Plugin')) {
            return 'SpinupWP cache';
        }

        // Generic signal: a drop-in page cache installed advanced-cache.php.
        if (defined('WP_CACHE') && WP_CACHE && file_exists(WP_CONTENT_DIR.'/advanced-cache.php')) {
            return __('a page cache (advanced-cache.php)', 'spliteezy');
        }

        return null;
    }

    /**
     * Resolves the effective delivery mode: 'server' (backend swap, the
     * default — the legacy 'auto' setting also resolves here), 'client'
     * (cache-safe embedded variants + JS assignment), or 'vary'
     * (one cached copy per variant, keyed by URL parameter).
     */
    public static function mode(): string
    {
        $setting = Options::delivery_mode();

        $mode = in_array($setting, [self::MODE_CLIENT, self::MODE_VARY], true) ? $setting : self::MODE_SERVER;

        /**
         * Filters the resolved delivery mode ('server', 'client' or 'vary').
         * Lets hosts/agencies force a mode from code.
         */
        return (string) apply_filters('spliteezy_delivery_mode', $mode);
    }

    /**
     * Host-level cache layered above a cookie-vary-capable plugin cache, or
     * null. Host caches key on URLs only, so assigned visitors there arrive
     * via the parametrized-URL redirect rather than a direct cookie hit.
     */
    public static function layered_host_cache(): ?string
    {
        if (! defined('NITROPACK_VERSION') && ! defined('WP_ROCKET_VERSION')) {
            return null;
        }

        if (class_exists('WpeCommon')) {
            return 'WP Engine';
        }

        if (defined('KINSTAMU_VERSION') || isset($_SERVER['KINSTA_CACHE_ZONE'])) {
            return 'Kinsta';
        }

        if (isset($_SERVER['IS_PRESSABLE']) || defined('IS_PRESSABLE')) {
            return 'Pressable';
        }

        if (class_exists('\Flywheel\Plugin')) {
            return 'Flywheel';
        }

        if (defined('SPINUPWP_CACHE_PATH') || class_exists('SpinupWp\Plugin')) {
            return 'SpinupWP';
        }

        return null;
    }

    /**
     * Cache signals for a vary-mode cookie-direct render, which is only
     * correct for requests carrying the same assignment cookie: browsers
     * must key on it (Vary) and URL-keyed layers must not store it. WP
     * Rocket is exempt — it is the cookie-vary layer itself, and a bypass
     * would disable it.
     */
    public static function vary_page(int $post_id = 0): void
    {
        if (! headers_sent()) {
            header('Vary: Cookie', false);
        }

        if (defined('WP_ROCKET_VERSION')) {
            return;
        }

        self::bypass_page($post_id);
    }

    /**
     * Flag the current request as uncacheable by every known full-page cache,
     * so the per-visitor swap on a tested page is never frozen into a cache.
     * NitroPack cannot be bypassed at request time — its drop-in serves cache
     * before plugins load — so sync_nitropack_excluded_urls() keeps its
     * cloud-side exclusion list in sync instead.
     */
    public static function bypass_page(int $post_id = 0): void
    {
        if (! defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- DONOTCACHEPAGE is the shared cache-bypass constant read by WP Rocket, W3TC, WP Super Cache, WP-Optimize and Breeze; defining it is its intended API and the name is owned by the ecosystem, not this plugin.
        }

        if (! headers_sent()) {
            nocache_headers();

            // SiteGround's cache ignores Cache-Control and DONOTCACHEPAGE;
            // this response header is its only bypass signal.
            header('X-Cache-Enabled: False');
        }

        // LiteSpeed Cache.
        do_action('litespeed_control_set_nocache', 'spliteezy: active A/B test on this page'); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- 'litespeed_control_set_nocache' is an action registered by LiteSpeed Cache for third-party integrations; the hook name is owned by LiteSpeed and cannot be renamed.

        // WP Fastest Cache.
        if (function_exists('wpfc_exclude_current_page')) {
            wpfc_exclude_current_page();
        }

        // Cache Enabler.
        add_filter('cache_enabler_bypass_cache', '__return_true');

        // Caches without a standard signal listen to this.
        do_action('spliteezy_bypass_page_cache', $post_id);
    }

    /**
     * Sync NitroPack's cloud-side "Excluded URLs" with the pages that must
     * not be cached. Its drop-in serves cache before plugins load, so this
     * Cloud API call — signed with the site's own stored credentials — is
     * the only bypass that works. Callers pass the desired set; best-effort,
     * with the settings page's manual instruction as fallback.
     *
     * @param  array<int>  $previous_post_ids
     * @param  array<int>  $current_post_ids
     */
    public static function sync_nitropack_excluded_urls(array $previous_post_ids, array $current_post_ids): void
    {
        if (
            ! defined('NITROPACK_VERSION')
            || ! function_exists('nitropack_get_site_config')
            || ! class_exists('\NitroPack\SDK\Api\ExcludedUrls')
        ) {
            return;
        }

        $added = array_diff($current_post_ids, $previous_post_ids);
        $removed = array_diff($previous_post_ids, $current_post_ids);

        if (empty($added) && empty($removed)) {
            return;
        }

        $config = nitropack_get_site_config();

        if (empty($config['siteId']) || empty($config['siteSecret'])) {
            return;
        }

        try {
            $api = new \NitroPack\SDK\Api\ExcludedUrls($config['siteId'], $config['siteSecret']);

            foreach ($added as $post_id) {
                foreach (self::nitropack_url_patterns((int) $post_id) as $pattern) {
                    $api->add($pattern);
                }
            }

            foreach ($removed as $post_id) {
                foreach (self::nitropack_url_patterns((int) $post_id) as $pattern) {
                    $api->remove($pattern);
                }
            }

            if (! empty($added)) {
                $api->enable();
            }
        } catch (\Throwable $e) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement -- best-effort cloud sync; failures intentionally fall back to the documented manual path.
            // Best-effort: exclusion failures fall back to the manual notice.
        }
    }

    /**
     * NitroPack exclusion patterns for one post: the exact URL plus its
     * query-string variants. Never `url*` — on a homepage that wildcard
     * would exclude the entire site from NitroPack.
     *
     * @return array<string>
     */
    private static function nitropack_url_patterns(int $post_id): array
    {
        $permalink = get_permalink($post_id);

        if (! $permalink) {
            return [];
        }

        return [$permalink, $permalink.'?*'];
    }

    /**
     * Sync NitroPack's variation-cookie registrations with the active tests
     * so its cache keeps one copy of a tested page per assignment-cookie
     * value. Callers pass the desired set; best-effort.
     *
     * @param  array<string>  $previous_names
     * @param  array<string, string>  $current  cookie name => comma-separated values (aids cache warmup)
     */
    public static function sync_nitropack_vary_cookies(array $previous_names, array $current): void
    {
        if (
            ! defined('NITROPACK_VERSION')
            || ! function_exists('nitropack_get_site_config')
            || ! class_exists('\NitroPack\SDK\Api\VariationCookie')
        ) {
            return;
        }

        $added = array_diff(array_keys($current), $previous_names);
        $removed = array_diff($previous_names, array_keys($current));

        if (empty($added) && empty($removed)) {
            return;
        }

        $config = nitropack_get_site_config();

        if (empty($config['siteId']) || empty($config['siteSecret'])) {
            return;
        }

        try {
            $api = new \NitroPack\SDK\Api\VariationCookie($config['siteId'], $config['siteSecret']);

            foreach ($added as $name) {
                $api->set($name, $current[$name], 0);
            }

            foreach ($removed as $name) {
                $api->delete($name);
            }
        } catch (\Throwable $e) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement -- best-effort cloud sync; failures intentionally fall back to the documented manual path.
            // Best-effort: without the registration, assigned visitors reach
            // their variant through the parametrized URL instead of a direct
            // cache hit.
        }
    }

    /**
     * Regenerate WP Rocket's static config file so the dynamic-cookie and
     * query-string registrations take effect. Deferred to the next wp-admin
     * load when the generator isn't available in this context.
     */
    public static function refresh_wp_rocket_config(): void
    {
        if (! defined('WP_ROCKET_VERSION')) {
            return;
        }

        if (function_exists('rocket_generate_config_file')) {
            rocket_generate_config_file();
            delete_option('spliteezy_rocket_config_pending');

            return;
        }

        update_option('spliteezy_rocket_config_pending', 1, false);
    }

    /**
     * Cache-plugin registrations for vary mode: assignment cookies and the
     * variant URL parameter. Registered on every request; the WP Rocket
     * filters only execute during its config generation, so the lazy
     * manifest read costs nothing on normal page loads.
     */
    public static function register_vary_hooks(): void
    {
        add_filter('rocket_cache_dynamic_cookies', static function ($cookies) {
            $cookies = (array) $cookies;

            if (self::mode() !== self::MODE_VARY) {
                return $cookies;
            }

            foreach (Manifest::active_test_ids() as $test_id) {
                $cookies[] = VaryRenderer::cookie_name($test_id);
            }

            return $cookies;
        });

        // WP Rocket skips caching for unknown query strings — register the
        // variant parameter so the parametrized copies are cached per value.
        add_filter('rocket_cache_query_strings', static function ($params) {
            $params = (array) $params;

            if (self::mode() === self::MODE_VARY) {
                $params[] = VaryRenderer::URL_PARAM;
            }

            return $params;
        });

        add_action('admin_init', static function (): void {
            if (! defined('WP_ROCKET_VERSION') || ! function_exists('rocket_generate_config_file')) {
                return;
            }

            if (get_option('spliteezy_rocket_config_pending')) {
                rocket_generate_config_file();
                delete_option('spliteezy_rocket_config_pending');
            }
        });
    }

    /**
     * Hooks that keep page caches honest when test content changes.
     * Editing a variant post must purge the cached page of its control —
     * the control's URL is where the variant's HTML is actually served.
     */
    public static function register_purge_hooks(): void
    {
        add_action(
            'save_post',
            static function (int $post_id): void {
                if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
                    return;
                }

                if (! get_post_meta($post_id, '_spliteezy_variant', true)) {
                    return;
                }

                $control_id = (int) get_post_meta($post_id, '_spliteezy_control_post_id', true);

                if ($control_id > 0) {
                    self::purge_post($control_id);
                }
            },
            20
        );
    }

    /**
     * Purge every known full-page cache for a single post's URL.
     * Falls back to nothing (not purge_all) when no cache is present.
     */
    public static function purge_post(int $post_id): void
    {
        if ($post_id <= 0) {
            return;
        }

        // WP Rocket.
        if (function_exists('rocket_clean_post')) {
            rocket_clean_post($post_id);
        }

        // W3 Total Cache.
        if (function_exists('w3tc_flush_post')) {
            w3tc_flush_post($post_id);
        }

        // WP Super Cache.
        if (function_exists('wp_cache_post_change')) {
            wp_cache_post_change($post_id);
        }

        // LiteSpeed Cache.
        do_action('litespeed_purge_post', $post_id); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- 'litespeed_purge_post' is an action registered by LiteSpeed Cache for third-party integrations; the hook name is owned by LiteSpeed and cannot be renamed.

        // WP Fastest Cache.
        if (function_exists('wpfc_clear_post_cache_by_id')) {
            wpfc_clear_post_cache_by_id($post_id);
        }

        // SiteGround Optimizer.
        if (function_exists('sg_cachepress_purge_cache')) {
            $url = get_permalink($post_id);
            sg_cachepress_purge_cache($url ?: '');
        }

        // Cache Enabler.
        if (class_exists('Cache_Enabler') && method_exists('Cache_Enabler', 'clear_page_cache_by_post')) {
            \Cache_Enabler::clear_page_cache_by_post($post_id);
        }

        // WP-Optimize.
        if (class_exists('WPO_Page_Cache') && method_exists('WPO_Page_Cache', 'delete_single_post_cache')) {
            \WPO_Page_Cache::delete_single_post_cache($post_id);
        }

        // NitroPack.
        if (function_exists('nitropack_sdk_purge')) {
            nitropack_sdk_purge(get_permalink($post_id) ?: '');
        }

        // SpinupWP.
        if (function_exists('spinupwp_purge_url')) {
            $url = get_permalink($post_id);

            if ($url) {
                spinupwp_purge_url($url);
            }
        }

        // WP Engine (no per-URL API — full purge).
        if (class_exists('WpeCommon') && method_exists('WpeCommon', 'purge_varnish_cache')) {
            \WpeCommon::purge_varnish_cache($post_id);
        }

        // Host/other caches without a per-post API listen to this.
        do_action('spliteezy_purge_post', $post_id);
    }

    /**
     * Purge every known full-page cache entirely. Used when the set of
     * active tests changes in a way that cannot be mapped to single posts.
     */
    public static function purge_all(): void
    {
        if (function_exists('rocket_clean_domain')) {
            rocket_clean_domain();
        }

        if (function_exists('w3tc_flush_posts')) {
            w3tc_flush_posts();
        }

        if (function_exists('wp_cache_clear_cache')) {
            wp_cache_clear_cache();
        }

        do_action('litespeed_purge_all'); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- 'litespeed_purge_all' is an action registered by LiteSpeed Cache for third-party integrations; the hook name is owned by LiteSpeed and cannot be renamed.

        if (function_exists('wpfc_clear_all_cache')) {
            wpfc_clear_all_cache(true);
        }

        if (function_exists('sg_cachepress_purge_cache')) {
            sg_cachepress_purge_cache();
        }

        if (class_exists('Cache_Enabler') && method_exists('Cache_Enabler', 'clear_complete_cache')) {
            \Cache_Enabler::clear_complete_cache();
        }

        if (class_exists('WPO_Page_Cache') && method_exists('WPO_Page_Cache', 'flush')) {
            \WPO_Page_Cache::flush();
        }

        if (defined('BREEZE_VERSION') && class_exists('Breeze_PurgeCache')) {
            do_action('breeze_clear_all_cache'); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- 'breeze_clear_all_cache' is an action registered by Breeze for third-party integrations; the hook name is owned by Breeze and cannot be renamed.
        }

        if (function_exists('nitropack_sdk_purge')) {
            nitropack_sdk_purge();
        }

        if (class_exists('WpeCommon')) {
            if (method_exists('WpeCommon', 'purge_memcached')) {
                \WpeCommon::purge_memcached();
            }
            if (method_exists('WpeCommon', 'purge_varnish_cache')) {
                \WpeCommon::purge_varnish_cache();
            }
        }

        // Legacy Kinsta MU plugin only — current versions expose no public
        // purge function (their cache honors Cache-Control + autopurges).
        if (function_exists('kinsta_cache_purge_all')) {
            kinsta_cache_purge_all();
        }

        if (function_exists('spinupwp_purge_site')) {
            spinupwp_purge_site();
        }

        do_action('spliteezy_purge_all');
    }
}
