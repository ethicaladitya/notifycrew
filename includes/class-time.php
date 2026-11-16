<?php
/**
 * Date and time helpers.
 *
 * Reminder times are stored in UTC. People type and read them in the site's
 * timezone (Settings > General), so every form input and every display goes
 * through this class.
 *
 * @package Aditya\NotifyCrew
 */

namespace Aditya\NotifyCrew;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Converts between stored UTC times and the site timezone.
 */
final class Time {

	/**
	 * MySQL datetime format used for storage.
	 */
	const DB_FORMAT = 'Y-m-d H:i:s';

	/**
	 * Value format of an <input type="datetime-local">.
	 */
	const INPUT_FORMAT = 'Y-m-d\TH:i';

	/**
	 * Convert a form value typed in the site timezone into a UTC storage string.
	 *
	 * Accepts a datetime-local value (2026-11-16T10:00), the same with seconds or
	 * a space, or an ISO 8601 string with an explicit offset (2026-11-16T10:00:00+05:30),
	 * in which case the offset wins over the site timezone.
	 *
	 * @param string $value Raw input.
	 * @return string UTC datetime (Y-m-d H:i:s), or '' when the value is not a date.
	 */
	public static function local_input_to_utc( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}

		$site_tz = wp_timezone();
		$date    = null;

		if ( preg_match( '/(Z|[+-]\d{2}:?\d{2})$/i', $value ) ) {
			try {
				$date = new \DateTimeImmutable( $value );
			} catch ( \Exception $e ) {
				$date = null;
			}
		} else {
			foreach ( array( self::INPUT_FORMAT, 'Y-m-d\TH:i:s', 'Y-m-d H:i', self::DB_FORMAT ) as $format ) {
				$parsed = \DateTimeImmutable::createFromFormat( '!' . $format, $value, $site_tz );
				$errors = \DateTimeImmutable::getLastErrors();
				if ( $parsed instanceof \DateTimeImmutable && ( false === $errors || ( 0 === $errors['warning_count'] && 0 === $errors['error_count'] ) ) ) {
					$date = $parsed;
					break;
				}
			}
		}

		if ( ! $date instanceof \DateTimeImmutable ) {
			return '';
		}

		return $date->setTimezone( new \DateTimeZone( 'UTC' ) )->format( self::DB_FORMAT );
	}

	/**
	 * Turn a stored UTC datetime into a datetime-local value in the site timezone.
	 *
	 * @param string|null $utc Stored UTC datetime.
	 * @return string Input value, or '' when empty/invalid.
	 */
	public static function utc_to_local_input( ?string $utc ): string {
		$timestamp = self::utc_to_timestamp( $utc );
		return null === $timestamp ? '' : wp_date( self::INPUT_FORMAT, $timestamp );
	}

	/**
	 * Format a stored UTC datetime for people, in the site timezone.
	 *
	 * @param string|null $utc    Stored UTC datetime.
	 * @param string      $format PHP date format; defaults to the site's date and time formats.
	 * @return string Formatted time with the timezone abbreviation, or the raw value when invalid.
	 */
	public static function display( ?string $utc, string $format = '' ): string {
		$timestamp = self::utc_to_timestamp( $utc );
		if ( null === $timestamp ) {
			return (string) $utc;
		}

		if ( '' === $format ) {
			$format = get_option( 'date_format', 'Y-m-d' ) . ' ' . get_option( 'time_format', 'H:i' );
		}

		return wp_date( $format . ' T', $timestamp );
	}

	/**
	 * The current time in the site timezone as a datetime-local value.
	 *
	 * @return string
	 */
	public static function now_local_input(): string {
		return wp_date( self::INPUT_FORMAT );
	}

	/**
	 * A short, human label for the site timezone (e.g. "Asia/Kolkata" or "UTC+5:30").
	 *
	 * @return string
	 */
	public static function site_timezone_label(): string {
		return wp_timezone_string();
	}

	/**
	 * Parse a stored UTC datetime into a Unix timestamp.
	 *
	 * @param string|null $utc Stored UTC datetime.
	 * @return int|null
	 */
	public static function utc_to_timestamp( ?string $utc ): ?int {
		if ( null === $utc || '' === trim( $utc ) || 0 === strpos( $utc, '0000-00-00' ) ) {
			return null;
		}

		try {
			$date = new \DateTimeImmutable( $utc, new \DateTimeZone( 'UTC' ) );
		} catch ( \Exception $e ) {
			return null;
		}

		return $date->getTimestamp();
	}

	/**
	 * A stored UTC datetime as a DateTime in the site timezone, for calendar maths
	 * (adding days/weeks/months keeps the same wall-clock time across DST changes).
	 *
	 * @param string $utc Stored UTC datetime.
	 * @return \DateTime|null
	 */
	public static function utc_to_site_datetime( string $utc ): ?\DateTime {
		$timestamp = self::utc_to_timestamp( $utc );
		if ( null === $timestamp ) {
			return null;
		}

		$date = new \DateTime( '@' . $timestamp );
		$date->setTimezone( wp_timezone() );
		return $date;
	}
}
