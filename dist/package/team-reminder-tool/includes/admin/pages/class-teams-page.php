<?php
/**
 * Admin Teams management page.
 *
 * @package Aditya\ReminderTool
 */

namespace Aditya\ReminderTool\Admin\Pages;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Aditya\ReminderTool\Services\Team_Service;

/**
 * Class Teams_Page
 */
class Teams_Page {

	/**
	 * Singleton instance.
	 *
	 * @var Teams_Page|null
	 */
	private static $instance = null;

	/** @return Teams_Page */
	public static function get_instance(): Teams_Page {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/**
	 * Register form handlers.
	 */
	public function register(): void {
		add_action( 'admin_post_trt_create_team', array( $this, 'handle_create_team' ) );
		add_action( 'admin_post_trt_update_team', array( $this, 'handle_update_team' ) );
		add_action( 'admin_post_trt_delete_team', array( $this, 'handle_delete_team' ) );
		add_action( 'admin_post_trt_add_team_user', array( $this, 'handle_add_team_user' ) );
		add_action( 'admin_post_trt_remove_team_user', array( $this, 'handle_remove_team_user' ) );
	}

	/**
	 * Handle team create.
	 */
	public function handle_create_team(): void {
		$this->assert_permission();
		check_admin_referer( 'trt_create_team', 'trt_nonce' );

		$name = sanitize_text_field( wp_unslash( $_POST['trt_team_name'] ?? '' ) );
		if ( '' !== $name ) {
			Team_Service::get_instance()->create_team( $name );
		}

		wp_safe_redirect( add_query_arg( array( 'page' => 'trt-teams', 'created' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Handle team update.
	 */
	public function handle_update_team(): void {
		$this->assert_permission();
		check_admin_referer( 'trt_update_team', 'trt_nonce' );

		$team_id = absint( $_POST['team_id'] ?? 0 );
		if ( $team_id > 0 && Team_Service::get_instance()->user_can_admin_team( $team_id ) ) {
			$data = array(
				'name'               => sanitize_text_field( wp_unslash( $_POST['trt_team_name'] ?? '' ) ),
				'slack_channel'      => sanitize_text_field( wp_unslash( $_POST['trt_slack_channel'] ?? '' ) ),
				'slack_mention_tag'  => sanitize_text_field( wp_unslash( $_POST['trt_slack_mention_tag'] ?? '' ) ),
				'quick_schedule_hours' => sanitize_text_field( wp_unslash( $_POST['trt_quick_schedule_hours'] ?? '' ) ),
			);
			Team_Service::get_instance()->update_team( $team_id, $data );
		}

		wp_safe_redirect( add_query_arg( array( 'page' => 'trt-teams', 'team' => $team_id, 'updated' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Handle team delete.
	 */
	public function handle_delete_team(): void {
		$this->assert_permission();
		check_admin_referer( 'trt_delete_team', 'trt_nonce' );

		$team_id = absint( $_POST['team_id'] ?? 0 );
		if ( $team_id > 0 && Team_Service::get_instance()->is_super_admin() ) {
			Team_Service::get_instance()->delete_team( $team_id );
		}

		wp_safe_redirect( add_query_arg( array( 'page' => 'trt-teams', 'deleted' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Handle add member.
	 */
	public function handle_add_team_user(): void {
		$this->assert_permission();
		check_admin_referer( 'trt_add_team_user', 'trt_nonce' );

		$team_id = absint( $_POST['team_id'] ?? 0 );
		$user_id = absint( $_POST['user_id'] ?? 0 );
		$email   = sanitize_email( wp_unslash( $_POST['member_email'] ?? '' ) );
		$role    = sanitize_key( wp_unslash( $_POST['role'] ?? 'user' ) );

		if ( $team_id > 0 && Team_Service::get_instance()->user_can_admin_team( $team_id ) ) {
			if ( $user_id > 0 ) {
				Team_Service::get_instance()->upsert_team_user( $team_id, $user_id, $role );
			} elseif ( '' !== $email ) {
				Team_Service::get_instance()->upsert_team_email( $team_id, $email, $role );
			}
		}

		wp_safe_redirect( add_query_arg( array( 'page' => 'trt-teams', 'team' => $team_id, 'member_added' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Handle remove member.
	 */
	public function handle_remove_team_user(): void {
		$this->assert_permission();
		check_admin_referer( 'trt_remove_team_user', 'trt_nonce' );

		$team_id = absint( $_POST['team_id'] ?? 0 );
		$user_id = absint( $_POST['user_id'] ?? 0 );
		$email   = sanitize_email( wp_unslash( $_POST['member_email'] ?? '' ) );

		if ( $team_id > 0 && Team_Service::get_instance()->user_can_admin_team( $team_id ) ) {
			Team_Service::get_instance()->remove_team_member( $team_id, $user_id > 0 ? $user_id : null, $email );
		}

		wp_safe_redirect( add_query_arg( array( 'page' => 'trt-teams', 'team' => $team_id, 'member_removed' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Render teams page.
	 */
	public function render(): void {
		$this->assert_permission();

		$service     = Team_Service::get_instance();
		$teams       = $service->get_teams_for_user();
		$selected_id = isset( $_GET['team'] ) ? absint( $_GET['team'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$selected    = $selected_id ? $service->get( $selected_id ) : null;

		if ( $selected && ! $service->user_can_access_team( $selected->id ) ) {
			$selected = null;
		}

		$members = $selected ? $service->get_team_users( $selected->id ) : array();
		$users   = get_users( array( 'orderby' => 'display_name', 'order' => 'ASC' ) );
		?>
		<div class="wrap trt-wrap">
			<h1><?php esc_html_e( 'Teams', 'reminder-manager' ); ?></h1>
			<?php $this->render_notices(); ?>

			<div class="trt-two-col">
				<div class="trt-col-main">
					<table class="wp-list-table widefat fixed striped trt-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Name', 'reminder-manager' ); ?></th>
								<th><?php esc_html_e( 'Slug', 'reminder-manager' ); ?></th>
								<th><?php esc_html_e( 'Slack Channel', 'reminder-manager' ); ?></th>
								<th><?php esc_html_e( 'Actions', 'reminder-manager' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php if ( empty( $teams ) ) : ?>
								<tr><td colspan="4"><?php esc_html_e( 'No teams found.', 'reminder-manager' ); ?></td></tr>
							<?php else : ?>
								<?php foreach ( $teams as $team ) : ?>
									<tr>
										<td><?php echo esc_html( $team->name ); ?></td>
										<td><?php echo esc_html( $team->slug ); ?></td>
										<td><?php echo esc_html( $team->slack_channel ?: '—' ); ?></td>
										<td>
											<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'trt-teams', 'team' => $team->id ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Manage', 'reminder-manager' ); ?></a>
										</td>
									</tr>
								<?php endforeach; ?>
							<?php endif; ?>
						</tbody>
					</table>
				</div>

				<div class="trt-col-side">
					<div class="trt-box">
						<h2><?php esc_html_e( 'Create Team', 'reminder-manager' ); ?></h2>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="trt_create_team"/>
							<?php wp_nonce_field( 'trt_create_team', 'trt_nonce' ); ?>
							<p>
								<label for="trt_team_name"><strong><?php esc_html_e( 'Team Name', 'reminder-manager' ); ?></strong></label><br/>
								<input type="text" class="widefat" id="trt_team_name" name="trt_team_name" required/>
							</p>
							<?php submit_button( __( 'Create Team', 'reminder-manager' ), 'primary', 'submit', false ); ?>
						</form>
					</div>

					<?php if ( $selected ) : ?>
						<div class="trt-box" style="margin-top:16px;">
							<h2><?php esc_html_e( 'Team Details', 'reminder-manager' ); ?></h2>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<input type="hidden" name="action" value="trt_update_team"/>
								<input type="hidden" name="team_id" value="<?php echo esc_attr( $selected->id ); ?>"/>
								<?php wp_nonce_field( 'trt_update_team', 'trt_nonce' ); ?>
								<p>
									<label for="trt_team_name_edit"><strong><?php esc_html_e( 'Name', 'reminder-manager' ); ?></strong></label><br/>
									<input type="text" class="widefat" id="trt_team_name_edit" name="trt_team_name" value="<?php echo esc_attr( $selected->name ); ?>" required/>
								</p>
								<p>
									<label for="trt_slack_channel"><strong><?php esc_html_e( 'Slack Channel', 'reminder-manager' ); ?></strong></label><br/>
									<input type="text" class="widefat" id="trt_slack_channel" name="trt_slack_channel" value="<?php echo esc_attr( $selected->slack_channel ); ?>" placeholder="#team-channel"/>
								</p>
								<p>
									<label for="trt_slack_mention_tag"><strong><?php esc_html_e( 'Slack Mention Tag', 'reminder-manager' ); ?></strong></label><br/>
									<select id="trt_slack_mention_tag" name="trt_slack_mention_tag" class="widefat">
										<option value="" <?php selected( $selected->slack_mention_tag, '' ); ?>><?php esc_html_e( '— None —', 'reminder-manager' ); ?></option>
										<option value="@channel" <?php selected( $selected->slack_mention_tag, '@channel' ); ?>><?php esc_html_e( '@channel (notify all members)', 'reminder-manager' ); ?></option>
										<option value="@here" <?php selected( $selected->slack_mention_tag, '@here' ); ?>><?php esc_html_e( '@here (notify active members)', 'reminder-manager' ); ?></option>
									</select>
									<span class="description"><?php esc_html_e( 'Mention tag appended to every Slack reminder sent for this team.', 'reminder-manager' ); ?></span>
								</p>
								<p>
									<label for="trt_quick_schedule_hours"><strong><?php esc_html_e( 'Quick Schedule Hours', 'reminder-manager' ); ?></strong></label><br/>
									<input type="text" class="widefat" id="trt_quick_schedule_hours" name="trt_quick_schedule_hours" value="<?php echo esc_attr( $selected->quick_schedule_hours ); ?>" placeholder="4,12,48"/>
									<span class="description"><?php esc_html_e( 'Comma-separated hour options used when staff chooses delay instead of exact date/time.', 'reminder-manager' ); ?></span>
								</p>
								<?php submit_button( __( 'Save Team', 'reminder-manager' ), 'primary', 'submit', false ); ?>
							</form>

							<?php if ( $service->is_super_admin() ) : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="trt-delete-form" style="margin-top:10px;">
									<input type="hidden" name="action" value="trt_delete_team"/>
									<input type="hidden" name="team_id" value="<?php echo esc_attr( $selected->id ); ?>"/>
									<?php wp_nonce_field( 'trt_delete_team', 'trt_nonce' ); ?>
									<button type="submit" class="button-link trt-btn-delete"><?php esc_html_e( 'Delete Team', 'reminder-manager' ); ?></button>
								</form>
							<?php endif; ?>
						</div>

						<div class="trt-box" style="margin-top:16px;">
							<h2><?php esc_html_e( 'Team Members', 'reminder-manager' ); ?></h2>
							<table class="widefat striped">
								<thead><tr><th><?php esc_html_e( 'Member', 'reminder-manager' ); ?></th><th><?php esc_html_e( 'Type', 'reminder-manager' ); ?></th><th><?php esc_html_e( 'Role', 'reminder-manager' ); ?></th><th><?php esc_html_e( 'Actions', 'reminder-manager' ); ?></th></tr></thead>
								<tbody>
									<?php if ( empty( $members ) ) : ?>
										<tr><td colspan="4"><?php esc_html_e( 'No members yet.', 'reminder-manager' ); ?></td></tr>
									<?php else : ?>
										<?php foreach ( $members as $member ) : ?>
											<tr>
												<td><?php echo esc_html( $member['member_name'] . ' (' . $member['member_email'] . ')' ); ?></td>
												<td><?php echo esc_html( 'wp_user' === $member['member_type'] ? __( 'WP User', 'reminder-manager' ) : __( 'Email', 'reminder-manager' ) ); ?></td>
												<td><?php echo esc_html( ucfirst( $member['role'] ) ); ?></td>
												<td>
													<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
														<input type="hidden" name="action" value="trt_remove_team_user"/>
														<input type="hidden" name="team_id" value="<?php echo esc_attr( $selected->id ); ?>"/>
														<input type="hidden" name="user_id" value="<?php echo esc_attr( (int) $member['user_id'] ); ?>"/>
														<input type="hidden" name="member_email" value="<?php echo esc_attr( (string) $member['member_email'] ); ?>"/>
														<?php wp_nonce_field( 'trt_remove_team_user', 'trt_nonce' ); ?>
														<button type="submit" class="button-link trt-btn-delete"><?php esc_html_e( 'Remove', 'reminder-manager' ); ?></button>
													</form>
												</td>
											</tr>
										<?php endforeach; ?>
									<?php endif; ?>
								</tbody>
							</table>

							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px;">
								<input type="hidden" name="action" value="trt_add_team_user"/>
								<input type="hidden" name="team_id" value="<?php echo esc_attr( $selected->id ); ?>"/>
								<?php wp_nonce_field( 'trt_add_team_user', 'trt_nonce' ); ?>
								<p>
									<label for="trt_member_user"><strong><?php esc_html_e( 'User', 'reminder-manager' ); ?></strong></label><br/>
									<select id="trt_member_user" name="user_id" class="widefat">
										<option value="0"><?php esc_html_e( 'Select user', 'reminder-manager' ); ?></option>
										<?php foreach ( $users as $user ) : ?>
											<option value="<?php echo esc_attr( $user->ID ); ?>"><?php echo esc_html( $user->display_name . ' (' . $user->user_email . ')' ); ?></option>
										<?php endforeach; ?>
									</select>
								</p>
								<p>
									<label for="trt_member_email"><strong><?php esc_html_e( 'Or Email', 'reminder-manager' ); ?></strong></label><br/>
									<input type="email" id="trt_member_email" name="member_email" class="widefat" placeholder="user@example.com"/>
									<span class="description"><?php esc_html_e( 'Use email when the member does not have a WordPress account.', 'reminder-manager' ); ?></span>
								</p>
								<p>
									<label for="trt_member_role"><strong><?php esc_html_e( 'Role', 'reminder-manager' ); ?></strong></label><br/>
									<select id="trt_member_role" name="role" class="widefat">
										<option value="user"><?php esc_html_e( 'User', 'reminder-manager' ); ?></option>
										<option value="admin"><?php esc_html_e( 'Admin', 'reminder-manager' ); ?></option>
									</select>
								</p>
								<?php submit_button( __( 'Add Member', 'reminder-manager' ), 'secondary', 'submit', false ); ?>
							</form>
						</div>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render feedback notices.
	 */
	private function render_notices(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$messages = array(
			'created'        => __( 'Team created.', 'reminder-manager' ),
			'updated'        => __( 'Team updated.', 'reminder-manager' ),
			'deleted'        => __( 'Team deleted.', 'reminder-manager' ),
			'member_added'   => __( 'Member added.', 'reminder-manager' ),
			'member_removed' => __( 'Member removed.', 'reminder-manager' ),
		);

		foreach ( $messages as $key => $message ) {
			if ( isset( $_GET[ $key ] ) && '1' === $_GET[ $key ] ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Assert admin permission.
	 */
	private function assert_permission(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'reminder-manager' ) );
		}
	}
}
