<?php

namespace Spliteezy\Admin;

use Spliteezy\Core\Options;

defined('ABSPATH') || exit;

class AdminMenu
{
    public function register(): void
    {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function add_menu(): void
    {
        add_menu_page(
            __('Spliteezy', 'spliteezy'),
            __('Spliteezy', 'spliteezy'),
            'spliteezy_view',
            'spliteezy',
            [new TestListPage, 'render'],
            'data:image/svg+xml;base64,'.base64_encode($this->logo_svg()),
            76
        );

        add_submenu_page(
            'spliteezy',
            __('A/B Tests', 'spliteezy'),
            __('A/B Tests', 'spliteezy'),
            'spliteezy_view',
            'spliteezy'
        );

        // Only show the variant posts debug page in dev mode.
        if (Options::dev_mode()) {
            add_submenu_page(
                'spliteezy',
                __('Variant Posts', 'spliteezy'),
                __('Variant Posts', 'spliteezy'),
                'spliteezy_view',
                'spliteezy-variants',
                [new VariantsPage, 'render']
            );
        }

        add_submenu_page(
            'spliteezy',
            __('Settings', 'spliteezy'),
            __('Settings', 'spliteezy'),
            'manage_options',
            'spliteezy-settings',
            [new SettingsPage, 'render']
        );
    }

    public function enqueue_assets(string $hook): void
    {
        if (strpos($hook, 'spliteezy') === false) {
            return;
        }

        wp_register_style(
            'spliteezy-fonts',
            SPLITEEZY_URL.'assets/css/fonts.css',
            [],
            SPLITEEZY_VERSION
        );

        wp_enqueue_style(
            'spliteezy-admin',
            SPLITEEZY_URL.'assets/css/admin.css',
            ['spliteezy-fonts'],
            SPLITEEZY_VERSION
        );

        if (strpos($hook, 'toplevel_page_spliteezy') !== false || strpos($hook, 'spliteezy-variants') !== false) {
            wp_enqueue_style(
                'spliteezy-dashboard',
                SPLITEEZY_URL.'assets/css/dashboard.css',
                ['spliteezy-fonts'],
                SPLITEEZY_VERSION
            );
        }
    }

    /**
     * The Spliteezy A/B-branch mark as a menu icon: two variant panels (A
     * faded, B solid) branching from a single visitor dot. Fill-only shapes —
     * wp-admin's svg-painter.js recolors fill attributes to match the admin
     * color scheme but ignores strokes, so the stroked logo paths from the
     * full logo cannot be used here.
     */
    private function logo_svg(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">'
            .'<rect x="1.6" y="2.2" width="7" height="5.8" rx="1.6" fill-opacity="0.5"/>'
            .'<rect x="11.4" y="2.2" width="7" height="5.8" rx="1.6"/>'
            .'<rect x="6.65" y="7.4" width="1.8" height="6.4" rx="0.9" transform="rotate(-50.8 7.55 10.6)"/>'
            .'<rect x="11.55" y="7.4" width="1.8" height="6.4" rx="0.9" transform="rotate(50.8 12.45 10.6)"/>'
            .'<rect x="9.1" y="11.6" width="1.8" height="4.2" rx="0.9"/>'
            .'<circle cx="10" cy="16" r="1.9"/>'
            .'</svg>';
    }
}
