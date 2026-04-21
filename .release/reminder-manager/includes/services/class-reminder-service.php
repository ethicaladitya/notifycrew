<?php
/**
 * Reminder service - team-scoped CRUD and worker lifecycle.
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
 * Class Reminder_Service
 */
class Reminder_Service {

	/** Max delivery attempts before terminal completion. */
	const MAX_ATTEMPTS = 5;

	/**
	 * Singleton instance.
	 *
	 * @var Reminder_Service|null
	 */
	private static $instance = null;

	/** @return Reminder_Service */
	public static function get_instance(): Reminder_Service {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/**
	 * Create reminder in a team.
	 *
	 * @param array $data Reminder payload.
	 * @return int|false
	 */
	public function create( array $data ) {
		global $wpdb;

		$team_id      = absint( $data['team_id'] ?? 0 );
		$user_id      = absint( $data['user_id'] ?? get_current_user_id() );
		$member_email = sanitize_email( (string) ( $data['member_email'] ?? '' ) );
		if ( ! Team_Service::get_instance()->user_can_access_team( $team_id, $user_id, $member_email ) ) {
			return false;
		}

		$inserted = $wpdb->insert(
			$wpdb->prefix . 'trt_reminders',
			array(
				'team_id'         => $team_id,
				'user_id'         => $user_id,
				'title'           => sanitize_text_field( $data['title'] ?? '' ),
				'remind_at'       => sanitize_text_field( $data['remind_at'] ?? '' ),
				'task_link'       => esc_url_raw( $data['task_link'] ?? '' ),
				'comments'        => sanitize_textarea_field( $data['comments'] ?? '' ),
				'status'          => 'pending',
				'attempts'        => 0,
				'next_attempt_at' => null,
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
		);

		if ( false === $inserted ) {
			return false;
		}

		$new_id = (int) $wpdb->insert_id;
		$this->log( $team_id, $new_id, 'created', __( 'Reminder created.', 'reminder-manager' ) );
		return $new_id;
	}

	/**
	 * Get reminder by ID.
	 *
	 * @param int $id Reminder id.
	 * @return Reminder|null
	 */
	public function get( int $id ): ?Reminder {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}trt_reminders WHERE id = %d LIMIT 1",
				$id
			)
		);

