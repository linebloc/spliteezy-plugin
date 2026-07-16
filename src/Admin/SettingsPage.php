<?php

namespace Spliteezy\Admin;

use Spliteezy\Api\Client;
use Spliteezy\Api\Manifest;
use Spliteezy\Core\CacheCompat;
use Spliteezy\Core\Options;

defined('ABSPATH') || exit;

class SettingsPage
{
    public function register(): void
    {
        add_action('admin_post_spliteezy_save_settings', [$this, 'handle_save']);
        add_action('wp_ajax_spliteezy_test_connection', [$this, 'ajax_test_connection']);
        add_action('wp_ajax_spliteezy_cleanup_orphans', [$this, 'ajax_cleanup_orphans']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_settings_scripts']);
    }

    public function enqueue_settings_scripts(string $hook): void
    {
        if ($hook !== 'spliteezy_page_spliteezy-settings') {
            return;
        }

        wp_register_script('spliteezy-settings', false, [], SPLITEEZY_VERSION, true);
        wp_enqueue_script('spliteezy-settings');

        wp_localize_script('spliteezy-settings', 'spliteezySettingsCfg', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('spliteezy_admin'),
            'i18n' => [
                'testing' => __('Testing…', 'spliteezy'),
                'connected' => __('Connected', 'spliteezy'),
                'connFailed' => __('Connection failed', 'spliteezy'),
                'reqFailed' => __('Request failed — check browser console.', 'spliteezy'),
                'cleaning' => __('Cleaning up…', 'spliteezy'),
                'noOrphans' => __('No unused variants found.', 'spliteezy'),
                'varDeleted' => __('variant(s) deleted.', 'spliteezy'),
                'cleanFailed' => __('Cleanup failed.', 'spliteezy'),
                'netFailed' => __('Request failed.', 'spliteezy'),
                'confirmClean' => __('This will permanently delete all variant posts not linked to a test. Continue?', 'spliteezy'),
            ],
        ]);

        if (Options::is_configured()) {
            wp_add_inline_script('spliteezy-settings', $this->connection_test_script());
        }

        wp_add_inline_script('spliteezy-settings', $this->cleanup_orphans_script());
    }

    private function connection_test_script(): string
    {
        return <<<'JS'
        (function () {
            var cfg   = spliteezySettingsCfg;
            var btn   = document.getElementById('eezy-test-connection');
            var dot   = document.querySelector('.eezy-connection-status__dot');
            var label = document.getElementById('eezy-connection-text');

            if (!btn) return;

            btn.addEventListener('click', function () {
                btn.disabled = true;
                dot.className = 'eezy-connection-status__dot eezy-connection-status__dot--idle';
                label.textContent = cfg.i18n.testing;

                var body = new FormData();
                body.append('action', 'spliteezy_test_connection');
                body.append('nonce', cfg.nonce);

                fetch(cfg.ajaxUrl, {
                    method: 'POST',
                    body: body,
                    credentials: 'same-origin',
                })
                .then(function (r) { return r.json(); })
                .then(function (json) {
                    if (json.success) {
                        dot.className = 'eezy-connection-status__dot eezy-connection-status__dot--ok';
                        label.textContent = cfg.i18n.connected;
                    } else {
                        dot.className = 'eezy-connection-status__dot eezy-connection-status__dot--error';
                        label.textContent = json.data && json.data.message
                            ? json.data.message
                            : cfg.i18n.connFailed;
                    }
                })
                .catch(function () {
                    dot.className = 'eezy-connection-status__dot eezy-connection-status__dot--error';
                    label.textContent = cfg.i18n.reqFailed;
                })
                .finally(function () {
                    btn.disabled = false;
                });
            });

            btn.click();
        }());
        JS;
    }

