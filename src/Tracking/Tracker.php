<?php

namespace Spliteezy\Tracking;

use Spliteezy\Api\Client;
use Spliteezy\Api\Manifest;
use Spliteezy\Core\Options;

defined('ABSPATH') || exit;

/**
 * Injects the front-end tracker script and proxies its events to the
 * Spliteezy API — the API key never reaches the browser.
 */
class Tracker
{
    /**
     * Opt-out attributes recognized by JS optimizers: NitroPack (nitro-exclude),
     * WP Rocket delay-JS (nowprocket), Cloudflare Rocket Loader (data-cfasync),
     * LiteSpeed Cache defer/delay (data-no-defer, data-no-optimize).
     * Assignment/tracking scripts must never be delayed until user interaction —
     * a delayed tracker silently drops every visitor who doesn't interact.
     */
    public const OPTIMIZER_OPT_OUT_ATTRS = [
        'nitro-exclude' => true,
        'nowprocket' => true,
        'data-cfasync' => 'false',
        'data-no-defer' => '1',
        'data-no-optimize' => '1',
    ];

    public function register(): void
    {
        add_action('wp_enqueue_scripts', [$this, 'enqueue']);
        add_filter('script_loader_tag', [$this, 'exclude_from_js_optimizers'], 10, 2);
        add_filter('rocket_delay_js_exclusions', [$this, 'rocket_delay_js_exclusions']);
        add_action('wp_ajax_nopriv_spliteezy_event', [$this, 'handle_ajax_event']);
        add_action('wp_ajax_spliteezy_event', [$this, 'handle_ajax_event']);
    }

