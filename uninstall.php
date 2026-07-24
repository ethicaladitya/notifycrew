<?php
/**
 * Uninstall cleanup — runs when the plugin is deleted from the WordPress admin.
 *
 * @package Aditya\NotifyCrew
 */

// Prevent direct file access — must run within WordPress uninstall context.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Bootstrap constants without loading the full plugin.
if ( ! defined( 'NCRW_DIR' ) ) {
	define( 'NCRW_DIR', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'NCRW_VERSION' ) ) {
	define( 'NCRW_VERSION', '1.0.0' );
}
if ( ! defined( 'NCRW_DB_VERSION' ) ) {
	define( 'NCRW_DB_VERSION', '2.4.0' );
}

require_once NCRW_DIR . 'includes/class-autoloader.php';

use Aditya\NotifyCrew\Database\Database;
use Aditya\NotifyCrew\Services\Cron_Service;
use Aditya\NotifyCrew\Services\Slack_Service;
use Aditya\NotifyCrew\Admin\Pages\Settings_Page;

// Destructive cleanup is opt-in only.
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$notifycrew_options = get_option( Settings_Page::OPTION_NAME, array() );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$notifycrew_allow_wipe = ! empty( $notifycrew_options['allow_uninstall_cleanup'] );
if ( ! $notifycrew_allow_wipe ) {
	return;
}

// Unschedule cron events.
wp_clear_scheduled_hook( Cron_Service::HOOK );

// Drop all custom tables.
Database::drop_tables();

// Remove all plugin options.
delete_option( Database::DB_VERSION_OPTION );
delete_option( Slack_Service::MODE_OPTION );
delete_option( Slack_Service::WEBHOOK_OPTION );
delete_option( Slack_Service::BOT_TOKEN_OPTION );
delete_option( Slack_Service::USERNAME_OPTION );
delete_option( 'ncrw_slack_channel' );
delete_option( Settings_Page::OPTION_NAME );

// Remove any leftover transients created by the admin forms.
global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( '_transient_ncrw_form_' ) . '%'
	)
);
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( '_transient_timeout_ncrw_form_' ) . '%'
	)
);
