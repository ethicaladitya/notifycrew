<?php
/**
 * Admin settings page.
 *
 * @package Aditya\NotifyCrew
 */

namespace Aditya\NotifyCrew\Admin\Pages;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Aditya\NotifyCrew\Services\Slack_Service;

/**
 * Class Settings_Page
 */
class Settings_Page {

	/** Settings group identifier. */
	const OPTION_GROUP = 'ncrw_settings';

	/** Option name for general settings. */
	const OPTION_NAME = 'ncrw_options';

	/**
	 * Singleton instance.
	 *
	 * @var Settings_Page|null
	 */
	private static $instance = null;

	/** @return Settings_Page */
	public static function get_instance(): Settings_Page {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_ncrw_save_slack_credentials', array( $this, 'handle_slack_save' ) );
	}

	/**
	 * Register settings.
	 */
	public function register_settings(): void {
		register_setting(
			self::OPTION_GROUP,
			self::OPTION_NAME,
			array(
				'sanitize_callback' => array( $this, 'sanitize_options' ),
				'default'           => array(),
			)
		);

		add_settings_section( 'ncrw_general', __( 'General', 'notifycrew' ), '__return_false', 'ncrw-settings' );
		add_settings_field( 'visibility', __( 'Reminder Visibility', 'notifycrew' ), array( $this, 'field_visibility' ), 'ncrw-settings', 'ncrw_general' );
		add_settings_field( 'allow_uninstall_cleanup', __( 'Data Cleanup', 'notifycrew' ), array( $this, 'field_allow_uninstall_cleanup' ), 'ncrw-settings', 'ncrw_general' );

		add_settings_section( 'ncrw_frontend', __( 'Frontend Reminder Intake', 'notifycrew' ), '__return_false', 'ncrw-settings' );
		add_settings_field( 'frontend_enabled', __( 'Enable Frontend Form', 'notifycrew' ), array( $this, 'field_frontend_enabled' ), 'ncrw-settings', 'ncrw_frontend' );
		add_settings_field( 'frontend_google_client_id', __( 'Google OAuth Client ID', 'notifycrew' ), array( $this, 'field_frontend_google_client_id' ), 'ncrw-settings', 'ncrw_frontend' );
		add_settings_field( 'frontend_allowed_domains', __( 'Allowed Email Domains', 'notifycrew' ), array( $this, 'field_frontend_allowed_domains' ), 'ncrw-settings', 'ncrw_frontend' );
		add_settings_field( 'frontend_api_key', __( 'Headless API Key', 'notifycrew' ), array( $this, 'field_frontend_api_key' ), 'ncrw-settings', 'ncrw_frontend' );
	}

	/**
	 * Sanitize options.
	 *
	 * @param mixed $input Raw settings.
	 * @return array
	 */
	public function sanitize_options( $input ): array {
		$output = array();

		if ( isset( $input['visibility'] ) ) {
			$allowed              = array( 'all', 'admin_only' );
			$output['visibility'] = in_array( $input['visibility'], $allowed, true ) ? $input['visibility'] : 'all';
		}

		$output['allow_uninstall_cleanup'] = ! empty( $input['allow_uninstall_cleanup'] ) ? 1 : 0;

		$output['frontend_enabled'] = ! empty( $input['frontend_enabled'] ) ? 1 : 0;

		if ( isset( $input['frontend_google_client_id'] ) ) {
			$output['frontend_google_client_id'] = sanitize_text_field( $input['frontend_google_client_id'] );
		}

		$raw_domains                        = isset( $input['frontend_allowed_domains'] ) ? (string) $input['frontend_allowed_domains'] : 'example.com';
		$output['frontend_allowed_domains'] = $this->normalize_domain_list( $raw_domains );

		if ( isset( $input['frontend_api_key'] ) ) {
			$output['frontend_api_key'] = sanitize_text_field( (string) $input['frontend_api_key'] );
		}

		return $output;
	}

	/**
	 * Normalize domain list.
	 *
	 * @param string $raw Raw value.
	 * @return string
	 */
	private function normalize_domain_list( string $raw ): string {
		$parts   = preg_split( '/[\s,]+/', strtolower( $raw ) );
		$domains = array();

		foreach ( $parts as $part ) {
			$domain = trim( (string) $part );
			if ( '' === $domain ) {
				continue;
			}
			$domain = ltrim( $domain, '@' );
			$domain = preg_replace( '/[^a-z0-9.-]/', '', $domain );
			if ( '' === $domain || false === strpos( $domain, '.' ) ) {
				continue;
			}
			$domains[] = $domain;
		}

		if ( empty( $domains ) ) {
			$domains = array( 'example.com' );
		}

		return implode( ', ', array_values( array_unique( $domains ) ) );
	}