    public function enqueue(): void
    {
        if (! Options::is_configured()) {
            return;
        }

        // Context is set by Assignor only when a test is active on this page.
        $context = apply_filters('spliteezy_tracker_context', null);

        if ($context === null) {
            return;
        }

        wp_enqueue_script(
            'spliteezy-tracker',
            SPLITEEZY_URL.'assets/js/tracker.js',
            [],
            self::tracker_version(),
            ['strategy' => 'defer']
        );

        $config = [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('spliteezy_event'),
            'context' => $context,
            'goals' => $this->get_goals($context['test_id']),
        ];

        // Printed manually instead of wp_localize_script so the tag carries
        // the optimizer opt-out attributes — a delayed config script would
        // strand the excluded tracker without its configuration.
        add_action('wp_head', static function () use ($config): void {
            wp_print_inline_script_tag(
                'window.SpliteezyConfig = '.wp_json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT).';',
                array_merge(['id' => 'spliteezy-config'], self::OPTIMIZER_OPT_OUT_ATTRS)
            );
        }, 4);
    }

    /**
     * Cache-busting version for tracker.js. The plugin version only changes
     * at releases, so browsers and asset CDNs would keep serving a stale
     * tracker across mid-release updates — and a stale tracker can send
     * events in a shape the API no longer accepts.
     */
    private static function tracker_version(): string
    {
        $path = SPLITEEZY_DIR.'assets/js/tracker.js';
        $mtime = file_exists($path) ? (int) filemtime($path) : 0;

        return $mtime ? SPLITEEZY_VERSION.'.'.$mtime : SPLITEEZY_VERSION;
    }

    /**
     * Mark the tracker script tag so JS delayers leave it alone.
     */
    public function exclude_from_js_optimizers(string $tag, string $handle): string
    {
        if ($handle !== 'spliteezy-tracker') {
            return $tag;
        }

        return str_replace('<script ', '<script nitro-exclude nowprocket data-cfasync="false" data-no-defer="1" data-no-optimize="1" ', $tag);
    }

    /**
     * WP Rocket's Delay JS honors the nowprocket attribute only through its
     * remotely-updated exclusion list; registering the patterns on this
     * filter does not depend on that list. Patterns are regex fragments
     * matched anywhere in the script tag.
     *
     * @param  array<string>  $excluded
     * @return array<string>
     */
    public function rocket_delay_js_exclusions(array $excluded): array
    {
        $excluded[] = 'spliteezy';
        $excluded[] = 'SpliteezyConfig';
        $excluded[] = 'eezy-resolver';

        return $excluded;
    }

    /**
     * AJAX handler: receives events from the tracker JS, validates the nonce,
     * and forwards the batch to the Laravel API.
     */
    public function handle_ajax_event(): void
    {
        // Cached pages can outlive the nonce lifetime; the same-origin
        // fallback provides equivalent protection for this anonymous endpoint.
        if (! check_ajax_referer('spliteezy_event', 'nonce', false) && ! $this->is_same_origin_request()) {
            wp_send_json_error(['message' => 'Invalid request origin'], 403);

            return;
        }

        if ($this->is_bot_request()) {
            wp_send_json_success(); // Accept silently; never store bot events.

            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON string; individual event fields are sanitized in sanitize_event().
        $raw = isset($_POST['events']) ? wp_unslash($_POST['events']) : null;

        if (! is_string($raw)) {
            wp_send_json_error(['message' => 'Invalid payload'], 400);

            return;
        }

        $events = json_decode($raw, true);

        if (! is_array($events)) {
            wp_send_json_error(['message' => 'Invalid JSON'], 400);

            return;
        }

        // Sanitise each event — only allow expected fields through.
        $clean_events = array_map([$this, 'sanitize_event'], $events);
        $clean_events = array_filter($clean_events);

        if (empty($clean_events)) {
            wp_send_json_error(['message' => 'No valid events'], 400);

            return;
        }

        $success = (new Client)->send_events(array_values($clean_events));

        if ($success) {
            wp_send_json_success();
        } else {
            wp_send_json_error(['message' => 'API error'], 502);
        }
    }

    /**
     * True when the Origin (or Referer) header matches this site's host.
     */
    private function is_same_origin_request(): bool
    {
        $origin = get_http_origin();

        if (! $origin && isset($_SERVER['HTTP_REFERER'])) {
            $origin = esc_url_raw(wp_unslash($_SERVER['HTTP_REFERER']));
        }

        if (! $origin) {
            return false;
        }

        $origin_host = wp_parse_url($origin, PHP_URL_HOST);
        $site_host = wp_parse_url(home_url(), PHP_URL_HOST);

        return is_string($origin_host) && is_string($site_host)
            && strtolower($origin_host) === strtolower($site_host);
    }

    /**
     * Crawler check at the event proxy — in cache-safe delivery mode bots
     * receive the same HTML as everyone, so they must be filtered here
     * instead of at assignment time.
     */
    private function is_bot_request(): bool
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

    /**
     * @param  mixed  $event
     * @return array<string, mixed>|null
     */
    private function sanitize_event($event): ?array
    {
        if (! is_array($event)) {
            return null;
        }

        $allowed_types = ['page_view', 'goal_page', 'click', 'scroll', 'time_on_page', 'element_view', 'video_play', 'external_event', 'form_submission'];

        $type = sanitize_key($event['type'] ?? '');

        if (! in_array($type, $allowed_types, true)) {
            return null;
        }

        $visitor_id = sanitize_text_field($event['visitor_id'] ?? '');

        if (! preg_match('/^eezy_[a-f0-9]{32}$/', $visitor_id)) {
            // Events can arrive without a visitor_id: vary-mode HTML carries
            // none, and a stale cached tracker won't fill it in. The events
            // request is same-origin, so recover the ID from the same
            // first-party cookie the tracker reads.
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- unslashed and sanitized inline; strictly pattern-validated below before use.
            $visitor_id = isset($_COOKIE['spliteezy_vid']) ? sanitize_text_field(wp_unslash($_COOKIE['spliteezy_vid'])) : '';

            if (! preg_match('/^eezy_[a-f0-9]{32}$/', $visitor_id)) {
                return null;
            }
        }

        // goal_id arrives inside meta; hoist it to the top level for the API.
        // Variant and goal IDs are UUIDs — sanitize as strings, never ints.
        $meta_raw = is_array($event['meta'] ?? null) ? $event['meta'] : [];
        $goal_id = isset($meta_raw['goal_id']) ? sanitize_text_field((string) $meta_raw['goal_id']) : null;
        unset($meta_raw['goal_id']);

        return [
            'type' => $type,
            'test_id' => sanitize_text_field($event['test_id'] ?? ''),
            'variant_id' => sanitize_text_field($event['variant_id'] ?? ''),
            'visitor_id' => $visitor_id,
            'url' => esc_url_raw($event['url'] ?? ''),
            'goal_id' => $goal_id ?: null,
            'meta' => array_map(
                static function ($val) {
                    return is_scalar($val) ? sanitize_text_field((string) $val) : wp_json_encode($val);
                },
                $meta_raw
            ),
            'occurred_at' => absint($event['occurred_at'] ?? time()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function get_goals(string $test_id): array
    {
        $manifest = Manifest::get();
        $tests = $manifest['tests'] ?? [];

        foreach ($tests as $test) {
            if (isset($test['id']) && (string) $test['id'] === $test_id) {
                return $test['goals'] ?? [];
            }
        }

        return [];
    }
}
