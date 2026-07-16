<?php

namespace Spliteezy\Admin;

use Spliteezy\Api\Client;
use Spliteezy\Api\Manifest;
use Spliteezy\Core\Options;

defined('ABSPATH') || exit;

/**
 * "Connect to Spliteezy" handshake — links this website to a Spliteezy
 * account without manual API-key copy-paste.
 *
 * Flow: handle_start() redirects the admin to the Spliteezy authorize page
 * with this site's domain, a callback URL, and a CSRF state value. After the
 * user approves, Spliteezy redirects back to handle_callback() with a
 * short-lived one-time code, which is exchanged server-to-server for the API
 * key. The raw key never travels through the browser.
 */
class ConnectHandler
{
    private const STATE_TRANSIENT = 'spliteezy_connect_state';

    public function register(): void
    {
        add_action('admin_post_spliteezy_connect_start', [$this, 'handle_start']);
        add_action('admin_post_spliteezy_connect_callback', [$this, 'handle_callback']);
        add_action('admin_post_spliteezy_disconnect', [$this, 'handle_disconnect']);
    }

    /**
     * Disconnect this website: revoke the key server-side (best effort),
     * then clear it locally. Tests and data are kept on the Spliteezy side;
     * reconnecting mints a fresh key.
     */
    public function handle_disconnect(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'spliteezy'));
        }

        check_admin_referer('spliteezy_disconnect');

        (new Client)->disconnect();

        Options::update(['api_key' => '']);
        Manifest::flush();

        $this->redirect_settings(['disconnected' => '1']);
    }

    /**
     * Step 1: generate the CSRF state and send the admin to the Spliteezy
     * authorize screen.
     */
    public function handle_start(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'spliteezy'));
        }

        check_admin_referer('spliteezy_connect_start');

        $state = bin2hex(random_bytes(16));

        set_transient(
            self::STATE_TRANSIENT,
            [
                'state' => $state,
                'user' => get_current_user_id(),
            ],
            10 * MINUTE_IN_SECONDS
        );

        $url = add_query_arg(
            [
                'domain' => rawurlencode((string) wp_parse_url(home_url(), PHP_URL_HOST)),
                'callback' => rawurlencode(admin_url('admin-post.php?action=spliteezy_connect_callback')),
                'state' => $state,
                // Site Title, so the Spliteezy account registers a friendly
                // name instead of the bare domain.
                'name' => rawurlencode(sanitize_text_field(get_bloginfo('name'))),
            ],
            Options::app_root().'/connect/authorize'
        );

        $this->redirect_offsite($url);
    }

    /**
     * Step 2: Spliteezy redirects back here with a one-time code (or an
     * error). Verify state, exchange the code for the API key, store it.
     */
    public function handle_callback(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'spliteezy'));
        }

        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- CSRF is covered by the single-use state transient below.
        $state = isset($_GET['state']) ? sanitize_text_field(wp_unslash($_GET['state'])) : '';
        $code = isset($_GET['code']) ? sanitize_text_field(wp_unslash($_GET['code'])) : '';
        $error = isset($_GET['error']) ? sanitize_key(wp_unslash($_GET['error'])) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        $stored = get_transient(self::STATE_TRANSIENT);
        delete_transient(self::STATE_TRANSIENT); // Single use, whatever the outcome.

        $state_valid = is_array($stored)
            && ! empty($stored['state'])
            && is_string($stored['state'])
            && $state !== ''
            && hash_equals($stored['state'], $state)
            && (int) ($stored['user'] ?? 0) === get_current_user_id();

        if (! $state_valid) {
            $this->redirect_settings(['connect_error' => 'state_mismatch']);
        }

        if ($error !== '') {
            $allowed = ['denied', 'plan_limit'];
            $this->redirect_settings(['connect_error' => in_array($error, $allowed, true) ? $error : 'unknown']);
        }

        if ($code === '') {
            $this->redirect_settings(['connect_error' => 'unknown']);
        }

        $api_key = $this->exchange_code($code, $state);

        if (is_wp_error($api_key)) {
            $this->redirect_settings(['connect_error' => $api_key->get_error_code()]);
        }

        Options::update(['api_key' => $api_key]);
        Manifest::flush();

        $this->redirect_settings(['connected' => '1']);
    }

    /**
     * Exchange the one-time code for the raw API key, server-to-server.
     *
     * This request happens before the plugin has a key, so it cannot be
     * HMAC-signed — the single-use code (bound to domain + state) is the
     * credential.
     *
     * @return string|\WP_Error The raw API key, or an error whose code maps
     *                          to a connect_error notice.
     */
    private function exchange_code(string $code, string $state)
    {
        $url = Options::app_root().'/api/v1/connect/exchange';

        $response = wp_remote_post(
            $url,
            [
                'timeout' => 8,
                'sslverify' => Client::should_verify_ssl($url),
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'body' => wp_json_encode(
                    [
                        'code' => $code,
                        'domain' => wp_parse_url(home_url(), PHP_URL_HOST),
                        'state' => $state,
                    ]
                ),
            ]
        );

        if (is_wp_error($response)) {
            return new \WP_Error('network');
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);

        if ($status === 410) {
            return new \WP_Error('expired_code');
        }

        if ($status === 403) {
            return new \WP_Error('domain_mismatch');
        }

        if ($status !== 200 || empty($data['data']['api_key']) || ! is_string($data['data']['api_key'])) {
            return new \WP_Error('network');
        }

        return sanitize_text_field($data['data']['api_key']);
    }

    /**
     * Redirect to the Spliteezy app (an off-site host), allowing it for
     * wp_safe_redirect just for this request.
     */
    private function redirect_offsite(string $url): void
    {
        $app_host = (string) wp_parse_url(Options::app_root(), PHP_URL_HOST);

        add_filter(
            'allowed_redirect_hosts',
            static function (array $hosts) use ($app_host): array {
                $hosts[] = $app_host;

                return $hosts;
            }
        );

        wp_safe_redirect(esc_url_raw($url));
        exit;
    }

    /**
     * @param  array<string, string>  $args
     */
    private function redirect_settings(array $args): void
    {
        wp_safe_redirect(
            add_query_arg(
                array_merge(['page' => 'spliteezy-settings'], $args),
                admin_url('admin.php')
            )
        );
        exit;
    }
}
