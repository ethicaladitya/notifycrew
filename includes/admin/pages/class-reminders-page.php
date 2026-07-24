<?php
/**
 * Admin reminders page.
 *
 * @package Aditya\NotifyCrew
 */

namespace Aditya\NotifyCrew\Admin\Pages;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Aditya\NotifyCrew\Services\Reminder_Service;
use Aditya\NotifyCrew\Services\Team_Service;
use Aditya\NotifyCrew\Services\Cron_Service;

/**
 * Class Reminders_Page
 */
class Reminders_Page {

	/**
	 * Singleton instance.
	 *
	 * @var Reminders_Page|null
	 */
	private static $instance = null;

	/** @return Reminders_Page */
	public static function get_instance(): Reminders_Page {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/**
	 * Register handlers.
	 */
	public function register(): void {
		add_action( 'admin_post_ncrw_create_reminder', array( $this, 'handle_create' ) );
		add_action( 'admin_post_ncrw_update_reminder', array( $this, 'handle_update' ) );
		add_action( 'admin_post_ncrw_delete_reminder', array( $this, 'handle_delete' ) );
		add_action( 'admin_post_ncrw_retry_reminder', array( $this, 'handle_retry' ) );
	}

	/**
	 * Handle create.
	 */
	public function handle_create(): void {
		$this->assert_permission();
		check_admin_referer( 'ncrw_create_reminder', 'ncrw_nonce' );

		$data   = $this->extract_form_data();
		$errors = $this->validate( $data );

		if ( ! empty( $errors ) ) {
			set_transient( 'ncrw_form_errors_' . get_current_user_id(), $errors, 60 );
			set_transient( 'ncrw_form_data_' . get_current_user_id(), $data, 60 );
			wp_safe_redirect( add_query_arg( array( 'page' => 'ncrw-add-reminder' ), admin_url( 'admin.php' ) ) );
			exit;
		}

		$new_id = Reminder_Service::get_instance()->create( $data );
		if ( false === $new_id ) {
			set_transient( 'ncrw_form_errors_' . get_current_user_id(), array( __( 'Could not create reminder. Please try again.', 'notifycrew' ) ), 60 );
			set_transient( 'ncrw_form_data_' . get_current_user_id(), $data, 60 );
			wp_safe_redirect( add_query_arg( array( 'page' => 'ncrw-add-reminder' ), admin_url( 'admin.php' ) ) );
			exit;
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => 'ncrw-reminders',
					'created'     => '1',
					'reminder_id' => (int) $new_id,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Handle update.
	 */
	public function handle_update(): void {
		$this->assert_permission();
		check_admin_referer( 'ncrw_update_reminder', 'ncrw_nonce' );

		$id   = absint( $_POST['reminder_id'] ?? 0 );
		$data = $this->extract_form_data();
		if ( $id > 0 ) {
			Reminder_Service::get_instance()->update( $id, $data );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'ncrw-reminders',
					'updated' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Handle delete.
	 */
	public function handle_delete(): void {
		$this->assert_permission();
		check_admin_referer( 'ncrw_delete_reminder', 'ncrw_nonce' );

		$id = absint( $_POST['reminder_id'] ?? 0 );
		if ( $id > 0 ) {
			Reminder_Service::get_instance()->delete( $id );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'ncrw-reminders',
					'deleted' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Handle retry.
	 */
	public function handle_retry(): void {
		$this->assert_permission();
		check_admin_referer( 'ncrw_retry_reminder', 'ncrw_nonce' );

		$id = absint( $_POST['reminder_id'] ?? 0 );
		if ( $id > 0 ) {
			Reminder_Service::get_instance()->queue_retry( $id );
			Cron_Service::get_instance()->process();
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'ncrw-reminders',
					'retried' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Render list.
	 */
	public function render(): void {
		$this->assert_permission();

		$team_service  = Team_Service::get_instance();
		$teams         = $team_service->get_teams_for_user();
		$status_filter = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$team_filter   = isset( $_GET['team_id'] ) ? absint( $_GET['team_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current_page  = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$per_page      = 20;

		$args = array(
			'team_id'  => $team_filter,
			'status'   => $status_filter,
			'per_page' => $per_page,
			'page'     => max( 1, $current_page ),
		);

		$service   = Reminder_Service::get_instance();
		$reminders = $service->get_list( $args );
		$total     = $service->count( $args );
		$pages     = max( 1, (int) ceil( $total / $per_page ) );

		$statuses = array(
			''          => __( 'All', 'notifycrew' ),
			'pending'   => __( 'Pending', 'notifycrew' ),
			'sent'      => __( 'Sent', 'notifycrew' ),
			'completed' => __( 'Completed', 'notifycrew' ),
		);
		?>
		<div class="wrap ncrw-wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'All Reminders', 'notifycrew' ); ?></h1>
			<a href="<?php echo esc_url( add_query_arg( 'page', 'ncrw-add-reminder', admin_url( 'admin.php' ) ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add Reminder', 'notifycrew' ); ?></a>
			<hr class="wp-header-end"/>

			<?php $this->render_notices(); ?>

			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="margin:10px 0;">
				<input type="hidden" name="page" value="ncrw-reminders"/>
				<select name="team_id">
					<option value="0"><?php esc_html_e( 'All Teams', 'notifycrew' ); ?></option>
					<?php foreach ( $teams as $team ) : ?>
						<option value="<?php echo esc_attr( $team->id ); ?>" <?php selected( $team_filter, (int) $team->id ); ?>><?php echo esc_html( $team->name ); ?></option>
					<?php endforeach; ?>
				</select>
				<select name="status">
					<?php foreach ( $statuses as $status_key => $label ) : ?>
						<option value="<?php echo esc_attr( $status_key ); ?>" <?php selected( $status_filter, $status_key ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<button class="button" type="submit"><?php esc_html_e( 'Filter', 'notifycrew' ); ?></button>
			</form>

			<table class="wp-list-table widefat fixed striped ncrw-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Title', 'notifycrew' ); ?></th>
						<th><?php esc_html_e( 'Team', 'notifycrew' ); ?></th>
						<th><?php esc_html_e( 'Scheduled', 'notifycrew' ); ?></th>
						<th><?php esc_html_e( 'Status', 'notifycrew' ); ?></th>
						<th><?php esc_html_e( 'Attempts', 'notifycrew' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'notifycrew' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $reminders ) ) : ?>
						<tr><td colspan="6"><?php esc_html_e( 'No reminders found.', 'notifycrew' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $reminders as $reminder ) : ?>
							<?php $team = $team_service->get( (int) $reminder->team_id ); ?>
							<tr>
								<td>
									<strong><?php echo esc_html( $reminder->title ); ?></strong>
									<?php if ( ! empty( $reminder->task_link ) ) : ?>
										<br/><a href="<?php echo esc_url( $reminder->task_link ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open Link', 'notifycrew' ); ?></a>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( $team ? $team->name : '—' ); ?></td>
								<td><?php echo esc_html( gmdate( 'Y-m-d H:i:s', strtotime( $reminder->remind_at . ' UTC' ) ) . ' UTC' ); ?></td>
								<td><span class="ncrw-status ncrw-status--<?php echo esc_attr( $reminder->status ); ?>"><?php echo esc_html( ucfirst( $reminder->status ) ); ?></span></td>
								<td>
									<?php echo esc_html( (int) $reminder->attempts ); ?>/<?php echo esc_html( (int) Reminder_Service::MAX_ATTEMPTS ); ?>
									<?php
									if ( (int) $reminder->attempts > 0 ) {
										$reason = $service->get_last_failure_reason( (int) $reminder->id );
										if ( '' !== $reason ) {
											echo '<br/><small style="color:#b32d2e;" title="' . esc_attr( $reason ) . '">' . esc_html( wp_trim_words( $reason, 10 ) ) . '</small>';
										}
									}
									?>
								</td>
								<td>
									<a href="
									<?php
									echo esc_url(
										add_query_arg(
											array(
												'page' => 'ncrw-add-reminder',
												'edit' => $reminder->id,
											),
											admin_url( 'admin.php' )
										)
									);
									?>
												"><?php esc_html_e( 'Edit', 'notifycrew' ); ?></a>
									<?php if ( 'pending' === $reminder->status && (int) $reminder->attempts < Reminder_Service::MAX_ATTEMPTS ) : ?>
										&nbsp;|&nbsp;
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
											<input type="hidden" name="action" value="ncrw_retry_reminder"/>
											<input type="hidden" name="reminder_id" value="<?php echo esc_attr( $reminder->id ); ?>"/>
											<?php wp_nonce_field( 'ncrw_retry_reminder', 'ncrw_nonce' ); ?>
											<button type="submit" class="button-link"><?php esc_html_e( 'Retry Now', 'notifycrew' ); ?></button>
										</form>
									<?php endif; ?>
									&nbsp;|&nbsp;
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline" class="ncrw-delete-form">
										<input type="hidden" name="action" value="ncrw_delete_reminder"/>
										<input type="hidden" name="reminder_id" value="<?php echo esc_attr( $reminder->id ); ?>"/>
										<?php wp_nonce_field( 'ncrw_delete_reminder', 'ncrw_nonce' ); ?>
										<button type="submit" class="button-link ncrw-btn-delete"><?php esc_html_e( 'Delete', 'notifycrew' ); ?></button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<?php if ( $pages > 1 ) : ?>
				<div class="tablenav bottom"><div class="tablenav-pages">
					<?php
					echo wp_kses_post(
						paginate_links(
							array(
								'base'      => add_query_arg( 'paged', '%#%' ),
								'format'    => '',
								'current'   => max( 1, $current_page ),
								'total'     => $pages,
								'prev_text' => '&laquo;',
								'next_text' => '&raquo;',
							)
						)
					);
					?>
				</div></div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render add/edit form.
	 */
	public function render_add(): void {
		$this->assert_permission();

		$team_service = Team_Service::get_instance();
		$teams        = $team_service->get_teams_for_user();
		$edit_id      = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$reminder     = $edit_id ? Reminder_Service::get_instance()->get( $edit_id ) : null;

		$uid         = get_current_user_id();
		$saved_data  = get_transient( 'ncrw_form_data_' . $uid );
		$form_errors = get_transient( 'ncrw_form_errors_' . $uid );
		delete_transient( 'ncrw_form_data_' . $uid );
		delete_transient( 'ncrw_form_errors_' . $uid );

		$v = array(
			'team_id'     => $saved_data['team_id'] ?? ( $reminder->team_id ?? ( isset( $teams[0] ) ? $teams[0]->id : 0 ) ),
			'title'       => $saved_data['title'] ?? ( $reminder->title ?? '' ),
			'remind_at'   => $saved_data['remind_at'] ?? ( $reminder->remind_at ?? '' ),
			'quick_hours' => $saved_data['quick_hours'] ?? 0,
			'task_link'   => $saved_data['task_link'] ?? ( $reminder->task_link ?? '' ),
			'comments'    => $saved_data['comments'] ?? ( $reminder->comments ?? '' ),
		);

		$action           = $reminder ? 'ncrw_update_reminder' : 'ncrw_create_reminder';
		$nonce            = $reminder ? 'ncrw_update_reminder' : 'ncrw_create_reminder';
		$default_datetime = ! empty( $v['remind_at'] ) ? gmdate( 'Y-m-d\\TH:i', strtotime( (string) $v['remind_at'] . ' UTC' ) ) : gmdate( 'Y-m-d\\TH:i' );
		$selected_team    = ! empty( $v['team_id'] ) ? $team_service->get( (int) $v['team_id'] ) : null;
		$quick_hours      = $selected_team ? $team_service->get_team_quick_schedule_hours( (int) $selected_team->id ) : array( 4, 12, 48 );
		$team_hours_map   = array();
		foreach ( $teams as $team ) {
			$team_hours_map[ (int) $team->id ] = $team_service->get_team_quick_schedule_hours( (int) $team->id );
		}
		?>
		<div class="wrap ncrw-wrap">
			<h1><?php echo $reminder ? esc_html__( 'Edit Reminder', 'notifycrew' ) : esc_html__( 'Add Reminder', 'notifycrew' ); ?></h1>

			<?php if ( ! empty( $form_errors ) ) : ?>
				<div class="notice notice-error"><ul>
				<?php
				foreach ( $form_errors as $error ) :
					?>
					<li><?php echo esc_html( $error ); ?></li><?php endforeach; ?></ul></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>"/>
				<?php if ( $reminder ) : ?>
					<input type="hidden" name="reminder_id" value="<?php echo esc_attr( $reminder->id ); ?>"/>
				<?php endif; ?>
				<?php wp_nonce_field( $nonce, 'ncrw_nonce' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ncrw_team_id"><?php esc_html_e( 'Team', 'notifycrew' ); ?> <span class="required">*</span></label></th>
						<td>
							<select id="ncrw_team_id" name="ncrw_team_id" required>
								<option value="0"><?php esc_html_e( 'Select Team', 'notifycrew' ); ?></option>
								<?php foreach ( $teams as $team ) : ?>
									<option value="<?php echo esc_attr( $team->id ); ?>" data-quick-hours="<?php echo esc_attr( implode( ',', $team_hours_map[ (int) $team->id ] ?? array() ) ); ?>" <?php selected( (int) $v['team_id'], (int) $team->id ); ?>><?php echo esc_html( $team->name ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ncrw_title"><?php esc_html_e( 'Title', 'notifycrew' ); ?> <span class="required">*</span></label></th>
						<td><input type="text" id="ncrw_title" name="ncrw_title" class="regular-text" required value="<?php echo esc_attr( (string) $v['title'] ); ?>"/></td>
					</tr>
					<tr>
						<th scope="row"><label for="ncrw_quick_hours"><?php esc_html_e( 'Quick Schedule', 'notifycrew' ); ?></label></th>
						<td>
							<select id="ncrw_quick_hours" name="ncrw_quick_hours">
								<option value="0"><?php esc_html_e( 'Custom date & time', 'notifycrew' ); ?></option>
								<?php foreach ( $quick_hours as $hours ) : ?>
									<option value="<?php echo esc_attr( (int) $hours ); ?>" <?php selected( (int) $v['quick_hours'], (int) $hours ); ?>>
										<?php
										// translators: %d is the number of hours.
										echo esc_html( sprintf( __( 'In %d hours', 'notifycrew' ), (int) $hours ) );
										?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Uses team-configured hour options and auto-calculates reminder time in UTC.', 'notifycrew' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ncrw_datetime"><?php esc_html_e( 'Scheduled Date & Time', 'notifycrew' ); ?> <span class="required">*</span></label></th>
						<td>
							<input type="datetime-local" id="ncrw_datetime" name="ncrw_reminder_datetime" value="<?php echo esc_attr( $default_datetime ); ?>"/>
							<p class="description"><?php esc_html_e( 'Optional when using Quick Schedule; otherwise provide UTC date/time.', 'notifycrew' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ncrw_link"><?php esc_html_e( 'Task/Ticket/Slack Link', 'notifycrew' ); ?></label></th>
						<td><input type="url" id="ncrw_link" name="ncrw_link" class="regular-text" value="<?php echo esc_url( (string) $v['task_link'] ); ?>"/></td>
					</tr>
					<tr>
						<th scope="row"><label for="ncrw_comments"><?php esc_html_e( 'Comments', 'notifycrew' ); ?></label></th>
						<td><textarea id="ncrw_comments" name="ncrw_comments" rows="5" class="large-text"><?php echo esc_textarea( (string) $v['comments'] ); ?></textarea></td>
					</tr>
				</table>

				<?php submit_button( $reminder ? __( 'Update Reminder', 'notifycrew' ) : __( 'Create Reminder', 'notifycrew' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render notices.
	 */
	private function render_notices(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['created'] ) && '1' === $_GET['created'] ) {
			$reminder_id = isset( $_GET['reminder_id'] ) ? absint( $_GET['reminder_id'] ) : 0;
			if ( $reminder_id > 0 ) {
				$message = sprintf(
					/* translators: %d is the created reminder ID. */
					__( 'Reminder created! Reminder ID: %d', 'notifycrew' ),
					$reminder_id
				);
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
			} else {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Reminder created!', 'notifycrew' ) . '</p></div>';
			}
		}
		if ( isset( $_GET['updated'] ) && '1' === $_GET['updated'] ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Reminder updated!', 'notifycrew' ) . '</p></div>';
		}
		if ( isset( $_GET['deleted'] ) && '1' === $_GET['deleted'] ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Reminder deleted.', 'notifycrew' ) . '</p></div>';
		}
		if ( isset( $_GET['retried'] ) && '1' === $_GET['retried'] ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Reminder queued for retry.', 'notifycrew' ) . '</p></div>';
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Extract posted values.
	 *
	 * @return array
	 */
	private function extract_form_data(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce is verified in the calling handler before this method is invoked.
		$raw_datetime = sanitize_text_field( wp_unslash( $_POST['ncrw_reminder_datetime'] ?? '' ) );
		$quick_hours  = absint( $_POST['ncrw_quick_hours'] ?? 0 );
		$team_id      = absint( $_POST['ncrw_team_id'] ?? 0 );
		$remind_at    = $this->normalize_datetime_input( $raw_datetime );

		if ( $quick_hours > 0 ) {
			$computed = $this->resolve_quick_schedule_datetime( $team_id, $quick_hours );
			if ( '' !== $computed ) {
				$remind_at = $computed;
			}
		}

		return array(
			'team_id'     => $team_id,
			'user_id'     => get_current_user_id(),
			'title'       => sanitize_text_field( wp_unslash( $_POST['ncrw_title'] ?? '' ) ),
			'remind_at'   => $remind_at,
			'quick_hours' => $quick_hours,
			'task_link'   => esc_url_raw( wp_unslash( $_POST['ncrw_link'] ?? '' ) ),
			'comments'    => sanitize_textarea_field( wp_unslash( $_POST['ncrw_comments'] ?? '' ) ),
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Normalize date input.
	 *
	 * @param string $value Input value.
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
	 * Validate reminder payload.
	 *
	 * @param array $data Form data.
	 * @return string[]
	 */
	private function validate( array $data ): array {
		$errors      = array();
		$quick_hours = absint( $data['quick_hours'] ?? 0 );
		if ( empty( $data['team_id'] ) || ! Team_Service::get_instance()->user_can_access_team( (int) $data['team_id'] ) ) {
			$errors[] = __( 'Please select a valid team.', 'notifycrew' );
		}
		if ( empty( $data['title'] ) ) {
			$errors[] = __( 'Title is required.', 'notifycrew' );
		}
		if ( $quick_hours > 0 && ! Team_Service::get_instance()->is_quick_hour_allowed( (int) $data['team_id'], $quick_hours ) ) {
			$errors[] = __( 'Selected quick schedule option is not allowed for this team.', 'notifycrew' );
		}
		if ( empty( $data['remind_at'] ) || ! strtotime( (string) $data['remind_at'] . ' UTC' ) ) {
			$errors[] = __( 'Valid scheduled date and time are required.', 'notifycrew' );
		}
		if ( ! empty( $data['task_link'] ) && ! filter_var( (string) $data['task_link'], FILTER_VALIDATE_URL ) ) {
			$errors[] = __( 'Link must be a valid URL.', 'notifycrew' );
		}
		return $errors;
	}

	/**
	 * Resolve a quick-schedule option into UTC datetime.
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
	 * Ensure admin permission.
	 */
	private function assert_permission(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'notifycrew' ) );
		}
	}
}
