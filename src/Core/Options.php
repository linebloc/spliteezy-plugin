<?php

namespace Spliteezy\Core;

defined('ABSPATH') || exit;

/**
 * Typed accessors for plugin options. Single source of truth for stored settings.
 */
class Options
{
    private const OPTION_KEY = 'spliteezy_settings';

    /** Production API endpoint. */
    private const DEFAULT_API_ENDPOINT = 'https://dashboard.spliteezy.com/api/v1/plugin';

    /** Custom capabilities registered by the plugin. */
    public const CAPS = ['spliteezy_view', 'spliteezy_create', 'spliteezy_edit'];

    /** @var string|null Memoized default endpoint for this request. */
    private static ?string $default_endpoint = null;

    /**
     * The default API endpoint used when no endpoint has been saved.
     */
    public static function default_api_endpoint(): string
    {
        if (self::$default_endpoint !== null) {
            return self::$default_endpoint;
        }

        $endpoint = self::DEFAULT_API_ENDPOINT;


        self::$default_endpoint = $endpoint;

        return $endpoint;
    }

    private static function all(): array
    {
        $defaults = [
            'api_key' => '',
            'api_endpoint' => '',
            'enabled_post_types' => ['post', 'page'],
            'dev_mode' => false,
            'delivery_mode' => 'server',
            'permissions' => [
                'view_roles' => ['administrator'],
                'create_roles' => ['administrator'],
                'edit_roles' => ['administrator'],
            ],
            'update_notice' => [
                'recommended' => false,
                'latest_version' => '',
            ],
        ];

        $stored = get_option(self::OPTION_KEY, []);

        return wp_parse_args(is_array($stored) ? $stored : [], $defaults);
    }

    public static function api_key(): string
    {
        $stored = (string) self::all()['api_key'];

        if ($stored === '') {
            return '';
        }

        $plain = Crypto::decrypt($stored);

        if ($plain !== '' && Crypto::needs_migration($stored)) {
            self::update(['api_key' => $plain]);
        }

        return $plain;
    }

    public static function api_endpoint(): string
    {
        $stored = rtrim((string) self::all()['api_endpoint'], '/');

        return $stored !== '' ? $stored : self::default_api_endpoint();
    }

    /**
     * The Spliteezy app root URL (no /api/v1/plugin suffix) — used for
     * browser-facing URLs like the connect authorize screen.
     */
    public static function app_root(): string
    {
        return (string) preg_replace('#/api/v1/plugin/?$#', '', self::api_endpoint());
    }

    public static function enabled_post_types(): array
    {
        return (array) self::all()['enabled_post_types'];
    }

    public static function dev_mode(): bool
    {
        return (bool) self::all()['dev_mode'];
    }

    /**
     * Variant delivery mode: 'server' (backend swap, tested pages excluded
     * from page caching — the default), 'client' (cache-safe embedded
     * variants + JS assignment), or 'vary' (one cached copy per variant,
     * keyed by URL parameter, so it works with any page cache). 'auto' is a
     * legacy stored value that resolves to server in CacheCompat::mode().
     */
    public static function delivery_mode(): string
    {
        $mode = (string) self::all()['delivery_mode'];

        return in_array($mode, ['auto', 'server', 'client', 'vary'], true) ? $mode : 'server';
    }

    public static function is_configured(): bool
    {
        return self::api_key() !== '';
    }

    /**
     * @return array{view_roles: string[], create_roles: string[], edit_roles: string[]}
     */
    public static function permissions(): array
    {
        $stored = self::all()['permissions'] ?? [];
        $defaults = [
            'view_roles' => ['administrator'],
            'create_roles' => ['administrator'],
            'edit_roles' => ['administrator'],
        ];

        return wp_parse_args(is_array($stored) ? $stored : [], $defaults);
    }

    /**
     * The API's version-compatibility signal, recorded by Client on every
     * response — see Client::record_version_notice().
     *
     * @return array{recommended: bool, latest_version: string}
     */
    public static function update_notice(): array
    {
        $stored = self::all()['update_notice'] ?? [];
        $defaults = ['recommended' => false, 'latest_version' => ''];

        return wp_parse_args(is_array($stored) ? $stored : [], $defaults);
    }

    /**
     * Check if the current user has the given Spliteezy permission.
     * Falls back to manage_options when capabilities haven't been synced yet.
     *
     * @param  string  $action  'view' | 'create' | 'edit'
     */
    public static function user_can(string $action): bool
    {
        if (current_user_can('manage_options')) {
            return true;
        }

        return current_user_can('spliteezy_'.sanitize_key($action));
    }

    /**
     * Sync stored role permissions → WordPress role capabilities.
     * Call on plugin activation and whenever settings are saved.
     */
    public static function sync_role_caps(): void
    {
        $perms = self::permissions();

        $cap_map = [
            'spliteezy_view' => $perms['view_roles'],
            'spliteezy_create' => $perms['create_roles'],
            'spliteezy_edit' => $perms['edit_roles'],
        ];

        foreach (wp_roles()->role_objects as $slug => $role) {
            foreach ($cap_map as $cap => $allowed_roles) {
                if (in_array($slug, $allowed_roles, true)) {
                    $role->add_cap($cap);
                } else {
                    $role->remove_cap($cap);
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public static function update(array $values): bool
    {
        if (array_key_exists('api_key', $values) && is_string($values['api_key'])) {
            $values['api_key'] = Crypto::encrypt($values['api_key']);
        }

        $current = self::all();
        $merged = array_merge($current, $values);

        return (bool) update_option(self::OPTION_KEY, $merged);
    }
}
