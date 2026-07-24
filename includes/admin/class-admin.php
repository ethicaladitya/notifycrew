<?php
/**
 * Admin orchestrator — registers all admin menus and sub-pages.
 *
 * @package Aditya\NotifyCrew
 */

namespace Aditya\NotifyCrew\Admin;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Aditya\NotifyCrew\Admin\Pages\Settings_Page;
use Aditya\NotifyCrew\Admin\Pages\Reminders_Page;
use Aditya\NotifyCrew\Admin\Pages\Teams_Page;

/**
 * Class Admin
 */
class Admin {

	/**
	 * Singleton instance.
	 *
	 * @var Admin|null
	 */
	private static $instance = null;

	/** @return Admin */
	public static function get_instance(): Admin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/**
	 * Wire up admin hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu_pages' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_slack_failure_notice' ) );

		// Register sub-page handlers.
		Settings_Page::get_instance()->register();
		Reminders_Page::get_instance()->register();
		Teams_Page::get_instance()->register();
	}

	/**
	 * Show an admin notice if Slack sends have been failing in the last 24 hours.
	 */
	public function maybe_show_slack_failure_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$recent_failure = $wpdb->get_row(
			"SELECT reminder_id, message, created_at
			 FROM {$wpdb->prefix}ncrw_logs
			 WHERE event IN ('retry_scheduled','completed')
			   AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
			 ORDER BY created_at DESC LIMIT 1"
		);

		if ( ! $recent_failure ) {
			return;
		}

		$settings_url = add_query_arg( 'page', 'ncrw-settings', admin_url( 'admin.php' ) );
		printf(
			'<div class="notice notice-error"><p><strong>%s</strong> %s &mdash; <em>%s</em> &mdash; <a href="%s">%s</a></p></div>',
			esc_html__( 'Reminder Manager:', 'notifycrew' ),
			esc_html__( 'Recent Slack delivery failures detected.', 'notifycrew' ),
			esc_html( $recent_failure->message ),
			esc_url( $settings_url ),
			esc_html__( 'Check Slack Settings', 'notifycrew' )
		);
	}

	/**
	 * Register WordPress admin menu structure.
	 */
	public function add_menu_pages(): void {
		add_menu_page(
			__( 'NotifyCrew', 'notifycrew' ),
			__( 'Reminders', 'notifycrew' ),
			'manage_options',
			'ncrw-reminders',
			array( Reminders_Page::get_instance(), 'render' ),
			'dashicons-bell',
			56
		);

		add_submenu_page(
			'ncrw-reminders',
			__( 'All Reminders', 'notifycrew' ),
			__( 'All Reminders', 'notifycrew' ),
			'manage_options',
			'ncrw-reminders',
			array( Reminders_Page::get_instance(), 'render' )
		);

		add_submenu_page(
			'ncrw-reminders',
			__( 'Add Reminder', 'notifycrew' ),
			__( 'Add Reminder', 'notifycrew' ),
			'manage_options',
			'ncrw-add-reminder',
			array( Reminders_Page::get_instance(), 'render_add' )
		);

		add_submenu_page(
			'ncrw-reminders',
			__( 'Teams', 'notifycrew' ),
			__( 'Teams', 'notifycrew' ),
			'manage_options',
			'ncrw-teams',
			array( Teams_Page::get_instance(), 'render' )
		);

		add_submenu_page(
			'ncrw-reminders',
			__( 'Settings', 'notifycrew' ),
			__( 'Settings', 'notifycrew' ),
			'manage_options',
			'ncrw-settings',
			array( Settings_Page::get_instance(), 'render' )
		);
	}

	/**
	 * Enqueue admin-only CSS and JS on plugin pages.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		$plugin_pages = array(
			'toplevel_page_ncrw-reminders',
			'reminders_page_ncrw-add-reminder',
			'reminders_page_ncrw-teams',
			'reminders_page_ncrw-settings',
		);

		if ( ! in_array( $hook_suffix, $plugin_pages, true ) ) {
			return;
		}

		wp_enqueue_style(
			'ncrw-admin',
			NCRW_URL . 'assets/css/admin.css',
			array(),
			NCRW_VERSION
		);

		wp_enqueue_script(
			'ncrw-admin',
			NCRW_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			NCRW_VERSION,
			true
		);

		wp_localize_script(
			'ncrw-admin',
			'ncrwAdmin',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'ncrw_admin_nonce' ),
				'restUrl'   => rest_url( 'ncrw/v1' ),
				'restNonce' => wp_create_nonce( 'wp_rest' ),
				'i18n'      => array(
					'confirmDelete' => __( 'Are you sure you want to delete this?', 'notifycrew' ),
					'confirmRetry'  => __( 'Queue this reminder for immediate retry?', 'notifycrew' ),
					'processing'    => __( 'Processing…', 'notifycrew' ),
					'success'       => __( 'Done!', 'notifycrew' ),
					'error'         => __( 'An error occurred. Please try again.', 'notifycrew' ),
				),
			)
		);
	}
}