    private function cleanup_orphans_script(): string
    {
        return <<<'JS'
        (function () {
            var cfg           = spliteezySettingsCfg;
            var cleanupBtn    = document.getElementById('eezy-cleanup-orphans');
            var cleanupResult = document.getElementById('eezy-cleanup-result');

            if (!cleanupBtn) return;

            cleanupBtn.addEventListener('click', function () {
                if (!confirm(cfg.i18n.confirmClean)) {
                    return;
                }
                cleanupBtn.disabled = true;
                cleanupResult.textContent = cfg.i18n.cleaning;
                cleanupResult.style.color = '';

                var body = new FormData();
                body.append('action', 'spliteezy_cleanup_orphans');
                body.append('nonce', cfg.nonce);

                fetch(cfg.ajaxUrl, {
                    method: 'POST',
                    body: body,
                    credentials: 'same-origin',
                })
                .then(function (r) { return r.json(); })
                .then(function (json) {
                    if (json.success) {
                        var n = json.data.deleted;
                        cleanupResult.textContent = n === 0
                            ? cfg.i18n.noOrphans
                            : n + ' ' + cfg.i18n.varDeleted;
                        cleanupResult.style.color = '#16a34a';
                    } else {
                        cleanupResult.textContent = (json.data && json.data.message) || cfg.i18n.cleanFailed;
                        cleanupResult.style.color = '#dc2626';
                    }
                })
                .catch(function () {
                    cleanupResult.textContent = cfg.i18n.netFailed;
                    cleanupResult.style.color = '#dc2626';
                })
                .finally(function () {
                    cleanupBtn.disabled = false;
                });
            });
        }());
        JS;
    }

