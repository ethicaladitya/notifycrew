<?php
/**
 * Site alerts: WordPress events sent to Slack.
 *
 * Updates available (WordPress, plugins, themes), automatic update results and
 * plugin or theme crashes, each switched on under Reminders > Settings > Site alerts.
 *
 * @package Aditya\NotifyCrew
 */

namespace Aditya\NotifyCrew\Services;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Site_Alerts_Service
 */
final class Site_Alerts_Service {

	/**
	 * Single cron event that checks for new updates a minute after WordPress saved its update data.
	 */
	const CHECK_HOOK = 'ncrw_site_alerts_check';

	/**
	 * Option holding the "type:slug:version" keys already announced.
	 */
	const NOTIFIED_OPTION = 'ncrw_alerts_notified';

	/**
	 * Option holding the last failed alert (time and Slack's error), shown on the settings page.
	 */
	const LAST_ERROR_OPTION = 'ncrw_alerts_last_error';

	/**
	 * Settings option (shared with the settings page).
	 */
	const OPTION_NAME = 'ncrw_options';

	/**
	 * Most update lines listed in one message.
	 */
	const MAX_LINES = 20;

	/**
	 * Singleton instance.
	 *
	 * @var Site_Alerts_Service|null
	 */
	private static $instance = null;

	/**
	 * Get singleton.
	 *
	 * @return Site_Alerts_Service
	 */
	public static function get_instance(): Site_Alerts_Service {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {}

	/**
	 * Register hooks (called from Plugin::init_services).
	 */
	public function register(): void {
		foreach ( array( 'update_plugins', 'update_themes', 'update_core' ) as $transient ) {
			add_action( "set_site_transient_{$transient}", array( $this, 'queue_update_check' ) );
		}
		add_action( self::CHECK_HOOK, array( $this, 'check_updates' ) );
		add_action( 'automatic_updates_complete', array( $this, 'on_automatic_updates' ) );
		add_filter( 'recovery_mode_email', array( $this, 'on_recovery_mode_email' ), 10, 1 );
	}

	/**
	 * Remove the scheduled check (plugin deactivation).
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::CHECK_HOOK );
	}

	/**
	 * WordPress just saved fresh update data: check it in a minute, outside this request.
	 */
	public function queue_update_check(): void {
		if ( ! $this->enabled( 'alerts_updates' ) || wp_next_scheduled( self::CHECK_HOOK ) ) {
			return;
		}
		wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::CHECK_HOOK );
	}

	/**
	 * Announce updates that weren't announced before. Each version is announced once.
	 */
	public function check_updates(): void {
		if ( ! $this->enabled( 'alerts_updates' ) ) {
			return;
		}

		$pending  = $this->pending_updates();
		$notified = get_option( self::NOTIFIED_OPTION, array() );
		$notified = is_array( $notified ) ? $notified : array();
		$still    = array_values( array_intersect( $notified, array_keys( $pending ) ) );
		$new      = array_diff_key( $pending, array_flip( $notified ) );

		if ( empty( $new ) ) {
			if ( $still !== $notified ) {
				update_option( self::NOTIFIED_OPTION, $still, false );
			}
			return;
		}

		$site  = $this->site_name();
		$lines = array_map( array( $this, 'esc' ), array_values( $new ) );
		$more  = count( $lines ) - self::MAX_LINES;
		$lines = array_slice( $lines, 0, self::MAX_LINES );
		if ( $more > 0 ) {
			/* translators: %d: number of further updates not listed */
			$lines[] = sprintf( _n( '…and %d more', '…and %d more', $more, 'notifycrew' ), $more );
		}

		$title = sprintf(
			/* translators: 1: number of updates, 2: site name */
			_n( '%1$d update available on %2$s', '%1$d updates available on %2$s', count( $new ), 'notifycrew' ),
			count( $new ),
			$site
		);
		$body = '• ' . implode( "\n• ", $lines );
		$link = sprintf( '<%s|%s>', esc_url_raw( admin_url( 'update-core.php' ) ), __( 'Open Dashboard > Updates', 'notifycrew' ) );

		$sent = $this->send( ':arrows_counterclockwise: ' . $title, "*{$this->esc( $title )}*\n{$body}\n{$link}" );
		if ( true === $sent ) {
			update_option( self::NOTIFIED_OPTION, array_values( array_unique( array_merge( $still, array_keys( $new ) ) ) ), false );
		}
	}

