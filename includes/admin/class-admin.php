<?php
/**
 * Admin orchestrator — registers all admin menus and sub-pages.
 *
 * @package Aditya\ReminderTool
 */

namespace Aditya\ReminderTool\Admin;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Aditya\ReminderTool\Admin\Pages\Settings_Page;
use Aditya\ReminderTool\Admin\Pages\Reminders_Page;
use Aditya\ReminderTool\Admin\Pages\Teams_Page;

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

		// Register sub-page handlers.
		Settings_Page::get_instance()->register();
		Reminders_Page::get_instance()->register();
		Teams_Page::get_instance()->register();
	}

	/**
	 * Register WordPress admin menu structure.
	 */
	public function add_menu_pages(): void {
		add_menu_page(
			__( 'Team Reminder Tool', 'reminder-manager' ),
			__( 'Reminders', 'reminder-manager' ),
			'manage_options',
			'trt-reminders',
			array( Reminders_Page::get_instance(), 'render' ),
			'dashicons-bell',
			56
		);

		add_submenu_page(
			'trt-reminders',
			__( 'All Reminders', 'reminder-manager' ),
			__( 'All Reminders', 'reminder-manager' ),
			'manage_options',
			'trt-reminders',
			array( Reminders_Page::get_instance(), 'render' )
		);

		add_submenu_page(
			'trt-reminders',
			__( 'Add Reminder', 'reminder-manager' ),
			__( 'Add Reminder', 'reminder-manager' ),
			'manage_options',
			'trt-add-reminder',
			array( Reminders_Page::get_instance(), 'render_add' )
		);

		add_submenu_page(
			'trt-reminders',
			__( 'Teams', 'reminder-manager' ),
			__( 'Teams', 'reminder-manager' ),
			'manage_options',
			'trt-teams',
			array( Teams_Page::get_instance(), 'render' )
		);

		add_submenu_page(
			'trt-reminders',
			__( 'Settings', 'reminder-manager' ),
			__( 'Settings', 'reminder-manager' ),
			'manage_options',
			'trt-settings',
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
			'toplevel_page_trt-reminders',
			'reminders_page_trt-add-reminder',
			'reminders_page_trt-teams',
			'reminders_page_trt-settings',
		);

		if ( ! in_array( $hook_suffix, $plugin_pages, true ) ) {
			return;
		}

		wp_enqueue_style(
			'trt-admin',
			TRT_URL . 'assets/css/admin.css',
			array(),
			TRT_VERSION
		);

		wp_enqueue_script(
			'trt-admin',
			TRT_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			TRT_VERSION,
			true
		);

		wp_localize_script(
			'trt-admin',
			'trtAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'trt_admin_nonce' ),
				'restUrl' => rest_url( 'trt/v1' ),
				'restNonce' => wp_create_nonce( 'wp_rest' ),
				'i18n'    => array(
					'confirmDelete' => __( 'Are you sure you want to delete this?', 'reminder-manager' ),
					'confirmRetry'  => __( 'Queue this reminder for immediate retry?', 'reminder-manager' ),
					'processing'    => __( 'Processing…', 'reminder-manager' ),
					'success'       => __( 'Done!', 'reminder-manager' ),
					'error'         => __( 'An error occurred. Please try again.', 'reminder-manager' ),
				),
			)
		);
	}
}
