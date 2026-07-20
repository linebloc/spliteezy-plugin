<?php

use Spliteezy\Core\Autoloader;

/**
 * Plugin Name:       Spliteezy - A/B Split Tests Made Easy
 * Plugin URI:        https://spliteezy.com
 * Description:       Backend A/B testing for WordPress. Server-side variant assignment — no redirect, no flash of wrong content.
 * Version:           0.10.2
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Author:            Linebloc
 * Author URI:        https://linebloc.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       spliteezy
 * Domain Path:       /languages
 */
defined('ABSPATH') || exit;

define('SPLITEEZY_VERSION', '0.10.2');
define('SPLITEEZY_FILE', __FILE__);
define('SPLITEEZY_DIR', plugin_dir_path(__FILE__));
define('SPLITEEZY_URL', plugin_dir_url(__FILE__));
define('SPLITEEZY_SLUG', 'spliteezy');

require_once SPLITEEZY_DIR.'src/Core/Autoloader.php';

Autoloader::register();

register_activation_hook(__FILE__, ['Spliteezy\Core\Activator', 'activate']);
register_deactivation_hook(__FILE__, ['Spliteezy\Core\Activator', 'deactivate']);
register_uninstall_hook(__FILE__, ['Spliteezy\Core\Activator', 'uninstall']);

add_action('plugins_loaded', ['Spliteezy\Core\Plugin', 'instance']);
