<?php
/**
 * Frontend reminder portal service (Google-authenticated, team-scoped).
 *
 * @package Aditya\NotifyCrew
 */

namespace Aditya\NotifyCrew\Services;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Aditya\NotifyCrew\Models\Team;

/**
 * Class Frontend_Service
 */
class Frontend_Service {

	/** Settings option name. */
	const SETTINGS_OPTION = 'ncrw_options';

	/** Shortcode tag. */
	const SHORTCODE = 'ncrw_frontend_reminder_form';

	/** View shortcode tag. */
	const SHORTCODE_VIEW = 'ncrw_upcoming_reminders';

	/**
	 * Singleton instance.
	 *
	 * @var Frontend_Service|null
	 */
	private static $instance = null;

	/** @return Frontend_Service */
	public static function get_instance(): Frontend_Service {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/**
	 * Register frontend hooks.
	 */
	public function register(): void {
		add_shortcode( self::SHORTCODE, array( $this, 'render_shortcode' ) );

		add_action( 'wp_ajax_ncrw_frontend_list_reminders', array( $this, 'handle_ajax_list_reminders' ) );
		add_action( 'wp_ajax_nopriv_ncrw_frontend_list_reminders', array( $this, 'handle_ajax_list_reminders' ) );

		add_action( 'wp_ajax_ncrw_frontend_save_reminder', array( $this, 'handle_ajax_save_reminder' ) );
		add_action( 'wp_ajax_nopriv_ncrw_frontend_save_reminder', array( $this, 'handle_ajax_save_reminder' ) );

		add_shortcode( self::SHORTCODE_VIEW, array( $this, 'render_shortcode_view' ) );

		add_action( 'wp_ajax_ncrw_frontend_view_reminders', array( $this, 'handle_ajax_view_reminders' ) );
		add_action( 'wp_ajax_nopriv_ncrw_frontend_view_reminders', array( $this, 'handle_ajax_view_reminders' ) );
	}

	/**
	 * Shortcode renderer.
	 *
	 * @return string
	 */
	public function render_shortcode(): string {
		$options = $this->get_options();

		if ( empty( $options['frontend_enabled'] ) ) {
			return '<p>' . esc_html__( 'Frontend reminders are currently disabled.', 'notifycrew' ) . '</p>';
		}

		$client_id = isset( $options['frontend_google_client_id'] ) ? (string) $options['frontend_google_client_id'] : '';
		if ( '' === $client_id ) {
			return '<p>' . esc_html__( 'Frontend reminders are not configured yet. Please set a Google OAuth Client ID in plugin settings.', 'notifycrew' ) . '</p>';
		}

		$this->enqueue_assets( $client_id );
		$default_datetime = gmdate( 'Y-m-d\TH:i' );

		ob_start();
		?>
		<section class="ncrw-portal" data-ncrw-portal="1">
			<div class="ncrw-orb ncrw-orb--1" aria-hidden="true"></div>
			<div class="ncrw-orb ncrw-orb--2" aria-hidden="true"></div>
			<div class="ncrw-orb ncrw-orb--3" aria-hidden="true"></div>

			<div class="ncrw-portal-shell">
				<header class="ncrw-portal-header">
					<div class="ncrw-header-badge"><?php esc_html_e( 'Team Reminders', 'notifycrew' ); ?></div>
					<h2><?php esc_html_e( 'Reminder Portal', 'notifycrew' ); ?></h2>
					<p><?php esc_html_e( 'Sign in with your company Google account to create and manage team reminders.', 'notifycrew' ); ?></p>
				</header>

				<div id="g_id_onload"
					data-client_id="<?php echo esc_attr( $client_id ); ?>"
					data-callback="ncrwOnGoogleCallback"
					data-auto_prompt="false">
				</div>

				<div class="ncrw-auth-gate" id="ncrw-auth-gate">
					<div class="ncrw-auth-card">
						<div class="ncrw-auth-icon" aria-hidden="true">
							<svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
						</div>
						<h3><?php esc_html_e( 'Secure Access', 'notifycrew' ); ?></h3>
						<p><?php esc_html_e( 'Use Google Sign-In to access teams linked to your account.', 'notifycrew' ); ?></p>
						<div class="ncrw-google-btn-wrap">
							<div class="g_id_signin"
								data-type="standard"
								data-shape="pill"
								data-theme="filled_blue"
								data-text="signin_with"
								data-size="large"
								data-logo_alignment="left">
							</div>
						</div>
					</div>
				</div>

				<div class="ncrw-portal-app" id="ncrw-portal-app" hidden>
					<div class="ncrw-toolbar">
						<div>
							<p class="ncrw-toolbar-label"><?php esc_html_e( 'Logged in as', 'notifycrew' ); ?></p>
							<p class="ncrw-toolbar-email" id="ncrw-user-email"></p>
						</div>
						<button type="button" class="button" id="ncrw-sign-out"><?php esc_html_e( 'Sign Out', 'notifycrew' ); ?></button>
					</div>

					<div id="ncrw-portal-notice" class="ncrw-frontend-notice" hidden></div>

					<div class="ncrw-grid">
						<div class="ncrw-panel">
							<h3 id="ncrw-form-title"><?php esc_html_e( 'Create Reminder', 'notifycrew' ); ?></h3>
							<form id="ncrw-reminder-form" class="ncrw-frontend-form">
								<input type="hidden" id="ncrw_front_id" name="id" value="0"/>
								<input type="hidden" id="ncrw_google_id_token" name="ncrw_google_id_token" value=""/>
								<input type="hidden" name="ncrw_frontend_nonce" value="<?php echo esc_attr( wp_create_nonce( 'ncrw_frontend_ajax_nonce' ) ); ?>"/>

								<label for="ncrw_front_team_id"><?php esc_html_e( 'Team', 'notifycrew' ); ?> *</label>
								<select id="ncrw_front_team_id" name="ncrw_team_id" required></select>

								<label for="ncrw_front_title"><?php esc_html_e( 'Title', 'notifycrew' ); ?> *</label>
								<input type="text" id="ncrw_front_title" name="ncrw_title" required/>

								<label for="ncrw_front_datetime"><?php esc_html_e( 'Scheduled Date & Time', 'notifycrew' ); ?> *</label>
								<select id="ncrw_front_quick_hours" name="ncrw_quick_hours">
									<option value="0"><?php esc_html_e( 'Custom date & time', 'notifycrew' ); ?></option>
								</select>
								<input type="datetime-local" id="ncrw_front_datetime" name="ncrw_reminder_datetime" value="<?php echo esc_attr( $default_datetime ); ?>"/>

								<label for="ncrw_front_link"><?php esc_html_e( 'Task/Ticket/Slack Link', 'notifycrew' ); ?></label>
								<input type="url" id="ncrw_front_link" name="ncrw_link"/>

								<label for="ncrw_front_comments"><?php esc_html_e( 'Comments', 'notifycrew' ); ?></label>
								<textarea id="ncrw_front_comments" name="ncrw_comments" rows="4"></textarea>

								<div class="ncrw-actions">
									<button type="submit" class="button button-primary" id="ncrw-front-submit"><?php esc_html_e( 'Save Reminder', 'notifycrew' ); ?></button>
									<button type="button" class="button" id="ncrw-front-cancel" hidden><?php esc_html_e( 'Cancel Edit', 'notifycrew' ); ?></button>
								</div>
							</form>
						</div>

						<div class="ncrw-panel">
							<div class="ncrw-list-header" style="flex-direction: column; align-items: stretch; gap: 10px;">
								<div style="display: flex; align-items: center; justify-content: space-between; gap: 10px;">
									<h3 style="margin-bottom: 0;"><?php esc_html_e( 'Your Reminders', 'notifycrew' ); ?></h3>
									<button type="button" class="button" id="ncrw-refresh-list"><?php esc_html_e( 'Refresh', 'notifycrew' ); ?></button>
								</div>
								<div class="ncrw-view-controls" id="ncrw-main-filters" style="margin-bottom: 0; border-bottom: none; padding-bottom: 0;">
									<div class="ncrw-filter-tabs" id="ncrw-main-status-tabs" role="tablist">
										<button type="button" class="ncrw-tab ncrw-tab--active" data-status="" role="tab"><?php esc_html_e( 'All', 'notifycrew' ); ?></button>
										<button type="button" class="ncrw-tab" data-status="pending" role="tab"><?php esc_html_e( 'Pending', 'notifycrew' ); ?></button>
										<button type="button" class="ncrw-tab" data-status="sent" role="tab"><?php esc_html_e( 'Sent', 'notifycrew' ); ?></button>
										<button type="button" class="ncrw-tab" data-status="failed" role="tab"><?php esc_html_e( 'Failed', 'notifycrew' ); ?></button>
									</div>
									<div class="ncrw-sort-controls">
										<label for="ncrw-main-orderby" class="ncrw-sort-label"><?php esc_html_e( 'Sort by', 'notifycrew' ); ?></label>
										<select id="ncrw-main-orderby" class="ncrw-sort-select">
											<option value="remind_at"><?php esc_html_e( 'Trigger Date', 'notifycrew' ); ?></option>
											<option value="created_at"><?php esc_html_e( 'Date Added', 'notifycrew' ); ?></option>
											<option value="title"><?php esc_html_e( 'Title (A–Z)', 'notifycrew' ); ?></option>
											<option value="status"><?php esc_html_e( 'Status', 'notifycrew' ); ?></option>
										</select>
										<button type="button" id="ncrw-main-order-toggle" class="ncrw-order-btn" data-order="ASC" aria-label="<?php esc_attr_e( 'Toggle sort direction', 'notifycrew' ); ?>">
											<span class="ncrw-order-icon" aria-hidden="true">↑</span>
											<span class="ncrw-order-label"><?php esc_html_e( 'ASC', 'notifycrew' ); ?></span>
										</button>
									</div>
								</div>
							</div>
							<div id="ncrw-reminders-list" class="ncrw-reminders-list"></div>
						</div>
					</div>
				</div>
			</div>
		</section>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * AJAX: list reminders for authenticated frontend user.
	 */
	public function handle_ajax_list_reminders(): void {
		$this->assert_frontend_enabled();
		check_ajax_referer( 'ncrw_frontend_ajax_nonce', 'ncrw_frontend_nonce' );

		$identity = $this->verify_request_identity();
		if ( is_wp_error( $identity ) ) {
			wp_send_json_error( array( 'message' => $identity->get_error_message() ), 403 );
		}

		$user_id      = (int) $identity['user_id'];
		$email        = (string) $identity['email'];
		$team_service = Team_Service::get_instance();
		$teams        = $team_service->get_teams_for_identity( $user_id, $email );
		if ( empty( $teams ) ) {
			wp_send_json_error( array( 'message' => __( 'No teams are assigned to your account.', 'notifycrew' ) ), 403 );
		}

		$requested_team_id = absint( $_POST['ncrw_team_id'] ?? 0 );
		$selected_team_id  = $requested_team_id;
		if ( 0 === $selected_team_id || ! $team_service->user_can_access_team( $selected_team_id, $user_id, $email ) ) {
			$selected_team_id = (int) $teams[0]->id;
		}

		$allowed_orderby = array( 'id', 'team_id', 'user_id', 'title', 'remind_at', 'status', 'attempts', 'created_at' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified above via check_ajax_referer.
		$orderby       = sanitize_key( wp_unslash( $_POST['ncrw_orderby'] ?? 'remind_at' ) );
		$orderby       = in_array( $orderby, $allowed_orderby, true ) ? $orderby : 'remind_at';
		$raw_order     = strtoupper( sanitize_key( wp_unslash( $_POST['ncrw_order'] ?? 'ASC' ) ) );
		$order         = 'DESC' === $raw_order ? 'DESC' : 'ASC';
		$status_filter = sanitize_key( wp_unslash( $_POST['ncrw_status'] ?? '' ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$allowed_statuses = array( '', 'pending', 'sent', 'failed', 'completed' );
		if ( ! in_array( $status_filter, $allowed_statuses, true ) ) {
			$status_filter = '';
		}

		$list_args = array(
			'team_id'        => $selected_team_id,
			'per_page'       => 200,
			'page'           => 1,
			'orderby'        => $orderby,
			'order'          => $order,
			'_actor_user_id' => $user_id,
			'_actor_email'   => $email,
		);
		if ( '' !== $status_filter ) {
			$list_args['status'] = $status_filter;
		}

		$items  = Reminder_Service::get_instance()->get_list( $list_args );
		$output = array_map( array( $this, 'serialize_reminder' ), $items );

		wp_send_json_success(
			array(
				'email'            => $email,
				'teams'            => array_map( array( $this, 'serialize_team' ), $teams ),
				'selected_team_id' => $selected_team_id,
				'items'            => $output,
			)
		);
	}

	/**
	 * AJAX: create or update reminder for authenticated frontend user.
	 */
	public function handle_ajax_save_reminder(): void {
		$this->assert_frontend_enabled();
		check_ajax_referer( 'ncrw_frontend_ajax_nonce', 'ncrw_frontend_nonce' );

		$identity = $this->verify_request_identity();
		if ( is_wp_error( $identity ) ) {
			wp_send_json_error( array( 'message' => $identity->get_error_message() ), 403 );
		}

		$user_id      = (int) $identity['user_id'];
		$email        = (string) $identity['email'];
		$team_service = Team_Service::get_instance();

		$service              = Reminder_Service::get_instance();
		$data                 = $this->extract_form_data();
		$data['user_id']      = $user_id;
		$data['member_email'] = $email;
		$errors               = $this->validate_form_data( $data, $user_id, $email );
		if ( ! empty( $errors ) ) {
			wp_send_json_error( array( 'message' => __( 'Please complete required fields with valid values.', 'notifycrew' ) ), 400 );
		}

		$id = absint( $_POST['id'] ?? 0 );
		if ( $id > 0 ) {
			$existing = $service->get( $id );
			if ( ! $existing || ! $team_service->user_can_access_team( (int) $existing->team_id, $user_id, $email ) ) {
				wp_send_json_error( array( 'message' => __( 'You can only edit reminders from your teams.', 'notifycrew' ) ), 403 );
			}

			if ( 'sent' === $existing->status || 'completed' === $existing->status ) {
				wp_send_json_error( array( 'message' => __( 'Sent/completed reminders cannot be edited from frontend.', 'notifycrew' ) ), 400 );
			}

			$data['_actor_user_id'] = $user_id;
			$data['_actor_email']   = $email;
			$updated                = $service->update( $id, $data );
			if ( ! $updated ) {
				wp_send_json_error( array( 'message' => __( 'Could not update reminder.', 'notifycrew' ) ), 500 );
			}

			$reminder = $service->get( $id );
			wp_send_json_success(
				array(
					'mode'     => 'updated',
					'reminder' => $reminder ? $this->serialize_reminder( $reminder ) : null,
				)
			);
		}

		$new_id = $service->create( $data );
		if ( false === $new_id ) {
			wp_send_json_error( array( 'message' => __( 'Could not create reminder.', 'notifycrew' ) ), 500 );
		}

		$reminder = $service->get( (int) $new_id );
		wp_send_json_success(
			array(
				'mode'        => 'created',
				'reminder_id' => (int) $new_id,
				'reminder'    => $reminder ? $this->serialize_reminder( $reminder ) : null,
			)
		);
	}

	/**
	 * Render the upcoming reminders view shortcode.
	 *
	 * @return string
	 */
	public function render_shortcode_view(): string {
		$options = $this->get_options();

		if ( empty( $options['frontend_enabled'] ) ) {
			return '<p>' . esc_html__( 'Frontend reminders are currently disabled.', 'notifycrew' ) . '</p>';
		}

		$client_id = isset( $options['frontend_google_client_id'] ) ? (string) $options['frontend_google_client_id'] : '';
		if ( '' === $client_id ) {
			return '<p>' . esc_html__( 'Frontend reminders are not configured yet. Please set a Google OAuth Client ID in plugin settings.', 'notifycrew' ) . '</p>';
		}

		$this->enqueue_view_assets( $client_id );

		ob_start();
		?>
		<section class="ncrw-portal ncrw-view-portal" data-ncrw-view="1">
			<div class="ncrw-orb ncrw-orb--1" aria-hidden="true"></div>
			<div class="ncrw-orb ncrw-orb--2" aria-hidden="true"></div>
			<div class="ncrw-orb ncrw-orb--3" aria-hidden="true"></div>

			<div class="ncrw-portal-shell">
				<header class="ncrw-portal-header">
					<div class="ncrw-header-badge"><?php esc_html_e( 'Team Reminders', 'notifycrew' ); ?></div>
					<h2><?php esc_html_e( 'Upcoming Reminders', 'notifycrew' ); ?></h2>
					<p><?php esc_html_e( 'Sign in with your company Google account to view your team\'s scheduled reminders.', 'notifycrew' ); ?></p>
				</header>

				<div id="g_id_onload"
					data-client_id="<?php echo esc_attr( $client_id ); ?>"
					data-callback="ncrwOnViewCallback"
					data-auto_prompt="false">
				</div>

				<div class="ncrw-auth-gate" id="ncrw-view-auth-gate">
					<div class="ncrw-auth-card">
						<div class="ncrw-auth-icon" aria-hidden="true">
							<svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></svg>
						</div>
						<h3><?php esc_html_e( 'View Reminders', 'notifycrew' ); ?></h3>
						<p><?php esc_html_e( 'Sign in with Google to see your team\'s upcoming reminders.', 'notifycrew' ); ?></p>
						<div class="ncrw-google-btn-wrap">
							<div class="g_id_signin"
								data-type="standard"
								data-shape="pill"
								data-theme="filled_blue"
								data-text="signin_with"
								data-size="large"
								data-logo_alignment="left">
							</div>
						</div>
					</div>
				</div>

				<div class="ncrw-view-app" id="ncrw-view-app" hidden>
					<div class="ncrw-toolbar">
						<div class="ncrw-toolbar-left">
							<div>
								<p class="ncrw-toolbar-label"><?php esc_html_e( 'Logged in as', 'notifycrew' ); ?></p>
								<p class="ncrw-toolbar-email" id="ncrw-view-user-email"></p>
							</div>
							<select id="ncrw-view-team" class="ncrw-toolbar-team" aria-label="<?php esc_attr_e( 'Select team', 'notifycrew' ); ?>"></select>
						</div>
						<button type="button" class="button" id="ncrw-view-sign-out"><?php esc_html_e( 'Sign Out', 'notifycrew' ); ?></button>
					</div>

					<div id="ncrw-view-notice" class="ncrw-frontend-notice" hidden></div>

					<div class="ncrw-view-panel">
						<div class="ncrw-view-controls">
							<div class="ncrw-filter-tabs" id="ncrw-view-status-tabs" role="tablist">
								<button type="button" class="ncrw-tab ncrw-tab--active" data-status="" role="tab"><?php esc_html_e( 'All', 'notifycrew' ); ?></button>
								<button type="button" class="ncrw-tab" data-status="pending" role="tab"><?php esc_html_e( 'Pending', 'notifycrew' ); ?></button>
								<button type="button" class="ncrw-tab" data-status="sent" role="tab"><?php esc_html_e( 'Sent', 'notifycrew' ); ?></button>
								<button type="button" class="ncrw-tab" data-status="failed" role="tab"><?php esc_html_e( 'Failed', 'notifycrew' ); ?></button>
							</div>
							<div class="ncrw-sort-controls">
								<label for="ncrw-view-orderby" class="ncrw-sort-label"><?php esc_html_e( 'Sort by', 'notifycrew' ); ?></label>
								<select id="ncrw-view-orderby" class="ncrw-sort-select">
									<option value="remind_at"><?php esc_html_e( 'Trigger Date', 'notifycrew' ); ?></option>
									<option value="created_at"><?php esc_html_e( 'Date Added', 'notifycrew' ); ?></option>
									<option value="title"><?php esc_html_e( 'Title (A–Z)', 'notifycrew' ); ?></option>
									<option value="status"><?php esc_html_e( 'Status', 'notifycrew' ); ?></option>
								</select>
								<button type="button" id="ncrw-view-order-toggle" class="ncrw-order-btn" data-order="ASC" aria-label="<?php esc_attr_e( 'Toggle sort direction', 'notifycrew' ); ?>">
									<span class="ncrw-order-icon" aria-hidden="true">↑</span>
									<span class="ncrw-order-label"><?php esc_html_e( 'ASC', 'notifycrew' ); ?></span>
								</button>
								<button type="button" id="ncrw-view-refresh" class="ncrw-refresh-btn">
									<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M1 4v6h6"/><path d="M23 20v-6h-6"/><path d="M20.49 9A9 9 0 0 0 5.64 5.64L1 10m22 4-4.64 4.36A9 9 0 0 1 3.51 15"/></svg>
									<?php esc_html_e( 'Refresh', 'notifycrew' ); ?>
								</button>
							</div>
						</div>

						<div id="ncrw-view-count" class="ncrw-view-count" hidden></div>
						<div id="ncrw-view-list" class="ncrw-view-list" aria-live="polite"></div>
					</div>
				</div>
			</div>
		</section>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * AJAX: list reminders for the view portal (with sorting and status filter).
	 */
	public function handle_ajax_view_reminders(): void {
		$this->assert_frontend_enabled();
		check_ajax_referer( 'ncrw_view_ajax_nonce', 'ncrw_view_nonce' );

		$identity = $this->verify_request_identity();
		if ( is_wp_error( $identity ) ) {
			wp_send_json_error( array( 'message' => $identity->get_error_message() ), 403 );
		}

		$user_id      = (int) $identity['user_id'];
		$email        = (string) $identity['email'];
		$team_service = Team_Service::get_instance();
		$teams        = $team_service->get_teams_for_identity( $user_id, $email );
		if ( empty( $teams ) ) {
			wp_send_json_error( array( 'message' => __( 'No teams are assigned to your account.', 'notifycrew' ) ), 403 );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified above via check_ajax_referer.
		$requested_team_id = absint( $_POST['ncrw_team_id'] ?? 0 );
		$selected_team_id  = $requested_team_id;
		if ( 0 === $selected_team_id || ! $team_service->user_can_access_team( $selected_team_id, $user_id, $email ) ) {
			$selected_team_id = (int) $teams[0]->id;
		}

		$allowed_orderby = array( 'remind_at', 'created_at', 'title', 'status' );
		$orderby         = sanitize_key( wp_unslash( $_POST['ncrw_orderby'] ?? 'remind_at' ) );
		$orderby         = in_array( $orderby, $allowed_orderby, true ) ? $orderby : 'remind_at';

		$raw_order = strtoupper( sanitize_key( wp_unslash( $_POST['ncrw_order'] ?? 'ASC' ) ) );
		$order     = 'DESC' === $raw_order ? 'DESC' : 'ASC';

		$status_filter    = sanitize_key( wp_unslash( $_POST['ncrw_status'] ?? '' ) );
		$allowed_statuses = array( '', 'pending', 'sent', 'failed', 'completed' );
		if ( ! in_array( $status_filter, $allowed_statuses, true ) ) {
			$status_filter = '';
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$list_args = array(
			'team_id'        => $selected_team_id,
			'per_page'       => 500,
			'page'           => 1,
			'orderby'        => $orderby,
			'order'          => $order,
			'_actor_user_id' => $user_id,
			'_actor_email'   => $email,
		);
		if ( '' !== $status_filter ) {
			$list_args['status'] = $status_filter;
		}

		$items  = Reminder_Service::get_instance()->get_list( $list_args );
		$output = array_map( array( $this, 'serialize_reminder_view' ), $items );

		wp_send_json_success(
			array(
				'email'            => $email,
				'teams'            => array_map( array( $this, 'serialize_team' ), $teams ),
				'selected_team_id' => $selected_team_id,
				'items'            => $output,
				'total'            => count( $output ),
			)
		);
	}

	/**
	 * Enqueue frontend assets.
	 *
	 * @param string $client_id Google client ID.
	 */
	private function enqueue_assets( string $client_id ): void {
		wp_enqueue_style(
			'ncrw-frontend',
			NCRW_URL . 'assets/css/frontend.css',
			array(),
			NCRW_VERSION
		);

		wp_enqueue_script(
			'ncrw-google-identity',
			'https://accounts.google.com/gsi/client',
			array(),
			NCRW_VERSION,
			false
		);

		wp_enqueue_script(
			'ncrw-frontend',
			NCRW_URL . 'assets/js/frontend.js',
			array(),
			NCRW_VERSION,
			true
		);

		wp_localize_script(
			'ncrw-frontend',
			'ncrwFrontend',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'ncrw_frontend_ajax_nonce' ),
				'i18n'    => array(
					'signedIn'       => __( 'Signed in as', 'notifycrew' ),
					'signInError'    => __( 'Google Sign-In failed. Please refresh and try again.', 'notifycrew' ),
					'sessionExpired' => __( 'Your session expired. Please sign in again.', 'notifycrew' ),
					'emptyState'     => __( 'No reminders yet. Create your first one on the left.', 'notifycrew' ),
					'edit'           => __( 'Edit', 'notifycrew' ),
					'createTitle'    => __( 'Create Reminder', 'notifycrew' ),
					'editTitle'      => __( 'Edit Reminder', 'notifycrew' ),
					'createdMessage' => __( 'Reminder created successfully.', 'notifycrew' ),
					'updatedMessage' => __( 'Reminder updated successfully.', 'notifycrew' ),
				),
			)
		);
	}

	/**
	 * Ensure frontend portal is enabled.
	 */
	private function assert_frontend_enabled(): void {
		$options = $this->get_options();
		if ( empty( $options['frontend_enabled'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Frontend reminder submission is disabled.', 'notifycrew' ) ), 403 );
		}
	}

	/**
	 * Verify Google identity from incoming token.
	 *
	 * @return array|\WP_Error
	 */
	private function verify_request_identity() {
		$options = $this->get_options();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce is verified in the calling AJAX handler before this method is invoked.
		$token = isset( $_POST['ncrw_google_id_token'] ) ? sanitize_text_field( wp_unslash( $_POST['ncrw_google_id_token'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( '' === $token ) {
			return new \WP_Error( 'missing_token', __( 'Google authentication token is required.', 'notifycrew' ) );
		}

		$email = $this->verify_google_token( $token, (string) ( $options['frontend_google_client_id'] ?? '' ) );
		if ( is_wp_error( $email ) ) {
			return $email;
		}

		if ( ! $this->is_email_domain_allowed( (string) $email ) ) {
			return new \WP_Error( 'domain_not_allowed', __( 'Your email domain is not allowed.', 'notifycrew' ) );
		}

		$user    = get_user_by( 'email', (string) $email );
		$user_id = $user ? (int) $user->ID : 0;

		return array(
			'email'   => (string) $email,
			'user_id' => $user_id,
		);
	}

	/**
	 * Convert reminder model to frontend-safe response object.
	 *
	 * @param \Aditya\NotifyCrew\Models\Reminder $reminder Reminder instance.
	 * @return array
	 */
	private function serialize_reminder( $reminder ): array {
		$timestamp = strtotime( (string) $reminder->remind_at . ' UTC' );
		$datetime  = $timestamp ? gmdate( 'Y-m-d\TH:i', $timestamp ) : '';
		$display   = $timestamp ? gmdate( 'Y-m-d H:i:s', $timestamp ) . ' UTC' : (string) $reminder->remind_at;

		$added_by = '';
		if ( $reminder->user_id > 0 ) {
			$user = get_userdata( $reminder->user_id );
			if ( $user ) {
				$email    = ! empty( $reminder->member_email ) ? $reminder->member_email : $user->user_email;
				$added_by = $user->display_name . ' (' . $email . ')';
			} elseif ( ! empty( $reminder->member_email ) ) {
				$added_by = $reminder->member_email;
			}
		} elseif ( ! empty( $reminder->member_email ) ) {
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
	 * Convert team model to frontend-safe shape.
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

	/**
	 * Enqueue assets for the view portal shortcode.
	 *
	 * @param string $client_id Google OAuth client ID.
	 */
	private function enqueue_view_assets( string $client_id ): void {
		wp_enqueue_style(
			'ncrw-frontend',
			NCRW_URL . 'assets/css/frontend.css',
			array(),
			NCRW_VERSION
		);

		wp_enqueue_script(
			'ncrw-google-identity',
			'https://accounts.google.com/gsi/client',
			array(),
			NCRW_VERSION,
			false
		);

		wp_enqueue_script(
			'ncrw-frontend',
			NCRW_URL . 'assets/js/frontend.js',
			array(),
			NCRW_VERSION,
			true
		);

		wp_localize_script(
			'ncrw-frontend',
			'ncrwView',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'ncrw_view_ajax_nonce' ),
				'i18n'    => array(
					'signInError'    => __( 'Google Sign-In failed. Please refresh and try again.', 'notifycrew' ),
					'sessionExpired' => __( 'Your session expired. Please sign in again.', 'notifycrew' ),
					'emptyState'     => __( 'No reminders found. Try adjusting the filters.', 'notifycrew' ),
					'loadError'      => __( 'Could not load reminders. Please try again.', 'notifycrew' ),
					'countSingular'  => __( 'reminder', 'notifycrew' ),
					'countPlural'    => __( 'reminders', 'notifycrew' ),
				),
			)
		);
	}

	/**
	 * Convert reminder model to a full view-safe response object (includes created_at).
	 *
	 * @param \Aditya\NotifyCrew\Models\Reminder $reminder Reminder instance.
	 * @return array
	 */
	private function serialize_reminder_view( $reminder ): array {
		$remind_ts      = strtotime( (string) $reminder->remind_at . ' UTC' );
		$datetime_local = $remind_ts ? gmdate( 'Y-m-d\TH:i', $remind_ts ) : '';
		$display_time   = $remind_ts ? gmdate( 'M j, Y · g:i A', $remind_ts ) . ' UTC' : (string) $reminder->remind_at;

		$created_ts      = strtotime( (string) $reminder->created_at . ' UTC' );
		$created_display = $created_ts ? gmdate( 'M j, Y', $created_ts ) : (string) $reminder->created_at;

		return array(
			'id'              => (int) $reminder->id,
			'team_id'         => (int) $reminder->team_id,
			'title'           => (string) $reminder->title,
			'remind_at'       => (string) $reminder->remind_at,
			'remind_at_ts'    => (int) ( $remind_ts ?: 0 ),
			'datetime_local'  => $datetime_local,
			'display_time'    => $display_time,
			'task_link'       => (string) $reminder->task_link,
			'comments'        => (string) $reminder->comments,
			'status'          => (string) $reminder->status,
			'attempts'        => (int) $reminder->attempts,
			'created_at'      => (string) $reminder->created_at,
			'created_display' => $created_display,
		);
	}

	/**
	 * Verify Google ID token and return verified email.
	 *
	 * @param string $id_token  Google ID token.
	 * @param string $client_id Expected OAuth client ID.
	 * @return string|\WP_Error
	 */
	private function verify_google_token( string $id_token, string $client_id ) {
		if ( '' === $id_token || '' === $client_id ) {
			return new \WP_Error( 'invalid_token', __( 'Missing token or client ID.', 'notifycrew' ) );
		}

		$response = wp_remote_get(
			'https://oauth2.googleapis.com/tokeninfo?id_token=' . rawurlencode( $id_token ),
			array(
				'timeout'   => 10,
				'sslverify' => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $code || ! is_array( $body ) ) {
			return new \WP_Error( 'invalid_google_response', __( 'Google token verification failed.', 'notifycrew' ) );
		}

		$aud            = isset( $body['aud'] ) ? (string) $body['aud'] : '';
		$email          = isset( $body['email'] ) ? sanitize_email( (string) $body['email'] ) : '';
		$email_verified = isset( $body['email_verified'] ) ? (string) $body['email_verified'] : 'false';

		if ( $aud !== $client_id ) {
			return new \WP_Error( 'invalid_audience', __( 'Invalid Google token audience.', 'notifycrew' ) );
		}
		if ( '' === $email || 'true' !== strtolower( $email_verified ) ) {
			return new \WP_Error( 'invalid_email', __( 'Google account email is not verified.', 'notifycrew' ) );
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
		$options = $this->get_options();
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
	 * Extract and sanitize reminder data from frontend request.
	 *
	 * @return array
	 */
	private function extract_form_data(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce is verified in the calling AJAX handler before this method is invoked.
		$raw_datetime = sanitize_text_field( wp_unslash( $_POST['ncrw_reminder_datetime'] ?? '' ) );
		$team_id      = absint( $_POST['ncrw_team_id'] ?? 0 );
		$quick_hours  = absint( $_POST['ncrw_quick_hours'] ?? 0 );
		$remind_at    = $this->normalize_datetime_input( $raw_datetime );

		if ( $quick_hours > 0 ) {
			$computed = $this->resolve_quick_schedule_datetime( $team_id, $quick_hours );
			if ( '' !== $computed ) {
				$remind_at = $computed;
			}
		}

		return array(
			'team_id'     => $team_id,
			'title'       => sanitize_text_field( wp_unslash( $_POST['ncrw_title'] ?? '' ) ),
			'remind_at'   => $remind_at,
			'quick_hours' => $quick_hours,
			'task_link'   => esc_url_raw( wp_unslash( $_POST['ncrw_link'] ?? '' ) ),
			'comments'    => sanitize_textarea_field( wp_unslash( $_POST['ncrw_comments'] ?? '' ) ),
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Validate reminder data.
	 *
	 * @param array  $data Reminder input.
	 * @param int    $user_id User id.
	 * @param string $email Member email.
	 * @return string[]
	 */
	private function validate_form_data( array $data, int $user_id, string $email ): array {
		$errors      = array();
		$quick_hours = absint( $data['quick_hours'] ?? 0 );

		if ( empty( $data['team_id'] ) || ! Team_Service::get_instance()->user_can_access_team( (int) $data['team_id'], $user_id, $email ) ) {
			$errors[] = 'team';
		}
		if ( empty( $data['title'] ) ) {
			$errors[] = 'title';
		}
		if ( $quick_hours > 0 && ! Team_Service::get_instance()->is_quick_hour_allowed( (int) $data['team_id'], $quick_hours ) ) {
			$errors[] = 'quick_hours';
		}
		if ( empty( $data['remind_at'] ) || ! strtotime( (string) $data['remind_at'] . ' UTC' ) ) {
			$errors[] = 'datetime';
		}
		if ( ! empty( $data['task_link'] ) && ! filter_var( (string) $data['task_link'], FILTER_VALIDATE_URL ) ) {
			$errors[] = 'link';
		}

		return $errors;
	}

	/**
	 * Resolve quick-schedule hour to UTC datetime.
	 *
	 * @param int $team_id Team id.
	 * @param int $hours Hours offset.
	 * @return string
	 */
	private function resolve_quick_schedule_datetime( int $team_id, int $hours ): string {
		if ( $hours <= 0 || ! Team_Service::get_instance()->is_quick_hour_allowed( $team_id, $hours ) ) {
			return '';
		}

		return gmdate( 'Y-m-d H:i:s', time() + ( $hours * HOUR_IN_SECONDS ) );
	}

	/**
	 * Normalize date/time input into MySQL datetime format.
	 *
	 * @param string $value Input date string.
	 * @return string
	 */
	private function normalize_datetime_input( string $value ): string {
		$value = trim( $value );

		$utc = \DateTime::createFromFormat( 'Y-m-d\TH:i', $value, new \DateTimeZone( 'UTC' ) );
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
	 * Read plugin options.
	 *
	 * @return array
	 */
	private function get_options(): array {
		$options = get_option( self::SETTINGS_OPTION, array() );
		return is_array( $options ) ? $options : array();
	}
}
