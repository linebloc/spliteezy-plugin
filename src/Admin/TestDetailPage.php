<?php

namespace Spliteezy\Admin;

use Spliteezy\Api\Client;
use Spliteezy\Api\Manifest;
use Spliteezy\Core\Options;
use Spliteezy\Core\VariantCloner;

defined('ABSPATH') || exit;

class TestDetailPage
{
    public function register(): void
    {
        add_action('wp_ajax_spliteezy_get_test', [$this, 'ajax_get_test']);
        add_action('wp_ajax_spliteezy_test_action', [$this, 'ajax_test_action']);
    }

    public function ajax_get_test(): void
    {
        check_ajax_referer('spliteezy_admin', 'nonce');

        if (! Options::user_can('view')) {
            wp_send_json_error(null, 403);
        }

        $test_id = isset($_POST['test_id']) ? sanitize_text_field(wp_unslash($_POST['test_id'])) : '';

        if (! $test_id) {
            wp_send_json_error(['message' => __('Missing test_id', 'spliteezy')], 400);

            return;
        }

        $test = (new Client)->get_test($test_id);

        if ($test === null) {
            wp_send_json_error(['message' => __('Unable to reach Spliteezy API. Check your connection in Settings.', 'spliteezy')], 502);

            return;
        }

        // Lazily sync WP post meta with the real API status.
        // This catches tests created with start_mode='now' (active in API, draft in WP)
        // and any other drift caused by external changes.
        $post_ids = $this->get_variant_post_ids($test_id);
        if (! empty($post_ids)) {
            $stored = (string) get_post_meta($post_ids[0], '_spliteezy_test_status', true);
            $api_status = $test['status'] ?? '';
            if ($api_status && $stored !== $api_status) {
                $this->sync_variant_post_status($test_id, $api_status);
            }
        }

        if (isset($test['variants'])) {
            foreach ($test['variants'] as &$variant) {
                $pid = isset($variant['post_id']) ? (int) $variant['post_id'] : 0;

                if ($pid && ! ($variant['is_control'] ?? false)) {
                    $wp_post = get_post($pid);
                    $variant['needs_edit'] = $wp_post instanceof \WP_Post &&
                        $wp_post->post_date === $wp_post->post_modified;
                } else {
                    $variant['needs_edit'] = false;
                }
            }

            unset($variant);
        }

        wp_send_json_success($test);
    }

    public function ajax_test_action(): void
    {
        check_ajax_referer('spliteezy_admin', 'nonce');

        $test_id = sanitize_text_field(wp_unslash($_POST['test_id'] ?? ''));
        $action = sanitize_key(wp_unslash($_POST['test_action'] ?? ''));

        $write_actions = ['delete', 'apply', 'update', 'clone', 'start', 'resume', 'pause', 'finish', 'stop'];
        $required_cap = in_array($action, $write_actions, true) ? 'edit' : 'view';

        if (! Options::user_can($required_cap)) {
            wp_send_json_error(__('Unauthorized.', 'spliteezy'), 403);
        }

        if (! $test_id || ! $action) {
            wp_send_json_error(__('Missing required fields.', 'spliteezy'));
        }

        $client = new Client;

        if ($action === 'delete') {
            $this->delete_variant_posts($test_id);
            $ok = $client->delete_test($test_id);

            if ($ok) {
                Manifest::flush();
                wp_send_json_success(['action' => 'deleted']);
            }

            wp_send_json_error(__('Failed to delete test.', 'spliteezy'));
        }

        if ($action === 'apply') {
            $variant_post_id = (int) sanitize_text_field(wp_unslash($_POST['variant_post_id'] ?? '0'));
            $this->handle_apply($test_id, $variant_post_id);
        }

        if ($action === 'update') {
            // phpcs:disable WordPress.Security.NonceVerification -- nonce verified via check_ajax_referer('spliteezy_admin') at the entry of this handler.
            $payload = [];

            if (isset($_POST['name'])) {
                $payload['name'] = sanitize_text_field(wp_unslash($_POST['name']));
            }

            if (isset($_POST['split'])) {
                $payload['split'] = max(10, min(90, absint(wp_unslash($_POST['split']))));
            }
            // phpcs:enable WordPress.Security.NonceVerification

            if (empty($payload)) {
                wp_send_json_error(__('No fields to update.', 'spliteezy'));
            }

            $ok = $client->update_test($test_id, $payload);

            if ($ok) {
                Manifest::flush();
                wp_send_json_success(['action' => 'update']);
            }

            wp_send_json_error(__('Update failed.', 'spliteezy'));
        }

        if ($action === 'clone') {
            $this->handle_clone($test_id, $client);
        }

        $status_map = [
            'start' => 'active',
            'resume' => 'active',
            'pause' => 'paused',
            'finish' => 'ended',
            'stop' => 'ended',
        ];

        if (! isset($status_map[$action])) {
            wp_send_json_error(__('Unknown action.', 'spliteezy'));
        }

        $new_status = $status_map[$action];
        $ok = $client->update_test_status($test_id, $new_status);

        if ($ok) {
            $this->sync_variant_post_status($test_id, $new_status);
            Manifest::flush();
            wp_send_json_success(['action' => $action]);
        } else {
            $error = $client->last_error();
            wp_send_json_error([
                'message' => $client->last_error_message(
                    __('Action failed. The transition may not be allowed for this test\'s current status.', 'spliteezy')
                ),
                'code' => $error['code'] ?? 'api_error',
            ]);
        }
    }

