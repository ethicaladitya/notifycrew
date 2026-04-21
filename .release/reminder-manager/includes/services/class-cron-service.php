<?php
/**
 * WP Cron service — schedules and processes reminders.
 *
 * @package Aditya\ReminderTool
 */

namespace Aditya\ReminderTool\Services;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Cron_Service
 */
class Cron_Service {

	/** Cron hook name. */
	const HOOK = 'trt_process_reminders';

	/** Cron schedule interval key. */
	const INTERVAL = 'trt_five_minutes';

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
		add_filter( 'cron_schedules', array( $this, 'add_cron_interval' ) );
		add_action( self::HOOK, array( $this, 'process' ) );
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
				'display'  => __( 'Every 5 Minutes', 'reminder-manager' ),
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
		add_filter( 'cron_schedules', array( $this, 'add_cron_interval' ) );

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
	}

	/**
	 * Main cron callback — processes due and retryable reminders.
	 */
	public function process(): void {
		$reminder_service = Reminder_Service::get_instance();
		$slack_service    = Slack_Service::get_instance();

		$all = $reminder_service->get_due_for_processing();

		foreach ( $all as $reminder ) {
			$result = $slack_service->send( $reminder );

			if ( true === $result ) {
				$reminder_service->mark_sent( $reminder->id );
			} else {
				$error_message = is_wp_error( $result ) ? $result->get_error_message() : __( 'Unknown error', 'reminder-manager' );
				$reminder_service->mark_failed_attempt( $reminder->id, $error_message );
			}
		}
	}
}
