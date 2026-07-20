<?php

namespace Spliteezy\Admin;

use Spliteezy\Api\Client;
use Spliteezy\Api\Manifest;
use Spliteezy\Core\Options;
use Spliteezy\Core\VariantCloner;

defined('ABSPATH') || exit;

/**
 * Handles AJAX actions for the test creation wizard in the React dashboard.
 */
class CreateTestPage
{
    public function register(): void
    {
        add_action('wp_ajax_spliteezy_search_posts', [$this, 'ajax_search_posts']);
        add_action('wp_ajax_spliteezy_create_test', [$this, 'ajax_create_test']);
    }

    /**
     * Search published posts/pages/CPTs for the post picker.
     */
    public function ajax_search_posts(): void
    {
        check_ajax_referer('spliteezy_admin', 'nonce');

        if (! Options::user_can('create')) {
            wp_send_json_error(__('Unauthorized.', 'spliteezy'), 403);
        }

        $search = sanitize_text_field(wp_unslash(isset($_POST['search']) ? $_POST['search'] : ''));
        $post_type_filter = sanitize_key(wp_unslash(isset($_POST['post_type_filter']) ? $_POST['post_type_filter'] : ''));

        $post_types = get_post_types(['public' => true, 'show_ui' => true], 'objects');
        unset($post_types['attachment']);

        if ($post_type_filter && isset($post_types[$post_type_filter])) {
            $allowed_types = [$post_type_filter];
        } else {
            $allowed_types = array_keys($post_types);
        }

        $args = [
            'post_type' => $allowed_types,
            'post_status' => 'publish',
            'posts_per_page' => 20,
            'orderby' => empty($search) ? 'modified' : 'relevance',
            'order' => 'DESC',
        ];

        if (! empty($search)) {
            $args['s'] = $search;
        }

        $query = new \WP_Query($args);
        $results = [];

        foreach ($query->posts as $post) {
            $type_obj = $post_types[$post->post_type] ?? null;
            $results[] = [
                'id' => $post->ID,
                'title' => $post->post_title,
                'type' => $post->post_type,
                'type_label' => $type_obj ? $type_obj->labels->singular_name : $post->post_type,
                'url' => get_permalink($post->ID),
            ];
        }

        wp_send_json_success(['posts' => $results]);
    }

    /**
     * Clone a post, register the test with the Laravel API, and return the edit URL.
     */
    public function ajax_create_test(): void
    {
        check_ajax_referer('spliteezy_admin', 'nonce');

        if (! Options::user_can('create')) {
            wp_send_json_error(__('Unauthorized.', 'spliteezy'), 403);
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON string; individual fields are sanitized after json_decode().
        $raw = wp_unslash(isset($_POST['data']) ? $_POST['data'] : '');
        $data = json_decode($raw, true);

        if (! is_array($data)) {
            wp_send_json_error(__('Invalid request data.', 'spliteezy'));
        }

        $post_id = (int) ($data['post_id'] ?? 0);
        $name = sanitize_text_field($data['name'] ?? '');
        $split = max(10, min(90, (int) ($data['split'] ?? 50)));
        $goal_type = sanitize_key($data['goal_type'] ?? 'scroll_depth');
        $goal_pct = (int) ($data['goal_percent'] ?? 50);
        $goal_url = esc_url_raw($data['goal_url'] ?? '');
        $goal_selector = sanitize_text_field($data['goal_selector'] ?? '');
        $goal_seconds = (int) ($data['goal_seconds'] ?? 30);
        $goal_event_name = sanitize_key($data['goal_event_name'] ?? '');
        $start_mode = sanitize_key($data['start_mode'] ?? 'draft');
        $scheduled_at = sanitize_text_field($data['scheduled_at'] ?? '');
        if ($scheduled_at && ! strtotime($scheduled_at)) {
            $scheduled_at = '';
        }

        if (! $post_id || ! $name) {
            wp_send_json_error(__('Missing required fields.', 'spliteezy'));
        }

        $valid_goal_types = ['page_view', 'page_reached', 'click', 'scroll_depth', 'time_on_page', 'element_view', 'video_play', 'external_event', 'form_submission'];
        if (! in_array($goal_type, $valid_goal_types, true)) {
            $goal_type = 'scroll_depth';
        }

        $goal_pct = max(1, min(100, $goal_pct));

        $goal_seconds = max(1, $goal_seconds);

        if (! in_array($start_mode, ['now', 'scheduled', 'draft'], true)) {
            $start_mode = 'draft';
        }

        $clone_id = VariantCloner::clone($post_id);
        if (is_wp_error($clone_id)) {
            wp_send_json_error($clone_id->get_error_message());
        }

        $original = get_post($post_id);
        $target_url = get_permalink($post_id);
        $post_type = $original ? $original->post_type : 'page';

        $payload = [
            'name' => $name,
            'type' => $post_type,
            'target_post_id' => $post_id,
            'target_url' => $target_url,
            'variant_post_id' => $clone_id,
            'split' => $split,
            'goal_type' => $goal_type,
            'goal_percent' => $goal_pct,
            'goal_url' => $goal_url ?: null,
            'goal_selector' => $goal_selector ?: null,
            'goal_seconds' => $goal_seconds,
            'goal_event_name' => $goal_event_name ?: null,
            'start_mode' => $start_mode,
            'scheduled_at' => $scheduled_at ?: null,
        ];

        $client = new Client;
        $test_id = $client->create_test($payload);

        if (! $test_id) {
            wp_delete_post($clone_id, true);

            $error = $client->last_error();
            wp_send_json_error([
                'message' => $client->last_error_message(
                    __('Could not register the test with Spliteezy. Check your connection in Settings.', 'spliteezy')
                ),
                'code' => $error['code'] ?? 'api_error',
            ]);
        }

        update_post_meta($clone_id, '_spliteezy_test_id', $test_id);
        // Always start as draft in WP so the editor is never blocked at creation time.
        // ajax_get_test() lazily syncs to the real API status when the dashboard opens.
        update_post_meta($clone_id, '_spliteezy_test_status', 'draft');

        Manifest::flush();

        wp_send_json_success([
            'test_id' => $test_id,
            'edit_url' => admin_url('post.php?post='.$clone_id.'&action=edit'),
        ]);
    }
}
