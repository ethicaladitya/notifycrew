<?php
/**
 * WP Cron service — schedules and processes reminders.
 *
 * @package Aditya\NotifyCrew
 */

namespace Aditya\NotifyCrew\Services;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Cron_Service
 */
class Cron_Service {

	/** Cron hook name. */
	const HOOK = 'ncrw_process_reminders';

	/** Cron schedule interval key. */
	const INTERVAL = 'ncrw_five_minutes';

	/** Option row used as the processing lock. */
	const LOCK_OPTION = 'ncrw_process_lock';

	/** A lock older than this belongs to a run that died and may be taken over. */
	const LOCK_SECONDS = 10 * MINUTE_IN_SECONDS;

	/** Reminders sent per run (each Slack call can take up to 15 seconds). */
	const BATCH_SIZE = 25;

	/** How long a claimed reminder is held before another run may retry it. */
	const CLAIM_SECONDS = 10 * MINUTE_IN_SECONDS;

	/**
	 * Singleton instance.
	 *
	 * @var Cron_Service|null
	 */
	private static $instance = null;

	/** @return Cron_Service */
	public static function get_instance(): Cron_Service {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/**
	 * Register hooks (called from Plugin::init_services).
	 */
	public function register(): void {
		add_filter( 'cron_schedules', array( $this, 'add_cron_interval' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- reminders are due to the minute; one 5-minute event runs a single indexed query for due rows.
		add_action( self::HOOK, array( $this, 'process' ) );
		add_action( self::HOOK . '_more', array( $this, 'process' ) );
		add_action( 'init', array( $this, 'ensure_scheduled' ), 20 );
	}

	/**
	 * Add custom 5-minute cron interval.
	 *
	 * @param array $schedules Existing cron schedules.
	 * @return array
	 */
	public function add_cron_interval( array $schedules ): array {
		if ( ! isset( $schedules[ self::INTERVAL ] ) ) {
			$schedules[ self::INTERVAL ] = array(
				'interval' => 5 * MINUTE_IN_SECONDS,
				'display'  => __( 'Every 5 Minutes', 'notifycrew' ),
			);
		}
		return $schedules;
	}

	/**
	 * Schedule the cron event on activation.
	 */
	public function schedule(): void {
		// Activation hooks can run before cron_schedules filters are attached in runtime.
		// Ensure our custom interval is available before scheduling.
		add_filter( 'cron_schedules', array( $this, 'add_cron_interval' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- reminders are due to the minute; one 5-minute event runs a single indexed query for due rows.

		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time(), self::INTERVAL, self::HOOK );
		}
	}

	/**
	 * Ensure cron event exists during normal runtime.
	 *
	 * This self-heals missing schedules in environments where activation
	 * did not schedule successfully or cron events were cleared.
	 */
	public function ensure_scheduled(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			$this->schedule();
		}
	}

	/**
	 * Unschedule the cron event on deactivation.
	 */
	public function unschedule(): void {
		$timestamp = wp_next_scheduled( self::HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK );
		}
		wp_clear_scheduled_hook( self::HOOK . '_more' );
	}

	/**
	 * Main cron callback — processes due and retryable reminders.
	 *
	 * Only one run sends at a time: WP-Cron, Retry Now and the REST /process route can overlap, and each Slack
	 * call can take up to 15 seconds. Each reminder is also claimed atomically before it is sent, so a run that
	 * outlives the lock still can't send the same reminder twice.
	 *
	 * @return bool False when another run holds the lock and nothing was processed.
	 */
	public function process(): bool {
		$token = $this->acquire_lock();
		if ( null === $token ) {
			return false;
		}

		try {
			$reminder_service = Reminder_Service::get_instance();
			$slack_service    = Slack_Service::get_instance();

			$batch = $reminder_service->get_due_for_processing( self::BATCH_SIZE );

			foreach ( $batch as $reminder ) {
				if ( ! $reminder_service->claim( $reminder, self::CLAIM_SECONDS ) ) {
					continue;
				}

				$result = $slack_service->send( $reminder );

				if ( true === $result ) {
					$reminder_service->mark_sent( $reminder->id );
				} else {
					$error_message = is_wp_error( $result ) ? $result->get_error_message() : __( 'Unknown error', 'notifycrew' );
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log( sprintf( '[NotifyCrew] Slack send failed for reminder #%d ("%s"): %s', $reminder->id, $reminder->title, $error_message ) );
					$reminder_service->mark_failed_attempt( $reminder->id, $error_message );
				}
			}

			// A full batch means more may be waiting: run again soon rather than in five minutes.
			if ( count( $batch ) >= self::BATCH_SIZE && ! wp_next_scheduled( self::HOOK . '_more' ) ) {
				wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::HOOK . '_more' );
			}
		} finally {
			$this->release_lock( $token );
		}

		return true;
	}

	/**
	 * Take the processing lock, or take over one left behind by a run that died.
	 *
	 * The lock is a row in the options table written with plain SQL: INSERT IGNORE either creates it or doesn't,
	 * atomically, which add_option() (INSERT ... ON DUPLICATE KEY UPDATE) can't promise.
	 *
	 * @return string|null The lock token to release with, or null when another run holds it.
	 */
	private function acquire_lock(): ?string {
		global $wpdb;

		$now   = time();
		$token = $now . '|' . wp_generate_password( 12, false );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- an atomic lock can't go through the cached options API.
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				self::LOCK_OPTION,
				$token
			)
		);
		if ( 1 === (int) $inserted ) {
			return $token;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read the live lock row, never a cached copy.
		$held  = (string) $wpdb->get_var(
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::LOCK_OPTION )
		);
		$since = (int) strtok( $held, '|' );
		if ( '' !== $held && $now - $since < self::LOCK_SECONDS ) {
			return null;
		}

		// Stale (or vanished between the two queries): take it over only if nobody else changed it first.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- compare-and-set on the lock row.
		$taken = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				$token,
				self::LOCK_OPTION,
				$held
			)
		);

		return 1 === (int) $taken ? $token : null;
	}

	/**
	 * Release the lock, but only if this run still owns it.
	 *
	 * @param string $token Token returned by acquire_lock().
	 */
	private function release_lock( string $token ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- delete only our own lock row.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
				self::LOCK_OPTION,
				$token
			)
		);
	}
}