	/**
	 * Results of a background (automatic) update run.
	 *
	 * @param array $results Results keyed by type (core, plugin, theme, translation), as WordPress passes them.
	 */
	public function on_automatic_updates( $results ): void {
		$mode = $this->option( 'alerts_auto_updates', 'off' );
		if ( 'off' === $mode || ! is_array( $results ) ) {
			return;
		}

		$done   = array();
		$failed = array();
		foreach ( $results as $type => $items ) {
			foreach ( (array) $items as $item ) {
				$name   = isset( $item->name ) ? wp_strip_all_tags( (string) $item->name ) : (string) $type;
				$result = isset( $item->result ) ? $item->result : null;
				if ( ! $result || is_wp_error( $result ) ) {
					$reason   = is_wp_error( $result ) ? $result->get_error_message() : __( 'no reason given', 'notifycrew' );
					$failed[] = $this->esc( $name . ': ' . wp_strip_all_tags( $reason ) );
				} elseif ( 'translation' !== $type ) {
					$done[] = $this->esc( $name );
				}
			}
		}

		if ( empty( $failed ) && ( 'failures' === $mode || empty( $done ) ) ) {
			return;
		}

		$site  = $this->site_name();
		$parts = array();
		if ( ! empty( $failed ) ) {
			/* translators: %s: site name */
			$title   = sprintf( __( 'Automatic updates failed on %s', 'notifycrew' ), $site );
			$parts[] = '*' . $this->esc( $title ) . "*\n• " . implode( "\n• ", $failed );
			$icon    = ':warning: ';
		} else {
			/* translators: %s: site name */
			$title = sprintf( __( 'Automatic updates finished on %s', 'notifycrew' ), $site );
			$icon  = ':white_check_mark: ';
		}
		if ( ! empty( $done ) && 'all' === $mode ) {
			$parts[] = '*' . $this->esc( __( 'Updated', 'notifycrew' ) ) . "*\n• " . implode( "\n• ", $done );
		}
		if ( empty( $failed ) ) {
			array_unshift( $parts, '*' . $this->esc( $title ) . '*' );
		}
		$parts[] = sprintf( '<%s|%s>', esc_url_raw( admin_url( 'update-core.php' ) ), __( 'Open Dashboard > Updates', 'notifycrew' ) );

		$this->send( $icon . $title, implode( "\n", $parts ) );
	}

	/**
	 * WordPress caught a plugin or theme fatal error and is about to email the admin (at most once a day).
	 * Post the same news to Slack. The recovery-mode login link is never included: it is a one-time admin
	 * login and must not go to a shared channel.
	 *
	 * @param array $email Email WordPress will send; returned unchanged.
	 * @return array
	 */
	public function on_recovery_mode_email( $email ) {
		if ( ! $this->enabled( 'alerts_fatal' ) ) {
			return $email;
		}

		try {
			$error   = error_get_last();
			$message = is_array( $error ) && isset( $error['message'] ) ? (string) $error['message'] : '';
			$where   = is_array( $error ) && isset( $error['file'], $error['line'] ) ? $this->relative_path( (string) $error['file'] ) . ':' . (int) $error['line'] : '';
			$message = $this->relative_path( wp_strip_all_tags( $message ) );
			// The first line says what broke; the stack trace is noise in a chat message.
			$cut = strpos( $message, 'Stack trace:' );
			if ( false !== $cut ) {
				$message = trim( substr( $message, 0, $cut ) );
			}
			if ( strlen( $message ) > 500 ) {
				$message = substr( $message, 0, 500 ) . '…';
			}

			/* translators: %s: site name */
			$title = sprintf( __( 'A plugin or theme crashed %s', 'notifycrew' ), $this->site_name() );
			$text  = '*' . $this->esc( $title ) . '*';
			if ( '' !== $message ) {
				$text .= "\n```" . $this->esc( $message ) . '```';
			}
			if ( '' !== $where ) {
				/* translators: %s: file path and line */
				$text .= "\n" . $this->esc( sprintf( __( 'In %s', 'notifycrew' ), $where ) );
			}
			$text .= "\n" . $this->esc( __( 'WordPress has emailed the site admin a recovery-mode link.', 'notifycrew' ) );
			$text .= "\n" . sprintf( '<%s|%s>', esc_url_raw( admin_url( 'plugins.php' ) ), __( 'Open Plugins', 'notifycrew' ) );

			$this->send( ':rotating_light: ' . $title, $text, 10 );
		} catch ( \Throwable $e ) {
			// Never make a fatal-error shutdown worse.
			unset( $e );
		}

		return $email;
	}

	/**
	 * Send a test alert (the "Send test alert" button).
	 *
	 * @return true|\WP_Error
	 */
	public function send_test() {
		/* translators: %s: site name */
		$title = sprintf( __( 'Test alert from %s', 'notifycrew' ), $this->site_name() );
		$text  = '*' . $this->esc( $title ) . "*\n" . $this->esc( __( 'Site alerts from NotifyCrew will arrive here.', 'notifycrew' ) );
		return $this->send( ':white_check_mark: ' . $title, $text );
	}

