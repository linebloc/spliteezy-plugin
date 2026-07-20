<?php

namespace Spliteezy\Core;

defined('ABSPATH') || exit;

class Activator
{
    public static function activate(): void
    {
        flush_rewrite_rules();

        // Set default options if not already present. api_endpoint stays
        // empty so Options::api_endpoint() resolves the default dynamically
        // instead of freezing it into the database at activation time.
        if (! get_option('spliteezy_settings')) {
            update_option(
                'spliteezy_settings',
                [
                    'api_key' => '',
                    'api_endpoint' => '',
                    'enabled_post_types' => ['post', 'page'],
                    'dev_mode' => false,
                    'permissions' => [
                        'view_roles' => ['administrator'],
                        'create_roles' => ['administrator'],
                        'edit_roles' => ['administrator'],
                    ],
                ]
            );
        }

        // Grant initial WP role capabilities.
        Options::sync_role_caps();
    }

    public static function deactivate(): void
    {
        flush_rewrite_rules();
    }

    public static function uninstall(): void
    {
        delete_option('spliteezy_settings');
        delete_option('spliteezy_rocket_config_pending');
        delete_transient('spliteezy_manifest');

        // Revoke all Spliteezy capabilities from all roles.
        foreach (wp_roles()->get_names() as $slug => $name) {
            $role = get_role($slug);
            if ($role) {
                $role->remove_cap('spliteezy_view');
                $role->remove_cap('spliteezy_create');
                $role->remove_cap('spliteezy_edit');
            }
        }
    }
}
