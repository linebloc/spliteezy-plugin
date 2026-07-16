<?php

namespace Spliteezy\Admin;

use Spliteezy\Core\Options;

defined('ABSPATH') || exit;

class VariantsPage
{
    public function register(): void
    {
        // Read-only listing page — no AJAX actions needed.
    }

    public function render(): void
    {
        if (! Options::user_can('view')) {
            wp_die(esc_html__('Insufficient permissions.', 'spliteezy'));
        }

        $grouped = $this->fetch_variants();

        include SPLITEEZY_DIR.'src/Admin/views/variants.php';
    }

    /**
     * @return array<string, \WP_Post[]>
     */
    private function fetch_variants(): array
    {
        // Current approach: native post type + _spliteezy_variant meta flag.
        $current = get_posts([
            'post_type' => 'any',
            'post_status' => 'any',
            'posts_per_page' => -1,
            'meta_query' => [['key' => '_spliteezy_variant', 'value' => '1', 'compare' => '=']], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Dev-mode-only page; meta_query required to find variant posts bypassed by the pre_get_posts hook.
            'orderby' => 'date',
            'order' => 'DESC',
        ]);

        // Legacy: posts stored as the old spliteezy_variant CPT (DB query — no type registration needed).
        $legacy = get_posts([
            'post_type' => 'spliteezy_variant',
            'post_status' => 'any',
            'posts_per_page' => -1,
            'orderby' => 'date',
            'order' => 'DESC',
        ]);

        // Merge and deduplicate by ID, then group by post_type.
        $by_id = [];
        foreach (array_merge($current, $legacy) as $post) {
            $by_id[$post->ID] = $post;
        }

        $grouped = [];
        foreach ($by_id as $post) {
            $grouped[$post->post_type][] = $post;
        }

        return $grouped;
    }
}
