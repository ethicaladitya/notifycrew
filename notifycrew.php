<?php
/**
 * Plugin Name:       NotifyCrew - Team reminders for Slack
 * Description:       Schedule and deliver team reminders to Slack with automatic retry, Google-authenticated frontend portal, and team management.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Aditya
 * Author URI:        https://profiles.wordpress.org/ethicaladitya/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       notifycrew
 * Domain Path:       /languages
 *
 * @package Aditya\NotifyCrew
 */

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin constants.
define( 'NCRW_VERSION', '1.0.0' );
define( 'NCRW_DB_VERSION', '2.4.0' );
define( 'NCRW_FILE', __FILE__ );
define( 'NCRW_DIR', plugin_dir_path( __FILE__ ) );
define( 'NCRW_URL', plugin_dir_url( __FILE__ ) );
define( 'NCRW_BASENAME', plugin_basename( __FILE__ ) );

// Autoloader.
require_once NCRW_DIR . 'includes/class-autoloader.php';

// Activation / Deactivation hooks.
register_activation_hook( __FILE__, array( 'Aditya\NotifyCrew\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Aditya\NotifyCrew\Plugin', 'deactivate' ) );

/**
 * Bootstrap the plugin after all plugins are loaded.
 */
add_action(
	'plugins_loaded',
	function () {
		Aditya\NotifyCrew\Plugin::get_instance();
	}
);
