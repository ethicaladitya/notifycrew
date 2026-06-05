<?php
/**
 * Frontend reminder portal service (Google-authenticated, team-scoped).
 *
 * @package Aditya\ReminderTool
 */

namespace Aditya\ReminderTool\Services;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Aditya\ReminderTool\Models\Team;

/**
 * Class Frontend_Service
 */
class Frontend_Service {

	/** Settings option name. */
	const SETTINGS_OPTION = 'trt_options';

	/** Shortcode tag. */
	const SHORTCODE = 'trt_frontend_reminder_form';

	/** View shortcode tag. */
	const SHORTCODE_VIEW = 'trt_upcoming_reminders';

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

		add_action( 'wp_ajax_trt_frontend_list_reminders', array( $this, 'handle_ajax_list_reminders' ) );
		add_action( 'wp_ajax_nopriv_trt_frontend_list_reminders', array( $this, 'handle_ajax_list_reminders' ) );

		add_action( 'wp_ajax_trt_frontend_save_reminder', array( $this, 'handle_ajax_save_reminder' ) );
		add_action( 'wp_ajax_nopriv_trt_frontend_save_reminder', array( $this, 'handle_ajax_save_reminder' ) );

		add_shortcode( self::SHORTCODE_VIEW, array( $this, 'render_shortcode_view' ) );