    public function ajax_cleanup_orphans(): void
    {
        check_ajax_referer('spliteezy_admin', 'nonce');

        if (! current_user_can('manage_options')) {
            wp_send_json_error(null, 403);
        }

        $all_tests = (new Client)->get_tests();

        if ($all_tests === null) {
            wp_send_json_error(['message' => __('Could not reach the Spliteezy API. Restore the connection before cleaning up.', 'spliteezy')]);

            return;
        }

        // Build a lookup of every test ID currently known by the API.
        $known_ids = [];
        foreach ($all_tests['tests'] ?? [] as $test) {
            if (! empty($test['id'])) {
                $known_ids[(string) $test['id']] = true;
            }
        }

        // Use a direct DB query to bypass the pre_get_posts hook that hides variant
        // posts from WP_Query — combining NOT EXISTS + value = '1' returns nothing.
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- must bypass the pre_get_posts hook that hides variant posts from WP_Query; prepared query on this plugin's own meta key, admin-only.
        $variant_post_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s",
                '_spliteezy_variant',
                '1'
            )
        );

        $deleted = 0;
        foreach ($variant_post_ids as $raw_id) {
            $post_id = (int) $raw_id;
            $test_id = (string) get_post_meta($post_id, '_spliteezy_test_id', true);

            if (! $test_id || ! isset($known_ids[$test_id])) {
                wp_delete_post($post_id, true);
                $deleted++;
            }
        }

        wp_send_json_success(['deleted' => $deleted]);
    }

    public function ajax_test_connection(): void
    {
        check_ajax_referer('spliteezy_admin', 'nonce');

        if (! current_user_can('manage_options')) {
            wp_send_json_error(null, 403);
        }

        $result = (new Client)->test_connection();

        if ($result['ok']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    public function render(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'spliteezy'));
        }

        $api_endpoint = Options::api_endpoint();
        $enabled_types = Options::enabled_post_types();
        $dev_mode = Options::dev_mode();
        $permissions = Options::permissions();
        $post_types = $this->get_available_post_types();
        $roles = $this->get_available_roles();
        $is_configured = Options::is_configured();
        $cache_plugin = CacheCompat::detect();
        $delivery_mode = Options::delivery_mode();
        $effective_mode = CacheCompat::mode();
        $layered_host_cache = CacheCompat::layered_host_cache();
        $nitropack_sync = $this->nitropack_sync_state();

        include SPLITEEZY_DIR.'src/Admin/views/settings.php';
    }

    /**
     * NitroPack's locally-synced cache config, for the settings diagnostic:
     * which URL exclusions and variation cookies have actually reached this
     * site (Cloud API changes propagate with a delay). Null when NitroPack
     * is absent or its SDK is unavailable.
     *
     * @return array{excluded_urls: array<string>, vary_cookies: array<string>}|null
     */
    private function nitropack_sync_state(): ?array
    {
        if (! defined('NITROPACK_VERSION') || ! function_exists('get_nitropack_sdk')) {
            return null;
        }

        try {
            $nitro = get_nitropack_sdk();

            if (! $nitro) {
                return null;
            }

            $config = $nitro->getConfig();

            $excluded = ! empty($config->DisabledURLs->Status)
                ? array_map('strval', (array) ($config->DisabledURLs->URLs ?? []))
                : [];

            return [
                'excluded_urls' => $excluded,
                'vary_cookies' => array_map('strval', (array) ($config->PageCache->SupportedCookies ?? [])),
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function handle_save(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'spliteezy'));
        }

        check_admin_referer('spliteezy_settings_save');

        // phpcs:disable WordPress.Security.NonceVerification -- nonce verified via check_admin_referer('spliteezy_settings_save') directly above.
        $dev_mode = isset($_POST['dev_mode']);

        $delivery_mode = sanitize_key(wp_unslash($_POST['delivery_mode'] ?? 'server'));
        if (! in_array($delivery_mode, ['auto', 'server', 'client', 'vary'], true)) {
            $delivery_mode = 'server';
        }

        $enabled_types = isset($_POST['enabled_post_types']) && is_array($_POST['enabled_post_types'])
            ? array_map('sanitize_key', wp_unslash($_POST['enabled_post_types']))
            : [];

        $permissions = [
            'view_roles' => $this->sanitize_roles(
                isset($_POST['permissions']['view_roles']) && is_array($_POST['permissions']['view_roles'])
                    ? array_map('sanitize_key', wp_unslash($_POST['permissions']['view_roles']))
                    : []
            ),
            'create_roles' => $this->sanitize_roles(
                isset($_POST['permissions']['create_roles']) && is_array($_POST['permissions']['create_roles'])
                    ? array_map('sanitize_key', wp_unslash($_POST['permissions']['create_roles']))
                    : []
            ),
            'edit_roles' => $this->sanitize_roles(
                isset($_POST['permissions']['edit_roles']) && is_array($_POST['permissions']['edit_roles'])
                    ? array_map('sanitize_key', wp_unslash($_POST['permissions']['edit_roles']))
                    : []
            ),
        ];
        // phpcs:enable WordPress.Security.NonceVerification

        // The API key is managed exclusively by the connect/disconnect flow
        // (ConnectHandler) — a settings save must never touch it.
        $data = [
            'enabled_post_types' => $enabled_types,
            'dev_mode' => $dev_mode,
            'delivery_mode' => $delivery_mode,
            'permissions' => $permissions,
        ];

        // Only persist api_endpoint when the dev mode field is present in the form,
        // so a normal save never overwrites the default with an empty string.
        if (isset($_POST['api_endpoint'])) { // phpcs:ignore WordPress.Security.NonceVerification -- nonce verified via check_admin_referer('spliteezy_settings_save') at the top of this method.
            $submitted = esc_url_raw(wp_unslash($_POST['api_endpoint']));
            $data['api_endpoint'] = $submitted ?: Options::api_endpoint();
        }

        Options::update($data);

        // Sync WP role capabilities to match saved permissions.
        Options::sync_role_caps();

        // Flush manifest so next page load fetches fresh data.
        Manifest::flush();

        wp_safe_redirect(
            add_query_arg(
                [
                    'page' => 'spliteezy-settings',
                    'updated' => '1',
                ],
                admin_url('admin.php')
            )
        );
        exit;
    }

    /**
     * @return array<string, string>
     */
    private function get_available_post_types(): array
    {
        $types = get_post_types(['public' => true], 'objects');
        $result = [];

        foreach ($types as $type) {
            if ($type->name === 'attachment') {
                continue;
            }
            $result[$type->name] = $type->labels->singular_name;
        }

        return $result;
    }

    /**
     * @return array<string, string> slug => display name
     */
    private function get_available_roles(): array
    {
        $roles = wp_roles()->get_names();
        $result = [];

        foreach ($roles as $slug => $name) {
            $result[$slug] = translate_user_role($name);
        }

        return $result;
    }

    /**
     * @param  mixed  $raw
     * @return string[]
     */
    private function sanitize_roles($raw): array
    {
        if (! is_array($raw)) {
            return ['administrator'];
        }

        $sanitized = array_values(array_filter(array_map('sanitize_key', $raw)));

        // Administrator always retains access.
        if (! in_array('administrator', $sanitized, true)) {
            $sanitized[] = 'administrator';
        }

        return $sanitized;
    }
}