    private function handle_clone(string $test_id, Client $client): void
    {
        $test = $client->get_test($test_id);

        if (! $test) {
            wp_send_json_error(__('Test not found.', 'spliteezy'));
        }

        $target_post_id = (int) ($test['target_post_id'] ?? 0);

        if (! $target_post_id) {
            wp_send_json_error(__('Original post not found.', 'spliteezy'));
        }

        $clone_id = VariantCloner::clone($target_post_id);

        if (is_wp_error($clone_id)) {
            wp_send_json_error($clone_id->get_error_message());
        }

        $variants = $test['variants'] ?? [];
        $variant_weight = 50;
        foreach ($variants as $v) {
            if (! ($v['is_control'] ?? true)) {
                $variant_weight = (int) ($v['weight'] ?? 50);
                break;
            }
        }

        $goals = $test['goals'] ?? [];
        $primary = null;
        foreach ($goals as $g) {
            if ($g['is_primary'] ?? false) {
                $primary = $g;
                break;
            }
        }
        if (! $primary && ! empty($goals)) {
            $primary = $goals[0];
        }

        $payload = [
            /* translators: %s: name of the test being duplicated. */
            'name' => sprintf(__('Copy of %s', 'spliteezy'), $test['name'] ?? __('Test', 'spliteezy')),
            'type' => $test['type'] ?? 'page',
            'target_post_id' => $target_post_id,
            'target_url' => $test['target_url'] ?? '',
            'variant_post_id' => $clone_id,
            'split' => $variant_weight,
            'goal_type' => $primary['type'] ?? 'scroll_depth',
            'goal_percent' => $primary['percent'] ?? 50,
            'goal_url' => $primary['url'] ?? null,
            'goal_selector' => $primary['selector'] ?? null,
            'goal_seconds' => $primary['seconds'] ?? null,
            'goal_event_name' => $primary['event_name'] ?? null,
            'start_mode' => 'draft',
            'scheduled_at' => null,
        ];

        $new_test_id = $client->create_test($payload);

        if (! $new_test_id) {
            wp_delete_post($clone_id, true);
            wp_send_json_error(__('Failed to create clone.', 'spliteezy'));
        }

        update_post_meta($clone_id, '_spliteezy_test_id', $new_test_id);
        update_post_meta($clone_id, '_spliteezy_test_status', 'draft');
        Manifest::flush();

        wp_send_json_success(['action' => 'clone', 'test_id' => $new_test_id]);
    }

    /**
     * Keep _spliteezy_test_status and WP post_status in sync for all variant posts.
     * Active tests publish the variant; every other status keeps it draft so it
     * cannot be accessed directly on the frontend.
     */
    private function sync_variant_post_status(string $test_id, string $status): void
    {
        $wp_status = $status === 'active' ? 'publish' : 'draft';

        foreach ($this->get_variant_post_ids($test_id) as $post_id) {
            update_post_meta($post_id, '_spliteezy_test_status', $status);
            wp_update_post(['ID' => $post_id, 'post_status' => $wp_status]);
        }
    }

    /**
     * Hard-delete all WordPress variant posts that belong to a test.
     * Called before removing the test from the API so the DB is left clean.
     */
    private function delete_variant_posts(string $test_id): void
    {
        foreach ($this->get_variant_post_ids($test_id) as $post_id) {
            wp_delete_post($post_id, true);
        }
    }

    /**
     * Returns IDs of all WP posts linked to a test via _spliteezy_test_id.
     * Uses a direct DB query to bypass the pre_get_posts hook that appends
     * _spliteezy_variant NOT EXISTS and would exclude every variant post.
     *
     * @return int[]
     */
    private function get_variant_post_ids(string $test_id): array
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Must bypass pre_get_posts which appends a NOT EXISTS clause that excludes all variant posts from WP_Query.
        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s",
                '_spliteezy_test_id',
                $test_id
            )
        );

        return array_map('intval', $ids);
    }

    /**
     * Copy a variant's content and meta onto the original post, then trash the variant.
     * This is the "apply winner" operation — irreversible, confirm on the front end.
     */
    private function handle_apply(string $test_id, int $variant_post_id): void
    {
        if (! $variant_post_id) {
            wp_send_json_error(__('Missing variant post ID.', 'spliteezy'));
        }

        $variant = get_post($variant_post_id);

        if (! $variant || get_post_meta($variant_post_id, '_spliteezy_test_id', true) !== $test_id) {
            wp_send_json_error(__('Variant not found or does not belong to this test.', 'spliteezy'));
        }

        // Prefer the explicit meta; fall back to post_parent for older variants.
        $original_id = (int) get_post_meta($variant_post_id, '_spliteezy_control_post_id', true);
        if (! $original_id) {
            $original_id = (int) $variant->post_parent;
        }

        if (! $original_id || ! get_post($original_id)) {
            wp_send_json_error(__('Original post not found.', 'spliteezy'));
        }

        wp_update_post([
            'ID' => $original_id,
            'post_content' => $variant->post_content,
            'post_excerpt' => $variant->post_excerpt,
        ]);

        // Copy all non-internal meta from the variant to the original, overwriting
        // any existing values so SEO fields (Yoast, RankMath, AIOSEO, etc.) are applied.
        $meta = get_post_meta($variant_post_id);
        foreach ($meta as $key => $values) {
            if (
                strpos($key, '_edit_') === 0 ||
                strpos($key, '_wp_') === 0 ||
                strpos($key, '_spliteezy_') === 0
            ) {
                continue;
            }

            delete_post_meta($original_id, $key);

            foreach ($values as $value) {
                add_post_meta($original_id, $key, $value);
            }
        }

        wp_trash_post($variant_post_id);
        Manifest::flush();

        wp_send_json_success(['action' => 'applied', 'original_id' => $original_id]);
    }
}
