<?php

namespace Spliteezy\PostTypes;

defined('ABSPATH') || exit;

/**
 * Controls how A/B variant posts behave in WordPress.
 *
 * Variants are regular posts/pages marked with the `_spliteezy_variant` meta flag.
 * No custom post type is registered — variants inherit the original post type so
 * they get the correct theme template, comments support, and all post-type features.
 */
class VariantPostType
{
    public function register(): void
    {
        add_action('pre_get_posts', [$this, 'exclude_variants_from_queries']);
        add_action('template_redirect', [$this, 'redirect_variant_requests'], 0);
        add_filter('wp_robots', [$this, 'noindex_variants']);
        add_filter('block_editor_settings_all', [$this, 'set_editor_back_link'], 10, 2);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_variant_editor_modal']);
        add_action('current_screen', [$this, 'redirect_if_active']);
    }

    /**
     * Exclude variant posts from every WP_Query that isn't a singular view.
     */
    public function exclude_variants_from_queries(\WP_Query $query): void
    {
        if ($query->is_singular()) {
            return;
        }

        // Allow the Variants admin page to query variant posts directly.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only check of which admin screen is rendering; no state change.
        if (is_admin() && sanitize_key($_GET['page'] ?? '') === 'spliteezy-variants') {
            return;
        }

        $meta_query = $query->get('meta_query') ?: [];
        $meta_query[] = [
            'key' => '_spliteezy_variant',
            'compare' => 'NOT EXISTS',
        ];
        $query->set('meta_query', $meta_query);
    }

    /**
     * Send a direct hit on a variant's own URL to the page it is testing.
     *
     * A variant is an ordinary post carrying a flag, and an active test's
     * variant has to be published, so WordPress gives it a working permalink.
     * exclude_variants_from_queries() lets singular views through, which leaves
     * that permalink serving a second public copy of the page: duplicate
     * content for a search engine to choose between, and the variant readable
     * by anyone who guesses the URL.
     *
     * 301 rather than 404 because the variant is never meant to be a
     * destination — applying one copies its content onto the original, so the
     * URL has no future in which it should resolve. Anyone who can edit it
     * passes through, so previewing your own draft still works.
     */
    public function redirect_variant_requests(): void
    {
        if (! is_singular()) {
            return;
        }

        $post_id = get_queried_object_id();

        if (! $post_id || ! get_post_meta($post_id, '_spliteezy_variant', true)) {
            return;
        }

        if (current_user_can('edit_post', $post_id)) {
            return;
        }

        $control_id = (int) get_post_meta($post_id, '_spliteezy_control_post_id', true);
        // get_permalink() returns false for a control that has since been
        // deleted, which would otherwise redirect to nowhere.
        $target = $control_id ? get_permalink($control_id) : false;

        wp_safe_redirect($target ?: home_url('/'), 301);
        exit;
    }

    /**
     * Belt and braces for the one view the redirect lets through: an editor
     * previewing a variant. Costs nothing and keeps a stray crawl harmless.
     *
     * @param  array<string, mixed>  $robots
     * @return array<string, mixed>
     */
    public function noindex_variants(array $robots): array
    {
        if (! is_singular()) {
            return $robots;
        }

        $post_id = get_queried_object_id();

        if ($post_id && get_post_meta($post_id, '_spliteezy_variant', true)) {
            $robots['noindex'] = true;
            $robots['nofollow'] = true;
        }

        return $robots;
    }

    /**
     * `$context` is deliberately untyped: it is a WP_Block_Editor_Context, but
     * the `mixed` declaration this used to carry is PHP 8.0+, and on the 7.4
     * the plugin still supports PHP reads it as a class name — so WordPress
     * passing the real context threw a TypeError and took the block editor
     * down with it. The `?->`-style guard below never needed the hint.
     *
     * @param  array<string, mixed>  $settings
     * @param  object|null  $context
     */
    public function set_editor_back_link(array $settings, $context): array
    {
        $post = $context->post ?? null;

        if (! $post instanceof \WP_Post) {
            return $settings;
        }

        if (! get_post_meta($post->ID, '_spliteezy_variant', true)) {
            return $settings;
        }

        $test_id = (string) get_post_meta($post->ID, '_spliteezy_test_id', true);
        $settings['dashboardLink'] = $this->back_url($test_id);

        return $settings;
    }

    /**
     * Enqueue the variant editor script which registers two Gutenberg plugins:
     *   1. A blocking modal on load ("You're editing a variant").
     *   2. A custom post-publish panel replacing the default "View Post" buttons.
     */
    public function enqueue_variant_editor_modal(): void
    {
        $screen = get_current_screen();

        if (! $screen || $screen->base !== 'post') {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only post ID from the edit-screen URL, cast to int; no state change.
        $post_id = isset($_GET['post']) ? (int) $_GET['post'] : 0;

        if (! $post_id || ! get_post_meta($post_id, '_spliteezy_variant', true)) {
            return;
        }

        // Active variants are redirected away in redirect_if_active(); no modal needed.
        if (get_post_meta($post_id, '_spliteezy_test_status', true) === 'active') {
            return;
        }

        $test_id = (string) get_post_meta($post_id, '_spliteezy_test_id', true);

        wp_enqueue_script(
            'spliteezy-variant-editor',
            SPLITEEZY_URL.'assets/js/variant-editor.js',
            ['wp-plugins', 'wp-components', 'wp-element', 'wp-i18n'],
            SPLITEEZY_VERSION,
            true
        );

        wp_set_script_translations('spliteezy-variant-editor', 'spliteezy', SPLITEEZY_DIR.'languages');

        wp_localize_script('spliteezy-variant-editor', 'spliteezyVariantCfg', [
            'backUrl' => $this->back_url($test_id),
            'dashboardUrl' => admin_url('admin.php?page=spliteezy'),
        ]);

        wp_add_inline_script('spliteezy-variant-editor', $this->back_link_patch_script());
    }

    private function back_link_patch_script(): string
    {
        return <<<'JS'
        (function () {
            function patch() {
                document.querySelectorAll('a.components-button').forEach(function (el) {
                    var href = el.getAttribute('href') || '';
                    if (href.indexOf('edit.php') !== -1) {
                        el.setAttribute('href', spliteezyVariantCfg.backUrl);
                    }
                });
            }

            document.addEventListener('DOMContentLoaded', function () {
                [0, 300, 1000, 2500].forEach(function (ms) {
                    setTimeout(patch, ms);
                });
            });
        }());
        JS;
    }

    /**
     * Redirect away from the block editor if someone tries to edit a variant
     * whose test is currently active. Editing live variant content would skew results.
     */
    public function redirect_if_active(): void
    {
        $screen = get_current_screen();

        if (! $screen || $screen->base !== 'post') {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only post ID from the edit-screen URL, cast to int; no state change.
        $post_id = isset($_GET['post']) ? (int) $_GET['post'] : 0;

        if (! $post_id || ! get_post_meta($post_id, '_spliteezy_variant', true)) {
            return;
        }

        if (get_post_meta($post_id, '_spliteezy_test_status', true) !== 'active') {
            return;
        }

        $test_id = (string) get_post_meta($post_id, '_spliteezy_test_id', true);
        wp_safe_redirect($this->back_url($test_id));
        exit;
    }

    /**
     * Build the Spliteezy back URL, linking to the specific test if we know the ID.
     */
    private function back_url(string $test_id): string
    {
        $base = admin_url('admin.php?page=spliteezy');

        return $test_id ? $base.'&test='.urlencode($test_id) : $base;
    }
}