	/**
	 * Updates WordPress currently knows about, keyed "type:slug:version".
	 *
	 * @return array<string,string> Key => human line ("Akismet 5.3 → 5.4").
	 */
	public function pending_updates(): array {
		$items = array();

		$plugins = get_site_transient( 'update_plugins' );
		if ( is_object( $plugins ) && ! empty( $plugins->response ) && is_array( $plugins->response ) ) {
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$installed = get_plugins();
			foreach ( $plugins->response as $file => $data ) {
				$data    = (array) $data;
				$version = isset( $data['new_version'] ) ? (string) $data['new_version'] : '';
				if ( '' === $version ) {
					continue;
				}
				$name                                        = isset( $installed[ $file ]['Name'] ) ? (string) $installed[ $file ]['Name'] : (string) $file;
				$current                                     = isset( $installed[ $file ]['Version'] ) ? (string) $installed[ $file ]['Version'] : '';
				$items[ 'plugin:' . $file . ':' . $version ] = $this->update_line( $name, $current, $version );
			}
		}

		$themes = get_site_transient( 'update_themes' );
		if ( is_object( $themes ) && ! empty( $themes->response ) && is_array( $themes->response ) ) {
			foreach ( $themes->response as $slug => $data ) {
				$data    = (array) $data;
				$version = isset( $data['new_version'] ) ? (string) $data['new_version'] : '';
				if ( '' === $version ) {
					continue;
				}
				$theme   = wp_get_theme( (string) $slug );
				$name    = $theme->exists() ? (string) $theme->get( 'Name' ) : (string) $slug;
				$current = $theme->exists() ? (string) $theme->get( 'Version' ) : '';
				/* translators: %s: theme name */
				$items[ 'theme:' . $slug . ':' . $version ] = $this->update_line( sprintf( __( '%s theme', 'notifycrew' ), $name ), $current, $version );
			}
		}

		$core = get_site_transient( 'update_core' );
		if ( is_object( $core ) && ! empty( $core->updates ) && is_array( $core->updates ) ) {
			foreach ( $core->updates as $update ) {
				if ( isset( $update->response, $update->current ) && 'upgrade' === $update->response ) {
					/* translators: 1: installed WordPress version, 2: new version */
					$items[ 'core:wordpress:' . $update->current ] = sprintf( __( 'WordPress %1$s → %2$s', 'notifycrew' ), get_bloginfo( 'version' ), (string) $update->current );
					break;
				}
			}
		}

		return $items;
	}

	/**
	 * One line of the updates message.
	 *
	 * @param string $name    Plugin or theme name.
	 * @param string $current Installed version ('' when unknown).
	 * @param string $version New version.
	 * @return string
	 */
	private function update_line( string $name, string $current, string $version ): string {
		if ( '' === $current ) {
			/* translators: 1: plugin or theme name, 2: new version */
			return sprintf( __( '%1$s → %2$s', 'notifycrew' ), $name, $version );
		}
		/* translators: 1: plugin or theme name, 2: installed version, 3: new version */
		return sprintf( __( '%1$s %2$s → %3$s', 'notifycrew' ), $name, $current, $version );
	}

	/**
	 * Post to Slack and remember the outcome for the settings page.
	 *
	 * @param string $fallback Notification text.
	 * @param string $mrkdwn   Message body.
	 * @param int    $timeout  Seconds to wait for Slack.
	 * @return true|\WP_Error
	 */
	private function send( string $fallback, string $mrkdwn, int $timeout = 15 ) {
		$blocks = array(
			array(
				'type' => 'section',
				'text' => array(
					'type' => 'mrkdwn',
					'text' => $mrkdwn,
				),
			),
		);
		$result = Slack_Service::get_instance()->send_message( $fallback, $blocks, (string) $this->option( 'alerts_channel', '' ), $timeout );

		if ( is_wp_error( $result ) ) {
			update_option(
				self::LAST_ERROR_OPTION,
				array(
					'time'    => time(),
					'message' => $result->get_error_message(),
				),
				false
			);
		} elseif ( false !== get_option( self::LAST_ERROR_OPTION, false ) ) {
			delete_option( self::LAST_ERROR_OPTION );
		}

		return $result;
	}

	/**
	 * Whether a checkbox alert is switched on.
	 *
	 * @param string $key Option key.
	 * @return bool
	 */
	private function enabled( string $key ): bool {
		return ! empty( $this->option( $key, 0 ) );
	}

	/**
	 * Read one key of the settings option.
	 *
	 * @param string $key     Option key.
	 * @param mixed  $fallback Default.
	 * @return mixed
	 */
	private function option( string $key, $fallback ) {
		$options = get_option( self::OPTION_NAME, array() );
		return is_array( $options ) && isset( $options[ $key ] ) ? $options[ $key ] : $fallback;
	}

	/**
	 * Site name for messages (falls back to the host name).
	 *
	 * @return string
	 */
	private function site_name(): string {
		$name = wp_strip_all_tags( (string) get_bloginfo( 'name' ) );
		return '' !== trim( $name ) ? $name : (string) wp_parse_url( home_url(), PHP_URL_HOST );
	}

	/**
	 * Escape text for Slack mrkdwn (&, < and > are control characters there).
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private function esc( string $text ): string {
		return str_replace( array( '&', '<', '>' ), array( '&amp;', '&lt;', '&gt;' ), $text );
	}

	/**
	 * Shorten absolute paths to paths inside the WordPress folder.
	 *
	 * @param string $text Text that may contain paths.
	 * @return string
	 */
	private function relative_path( string $text ): string {
		return str_replace( wp_normalize_path( ABSPATH ), '', str_replace( '\\', '/', $text ) );
	}
}
