<?php
/**
 * Slack delivery service.
 *
 * @package Aditya\ReminderTool
 */

namespace Aditya\ReminderTool\Services;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Aditya\ReminderTool\Models\Reminder;

/**
 * Class Slack_Service
 */
class Slack_Service {

	/** Auth mode option key. */
	const MODE_OPTION = 'trt_slack_mode';

	/** Encrypted webhook option key. */
	const WEBHOOK_OPTION = 'trt_slack_webhook';

	/** Encrypted bot token option key. */
	const BOT_TOKEN_OPTION = 'trt_slack_bot_token';

	/** Optional username override. */
	const USERNAME_OPTION = 'trt_slack_username';

	/** Supported auth modes. */
	const MODE_WEBHOOK = 'webhook';
	const MODE_BOT     = 'bot';

	/**
	 * Singleton instance.
	 *
	 * @var Slack_Service|null
	 */
	private static $instance = null;

	/** @return Slack_Service */
	public static function get_instance(): Slack_Service {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/**
	 * Send a reminder notification to Slack using global credentials
	 * and team-specific channel configuration.
	 *
	 * @param Reminder $reminder Reminder instance.
	 * @return true|\WP_Error
	 */
	public function send( Reminder $reminder ) {
		$team          = Team_Service::get_instance()->get( $reminder->team_id );
		$team_channel  = $team ? trim( (string) $team->slack_channel ) : '';
		$global_channel = trim( $this->get_channel() );
		$channel       = '' !== $team_channel ? $team_channel : $global_channel;
		$mode = $this->get_mode();
		$mention_tag = $team ? Team_Service::get_instance()->get_team_mention_tag( (int) $reminder->team_id ) : '';

		if ( '' === $channel && self::MODE_WEBHOOK !== $mode ) {
			return new \WP_Error( 'missing_team_channel', __( 'No Slack channel configured. Set a Team Slack Channel or a global fallback channel.', 'reminder-manager' ) );
		}

		if ( self::MODE_BOT === $mode ) {
			return $this->send_via_bot_token( $reminder, $channel, $mention_tag );
		}

		return $this->send_via_webhook( $reminder, $channel, $mention_tag );
	}

	/**
	 * Save the selected Slack auth mode.
	 *
	 * @param string $mode Mode value.
	 */
	public function save_mode( string $mode ): void {
		$allowed = array( self::MODE_WEBHOOK, self::MODE_BOT );
		$mode    = in_array( $mode, $allowed, true ) ? $mode : self::MODE_WEBHOOK;
		update_option( self::MODE_OPTION, $mode, false );
	}

	/**
	 * Get current auth mode.
	 *
	 * @return string
	 */
	public function get_mode(): string {
		$mode = (string) get_option( self::MODE_OPTION, self::MODE_WEBHOOK );
		if ( in_array( $mode, array( self::MODE_WEBHOOK, self::MODE_BOT ), true ) ) {
			return $mode;
		}

		return self::MODE_WEBHOOK;
	}

	/**
	 * Remove deprecated relay options from previous versions.
	 *
	 * This is idempotent and safe to run on every bootstrap.
	 */
	public function cleanup_legacy_relay_options(): void {
		$legacy_mode = (string) get_option( self::MODE_OPTION, '' );
		if ( 'relay' === $legacy_mode ) {
			update_option( self::MODE_OPTION, self::MODE_WEBHOOK, false );
		}

		delete_option( 'trt_slack_relay_url' );
		delete_option( 'trt_slack_relay_key' );
	}

	/**
	 * Save webhook URL encrypted.
	 *
	 * @param string $url Webhook URL.
	 */
	public function save_webhook( string $url ): void {
		$sanitized = esc_url_raw( $url );
		update_option( self::WEBHOOK_OPTION, $this->encrypt( $sanitized ), false );
	}

	/**
	 * Retrieve decrypted webhook URL.
	 *
	 * @return string
	 */
	public function get_webhook(): string {
		$encrypted = (string) get_option( self::WEBHOOK_OPTION, '' );
		if ( '' === $encrypted ) {
			return '';
		}
		return $this->decrypt( $encrypted );
	}

	/**
	 * Save bot token encrypted.
	 *
	 * @param string $token Bot token.
	 */
	public function save_bot_token( string $token ): void {
		$sanitized = sanitize_text_field( $token );
		update_option( self::BOT_TOKEN_OPTION, $this->encrypt( $sanitized ), false );
	}


	/**
	 * Retrieve decrypted bot token.
	 *
	 * @return string
	 */
	public function get_bot_token(): string {
		$encrypted = (string) get_option( self::BOT_TOKEN_OPTION, '' );
		if ( '' === $encrypted ) {
			return '';
		}
		return $this->decrypt( $encrypted );
	}

	/**
	 * Save optional bot username override.
	 *
	 * @param string $username Username value.
	 */
	public function save_username( string $username ): void {
		update_option( self::USERNAME_OPTION, sanitize_text_field( $username ), false );
	}

	/**
	 * @return string
	 */
	public function get_username(): string {
		return (string) get_option( self::USERNAME_OPTION, '' );
	}

	/**
	 * @return bool
	 */
	public function has_webhook(): bool {
		return '' !== (string) get_option( self::WEBHOOK_OPTION, '' );
	}

	/**
	 * @return bool
	 */
	public function has_bot_token(): bool {
		return '' !== (string) get_option( self::BOT_TOKEN_OPTION, '' );
	}

	/**
	 * Backward-compatible channel saver; no-op in team mode.
	 *
	 * @param string $channel Channel.
	 */
	public function save_channel( string $channel ): void {
		update_option( 'trt_slack_channel', sanitize_text_field( $channel ), false );
	}

	/**
	 * Backward-compatible getter.
	 *
	 * @return string
	 */
	public function get_channel(): string {
		return (string) get_option( 'trt_slack_channel', '' );
	}

	/**
	 * Send via incoming webhook.
	 *
	 * @param Reminder $reminder Reminder.
	 * @param string   $channel Team channel.
	 * @param string   $mention_tag Slack mention tag (e.g. '<!channel>').
	 * @return true|\WP_Error
	 */
	private function send_via_webhook( Reminder $reminder, string $channel, string $mention_tag = '' ) {
		$webhook = $this->get_webhook();
		if ( '' === $webhook ) {
			return new \WP_Error( 'no_webhook', __( 'Slack webhook URL is not configured.', 'reminder-manager' ) );
		}

		$payload  = $this->build_payload( $reminder, $channel, $mention_tag );
		$response = wp_remote_post(
			$webhook,
			array(
				'headers'   => array( 'Content-Type' => 'application/json' ),
				'body'      => wp_json_encode( $payload ),
				'timeout'   => 15,
				'sslverify' => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		if ( 200 !== $code || 'ok' !== trim( strtolower( $body ) ) ) {
			return new \WP_Error(
				'slack_error',
				sprintf(
					/* translators: 1: status code, 2: body */
					__( 'Slack returned HTTP %1$d: %2$s', 'reminder-manager' ),
					$code,
					sanitize_text_field( $body )
				)
			);
		}

		return true;
	}

	/**
	 * Send via Slack bot token and chat.postMessage.
	 *
	 * @param Reminder $reminder Reminder.
	 * @param string   $channel Team channel.
	 * @param string   $mention_tag Slack mention tag (e.g. '<!channel>').
	 * @return true|\WP_Error
	 */
	private function send_via_bot_token( Reminder $reminder, string $channel, string $mention_tag = '' ) {
		$token = $this->get_bot_token();
		if ( '' === $token ) {
			return new \WP_Error( 'no_bot_token', __( 'Slack bot token is not configured.', 'reminder-manager' ) );
		}

		$payload  = $this->build_payload( $reminder, $channel, $mention_tag );
		$response = wp_remote_post(
			'https://slack.com/api/chat.postMessage',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json; charset=utf-8',
				),
				'body'    => wp_json_encode( $payload ),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $code || empty( $body['ok'] ) ) {
			$error = is_array( $body ) && ! empty( $body['error'] ) ? sanitize_text_field( $body['error'] ) : __( 'Unknown Slack API error', 'reminder-manager' );
			return new \WP_Error( 'slack_api_error', $error );
		}

		return true;
	}

	/**
	 * Build message payload.
	 *
	 * @param Reminder $reminder Reminder.
	 * @param string   $channel Team channel.
	 * @param string   $mention_tag Slack-formatted mention tag (e.g. '<!channel>').
	 * @return array
	 */
	private function build_payload( Reminder $reminder, string $channel, string $mention_tag = '' ): array {
		$date = gmdate( 'Y-m-d H:i:s', strtotime( $reminder->remind_at . ' UTC' ) ) . ' UTC';
		$text_prefix = '' !== $mention_tag ? $mention_tag . ' ' : '';

		$fields = array(
			array(
				'type' => 'mrkdwn',
				'text' => sprintf( "*%s*\n%s", __( 'Scheduled', 'reminder-manager' ), $date ),
			),
		);

		if ( ! empty( $reminder->task_link ) ) {
			$fields[] = array(
				'type' => 'mrkdwn',
				'text' => sprintf( "*%s*\n<%s|%s>", __( 'Task/Ticket/Slack Link', 'reminder-manager' ), esc_url_raw( $reminder->task_link ), esc_url_raw( $reminder->task_link ) ),
			);
		}

		if ( $reminder->user_id > 0 ) {
			$user = get_userdata( $reminder->user_id );
			if ( $user ) {
				$email = '' !== $reminder->member_email ? $reminder->member_email : $user->user_email;
				$fields[] = array(
					'type' => 'mrkdwn',
					'text' => sprintf( "*%s*\n%s (%s)", __( 'Added by', 'reminder-manager' ), $user->display_name, $email ),
				);
			} elseif ( '' !== $reminder->member_email ) {
				$fields[] = array(
					'type' => 'mrkdwn',
					'text' => sprintf( "*%s*\n%s", __( 'Added by', 'reminder-manager' ), $reminder->member_email ),
				);
			}
		} elseif ( '' !== $reminder->member_email ) {
			$fields[] = array(
				'type' => 'mrkdwn',
				'text' => sprintf( "*%s*\n%s", __( 'Added by', 'reminder-manager' ), $reminder->member_email ),
			);
		}

		$payload = array(
			'text'    => sprintf( '%s:bell: %s', $text_prefix, $reminder->title ),
			'blocks'  => array(
				array(
					'type' => 'header',
					'text' => array(
						'type'  => 'plain_text',
						'text'  => sprintf( ':bell: %s', $reminder->title ),
						'emoji' => true,
					),
				),
				array(
					'type'   => 'section',
					'fields' => $fields,
				),
			),
		);

		if ( '' !== $channel ) {
			$payload['channel'] = $channel;
		}

		if ( '' !== $mention_tag ) {
			$payload['blocks'][] = array(
				'type' => 'section',
				'text' => array(
					'type' => 'mrkdwn',
					'text' => $mention_tag,
				),
			);
		}

		if ( ! empty( $reminder->comments ) ) {
			$payload['blocks'][] = array(
				'type' => 'section',
				'text' => array(
					'type' => 'mrkdwn',
					'text' => sprintf( "*%s*\n%s", __( 'Notes', 'reminder-manager' ), $reminder->comments ),
				),
			);
		}

		$username = $this->get_username();
		if ( '' !== $username ) {
			$payload['username'] = $username;
		}

		return $payload;
	}

	/**
	 * Encrypt a setting value.
	 *
	 * @param string $value Plain text value.
	 * @return string
	 */
	private function encrypt( string $value ): string {
		$key    = defined( 'AUTH_KEY' ) ? AUTH_KEY : wp_salt();
		$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$key32  = substr( hash( 'sha256', $key, true ), 0, SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
		$cipher = sodium_crypto_secretbox( $value, $nonce, $key32 );
		return base64_encode( $nonce . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decrypt a setting value.
	 *
	 * @param string $encoded Encrypted value.
	 * @return string
	 */
	private function decrypt( string $encoded ): string {
		try {
			$key     = defined( 'AUTH_KEY' ) ? AUTH_KEY : wp_salt();
			$key32   = substr( hash( 'sha256', $key, true ), 0, SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
			$decoded = base64_decode( $encoded, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			if ( false === $decoded ) {
				return '';
			}
			$nonce  = substr( $decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher = substr( $decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$plain  = sodium_crypto_secretbox_open( $cipher, $nonce, $key32 );
			return false !== $plain ? $plain : '';
		} catch ( \Exception $e ) {
			return '';
		}
	}
}
