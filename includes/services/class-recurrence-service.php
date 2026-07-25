<?php
/**
 * Recurrence calculation service — next-occurrence dates and end-of-series checks.
 *
 * @package Aditya\NotifyCrew
 */

namespace Aditya\NotifyCrew\Services;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Recurrence_Service
 */
class Recurrence_Service {

	/** No recurrence. */
	const RECURRENCE_NONE = 'none';

	/** Daily recurrence. */
	const RECURRENCE_DAILY = 'daily';

	/** Weekly recurrence. */
	const RECURRENCE_WEEKLY = 'weekly';

	/** Monthly recurrence. */
	const RECURRENCE_MONTHLY = 'monthly';

	/** Custom (day-based) recurrence. */
	const RECURRENCE_CUSTOM = 'custom';

	/** Series never ends. */
	const END_NONE = 'none';

	/** Series ends after a fixed number of occurrences. */
	const END_OCCURRENCES = 'occurrences';

	/** Series ends on a specific date. */
	const END_DATE = 'date';

	/**
	 * Singleton instance.
	 *
	 * @var Recurrence_Service|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return Recurrence_Service
	 */
	public static function get_instance(): Recurrence_Service {
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
	 * Compute the next remind_at for a recurring reminder.
	 *
	 * @param \DateTime $current_remind_at Current occurrence's remind_at.
	 * @param string    $recurrence Recurrence type.
	 * @param int       $interval Recurrence interval.
	 * @return \DateTime|null
	 */
	public function get_next_remind_at( \DateTime $current_remind_at, string $recurrence, int $interval = 1 ): ?\DateTime {
		if ( self::RECURRENCE_NONE === $recurrence ) {
			return null;
		}

		$next = clone $current_remind_at;

		switch ( $recurrence ) {
			case self::RECURRENCE_DAILY:
				$next->modify( "+{$interval} days" );
				break;
			case self::RECURRENCE_WEEKLY:
				$next->modify( "+{$interval} weeks" );
				break;
			case self::RECURRENCE_MONTHLY:
				$next->modify( "+{$interval} months" );
				break;
			case self::RECURRENCE_CUSTOM:
				$next->modify( "+{$interval} days" );
				break;
			default:
				return null;
		}

		return $next;
	}

	/**
	 * Determine whether a recurring series should stop.
	 *
	 * @param int         $occurrence_count Occurrences created so far (including the one that just fired).
	 * @param string      $end_type End condition type.
	 * @param int|null    $max_occurrences Maximum occurrences allowed.
	 * @param string|null $end_date End date (datetime string).
	 * @return bool
	 */
	public function should_end( int $occurrence_count, string $end_type, ?int $max_occurrences, ?string $end_date ): bool {
		if ( self::END_NONE === $end_type ) {
			return false;
		}

		if ( self::END_OCCURRENCES === $end_type && null !== $max_occurrences ) {
			return $occurrence_count >= $max_occurrences;
		}

		if ( self::END_DATE === $end_type && null !== $end_date ) {
			$end = new \DateTime( $end_date . ' UTC' );
			$now = new \DateTime( 'now', new \DateTimeZone( 'UTC' ) );
			return $now > $end;
		}

		return false;
	}
}
