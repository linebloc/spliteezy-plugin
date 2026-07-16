<?php

namespace Spliteezy\Admin;

use Spliteezy\Api\Client;
use Spliteezy\Api\Manifest;
use Spliteezy\Core\Options;

defined('ABSPATH') || exit;

class TestListPage
{
    public function register(): void
    {
        add_action('wp_ajax_spliteezy_get_tests', [$this, 'ajax_get_tests']);
        add_action('wp_ajax_spliteezy_flush_manifest', [$this, 'ajax_flush_manifest']);
    }

    public function ajax_flush_manifest(): void
    {
        check_ajax_referer('spliteezy_admin', 'nonce');

        if (! Options::user_can('view')) {
            wp_send_json_error(null, 403);
        }

        Manifest::flush();
        wp_send_json_success();
    }

    public function render(): void
    {
        if (! Options::user_can('view')) {
            wp_die(esc_html__('Insufficient permissions.', 'spliteezy'));
        }

        $is_configured = Options::is_configured();

        include SPLITEEZY_DIR.'src/Admin/views/test-list.php';
    }

    public function ajax_get_tests(): void
    {
        check_ajax_referer('spliteezy_admin', 'nonce');

        if (! Options::user_can('view')) {
            wp_send_json_error(null, 403);
        }

        $response = $this->fetch_tests();

        if ($response === null) {
            wp_send_json_error(['message' => __('Unable to reach Spliteezy API. Check your API key in Settings.', 'spliteezy')], 502);

            return;
        }

        wp_send_json_success($response);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetch_tests(): ?array
    {
        $data = (new Client)->get_tests();

        if ($data === null || ! isset($data['tests'])) {
            return null;
        }

        return $data;
    }
}
