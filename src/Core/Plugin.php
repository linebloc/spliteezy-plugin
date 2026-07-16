<?php

namespace Spliteezy\Core;

use Spliteezy\Admin\AdminMenu;
use Spliteezy\Admin\ConnectHandler;
use Spliteezy\Admin\CreateTestPage;
use Spliteezy\Admin\SettingsPage;
use Spliteezy\Admin\TestDetailPage;
use Spliteezy\Admin\TestListPage;
use Spliteezy\Admin\VariantsPage;
use Spliteezy\Api\Manifest;
use Spliteezy\PostTypes\VariantPostType;
use Spliteezy\Tracking\Tracker;

defined('ABSPATH') || exit;

/**
 * Main plugin bootstrap. Initialises all subsystems on plugins_loaded.
 */
final class Plugin
{
    private static ?self $instance = null;

    private function __construct()
    {
        $this->init_subsystems();
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self;
        }

        return self::$instance;
    }

    private function init_subsystems(): void
    {
        // Plugin-shipped translations (languages/). Translations delivered by
        // wordpress.org load automatically and take precedence when present.
        add_action('init', static function (): void {
            load_plugin_textdomain('spliteezy', false, dirname(plugin_basename(SPLITEEZY_FILE)).'/languages');
        });

        // Custom post types (must run early for rewrite rules).
        (new VariantPostType)->register();

        // REST endpoint for instant manifest invalidation from the Spliteezy app.
        Manifest::register_flush_endpoint();

        // Page-cache purge hooks (variant edits must purge the control's URL).
        CacheCompat::register_purge_hooks();

        // Vary-mode cache registrations (assignment cookies + variant URL
        // parameter).
        CacheCompat::register_vary_hooks();

        // Admin UI.
        if (is_admin()) {
            (new AdminMenu)->register();
            (new SettingsPage)->register();
            (new ConnectHandler)->register();
            (new TestListPage)->register();
            (new TestDetailPage)->register();
            (new CreateTestPage)->register();
            (new VariantsPage)->register();
        }

        // Front-end assignment — must run before template_redirect.
        (new Assignor)->register();

        // WooCommerce price overrides for visitors assigned to a variant.
        (new WooCommerceCompat)->register();

        // Tracker script injection.
        (new Tracker)->register();
    }
}
