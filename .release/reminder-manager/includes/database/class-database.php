<?php
/**
 * Database management: table creation and schema upgrades via dbDelta.
 *
 * @package Aditya\ReminderTool
 */

namespace Aditya\ReminderTool\Database;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Database
 *
 * Manages table installations and upgrades.
 */
class Database {

	/**
	 * Singleton instance.
	 *
	 * @var Database|null
	 */
	private static $instance = null;

	/**
	 * Option key that stores the current DB version.
	 *
	 * @var string
	 */
	const DB_VERSION_OPTION = 'trt_db_version';

	/**
	 * Get singleton instance.
	 *
	 * @return Database
	 */
	public static function get_instance(): Database {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor.
	 */
	private function __construct() {}

	/**
	 * Run on plugin activation — always install latest schema.
	 */
	public function install(): void {
		$this->run_migrations();
		update_option( self::DB_VERSION_OPTION, TRT_DB_VERSION );
	}

	/**
	 * Run only if the stored DB version differs from current.
	 */
	public function maybe_upgrade(): void {
		$stored = get_option( self::DB_VERSION_OPTION, '0.0.0' );
		if ( version_compare( $stored, TRT_DB_VERSION, '<' ) ) {
			$this->install();
		}
	}

	/**
	 * Execute dbDelta for all plugin tables.
	 */
	private function run_migrations(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		// ------------------------------------------------------------------ //
		// teams table                                                          //
		// ------------------------------------------------------------------ //
		$sql_teams = "CREATE TABLE {$wpdb->prefix}trt_teams (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(190) NOT NULL DEFAULT '',
			slug VARCHAR(190) NOT NULL DEFAULT '',
			slack_channel VARCHAR(190) NOT NULL DEFAULT '',
			slack_mention_tag VARCHAR(50) NOT NULL DEFAULT '',
			quick_schedule_hours VARCHAR(190) NOT NULL DEFAULT '4,12,48',
			created_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY slug (slug)
		) $charset_collate;";

		// ------------------------------------------------------------------ //
		// team users table                                                     //
		// ------------------------------------------------------------------ //
		$sql_team_users = "CREATE TABLE {$wpdb->prefix}trt_team_users (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			team_id BIGINT(20) UNSIGNED NOT NULL,
			user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			email VARCHAR(190) NOT NULL DEFAULT '',
			role ENUM('admin','user') NOT NULL DEFAULT 'user',
			PRIMARY KEY (id),
			KEY team_user (team_id, user_id),
			UNIQUE KEY team_email (team_id, email),
			KEY team_id (team_id),
			KEY user_id (user_id),
			KEY email (email)
		) $charset_collate;";

		// ------------------------------------------------------------------ //
		// reminders table                                                      //
		// ------------------------------------------------------------------ //
		$sql_reminders = "CREATE TABLE {$wpdb->prefix}trt_reminders (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			team_id BIGINT(20) UNSIGNED NOT NULL,
			user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			title VARCHAR(255) NOT NULL DEFAULT '',
			remind_at DATETIME NOT NULL,
			task_link VARCHAR(2083) NOT NULL DEFAULT '',
			comments TEXT NOT NULL DEFAULT '',
			status ENUM('pending','sent','completed') NOT NULL DEFAULT 'pending',
			attempts INT(11) NOT NULL DEFAULT 0,
			next_attempt_at DATETIME DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY team_id (team_id),
			KEY status (status),
			KEY remind_at (remind_at),
			KEY next_attempt_at (next_attempt_at)
		) $charset_collate;";

		// ------------------------------------------------------------------ //
		// logs table                                                           //
		// ------------------------------------------------------------------ //
		$sql_logs = "CREATE TABLE {$wpdb->prefix}trt_logs (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			team_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			reminder_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			event VARCHAR(50) NOT NULL DEFAULT '',
			message TEXT NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY team_id (team_id),
			KEY reminder_id (reminder_id),
			KEY event (event)
		) $charset_collate;";

		dbDelta( $sql_teams );
		dbDelta( $sql_team_users );
		dbDelta( $sql_reminders );
		dbDelta( $sql_logs );
	}

	/**
	 * Drop all plugin tables. Called from uninstall.php.
	 */
	public static function drop_tables(): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- Table names are derived from $wpdb->prefix, safe.
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}trt_logs" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}trt_reminders" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}trt_team_users" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}trt_teams" );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

		delete_option( self::DB_VERSION_OPTION );
	}
}
