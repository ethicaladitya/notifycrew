<?php
/**
 * REST API endpoint - manual processing + team-scoped reminders.
 *
 * @package Aditya\ReminderTool
 */

namespace Aditya\ReminderTool\Services;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Aditya\ReminderTool\Models\Reminder;
use Aditya\ReminderTool\Models\Team;

/**
 * Class Rest_Service
 */
class Rest_Service {

	/** REST namespace. */
	const NAMESPACE = 'trt/v1';

	/** Plugin settings option. */
	const SETTINGS_OPTION = 'trt_options';

	/**
	 * Singleton instance.
	 *
	 * @var Rest_Service|null
	 */
	private static $instance = null;

	/** @return Rest_Service */
	public static function get_instance(): Rest_Service {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/**
	 * Register REST routes.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Route definitions.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/process',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_process' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/reminders',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_list_reminders' ),
				'permission_callback' => array( $this, 'read_permission' ),
				'args'                => array(
					'team_id'  => array( 'type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 0 ),
					'status'   => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_key', 'default' => '' ),
					'per_page' => array( 'type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 20 ),
					'page'     => array( 'type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 1 ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/reminders/(?P<id>[\d]+)/retry',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_retry' ),
				'permission_callback' => array( $this, 'read_permission' ),
				'args'                => array(
					'id' => array( 'type' => 'integer', 'sanitize_callback' => 'absint', 'required' => true ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/portal/bootstrap',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_portal_bootstrap' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'id_token' => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'required' => true ),
					'team_id'  => array( 'type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 0 ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/portal/reminder',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_portal_save_reminder' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'id_token'         => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'required' => true ),
					'id'               => array( 'type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 0 ),
					'team_id'          => array( 'type' => 'integer', 'sanitize_callback' => 'absint', 'required' => true ),
					'title'            => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'required' => true ),
					'reminder_datetime'=> array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'required' => false ),
					'quick_hours'      => array( 'type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 0 ),
					'link'             => array( 'type' => 'string', 'sanitize_callback' => 'esc_url_raw', 'default' => '' ),
					'comments'         => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field', 'default' => '' ),
				),
			)
		);
	}

	/**
	 * Admin-only permission.
	 *
	 * @return bool|\WP_Error
	 */
	public function admin_permission() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'rest_forbidden', __( 'You do not have permission to perform this action.', 'reminder-manager' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Read permission.
	 *
	 * @return bool|\WP_Error
	 */
	public function read_permission() {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error( 'rest_forbidden', __( 'You must be logged in.', 'reminder-manager' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Trigger processing.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function handle_process( \WP_REST_Request $request ): \WP_REST_Response {
		Cron_Service::get_instance()->process();
		return new \WP_REST_Response( array( 'success' => true, 'message' => __( 'Processing complete.', 'reminder-manager' ) ), 200 );
	}

	/**
	 * List reminders.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function handle_list_reminders( \WP_REST_Request $request ): \WP_REST_Response {
		$args = array(
			'team_id'  => (int) $request->get_param( 'team_id' ),
			'status'   => (string) $request->get_param( 'status' ),
			'per_page' => (int) $request->get_param( 'per_page' ),
			'page'     => (int) $request->get_param( 'page' ),
		);

		$service   = Reminder_Service::get_instance();
		$reminders = $service->get_list( $args );
		$total     = $service->count( $args );
		$items     = array();
		foreach ( $reminders as $reminder ) {
			$items[] = (array) $reminder;
		}

		return new \WP_REST_Response( array( 'items' => $items, 'total' => $total ), 200 );
	}

	/**
	 * Queue manual retry.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_retry( \WP_REST_Request $request ) {
		$id       = (int) $request->get_param( 'id' );
		$service  = Reminder_Service::get_instance();
		$reminder = $service->get( $id );
		if ( ! $reminder ) {
			return new \WP_Error( 'not_found', __( 'Reminder not found.', 'reminder-manager' ), array( 'status' => 404 ) );
		}
		if ( ! Team_Service::get_instance()->user_can_admin_team( (int) $reminder->team_id ) ) {
			return new \WP_Error( 'rest_forbidden', __( 'You do not have permission to retry this reminder.', 'reminder-manager' ), array( 'status' => 403 ) );
		}

		$queued = $service->queue_retry( $id );
		if ( ! $queued ) {
			return new \WP_Error( 'retry_failed', __( 'Could not queue retry for this reminder.', 'reminder-manager' ), array( 'status' => 400 ) );
		}

		return new \WP_REST_Response( array( 'success' => true ), 200 );
	}

	/**
	 * Bootstrap portal session (identity + teams + reminders).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_portal_bootstrap( \WP_REST_Request $request ) {
		if ( ! $this->is_frontend_portal_enabled() ) {
			return new \WP_Error( 'portal_disabled', __( 'Frontend reminder submission is disabled.', 'reminder-manager' ), array( 'status' => 403 ) );
		}

		if ( ! $this->has_valid_portal_api_key( $request ) ) {
			return new \WP_Error( 'invalid_api_key', __( 'Invalid API key.', 'reminder-manager' ), array( 'status' => 403 ) );
		}

		$identity = $this->verify_portal_identity( (string) $request->get_param( 'id_token' ) );
		if ( is_wp_error( $identity ) ) {
			$identity->add_data( array( 'status' => 403 ) );
			return $identity;
		}

		$user_id      = (int) $identity['user_id'];
		$email        = (string) $identity['email'];
		$team_service = Team_Service::get_instance();
		$teams        = $team_service->get_teams_for_identity( $user_id, $email );

		if ( empty( $teams ) ) {
			return new \WP_Error( 'no_teams', __( 'No teams are assigned to your account.', 'reminder-manager' ), array( 'status' => 403 ) );
		}

		$requested_team_id = (int) $request->get_param( 'team_id' );
		$selected_team_id  = $requested_team_id;
		if ( 0 === $selected_team_id || ! $team_service->user_can_access_team( $selected_team_id, $user_id, $email ) ) {
			$selected_team_id = (int) $teams[0]->id;
		}

		$items = Reminder_Service::get_instance()->get_list(
			array(
				'team_id'        => $selected_team_id,
				'per_page'       => 200,
				'page'           => 1,
				'order'          => 'DESC',
				'_actor_user_id' => $user_id,
				'_actor_email'   => $email,
			)
		);

		return new \WP_REST_Response(
			array(
				'email'            => $email,
				'teams'            => array_map( array( $this, 'serialize_team' ), $teams ),
				'selected_team_id' => $selected_team_id,
				'items'            => array_map( array( $this, 'serialize_reminder' ), $items ),
			),
			200
		);
	}

	/**
	 * Create or update a reminder from headless portal.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_portal_save_reminder( \WP_REST_Request $request ) {
		if ( ! $this->is_frontend_portal_enabled() ) {
			return new \WP_Error( 'portal_disabled', __( 'Frontend reminder submission is disabled.', 'reminder-manager' ), array( 'status' => 403 ) );
		}

		if ( ! $this->has_valid_portal_api_key( $request ) ) {
			return new \WP_Error( 'invalid_api_key', __( 'Invalid API key.', 'reminder-manager' ), array( 'status' => 403 ) );
		}

		$identity = $this->verify_portal_identity( (string) $request->get_param( 'id_token' ) );
		if ( is_wp_error( $identity ) ) {
			$identity->add_data( array( 'status' => 403 ) );
			return $identity;
		}

		$user_id      = (int) $identity['user_id'];
		$email        = (string) $identity['email'];
		$team_service = Team_Service::get_instance();
		$team_id      = (int) $request->get_param( 'team_id' );

		if ( ! $team_service->user_can_access_team( $team_id, $user_id, $email ) ) {
			return new \WP_Error( 'forbidden_team', __( 'You can only create reminders in your teams.', 'reminder-manager' ), array( 'status' => 403 ) );
		}

		$quick_hours = absint( $request->get_param( 'quick_hours' ) );
		$remind_at   = $this->normalize_datetime_input( (string) $request->get_param( 'reminder_datetime' ) );
		if ( $quick_hours > 0 ) {
			if ( ! $team_service->is_quick_hour_allowed( $team_id, $quick_hours ) ) {
				return new \WP_Error( 'invalid_quick_hours', __( 'Invalid quick schedule hour value.', 'reminder-manager' ), array( 'status' => 400 ) );
			}
			$remind_at = gmdate( 'Y-m-d H:i:s', time() + ( $quick_hours * HOUR_IN_SECONDS ) );
		}

		if ( '' === sanitize_text_field( (string) $request->get_param( 'title' ) ) ) {
			return new \WP_Error( 'invalid_title', __( 'Title is required.', 'reminder-manager' ), array( 'status' => 400 ) );
		}

		if ( '' === $remind_at || ! strtotime( $remind_at . ' UTC' ) ) {
			return new \WP_Error( 'invalid_datetime', __( 'A valid date and time is required.', 'reminder-manager' ), array( 'status' => 400 ) );
		}

		$link = (string) $request->get_param( 'link' );
		if ( '' !== $link && ! filter_var( $link, FILTER_VALIDATE_URL ) ) {
			return new \WP_Error( 'invalid_link', __( 'Task link must be a valid URL.', 'reminder-manager' ), array( 'status' => 400 ) );
		}

		$data = array(
			'team_id'       => $team_id,
			'title'         => (string) $request->get_param( 'title' ),
			'remind_at'     => $remind_at,
			'task_link'     => $link,
			'comments'      => (string) $request->get_param( 'comments' ),
			'user_id'       => $user_id,
			'member_email'  => $email,
			'_actor_user_id'=> $user_id,
			'_actor_email'  => $email,
		);

		$service = Reminder_Service::get_instance();
		$id      = absint( $request->get_param( 'id' ) );

		if ( $id > 0 ) {
			$existing = $service->get( $id );
			if ( ! $existing || ! $team_service->user_can_access_team( (int) $existing->team_id, $user_id, $email ) ) {
				return new \WP_Error( 'forbidden_edit', __( 'You can only edit reminders from your teams.', 'reminder-manager' ), array( 'status' => 403 ) );
			}

			if ( 'sent' === $existing->status || 'completed' === $existing->status ) {
				return new \WP_Error( 'immutable_status', __( 'Sent/completed reminders cannot be edited.', 'reminder-manager' ), array( 'status' => 400 ) );
			}

			$updated = $service->update( $id, $data );
			if ( ! $updated ) {
				return new \WP_Error( 'update_failed', __( 'Could not update reminder.', 'reminder-manager' ), array( 'status' => 500 ) );
			}

			$reminder = $service->get( $id );
			return new \WP_REST_Response(
				array(
					'mode'     => 'updated',
					'reminder' => $reminder ? $this->serialize_reminder( $reminder ) : null,
				),
				200
			);
		}

		$new_id = $service->create( $data );
		if ( false === $new_id ) {
			return new \WP_Error( 'create_failed', __( 'Could not create reminder.', 'reminder-manager' ), array( 'status' => 500 ) );
		}

		$reminder = $service->get( (int) $new_id );
		return new \WP_REST_Response(
			array(
				'mode'        => 'created',
				'reminder_id' => (int) $new_id,
				'reminder'    => $reminder ? $this->serialize_reminder( $reminder ) : null,
			),
			200
		);
	}

	/**
	 * Check whether frontend portal setting is enabled.
	 *
	 * @return bool
	 */
	private function is_frontend_portal_enabled(): bool {
		$options = get_option( self::SETTINGS_OPTION, array() );
		return ! empty( $options['frontend_enabled'] );
	}

	/**
	 * Validate shared API key sent by proxy.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool
	 */
	private function has_valid_portal_api_key( \WP_REST_Request $request ): bool {
		$options = get_option( self::SETTINGS_OPTION, array() );
		$stored  = isset( $options['frontend_api_key'] ) ? (string) $options['frontend_api_key'] : '';

		if ( '' === $stored ) {
			return false;
		}

		$sent = (string) $request->get_header( 'x-trt-api-key' );
		if ( '' === $sent ) {
			$sent = (string) $request->get_header( 'X-TRT-API-Key' );
		}

		return '' !== $sent && hash_equals( $stored, $sent );
	}

	/**
	 * Validate Google token identity and domain.
	 *
	 * @param string $id_token Google ID token.
	 * @return array|\WP_Error
	 */
	private function verify_portal_identity( string $id_token ) {
		$options   = get_option( self::SETTINGS_OPTION, array() );
		$client_id = isset( $options['frontend_google_client_id'] ) ? (string) $options['frontend_google_client_id'] : '';

		$email = $this->verify_google_token( $id_token, $client_id );
		if ( is_wp_error( $email ) ) {
			return $email;
		}

		if ( ! $this->is_email_domain_allowed( (string) $email ) ) {
			return new \WP_Error( 'domain_not_allowed', __( 'Your email domain is not allowed.', 'reminder-manager' ) );
		}

		$user    = get_user_by( 'email', (string) $email );
		$user_id = $user ? (int) $user->ID : 0;

		return array(
			'email'   => (string) $email,
			'user_id' => $user_id,
		);
	}

	/**
	 * Verify Google ID token and return verified email.
	 *
	 * @param string $id_token Google ID token.
	 * @param string $client_id Expected OAuth client ID.
	 * @return string|\WP_Error
	 */
	private function verify_google_token( string $id_token, string $client_id ) {
		if ( '' === $id_token || '' === $client_id ) {
			return new \WP_Error( 'invalid_token', __( 'Missing token or client ID.', 'reminder-manager' ) );
		}

		$response = wp_remote_get(
			'https://oauth2.googleapis.com/tokeninfo?id_token=' . rawurlencode( $id_token ),
			array( 'timeout' => 10, 'sslverify' => true )
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $code || ! is_array( $body ) ) {
			return new \WP_Error( 'invalid_google_response', __( 'Google token verification failed.', 'reminder-manager' ) );
		}

		$aud            = isset( $body['aud'] ) ? (string) $body['aud'] : '';
		$email          = isset( $body['email'] ) ? sanitize_email( (string) $body['email'] ) : '';
		$email_verified = isset( $body['email_verified'] ) ? (string) $body['email_verified'] : 'false';

		if ( $aud !== $client_id ) {
			return new \WP_Error( 'invalid_audience', __( 'Invalid Google token audience.', 'reminder-manager' ) );
		}
		if ( '' === $email || 'true' !== strtolower( $email_verified ) ) {
			return new \WP_Error( 'invalid_email', __( 'Google account email is not verified.', 'reminder-manager' ) );
		}

		return $email;
	}

	/**
	 * Check if email domain is allowed.
	 *
	 * @param string $email Email address.
	 * @return bool
	 */
	private function is_email_domain_allowed( string $email ): bool {
		$domain = strtolower( (string) substr( strrchr( $email, '@' ) ?: '', 1 ) );
		if ( '' === $domain ) {
			return false;
		}

		return in_array( $domain, $this->get_allowed_domains(), true );
	}

	/**
	 * Get configured allowed domains.
	 *
	 * @return string[]
	 */
	private function get_allowed_domains(): array {
		$options = get_option( self::SETTINGS_OPTION, array() );
		$raw     = isset( $options['frontend_allowed_domains'] ) ? (string) $options['frontend_allowed_domains'] : 'example.com';
		$parts   = preg_split( '/[\s,]+/', strtolower( $raw ) );
		$domains = array();

		foreach ( $parts as $part ) {
			$domain = ltrim( trim( (string) $part ), '@' );
			if ( '' === $domain || false === strpos( $domain, '.' ) ) {
				continue;
			}
			$domains[] = $domain;
		}

		if ( empty( $domains ) ) {
			$domains[] = 'example.com';
		}

		return array_values( array_unique( $domains ) );
	}

	/**
	 * Normalize date/time input into MySQL datetime format.
	 *
	 * @param string $value Input date string.
	 * @return string
	 */
	private function normalize_datetime_input( string $value ): string {
		$value = trim( $value );

		$utc = \DateTime::createFromFormat( 'Y-m-d\\TH:i', $value, new \DateTimeZone( 'UTC' ) );
		if ( $utc instanceof \DateTime ) {
			return $utc->format( 'Y-m-d H:i:s' );
		}

		$timestamp = strtotime( $value . ' UTC' );
		if ( false === $timestamp ) {
			return $value;
		}

		return gmdate( 'Y-m-d H:i:s', $timestamp );
	}

	/**
	 * Convert reminder model to API response object.
	 *
	 * @param Reminder $reminder Reminder instance.
	 * @return array
	 */
	private function serialize_reminder( Reminder $reminder ): array {
		$timestamp = strtotime( (string) $reminder->remind_at . ' UTC' );
		$datetime  = $timestamp ? gmdate( 'Y-m-d\\TH:i', $timestamp ) : '';
		$display   = $timestamp ? gmdate( 'Y-m-d H:i:s', $timestamp ) . ' UTC' : (string) $reminder->remind_at;

		$added_by = '';
		if ( $reminder->user_id > 0 ) {
			$user = get_userdata( $reminder->user_id );
			if ( $user ) {
				$email    = '' !== $reminder->member_email ? $reminder->member_email : $user->user_email;
				$added_by = $user->display_name . ' (' . $email . ')';
			} elseif ( '' !== $reminder->member_email ) {
				$added_by = $reminder->member_email;
			}
		} elseif ( '' !== $reminder->member_email ) {
			$added_by = $reminder->member_email;
		}

		return array(
			'id'             => (int) $reminder->id,
			'team_id'        => (int) $reminder->team_id,
			'title'          => (string) $reminder->title,
			'remind_at'      => (string) $reminder->remind_at,
			'datetime_local' => $datetime,
			'display_time'   => $display,
			'task_link'      => (string) $reminder->task_link,
			'comments'       => (string) $reminder->comments,
			'status'         => (string) $reminder->status,
			'attempts'       => (int) $reminder->attempts,
			'added_by'       => $added_by,
		);
	}

	/**
	 * Convert team model to API response object.
	 *
	 * @param Team $team Team model.
	 * @return array
	 */
	private function serialize_team( Team $team ): array {
		return array(
			'id'          => (int) $team->id,
			'name'        => (string) $team->name,
			'quick_hours' => Team_Service::get_instance()->get_team_quick_schedule_hours( (int) $team->id ),
		);
	}
}