	/**
	 * Field renderer.
	 */
	public function field_visibility(): void {
		$options = get_option( self::OPTION_NAME, array() );
		$current = $options['visibility'] ?? 'all';
		?>
		<select name="<?php echo esc_attr( self::OPTION_NAME . '[visibility]' ); ?>">
			<option value="all" <?php selected( $current, 'all' ); ?>><?php esc_html_e( 'All Users', 'notifycrew' ); ?></option>
			<option value="admin_only" <?php selected( $current, 'admin_only' ); ?>><?php esc_html_e( 'Admins Only', 'notifycrew' ); ?></option>
		</select>
		<p class="description"><?php esc_html_e( 'Control who can see the reminders front-end.', 'notifycrew' ); ?></p>
		<?php
	}

	/**
	 * Field renderer.
	 */
	public function field_allow_uninstall_cleanup(): void {
		$options = get_option( self::OPTION_NAME, array() );
		$enabled = ! empty( $options['allow_uninstall_cleanup'] );
		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( self::OPTION_NAME . '[allow_uninstall_cleanup]' ); ?>" value="1" <?php checked( $enabled ); ?>/>
			<?php esc_html_e( 'Delete all plugin data when the plugin is uninstalled.', 'notifycrew' ); ?>
		</label>
		<p class="description"><?php esc_html_e( 'Disabled by default. Enable this only if you explicitly want reminders, teams, logs, and plugin settings to be permanently removed on uninstall.', 'notifycrew' ); ?></p>
		<?php
	}

	/**
	 * Field renderer.
	 */
	public function field_frontend_enabled(): void {
		$options = get_option( self::OPTION_NAME, array() );
		$enabled = ! empty( $options['frontend_enabled'] );
		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( self::OPTION_NAME . '[frontend_enabled]' ); ?>" value="1" <?php checked( $enabled ); ?>/>
			<?php esc_html_e( 'Allow reminder submissions from frontend pages using Google Sign-In.', 'notifycrew' ); ?>
		</label>
		<p class="description"><?php esc_html_e( 'Use shortcode [ncrw_frontend_reminder_form] on any page to render the portal.', 'notifycrew' ); ?></p>
		<?php
	}

	/**
	 * Field renderer.
	 */
	public function field_frontend_google_client_id(): void {
		$options   = get_option( self::OPTION_NAME, array() );
		$client_id = isset( $options['frontend_google_client_id'] ) ? (string) $options['frontend_google_client_id'] : '';
		?>
		<input type="text" class="regular-text" name="<?php echo esc_attr( self::OPTION_NAME . '[frontend_google_client_id]' ); ?>" value="<?php echo esc_attr( $client_id ); ?>" placeholder="1234567890-abcxyz.apps.googleusercontent.com"/>
		<p class="description"><?php esc_html_e( 'Required for Google Sign-In token validation.', 'notifycrew' ); ?></p>
		<?php
	}

	/**
	 * Field renderer.
	 */
	public function field_frontend_allowed_domains(): void {
		$options = get_option( self::OPTION_NAME, array() );
		$domains = isset( $options['frontend_allowed_domains'] ) ? (string) $options['frontend_allowed_domains'] : 'example.com';
		?>
		<input type="text" class="regular-text" name="<?php echo esc_attr( self::OPTION_NAME . '[frontend_allowed_domains]' ); ?>" value="<?php echo esc_attr( $domains ); ?>" placeholder="example.com"/>
		<p class="description"><?php esc_html_e( 'Comma-separated domains allowed to sign in.', 'notifycrew' ); ?></p>
		<?php
	}

	/**
	 * Field renderer.
	 */
	public function field_frontend_api_key(): void {
		$options = get_option( self::OPTION_NAME, array() );
		$api_key = isset( $options['frontend_api_key'] ) ? (string) $options['frontend_api_key'] : '';
		?>
		<input type="text" class="regular-text" name="<?php echo esc_attr( self::OPTION_NAME . '[frontend_api_key]' ); ?>" value="<?php echo esc_attr( $api_key ); ?>" placeholder="ncrw_live_xxxxxxxxx" autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false"/>
		<p class="description"><?php esc_html_e( 'Required for external frontend proxy requests. Keep this secret and only store it in server-side environment variables.', 'notifycrew' ); ?></p>
		<?php
	}

