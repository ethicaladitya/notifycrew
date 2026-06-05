<?php
/**
 * Plugin Name:       Team Reminder Tool
 * Plugin URI:        https://github.com/aditya/reminder-manager
 * Description:       A production-grade team reminder tool with Slack integration, tag management, and retry logic.
 * Version:           1.0.2
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Aditya
 * Author URI:        https://profiles.wordpress.org/aditya/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       reminder-manager
 * Domain Path:       /languages
 *
 * @package Aditya\ReminderTool
 */

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin constants.
define( 'TRT_VERSION', '1.0.2' );
define( 'TRT_DB_VERSION', '2.4.0' );
define( 'TRT_FILE', __FILE__ );
define( 'TRT_DIR', plugin_dir_path( __FILE__ ) );
define( 'TRT_URL', plugin_dir_url( __FILE__ ) );
define( 'TRT_BASENAME', plugin_basename( __FILE__ ) );

// Autoloader.
require_once TRT_DIR . 'includes/class-autoloader.php';

// Activation / Deactivation hooks.
register_activation_hook( __FILE__, array( 'Aditya\\ReminderTool\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Aditya\\ReminderTool\\Plugin', 'deactivate' ) );

/**
 * Bootstrap the plugin after all plugins are loaded.
 */
add_action( 'plugins_loaded', function () {
	Aditya\ReminderTool\Plugin::get_instance();
} );
