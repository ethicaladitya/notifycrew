<?php
/**
 * PSR-4 Autoloader for the NotifyCrew plugin.
 *
 * @package Aditya\NotifyCrew
 */

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

spl_autoload_register(
	function ( $class ) {
		$prefix   = 'Aditya\\NotifyCrew\\';
		$base_dir = NCRW_DIR . 'includes/';

		$len = strlen( $prefix );
		if ( strncmp( $prefix, $class, $len ) !== 0 ) {
				return;
		}

		$relative_class = substr( $class, $len );

		// Map namespace segments to directory structure.
		$parts      = explode( '\\', $relative_class );
		$class_name = array_pop( $parts );

		// Convert CamelCase and snake_case class names to file name format (class-my-class.php).
		// 1. Insert hyphen between lowercase→uppercase transitions (CamelCase).
		// 2. Replace underscores with hyphens (snake_case like Cron_Service).
		$file_name = 'class-' . strtolower( str_replace( '_', '-', preg_replace( '/([a-z])([A-Z])/', '$1-$2', $class_name ) ) ) . '.php';

		// Build directory path from remaining namespace segments.
		$sub_dir = '';
		if ( ! empty( $parts ) ) {
			$sub_dir = implode( '/', array_map( 'strtolower', $parts ) ) . '/';
		}

		$file = $base_dir . $sub_dir . $file_name;

		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
);
