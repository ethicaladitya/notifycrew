<?php
/**
 * Plugin Name:       NotifyCrew - Team reminders and site alerts for Slack
 * Description:       Team reminders and site alerts for Slack: scheduled reminders per team, plus messages when updates are due, fail, or a plugin crashes.
 * Version:           1.2.1
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
define( 'NCRW_VERSION', '1.2.1' );
define( 'NCRW_DB_VERSION', '2.5.0' );
define( 'NCRW_FILE', __FILE__ );
define( 'NCRW_DIR', plugin_dir_path( __FILE__ ) );
define( 'NCRW_URL', plugin_dir_url( __FILE__ ) );
define( 'NCRW_BASENAME', plugin_basename( __FILE__ ) );

// Autoloader.
require_once NCRW_DIR . 'includes/class-autoloader.php';

// Crash alerts are hooked now, not on plugins_loaded, so a plugin that breaks while WordPress is still loading
// plugins is reported too. They do nothing until "Crashes" is turned on under Site alerts.
Aditya\NotifyCrew\Services\Site_Alerts_Service::get_instance()->register_crash_hooks();

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
