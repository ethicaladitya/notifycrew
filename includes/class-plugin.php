<?php
/**
 * Main plugin bootstrap class.
 *
 * @package Aditya\NotifyCrew
 */

namespace Aditya\NotifyCrew;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Aditya\NotifyCrew\Database\Database;
use Aditya\NotifyCrew\Services\Cron_Service;
use Aditya\NotifyCrew\Services\Frontend_Service;
use Aditya\NotifyCrew\Services\Rest_Service;
use Aditya\NotifyCrew\Services\Slack_Service;
use Aditya\NotifyCrew\Admin\Admin;

/**
 * Class Plugin
 *
 * Main plugin orchestrator.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return Plugin
	 */
	public static function get_instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor — wire up all hooks.
	 */
	private function __construct() {
		$this->load_textdomain();
		$this->init_services();
	}

	/**
	 * Load plugin text domain for i18n.
	 */
	private function load_textdomain(): void {
		// Translations are loaded automatically by WordPress since 4.6 when hosted on WordPress.org.
	}

	/**
	 * Initialise sub-services.
	 */
	private function init_services(): void {
		// Database migrations (idempotent).
		Database::get_instance()->maybe_upgrade();

		// Cleanup deprecated Slack relay settings from previous versions.
		Slack_Service::get_instance()->cleanup_legacy_relay_options();

		// Cron jobs.
		Cron_Service::get_instance()->register();

		// REST API.
		Rest_Service::get_instance()->register();

		// Frontend shortcode + Google-authenticated submissions.
		Frontend_Service::get_instance()->register();

		// Admin UI (admin-only).
		if ( is_admin() ) {
			Admin::get_instance()->register();
		}
	}

	/**
	 * Plugin activation callback.
	 */
	public static function activate(): void {
		// Create / upgrade tables.
		Database::get_instance()->install();

		// Schedule cron event.
		Cron_Service::get_instance()->schedule();

		// Flush rewrite rules.
		flush_rewrite_rules();
	}

	/**
	 * Plugin deactivation callback.
	 */
	public static function deactivate(): void {
		Cron_Service::get_instance()->unschedule();
		flush_rewrite_rules();
	}
}