		add_action( 'wp_ajax_trt_frontend_view_reminders', array( $this, 'handle_ajax_view_reminders' ) );
		add_action( 'wp_ajax_nopriv_trt_frontend_view_reminders', array( $this, 'handle_ajax_view_reminders' ) );
	}

	/**
	 * Shortcode renderer.
	 *
	 * @return string
	 */
	public function render_shortcode(): string {
		$options = $this->get_options();

		if ( empty( $options['frontend_enabled'] ) ) {
			return '<p>' . esc_html__( 'Frontend reminders are currently disabled.', 'reminder-manager' ) . '</p>';
		}

		$client_id = isset( $options['frontend_google_client_id'] ) ? (string) $options['frontend_google_client_id'] : '';
		if ( '' === $client_id ) {
			return '<p>' . esc_html__( 'Frontend reminders are not configured yet. Please set a Google OAuth Client ID in plugin settings.', 'reminder-manager' ) . '</p>';
		}

		$this->enqueue_assets( $client_id );
		$default_datetime = gmdate( 'Y-m-d\TH:i' );

		ob_start();
		?>
		<section class="trt-portal" data-trt-portal="1">
			<div class="trt-orb trt-orb--1" aria-hidden="true"></div>
			<div class="trt-orb trt-orb--2" aria-hidden="true"></div>
			<div class="trt-orb trt-orb--3" aria-hidden="true"></div>

			<div class="trt-portal-shell">
				<header class="trt-portal-header">
					<div class="trt-header-badge"><?php esc_html_e( 'Team Reminders', 'reminder-manager' ); ?></div>
					<h2><?php esc_html_e( 'Reminder Portal', 'reminder-manager' ); ?></h2>
					<p><?php esc_html_e( 'Sign in with your company Google account to create and manage team reminders.', 'reminder-manager' ); ?></p>
				</header>

				<div id="g_id_onload"
					data-client_id="<?php echo esc_attr( $client_id ); ?>"
					data-callback="trtOnGoogleCallback"
					data-auto_prompt="false">
				</div>

				<div class="trt-auth-gate" id="trt-auth-gate">
					<div class="trt-auth-card">
						<div class="trt-auth-icon" aria-hidden="true">
							<svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
						</div>
						<h3><?php esc_html_e( 'Secure Access', 'reminder-manager' ); ?></h3>
						<p><?php esc_html_e( 'Use Google Sign-In to access teams linked to your account.', 'reminder-manager' ); ?></p>
						<div class="trt-google-btn-wrap">
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

				<div class="trt-portal-app" id="trt-portal-app" hidden>
					<div class="trt-toolbar">
						<div>
							<p class="trt-toolbar-label"><?php esc_html_e( 'Logged in as', 'reminder-manager' ); ?></p>
							<p class="trt-toolbar-email" id="trt-user-email"></p>
						</div>
						<button type="button" class="button" id="trt-sign-out"><?php esc_html_e( 'Sign Out', 'reminder-manager' ); ?></button>
					</div>

					<div id="trt-portal-notice" class="trt-frontend-notice" hidden></div>

					<div class="trt-grid">
						<div class="trt-panel">
							<h3 id="trt-form-title"><?php esc_html_e( 'Create Reminder', 'reminder-manager' ); ?></h3>
							<form id="trt-reminder-form" class="trt-frontend-form">
								<input type="hidden" id="trt_front_id" name="id" value="0"/>
								<input type="hidden" id="trt_google_id_token" name="trt_google_id_token" value=""/>
								<input type="hidden" name="trt_frontend_nonce" value="<?php echo esc_attr( wp_create_nonce( 'trt_frontend_ajax_nonce' ) ); ?>"/>

								<label for="trt_front_team_id"><?php esc_html_e( 'Team', 'reminder-manager' ); ?> *</label>
								<select id="trt_front_team_id" name="trt_team_id" required></select>

								<label for="trt_front_title"><?php esc_html_e( 'Title', 'reminder-manager' ); ?> *</label>
								<input type="text" id="trt_front_title" name="trt_title" required/>

								<label for="trt_front_datetime"><?php esc_html_e( 'Scheduled Date & Time', 'reminder-manager' ); ?> *</label>
								<select id="trt_front_quick_hours" name="trt_quick_hours">
									<option value="0"><?php esc_html_e( 'Custom date & time', 'reminder-manager' ); ?></option>
								</select>
								<input type="datetime-local" id="trt_front_datetime" name="trt_reminder_datetime" value="<?php echo esc_attr( $default_datetime ); ?>"/>

								<label for="trt_front_link"><?php esc_html_e( 'Task/Ticket/Slack Link', 'reminder-manager' ); ?></label>
								<input type="url" id="trt_front_link" name="trt_link"/>

								<label for="trt_front_comments"><?php esc_html_e( 'Comments', 'reminder-manager' ); ?></label>
								<textarea id="trt_front_comments" name="trt_comments" rows="4"></textarea>

								<div class="trt-actions">
									<button type="submit" class="button button-primary" id="trt-front-submit"><?php esc_html_e( 'Save Reminder', 'reminder-manager' ); ?></button>
									<button type="button" class="button" id="trt-front-cancel" hidden><?php esc_html_e( 'Cancel Edit', 'reminder-manager' ); ?></button>
								</div>
							</form>
						</div>

						<div class="trt-panel">
							<div class="trt-list-header" style="flex-direction: column; align-items: stretch; gap: 10px;">
								<div style="display: flex; align-items: center; justify-content: space-between; gap: 10px;">
									<h3 style="margin-bottom: 0;"><?php esc_html_e( 'Your Reminders', 'reminder-manager' ); ?></h3>
									<button type="button" class="button" id="trt-refresh-list"><?php esc_html_e( 'Refresh', 'reminder-manager' ); ?></button>
								</div>
								<div class="trt-view-controls" id="trt-main-filters" style="margin-bottom: 0; border-bottom: none; padding-bottom: 0;">
									<div class="trt-filter-tabs" id="trt-main-status-tabs" role="tablist">
										<button type="button" class="trt-tab trt-tab--active" data-status="" role="tab"><?php esc_html_e( 'All', 'reminder-manager' ); ?></button>
										<button type="button" class="trt-tab" data-status="pending" role="tab"><?php esc_html_e( 'Pending', 'reminder-manager' ); ?></button>
										<button type="button" class="trt-tab" data-status="sent" role="tab"><?php esc_html_e( 'Sent', 'reminder-manager' ); ?></button>
										<button type="button" class="trt-tab" data-status="failed" role="tab"><?php esc_html_e( 'Failed', 'reminder-manager' ); ?></button>
									</div>
									<div class="trt-sort-controls">
										<label for="trt-main-orderby" class="trt-sort-label"><?php esc_html_e( 'Sort by', 'reminder-manager' ); ?></label>
										<select id="trt-main-orderby" class="trt-sort-select">
											<option value="remind_at"><?php esc_html_e( 'Trigger Date', 'reminder-manager' ); ?></option>
											<option value="created_at"><?php esc_html_e( 'Date Added', 'reminder-manager' ); ?></option>
											<option value="title"><?php esc_html_e( 'Title (A–Z)', 'reminder-manager' ); ?></option>
											<option value="status"><?php esc_html_e( 'Status', 'reminder-manager' ); ?></option>
										</select>
										<button type="button" id="trt-main-order-toggle" class="trt-order-btn" data-order="ASC" aria-label="<?php esc_attr_e( 'Toggle sort direction', 'reminder-manager' ); ?>">
											<span class="trt-order-icon" aria-hidden="true">↑</span>
											<span class="trt-order-label"><?php esc_html_e( 'ASC', 'reminder-manager' ); ?></span>
										</button>
									</div>
								</div>
							</div>
							<div id="trt-reminders-list" class="trt-reminders-list"></div>
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
		check_ajax_referer( 'trt_frontend_ajax_nonce', 'trt_frontend_nonce' );

		$identity = $this->verify_request_identity();
		if ( is_wp_error( $identity ) ) {
			wp_send_json_error( array( 'message' => $identity->get_error_message() ), 403 );
		}

		$user_id      = (int) $identity['user_id'];
		$email        = (string) $identity['email'];
		$team_service = Team_Service::get_instance();
		$teams        = $team_service->get_teams_for_identity( $user_id, $email );
		if ( empty( $teams ) ) {
			wp_send_json_error( array( 'message' => __( 'No teams are assigned to your account.', 'reminder-manager' ) ), 403 );
		}

		$requested_team_id = absint( $_POST['trt_team_id'] ?? 0 );
		$selected_team_id  = $requested_team_id;
		if ( 0 === $selected_team_id || ! $team_service->user_can_access_team( $selected_team_id, $user_id, $email ) ) {
			$selected_team_id = (int) $teams[0]->id;
		}

		$allowed_orderby = array( 'id', 'team_id', 'user_id', 'title', 'remind_at', 'status', 'attempts', 'created_at' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified above via check_ajax_referer.
		$orderby         = sanitize_key( wp_unslash( $_POST['trt_orderby'] ?? 'remind_at' ) );
		$orderby         = in_array( $orderby, $allowed_orderby, true ) ? $orderby : 'remind_at';
		$raw_order       = strtoupper( sanitize_key( wp_unslash( $_POST['trt_order'] ?? 'ASC' ) ) );
		$order           = 'DESC' === $raw_order ? 'DESC' : 'ASC';
		$status_filter   = sanitize_key( wp_unslash( $_POST['trt_status'] ?? '' ) );
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
		check_ajax_referer( 'trt_frontend_ajax_nonce', 'trt_frontend_nonce' );

		$identity = $this->verify_request_identity();
		if ( is_wp_error( $identity ) ) {
			wp_send_json_error( array( 'message' => $identity->get_error_message() ), 403 );
		}

		$user_id      = (int) $identity['user_id'];
		$email        = (string) $identity['email'];
		$team_service = Team_Service::get_instance();

		$service = Reminder_Service::get_instance();
		$data    = $this->extract_form_data();
		$data['user_id']      = $user_id;
		$data['member_email'] = $email;
		$errors               = $this->validate_form_data( $data, $user_id, $email );
		if ( ! empty( $errors ) ) {
			wp_send_json_error( array( 'message' => __( 'Please complete required fields with valid values.', 'reminder-manager' ) ), 400 );
		}

		$id = absint( $_POST['id'] ?? 0 );
		if ( $id > 0 ) {
			$existing = $service->get( $id );
			if ( ! $existing || ! $team_service->user_can_access_team( (int) $existing->team_id, $user_id, $email ) ) {
				wp_send_json_error( array( 'message' => __( 'You can only edit reminders from your teams.', 'reminder-manager' ) ), 403 );
			}

			if ( 'sent' === $existing->status || 'completed' === $existing->status ) {
				wp_send_json_error( array( 'message' => __( 'Sent/completed reminders cannot be edited from frontend.', 'reminder-manager' ) ), 400 );
			}

			$data['_actor_user_id'] = $user_id;
			$data['_actor_email']   = $email;
			$updated = $service->update( $id, $data );
			if ( ! $updated ) {
				wp_send_json_error( array( 'message' => __( 'Could not update reminder.', 'reminder-manager' ) ), 500 );
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
			wp_send_json_error( array( 'message' => __( 'Could not create reminder.', 'reminder-manager' ) ), 500 );
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
			return '<p>' . esc_html__( 'Frontend reminders are currently disabled.', 'team-reminder-tool' ) . '</p>';
		}

		$client_id = isset( $options['frontend_google_client_id'] ) ? (string) $options['frontend_google_client_id'] : '';
		if ( '' === $client_id ) {
			return '<p>' . esc_html__( 'Frontend reminders are not configured yet. Please set a Google OAuth Client ID in plugin settings.', 'team-reminder-tool' ) . '</p>';
		}

		$this->enqueue_view_assets( $client_id );

		ob_start();
		?>
		<section class="trt-portal trt-view-portal" data-trt-view="1">
			<div class="trt-orb trt-orb--1" aria-hidden="true"></div>
			<div class="trt-orb trt-orb--2" aria-hidden="true"></div>
			<div class="trt-orb trt-orb--3" aria-hidden="true"></div>

			<div class="trt-portal-shell">
				<header class="trt-portal-header">
					<div class="trt-header-badge"><?php esc_html_e( 'Team Reminders', 'team-reminder-tool' ); ?></div>
					<h2><?php esc_html_e( 'Upcoming Reminders', 'team-reminder-tool' ); ?></h2>
					<p><?php esc_html_e( 'Sign in with your company Google account to view your team\'s scheduled reminders.', 'team-reminder-tool' ); ?></p>
				</header>

				<div id="g_id_onload"
					data-client_id="<?php echo esc_attr( $client_id ); ?>"
					data-callback="trtOnViewCallback"
					data-auto_prompt="false">
				</div>

				<div class="trt-auth-gate" id="trt-view-auth-gate">
					<div class="trt-auth-card">
						<div class="trt-auth-icon" aria-hidden="true">
							<svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></svg>
						</div>
						<h3><?php esc_html_e( 'View Reminders', 'team-reminder-tool' ); ?></h3>
						<p><?php esc_html_e( 'Sign in with Google to see your team\'s upcoming reminders.', 'team-reminder-tool' ); ?></p>
						<div class="trt-google-btn-wrap">
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

				<div class="trt-view-app" id="trt-view-app" hidden>
					<div class="trt-toolbar">
						<div class="trt-toolbar-left">
							<div>
								<p class="trt-toolbar-label"><?php esc_html_e( 'Logged in as', 'team-reminder-tool' ); ?></p>
								<p class="trt-toolbar-email" id="trt-view-user-email"></p>
							</div>
							<select id="trt-view-team" class="trt-toolbar-team" aria-label="<?php esc_attr_e( 'Select team', 'team-reminder-tool' ); ?>"></select>
						</div>
						<button type="button" class="button" id="trt-view-sign-out"><?php esc_html_e( 'Sign Out', 'team-reminder-tool' ); ?></button>
					</div>

					<div id="trt-view-notice" class="trt-frontend-notice" hidden></div>

					<div class="trt-view-panel">
						<div class="trt-view-controls">
							<div class="trt-filter-tabs" id="trt-view-status-tabs" role="tablist">
								<button type="button" class="trt-tab trt-tab--active" data-status="" role="tab"><?php esc_html_e( 'All', 'team-reminder-tool' ); ?></button>
								<button type="button" class="trt-tab" data-status="pending" role="tab"><?php esc_html_e( 'Pending', 'team-reminder-tool' ); ?></button>
								<button type="button" class="trt-tab" data-status="sent" role="tab"><?php esc_html_e( 'Sent', 'team-reminder-tool' ); ?></button>
								<button type="button" class="trt-tab" data-status="failed" role="tab"><?php esc_html_e( 'Failed', 'team-reminder-tool' ); ?></button>
							</div>
							<div class="trt-sort-controls">
								<label for="trt-view-orderby" class="trt-sort-label"><?php esc_html_e( 'Sort by', 'team-reminder-tool' ); ?></label>
								<select id="trt-view-orderby" class="trt-sort-select">
									<option value="remind_at"><?php esc_html_e( 'Trigger Date', 'team-reminder-tool' ); ?></option>
									<option value="created_at"><?php esc_html_e( 'Date Added', 'team-reminder-tool' ); ?></option>
									<option value="title"><?php esc_html_e( 'Title (A–Z)', 'team-reminder-tool' ); ?></option>
									<option value="status"><?php esc_html_e( 'Status', 'team-reminder-tool' ); ?></option>
								</select>
								<button type="button" id="trt-view-order-toggle" class="trt-order-btn" data-order="ASC" aria-label="<?php esc_attr_e( 'Toggle sort direction', 'team-reminder-tool' ); ?>">
									<span class="trt-order-icon" aria-hidden="true">↑</span>
									<span class="trt-order-label"><?php esc_html_e( 'ASC', 'team-reminder-tool' ); ?></span>
								</button>
								<button type="button" id="trt-view-refresh" class="trt-refresh-btn">
									<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M1 4v6h6"/><path d="M23 20v-6h-6"/><path d="M20.49 9A9 9 0 0 0 5.64 5.64L1 10m22 4-4.64 4.36A9 9 0 0 1 3.51 15"/></svg>
									<?php esc_html_e( 'Refresh', 'team-reminder-tool' ); ?>
								</button>
							</div>
						</div>

						<div id="trt-view-count" class="trt-view-count" hidden></div>
						<div id="trt-view-list" class="trt-view-list" aria-live="polite"></div>
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
		check_ajax_referer( 'trt_view_ajax_nonce', 'trt_view_nonce' );

		$identity = $this->verify_request_identity();
		if ( is_wp_error( $identity ) ) {
			wp_send_json_error( array( 'message' => $identity->get_error_message() ), 403 );
		}

		$user_id      = (int) $identity['user_id'];
		$email        = (string) $identity['email'];
		$team_service = Team_Service::get_instance();
		$teams        = $team_service->get_teams_for_identity( $user_id, $email );
		if ( empty( $teams ) ) {
			wp_send_json_error( array( 'message' => __( 'No teams are assigned to your account.', 'team-reminder-tool' ) ), 403 );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified above via check_ajax_referer.
		$requested_team_id = absint( $_POST['trt_team_id'] ?? 0 );
		$selected_team_id  = $requested_team_id;
		if ( 0 === $selected_team_id || ! $team_service->user_can_access_team( $selected_team_id, $user_id, $email ) ) {
			$selected_team_id = (int) $teams[0]->id;
		}

		$allowed_orderby = array( 'remind_at', 'created_at', 'title', 'status' );
		$orderby         = sanitize_key( wp_unslash( $_POST['trt_orderby'] ?? 'remind_at' ) );
		$orderby         = in_array( $orderby, $allowed_orderby, true ) ? $orderby : 'remind_at';

		$raw_order = strtoupper( sanitize_key( wp_unslash( $_POST['trt_order'] ?? 'ASC' ) ) );
		$order     = 'DESC' === $raw_order ? 'DESC' : 'ASC';

		$status_filter    = sanitize_key( wp_unslash( $_POST['trt_status'] ?? '' ) );
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
			'trt-frontend',
			TRT_URL . 'assets/css/frontend.css',
			array(),
			TRT_VERSION
		);

		wp_enqueue_script(
			'trt-google-identity',
			'https://accounts.google.com/gsi/client',
			array(),
			null,
			false
		);

		wp_enqueue_script(
			'trt-frontend',
			TRT_URL . 'assets/js/frontend.js',
			array(),
			TRT_VERSION,
			true
		);

		wp_localize_script(
			'trt-frontend',
			'trtFrontend',
			array(
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'trt_frontend_ajax_nonce' ),
				'i18n'     => array(
					'signedIn'       => __( 'Signed in as', 'reminder-manager' ),
					'signInError'    => __( 'Google Sign-In failed. Please refresh and try again.', 'reminder-manager' ),
					'sessionExpired' => __( 'Your session expired. Please sign in again.', 'reminder-manager' ),
					'emptyState'     => __( 'No reminders yet. Create your first one on the left.', 'reminder-manager' ),
					'edit'           => __( 'Edit', 'reminder-manager' ),
					'createTitle'    => __( 'Create Reminder', 'reminder-manager' ),
					'editTitle'      => __( 'Edit Reminder', 'reminder-manager' ),
					'createdMessage' => __( 'Reminder created successfully.', 'reminder-manager' ),
					'updatedMessage' => __( 'Reminder updated successfully.', 'reminder-manager' ),
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
			wp_send_json_error( array( 'message' => __( 'Frontend reminder submission is disabled.', 'reminder-manager' ) ), 403 );
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
		$token   = isset( $_POST['trt_google_id_token'] ) ? sanitize_text_field( wp_unslash( $_POST['trt_google_id_token'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( '' === $token ) {
			return new \WP_Error( 'missing_token', __( 'Google authentication token is required.', 'reminder-manager' ) );
		}

		$email = $this->verify_google_token( $token, (string) ( $options['frontend_google_client_id'] ?? '' ) );
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
	 * Convert reminder model to frontend-safe response object.
	 *
	 * @param \Aditya\ReminderTool\Models\Reminder $reminder Reminder instance.
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
			'id'   => (int) $team->id,
			'name' => (string) $team->name,
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
			'trt-frontend',
			TRT_URL . 'assets/css/frontend.css',
			array(),
			TRT_VERSION
		);

		wp_enqueue_script(
			'trt-google-identity',
			'https://accounts.google.com/gsi/client',
			array(),
			null,
			false
		);

		wp_enqueue_script(
			'trt-frontend',
			TRT_URL . 'assets/js/frontend.js',
			array(),
			TRT_VERSION,
			true
		);

		wp_localize_script(
			'trt-frontend',
			'trtView',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'trt_view_ajax_nonce' ),
				'i18n'    => array(
					'signInError'    => __( 'Google Sign-In failed. Please refresh and try again.', 'team-reminder-tool' ),
					'sessionExpired' => __( 'Your session expired. Please sign in again.', 'team-reminder-tool' ),
					'emptyState'     => __( 'No reminders found. Try adjusting the filters.', 'team-reminder-tool' ),
					'loadError'      => __( 'Could not load reminders. Please try again.', 'team-reminder-tool' ),
					'countSingular'  => __( 'reminder', 'team-reminder-tool' ),
					'countPlural'    => __( 'reminders', 'team-reminder-tool' ),
				),
			)
		);
	}

	/**
	 * Convert reminder model to a full view-safe response object (includes created_at).
	 *
	 * @param \Aditya\ReminderTool\Models\Reminder $reminder Reminder instance.
	 * @return array
	 */
	private function serialize_reminder_view( $reminder ): array {
		$remind_ts       = strtotime( (string) $reminder->remind_at . ' UTC' );
		$datetime_local  = $remind_ts ? gmdate( 'Y-m-d\TH:i', $remind_ts ) : '';
		$display_time    = $remind_ts ? gmdate( 'M j, Y · g:i A', $remind_ts ) . ' UTC' : (string) $reminder->remind_at;

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
		$raw_datetime = sanitize_text_field( wp_unslash( $_POST['trt_reminder_datetime'] ?? '' ) );
		$team_id      = absint( $_POST['trt_team_id'] ?? 0 );
		$quick_hours  = absint( $_POST['trt_quick_hours'] ?? 0 );
		$remind_at    = $this->normalize_datetime_input( $raw_datetime );

		if ( $quick_hours > 0 ) {
			$computed = $this->resolve_quick_schedule_datetime( $team_id, $quick_hours );
			if ( '' !== $computed ) {
				$remind_at = $computed;
			}
		}

		return array(
			'team_id'   => $team_id,
			'title'     => sanitize_text_field( wp_unslash( $_POST['trt_title'] ?? '' ) ),
			'remind_at' => $remind_at,
			'quick_hours' => $quick_hours,
			'task_link' => esc_url_raw( wp_unslash( $_POST['trt_link'] ?? '' ) ),
			'comments'  => sanitize_textarea_field( wp_unslash( $_POST['trt_comments'] ?? '' ) ),
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Validate reminder data.
	 *
	 * @param array $data Reminder input.
	 * @param int    $user_id User id.
	 * @param string $email Member email.
	 * @return string[]
	 */
	private function validate_form_data( array $data, int $user_id, string $email ): array {
		$errors = array();
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
