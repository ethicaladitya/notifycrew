<?php
/**
 * Uninstall cleanup — runs when the plugin is deleted from the WordPress admin.
 *
 * @package Aditya\ReminderTool
 */

// Only run from WordPress uninstall hook, never directly.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Bootstrap constants without loading the full plugin.
if ( ! defined( 'TRT_DIR' ) ) {
	define( 'TRT_DIR', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'TRT_VERSION' ) ) {
	define( 'TRT_VERSION', '1.0.0' );
}
if ( ! defined( 'TRT_DB_VERSION' ) ) {
	define( 'TRT_DB_VERSION', '2.3.0' );
}

require_once TRT_DIR . 'includes/class-autoloader.php';

use Aditya\ReminderTool\Database\Database;
use Aditya\ReminderTool\Services\Cron_Service;
use Aditya\ReminderTool\Services\Slack_Service;
use Aditya\ReminderTool\Admin\Pages\Settings_Page;

// Destructive cleanup is opt-in only.
$options               = get_option( Settings_Page::OPTION_NAME, array() );
$allow_uninstall_wipe  = ! empty( $options['allow_uninstall_cleanup'] );
if ( ! $allow_uninstall_wipe ) {
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
delete_option( 'trt_slack_channel' );
delete_option( Settings_Page::OPTION_NAME );

// Remove any leftover transients created by the admin forms.
global $wpdb;
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( '_transient_trt_form_' ) . '%'
	)
);
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( '_transient_timeout_trt_form_' ) . '%'
	)
);
