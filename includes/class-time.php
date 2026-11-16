<?php
/**
 * Date and time helpers.
 *
 * Reminder times are stored in UTC. People type and read them in NotifyCrew's
 * timezone: its own setting (Reminders > Settings) or, by default, the site's
 * (Settings > General). Every form input and every display goes through this class.
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
	 * Key in the ncrw_options array that holds the NotifyCrew timezone ('' = same as the site).
	 */
	const OPTION_KEY = 'timezone';

	/**
	 * The timezone NotifyCrew uses: its own setting when one is chosen, else the site's (Settings > General).
	 *
	 * @return \DateTimeZone
	 */
	public static function timezone(): \DateTimeZone {
		$zone = self::setting();
		if ( '' === $zone ) {
			return wp_timezone();
		}

		$offset = self::manual_offset( $zone );
		if ( null !== $offset ) {
			return new \DateTimeZone( $offset );
		}

		try {
			return new \DateTimeZone( $zone );
		} catch ( \Exception $e ) {
			return wp_timezone();
		}
	}

	/**
	 * The saved NotifyCrew timezone setting ('' when it follows the site).
	 *
	 * @return string
	 */
	public static function setting(): string {
		$options = get_option( 'ncrw_options', array() );
		return is_array( $options ) && isset( $options[ self::OPTION_KEY ] ) ? (string) $options[ self::OPTION_KEY ] : '';
	}

	/**
	 * Validate a timezone setting value from the settings form.
	 *
	 * Accepts '' (same as site), a timezone identifier such as Europe/London, or a manual
	 * offset as WordPress writes it in wp_timezone_choice() (UTC+5.5, UTC-8, UTC+0).
	 *
	 * @param string $value Raw value.
	 * @return string Clean value, or '' when it isn't a timezone.
	 */
	public static function sanitize_setting( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		if ( null !== self::manual_offset( $value ) ) {
			return $value;
		}
		return in_array( $value, timezone_identifiers_list(), true ) ? $value : '';
	}

	/**
	 * Turn a manual offset such as UTC+5.5 into a DateTimeZone offset such as +05:30.
	 *
	 * @param string $value Setting value.
	 * @return string|null Offset string, or null when the value is not a manual offset.
	 */
	private static function manual_offset( string $value ): ?string {
		if ( ! preg_match( '/^UTC([+-])(\d{1,2})(?:\.(\d{1,2}))?$/', $value, $m ) ) {
			return null;
		}
		$hours   = (int) $m[2];
		$minutes = isset( $m[3] ) ? (int) round( (float) ( '0.' . $m[3] ) * 60 ) : 0;
		if ( $hours > 14 || $minutes > 59 ) {
			return null;
		}
		return sprintf( '%s%02d:%02d', $m[1], $hours, $minutes );
	}

	/**
	 * Convert a form value typed in NotifyCrew's timezone into a UTC storage string.
	 *
	 * Accepts a datetime-local value (2026-11-16T10:00), the same with seconds or
	 * a space, or an ISO 8601 string with an explicit offset (2026-11-16T10:00:00+05:30),
	 * in which case the offset wins over NotifyCrew's timezone.
	 *
	 * @param string $value Raw input.
	 * @return string UTC datetime (Y-m-d H:i:s), or '' when the value is not a date.
	 */
	public static function local_input_to_utc( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}

		$site_tz = self::timezone();
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
	 * Turn a stored UTC datetime into a datetime-local value in NotifyCrew's timezone.
	 *
	 * @param string|null $utc Stored UTC datetime.
	 * @return string Input value, or '' when empty/invalid.
	 */
	public static function utc_to_local_input( ?string $utc ): string {
		$date = null === $utc ? null : self::utc_to_site_datetime( $utc );
		return null === $date ? '' : $date->format( self::INPUT_FORMAT );
	}

	/**
	 * Format a stored UTC datetime for people, in NotifyCrew's timezone.
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

		// Name the zone unless the format already does (e, T, O, P print it).
		if ( ! preg_match( '/(?<!\\\\)[eTOP]/', $format ) ) {
			$format .= ' T';
		}

		return (string) wp_date( $format, $timestamp, self::timezone() );
	}

	/**
	 * The current time in NotifyCrew's timezone as a datetime-local value.
	 *
	 * @return string
	 */
	public static function now_local_input(): string {
		// Not wp_date(): its filter lets calendar plugins localise digits and calendars, which breaks a datetime-local value.
		return ( new \DateTimeImmutable( 'now', self::timezone() ) )->format( self::INPUT_FORMAT );
	}

	/**
	 * A short, human label for NotifyCrew's timezone (e.g. "Asia/Kolkata" or "+05:30").
	 *
	 * @return string
	 */
	public static function site_timezone_label(): string {
		return '' === self::setting() ? wp_timezone_string() : self::timezone()->getName();
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
	 * A stored UTC datetime as a DateTime in NotifyCrew's timezone, for calendar maths
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
		$date->setTimezone( self::timezone() );
		return $date;
	}
}