	/**
	 * Handle Slack credential save.
	 */
	public function handle_slack_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'notifycrew' ), 403 );
		}
		check_admin_referer( 'ncrw_save_slack_credentials', 'ncrw_slack_nonce' );

		$mode      = sanitize_key( wp_unslash( $_POST['ncrw_slack_mode'] ?? Slack_Service::MODE_WEBHOOK ) );
		$webhook   = sanitize_text_field( wp_unslash( $_POST['ncrw_slack_webhook'] ?? '' ) );
		$bot_token = sanitize_text_field( wp_unslash( $_POST['ncrw_slack_bot_token'] ?? '' ) );
		$username  = sanitize_text_field( wp_unslash( $_POST['ncrw_slack_username'] ?? '' ) );

		$slack = Slack_Service::get_instance();
		$slack->save_mode( $mode );
		if ( '' !== $webhook ) {
			if ( ! filter_var( $webhook, FILTER_VALIDATE_URL ) ) {
				add_settings_error( 'ncrw_slack', 'invalid_webhook', __( 'Please enter a valid Slack webhook URL.', 'notifycrew' ), 'error' );
				set_transient( 'settings_errors', get_settings_errors(), 30 );
				wp_safe_redirect(
					add_query_arg(
						array(
							'page'       => 'ncrw-settings',
							'ncrw_error' => '1',
						),
						admin_url( 'admin.php' )
					)
				);
				exit;
			}
			$slack->save_webhook( $webhook );
		}
		if ( '' !== $bot_token ) {
			$slack->save_bot_token( $bot_token );
		}
		$slack->save_username( $username );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'ncrw-settings',
					'updated' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Render page.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'notifycrew' ) );
		}

		$slack     = Slack_Service::get_instance();
		$mode      = $slack->get_mode();
		$has_hook  = $slack->has_webhook();
		$has_token = $slack->has_bot_token();
		?>
		<div class="wrap ncrw-wrap">
			<h1><?php esc_html_e( 'NotifyCrew - Settings', 'notifycrew' ); ?></h1>

			<?php if ( isset( $_GET['updated'] ) && '1' === $_GET['updated'] ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'notifycrew' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['ncrw_error'] ) && '1' === $_GET['ncrw_error'] ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<?php settings_errors( 'ncrw_slack' ); ?>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php
				settings_fields( self::OPTION_GROUP );
				do_settings_sections( 'ncrw-settings' );
				submit_button( __( 'Save Settings', 'notifycrew' ) );
				?>
			</form>

			<hr/>
			<h2><?php esc_html_e( 'Slack Integration', 'notifycrew' ); ?></h2>
			<p>
				<?php if ( $has_hook || $has_token ) : ?>
					<span class="ncrw-badge ncrw-badge--success"><?php esc_html_e( 'Slack credentials configured', 'notifycrew' ); ?></span>
				<?php else : ?>
					<span class="ncrw-badge ncrw-badge--warning"><?php esc_html_e( 'No Slack credentials configured', 'notifycrew' ); ?></span>
				<?php endif; ?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="ncrw_save_slack_credentials"/>
				<?php wp_nonce_field( 'ncrw_save_slack_credentials', 'ncrw_slack_nonce' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ncrw_slack_mode"><?php esc_html_e( 'Auth Mode', 'notifycrew' ); ?></label></th>
						<td>
							<select id="ncrw_slack_mode" name="ncrw_slack_mode">
								<option value="webhook" <?php selected( $mode, Slack_Service::MODE_WEBHOOK ); ?>><?php esc_html_e( 'Incoming Webhook', 'notifycrew' ); ?></option>
								<option value="bot" <?php selected( $mode, Slack_Service::MODE_BOT ); ?>><?php esc_html_e( 'Bot Token (chat.postMessage)', 'notifycrew' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Choose global Slack authentication mode.', 'notifycrew' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ncrw_slack_webhook"><?php esc_html_e( 'Slack Webhook URL', 'notifycrew' ); ?></label></th>
						<td>
							<input type="url" id="ncrw_slack_webhook" name="ncrw_slack_webhook" class="regular-text" placeholder="https://hooks.slack.com/services/..." autocomplete="new-password" autocapitalize="off" autocorrect="off" spellcheck="false" data-lpignore="true" data-1p-ignore="true"/>
							<p class="description"><?php esc_html_e( 'Stored encrypted. Leave empty to keep current value.', 'notifycrew' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ncrw_slack_bot_token"><?php esc_html_e( 'Slack Bot Token', 'notifycrew' ); ?></label></th>
						<td>
							<input type="password" id="ncrw_slack_bot_token" name="ncrw_slack_bot_token" class="regular-text" placeholder="xoxb-..." autocomplete="new-password"/>
							<p class="description"><?php esc_html_e( 'Stored encrypted. Leave empty to keep current value.', 'notifycrew' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ncrw_slack_username"><?php esc_html_e( 'Bot Username', 'notifycrew' ); ?></label></th>
						<td>
							<input type="text" id="ncrw_slack_username" name="ncrw_slack_username" class="regular-text" value="<?php echo esc_attr( $slack->get_username() ); ?>" placeholder="reminder_bot"/>
							<p class="description"><?php esc_html_e( 'Optional display name override.', 'notifycrew' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save Slack Credentials', 'notifycrew' ) ); ?>
			</form>
		</div>
		<?php
	}
}