		return $row ? Reminder::from_row( $row ) : null;
	}

	/**
	 * List reminders with team scoping.
	 *
	 * @param array $args Filters.
	 * @return Reminder[]
	 */
	public function get_list( array $args = array() ): array {
		global $wpdb;

		$defaults = array(
			'team_id'  => 0,
			'user_id'  => 0,
			'status'   => '',
			'per_page' => 20,
			'page'     => 1,
			'orderby'  => 'remind_at',
			'order'    => 'DESC',
		);
		$args = wp_parse_args( $args, $defaults );

		$actor_user  = isset( $args['_actor_user_id'] ) ? absint( $args['_actor_user_id'] ) : get_current_user_id();
		$actor_email = sanitize_email( (string) ( $args['_actor_email'] ?? '' ) );
		$team_filter  = absint( $args['team_id'] );

		$where  = ' WHERE 1=1';
		$values = array();

		if ( $team_filter > 0 ) {
			if ( ! Team_Service::get_instance()->user_can_access_team( $team_filter, $actor_user, $actor_email ) ) {
				return array();
			}
			$where   .= ' AND team_id = %d';
			$values[] = $team_filter;
		} elseif ( ! Team_Service::get_instance()->is_super_admin( $actor_user ) ) {
			$teams = Team_Service::get_instance()->get_teams_for_identity( $actor_user, $actor_email );
			if ( empty( $teams ) ) {
				return array();
			}
			$team_ids      = wp_list_pluck( $teams, 'id' );
			$placeholders  = implode( ',', array_fill( 0, count( $team_ids ), '%d' ) );
			$where        .= " AND team_id IN ({$placeholders})";
			$values        = array_merge( $values, array_map( 'intval', $team_ids ) );
		}

		if ( ! empty( $args['status'] ) ) {
			$where   .= ' AND status = %s';
			$values[] = sanitize_key( $args['status'] );
		}

		if ( ! empty( $args['user_id'] ) ) {
			$where   .= ' AND user_id = %d';
			$values[] = absint( $args['user_id'] );
		}

		$allowed_columns = array( 'id', 'team_id', 'user_id', 'title', 'remind_at', 'status', 'attempts', 'created_at' );
		$orderby         = in_array( $args['orderby'], $allowed_columns, true ) ? $args['orderby'] : 'remind_at';
		$order           = 'DESC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';
		$per_page        = max( 1, (int) $args['per_page'] );
		$offset          = ( max( 1, (int) $args['page'] ) - 1 ) * $per_page;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = "SELECT * FROM {$wpdb->prefix}trt_reminders {$where} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
		// phpcs:enable

		$values[] = $per_page;
		$values[] = $offset;

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $values ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return array_map( array( Reminder::class, 'from_row' ), $rows ?: array() );
	}

	/**
	 * Count reminders with team scoping.
	 *
	 * @param array $args Count filters.
	 * @return int
	 */
	public function count( array $args = array() ): int {
		global $wpdb;

		$list_args = wp_parse_args(
			$args,
			array(
				'team_id' => 0,
				'user_id' => 0,
				'status'  => '',
			)
		);

		$actor_user  = isset( $list_args['_actor_user_id'] ) ? absint( $list_args['_actor_user_id'] ) : get_current_user_id();
		$actor_email = sanitize_email( (string) ( $list_args['_actor_email'] ?? '' ) );
		$where        = ' WHERE 1=1';
		$values       = array();

		$team_filter = absint( $list_args['team_id'] );
		if ( $team_filter > 0 ) {
			if ( ! Team_Service::get_instance()->user_can_access_team( $team_filter, $actor_user, $actor_email ) ) {
				return 0;
			}
			$where   .= ' AND team_id = %d';
			$values[] = $team_filter;
		} elseif ( ! Team_Service::get_instance()->is_super_admin( $actor_user ) ) {
			$teams = Team_Service::get_instance()->get_teams_for_identity( $actor_user, $actor_email );
			if ( empty( $teams ) ) {
				return 0;
			}
			$team_ids      = wp_list_pluck( $teams, 'id' );
			$placeholders  = implode( ',', array_fill( 0, count( $team_ids ), '%d' ) );
			$where        .= " AND team_id IN ({$placeholders})";
			$values        = array_merge( $values, array_map( 'intval', $team_ids ) );
		}

		if ( ! empty( $list_args['status'] ) ) {
			$where   .= ' AND status = %s';
			$values[] = sanitize_key( $list_args['status'] );
		}
		if ( ! empty( $list_args['user_id'] ) ) {
			$where   .= ' AND user_id = %d';
			$values[] = absint( $list_args['user_id'] );
		}

		$sql = "SELECT COUNT(*) FROM {$wpdb->prefix}trt_reminders {$where}";
		if ( empty( $values ) ) {
			return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $values ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Update reminder.
	 *
	 * @param int   $id Reminder id.
	 * @param array $data Update payload.
	 * @return bool
	 */
	public function update( int $id, array $data ): bool {
		global $wpdb;

		$reminder = $this->get( $id );
		if ( ! $reminder ) {
			return false;
		}

		$actor_user  = isset( $data['_actor_user_id'] ) ? absint( $data['_actor_user_id'] ) : get_current_user_id();
		$actor_email = sanitize_email( (string) ( $data['_actor_email'] ?? '' ) );

		if ( ! Team_Service::get_instance()->user_can_access_team( $reminder->team_id, $actor_user, $actor_email ) ) {
			return false;
		}

		$update = array();
		$format = array();

		if ( isset( $data['team_id'] ) ) {
			$new_team_id = absint( $data['team_id'] );
			if ( ! Team_Service::get_instance()->user_can_access_team( $new_team_id, $actor_user, $actor_email ) ) {
				return false;
			}
			$update['team_id'] = $new_team_id;
			$format[]          = '%d';
		}
		if ( isset( $data['title'] ) ) {
			$update['title'] = sanitize_text_field( $data['title'] );
			$format[]        = '%s';
		}
		if ( isset( $data['remind_at'] ) ) {
			$update['remind_at'] = sanitize_text_field( $data['remind_at'] );
			$format[]            = '%s';
		}
		if ( isset( $data['task_link'] ) ) {
			$update['task_link'] = esc_url_raw( $data['task_link'] );
			$format[]            = '%s';
		}
		if ( isset( $data['comments'] ) ) {
			$update['comments'] = sanitize_textarea_field( $data['comments'] );
			$format[]           = '%s';
		}
		if ( isset( $data['status'] ) ) {
			$allowed = array( 'pending', 'sent', 'completed' );
			$status  = sanitize_key( $data['status'] );
			if ( ! in_array( $status, $allowed, true ) ) {
				return false;
			}
			$update['status'] = $status;
			$format[]         = '%s';
		}

		if ( empty( $update ) ) {
			return false;
		}

		$result = $wpdb->update(
			$wpdb->prefix . 'trt_reminders',
			$update,
			array( 'id' => $id ),
			$format,
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Delete reminder.
	 *
	 * @param int $id Reminder id.
	 * @return bool
	 */
	public function delete( int $id ): bool {
		global $wpdb;

		$reminder = $this->get( $id );
		if ( ! $reminder ) {
			return false;
		}

		$current_user = get_current_user_id();
		if ( ! Team_Service::get_instance()->user_can_admin_team( $reminder->team_id, $current_user ) ) {
			return false;
		}

		$wpdb->delete( $wpdb->prefix . 'trt_logs', array( 'reminder_id' => $id ), array( '%d' ) );
		$result = $wpdb->delete( $wpdb->prefix . 'trt_reminders', array( 'id' => $id ), array( '%d' ) );
		return false !== $result;
	}

	/**
	 * Queue retry immediately.
	 *
	 * @param int $id Reminder id.
	 * @return bool
	 */
	public function queue_retry( int $id ): bool {
		global $wpdb;

		$reminder = $this->get( $id );
		if ( ! $reminder || 'pending' !== $reminder->status ) {
			return false;
		}
		if ( $reminder->attempts >= self::MAX_ATTEMPTS ) {
			return false;
		}

		if ( ! Team_Service::get_instance()->user_can_admin_team( $reminder->team_id ) ) {
			return false;
		}

		$result = $wpdb->update(
			$wpdb->prefix . 'trt_reminders',
			array( 'next_attempt_at' => gmdate( 'Y-m-d H:i:s' ) ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Retrieve reminders that should be processed now.
	 *
	 * @return Reminder[]
	 */
	public function get_due_for_processing(): array {
		global $wpdb;

		$now  = gmdate( 'Y-m-d H:i:s' );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT *
				 FROM {$wpdb->prefix}trt_reminders
				 WHERE status = 'pending'
				   AND remind_at <= %s
				   AND attempts < %d
				   AND (next_attempt_at IS NULL OR next_attempt_at <= %s)
				 ORDER BY remind_at ASC",
				$now,
				self::MAX_ATTEMPTS,
				$now
			)
		);

		return array_map( array( Reminder::class, 'from_row' ), $rows ?: array() );
	}

	/**
	 * Mark reminder sent.
	 *
	 * @param int $id Reminder id.
	 */
	public function mark_sent( int $id ): void {
		global $wpdb;

		$reminder = $this->get( $id );
		if ( ! $reminder ) {
			return;
		}

		$wpdb->update(
			$wpdb->prefix . 'trt_reminders',
			array(
				'status'          => 'sent',
				'next_attempt_at' => null,
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		$this->log( $reminder->team_id, $id, 'sent', __( 'Reminder sent to Slack.', 'reminder-manager' ) );
	}

	/**
	 * Register failed attempt with backoff.
	 *
	 * @param int    $id Reminder id.
	 * @param string $reason Failure reason.
	 */
	public function mark_failed_attempt( int $id, string $reason = '' ): void {
		global $wpdb;

		$reminder = $this->get( $id );
		if ( ! $reminder ) {
			return;
		}

		$new_attempts = $reminder->attempts + 1;
		if ( $new_attempts >= self::MAX_ATTEMPTS ) {
			$wpdb->update(
				$wpdb->prefix . 'trt_reminders',
				array(
					'attempts'        => $new_attempts,
					'status'          => 'completed',
					'next_attempt_at' => null,
				),
				array( 'id' => $id ),
				array( '%d', '%s', '%s' ),
				array( '%d' )
			);

			$this->log( $reminder->team_id, $id, 'completed', __( 'Max attempts reached. Marked completed.', 'reminder-manager' ) );
			return;
		}

		$delay_minutes = (int) pow( 2, $new_attempts );
		$next_attempt  = gmdate( 'Y-m-d H:i:s', time() + ( $delay_minutes * MINUTE_IN_SECONDS ) );

		$wpdb->update(
			$wpdb->prefix . 'trt_reminders',
			array(
				'attempts'        => $new_attempts,
				'next_attempt_at' => $next_attempt,
			),
			array( 'id' => $id ),
			array( '%d', '%s' ),
			array( '%d' )
		);

		$this->log(
			$reminder->team_id,
			$id,
			'retry_scheduled',
			sprintf(
				/* translators: 1: attempts, 2: minutes, 3: reason */
				__( 'Attempt %1$d failed. Next attempt in %2$d minutes. Reason: %3$s', 'reminder-manager' ),
				$new_attempts,
				$delay_minutes,
				sanitize_text_field( $reason )
			)
		);
	}

	/**
	 * Add reminder log.
	 *
	 * @param int    $team_id Team id.
	 * @param int    $reminder_id Reminder id.
	 * @param string $event Event key.
	 * @param string $message Message.
	 */
	public function log( int $team_id, int $reminder_id, string $event, string $message ): void {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'trt_logs',
			array(
				'team_id'     => $team_id,
				'reminder_id' => $reminder_id,
				'event'       => sanitize_key( $event ),
				'message'     => sanitize_textarea_field( $message ),
			),
			array( '%d', '%d', '%s', '%s' )
		);
	}

	/**
	 * List reminder logs.
	 *
	 * @param int $reminder_id Reminder id.
	 * @return array
	 */
	public function get_logs( int $reminder_id ): array {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}trt_logs WHERE reminder_id = %d ORDER BY created_at DESC",
				$reminder_id
			)
		) ?: array();
	}
}
