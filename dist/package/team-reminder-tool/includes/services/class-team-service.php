<?php
/**
 * Team service: teams, memberships, and permission checks.
 *
 * Supports memberships by either WordPress user ID or plain email.
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
 * Class Team_Service
 */
class Team_Service {

	/**
	 * Singleton instance.
	 *
	 * @var Team_Service|null
	 */
	private static $instance = null;

	/** @return Team_Service */
	public static function get_instance(): Team_Service {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/**
	 * Create a team.
	 *
	 * @param string $name Team name.
	 * @return int|false
	 */
	public function create_team( string $name ) {
		global $wpdb;

		$name = sanitize_text_field( $name );
		if ( '' === $name ) {
			return false;
		}

		$slug_base = sanitize_title( $name );
		$slug      = $slug_base;
		$counter   = 2;
		while ( $this->slug_exists( $slug ) ) {
			$slug = $slug_base . '-' . $counter;
			++$counter;
		}

		$result = $wpdb->insert(
			$wpdb->prefix . 'trt_teams',
			array(
				'name'       => $name,
				'slug'       => $slug,
				'created_by' => get_current_user_id(),
			),
			array( '%s', '%s', '%d' )
		);

		if ( false === $result ) {
			return false;
		}

		$team_id = (int) $wpdb->insert_id;
		$this->upsert_team_user( $team_id, get_current_user_id(), 'admin' );
		return $team_id;
	}

	/**
	 * Update team core data.
	 *
	 * @param int   $team_id Team ID.
	 * @param array $data Team fields.
	 * @return bool
	 */
	public function update_team( int $team_id, array $data ): bool {
		global $wpdb;

		$update = array();
		$format = array();

		if ( isset( $data['name'] ) ) {
			$name = sanitize_text_field( (string) $data['name'] );
			if ( '' === $name ) {
				return false;
			}
			$update['name'] = $name;
			$format[]       = '%s';
		}

		if ( isset( $data['slack_channel'] ) ) {
			$update['slack_channel'] = sanitize_text_field( (string) $data['slack_channel'] );
			$format[]                = '%s';
		}

		if ( isset( $data['slack_mention_tag'] ) ) {
			$update['slack_mention_tag'] = $this->sanitize_mention_tag( (string) $data['slack_mention_tag'] );
			$format[]                    = '%s';
		}

		if ( isset( $data['quick_schedule_hours'] ) ) {
			$update['quick_schedule_hours'] = $this->normalize_quick_schedule_hours( (string) $data['quick_schedule_hours'] );
			$format[]                       = '%s';
		}

		if ( empty( $update ) ) {
			return false;
		}

		$result = $wpdb->update(
			$wpdb->prefix . 'trt_teams',
			$update,
			array( 'id' => $team_id ),
			$format,
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Delete team and related memberships.
	 *
	 * @param int $team_id Team ID.
	 * @return bool
	 */
	public function delete_team( int $team_id ): bool {
		global $wpdb;

		$wpdb->delete( $wpdb->prefix . 'trt_team_users', array( 'team_id' => $team_id ), array( '%d' ) );
		$wpdb->delete( $wpdb->prefix . 'trt_reminders', array( 'team_id' => $team_id ), array( '%d' ) );

		$result = $wpdb->delete( $wpdb->prefix . 'trt_teams', array( 'id' => $team_id ), array( '%d' ) );
		return false !== $result;
	}

	/**
	 * Get team by ID.
	 *
	 * @param int $team_id Team ID.
	 * @return Team|null
	 */
	public function get_team( int $team_id ): ?Team {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}trt_teams WHERE id = %d LIMIT 1",
				$team_id
			)
		);

		return $row ? Team::from_row( $row ) : null;
	}

	/**
	 * Alias for get_team().
	 *
	 * @param int $team_id Team ID.
	 * @return Team|null
	 */
	public function get( int $team_id ): ?Team {
		return $this->get_team( $team_id );
	}

	/**
	 * List teams by identity (user ID and/or email).
	 *
	 * @param int|null    $user_id Optional user ID.
	 * @param string|null $email Optional email identity.
	 * @return Team[]
	 */
	public function get_teams_for_identity( ?int $user_id = null, ?string $email = null ): array {
		global $wpdb;

		$user_id = absint( $user_id ?? get_current_user_id() );
		$email   = sanitize_email( (string) ( $email ?? '' ) );

		if ( $this->is_super_admin( $user_id ) ) {
			$rows = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}trt_teams ORDER BY name ASC" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			return array_map( array( Team::class, 'from_row' ), $rows ?: array() );
		}

		if ( 0 === $user_id && '' === $email ) {
			return array();
		}

		$where = array();
		$args  = array();

		if ( $user_id > 0 ) {
			$where[] = 'tu.user_id = %d';
			$args[]  = $user_id;
		}
		if ( '' !== $email ) {
			$where[] = 'LOWER(tu.email) = LOWER(%s)';
			$args[]  = $email;
		}

		if ( empty( $where ) ) {
			return array();
		}

		$where_sql = implode( ' OR ', $where );
		$sql       = "SELECT DISTINCT t.*
			FROM {$wpdb->prefix}trt_teams t
			INNER JOIN {$wpdb->prefix}trt_team_users tu ON tu.team_id = t.id
			WHERE ({$where_sql})
			ORDER BY t.name ASC";

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return array_map( array( Team::class, 'from_row' ), $rows ?: array() );
	}

	/**
	 * Backward-compatible teams query by WP user only.
	 *
	 * @param int|null $user_id Optional user ID.
	 * @return Team[]
	 */
	public function get_teams_for_user( ?int $user_id = null ): array {
		return $this->get_teams_for_identity( $user_id, '' );
	}

	/**
	 * Get team members, including email-only members.
	 *
	 * @param int $team_id Team id.
	 * @return array
	 */
	public function get_team_users( int $team_id ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					tu.id,
					tu.team_id,
					tu.user_id,
					tu.email,
					tu.role,
					u.user_login,
					u.user_email,
					u.display_name
				 FROM {$wpdb->prefix}trt_team_users tu
				 LEFT JOIN {$wpdb->users} u ON u.ID = tu.user_id
				 WHERE tu.team_id = %d
				 ORDER BY COALESCE(u.display_name, tu.email) ASC",
				$team_id
			),
			ARRAY_A
		) ?: array();

		foreach ( $rows as &$row ) {
			$row['member_email'] = ! empty( $row['user_email'] ) ? $row['user_email'] : (string) $row['email'];
			$row['member_name']  = ! empty( $row['display_name'] ) ? $row['display_name'] : (string) $row['member_email'];
			$row['member_type']  = ! empty( $row['user_id'] ) ? 'wp_user' : 'email';
		}

		return $rows;
	}

	/**
	 * Add/update membership by WP user.
	 *
	 * @param int    $team_id Team id.
	 * @param int    $user_id User id.
	 * @param string $role admin|user.
	 * @return bool
	 */
	public function upsert_team_user( int $team_id, int $user_id, string $role ): bool {
		$user_id = absint( $user_id );
		if ( $user_id <= 0 ) {
			return false;
		}

		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return false;
		}

		return $this->upsert_team_member( $team_id, $user_id, (string) $user->user_email, $role );
	}

	/**
	 * Add/update membership by email.
	 *
	 * @param int    $team_id Team id.
	 * @param string $email Member email.
	 * @param string $role admin|user.
	 * @return bool
	 */
	public function upsert_team_email( int $team_id, string $email, string $role ): bool {
		$email = sanitize_email( $email );
		if ( '' === $email ) {
			return false;
		}

		$user = get_user_by( 'email', $email );
		if ( $user ) {
			return $this->upsert_team_member( $team_id, (int) $user->ID, (string) $user->user_email, $role );
		}

		return $this->upsert_team_member( $team_id, null, $email, $role );
	}

	/**
	 * Add/update membership by either WP user or email.
	 *
	 * @param int         $team_id Team id.
	 * @param int|null    $user_id User id (optional).
	 * @param string      $email Member email.
	 * @param string      $role admin|user.
	 * @return bool
	 */
	public function upsert_team_member( int $team_id, ?int $user_id, string $email, string $role ): bool {
		global $wpdb;

		$user_id = absint( (int) $user_id );
		$email   = sanitize_email( $email );
		$role    = ( 'admin' === $role ) ? 'admin' : 'user';

		if ( 0 === $user_id && '' === $email ) {
			return false;
		}

		if ( 0 !== $user_id && '' === $email ) {
			$user = get_user_by( 'id', $user_id );
			if ( ! $user ) {
				return false;
			}
			$email = (string) $user->user_email;
		}

		$existing_id = null;
		if ( 0 !== $user_id ) {
			$existing_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$wpdb->prefix}trt_team_users WHERE team_id = %d AND user_id = %d LIMIT 1",
					$team_id,
					$user_id
				)
			);
		}

		if ( ! $existing_id && '' !== $email ) {
			$existing_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$wpdb->prefix}trt_team_users WHERE team_id = %d AND LOWER(email) = LOWER(%s) LIMIT 1",
					$team_id,
					$email
				)
			);
		}

		if ( $existing_id ) {
			$result = $wpdb->update(
				$wpdb->prefix . 'trt_team_users',
				array(
					'user_id' => $user_id,
					'email'   => $email,
					'role'    => $role,
				),
				array( 'id' => (int) $existing_id ),
				array( '%d', '%s', '%s' ),
				array( '%d' )
			);

			return false !== $result;
		}

		$result = $wpdb->insert(
			$wpdb->prefix . 'trt_team_users',
			array(
				'team_id' => $team_id,
				'user_id' => $user_id,
				'email'   => $email,
				'role'    => $role,
			),
			array( '%d', '%d', '%s', '%s' )
		);

		return false !== $result;
	}

	/**
	 * Remove team membership by user id and/or email.
	 *
	 * @param int         $team_id Team id.
	 * @param int|null    $user_id User id.
	 * @param string|null $email Member email.
	 * @return bool
	 */
	public function remove_team_member( int $team_id, ?int $user_id = null, ?string $email = null ): bool {
		global $wpdb;

		$user_id = null !== $user_id ? absint( $user_id ) : null;
		$email   = sanitize_email( (string) $email );

		if ( null === $user_id && '' === $email ) {
			return false;
		}

		$where = array( 'team_id' => $team_id );
		$fmt   = array( '%d' );

		if ( null !== $user_id ) {
			$where['user_id'] = $user_id;
			$fmt[]            = '%d';
		} else {
			$where['email'] = $email;
			$fmt[]          = '%s';
		}

		$result = $wpdb->delete( $wpdb->prefix . 'trt_team_users', $where, $fmt );
		return false !== $result;
	}

	/**
	 * Backward-compatible remove by WP user ID.
	 *
	 * @param int $team_id Team id.
	 * @param int $user_id User id.
	 * @return bool
	 */
	public function remove_team_user( int $team_id, int $user_id ): bool {
		return $this->remove_team_member( $team_id, $user_id, null );
	}

	/**
	 * Get role in team by user id and/or email.
	 *
	 * @param int         $team_id Team id.
	 * @param int|null    $user_id User id.
	 * @param string|null $email Member email.
	 * @return string|null
	 */
	public function get_user_role_in_team( int $team_id, ?int $user_id = null, ?string $email = null ): ?string {
		$user_id = absint( $user_id ?? get_current_user_id() );
		$email   = sanitize_email( (string) ( $email ?? '' ) );

		if ( $this->is_super_admin( $user_id ) ) {
			return 'admin';
		}

		if ( 0 === $user_id && '' === $email ) {
			return null;
		}

		global $wpdb;

		$where = array();
		$args  = array( $team_id );

		if ( $user_id > 0 ) {
			$where[] = 'user_id = %d';
			$args[]  = $user_id;
		}
		if ( '' !== $email ) {
			$where[] = 'LOWER(email) = LOWER(%s)';
			$args[]  = $email;
		}

		if ( empty( $where ) ) {
			return null;
		}

		$sql  = "SELECT role FROM {$wpdb->prefix}trt_team_users WHERE team_id = %d AND (" . implode( ' OR ', $where ) . ') LIMIT 1';
		$role = $wpdb->get_var( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return $role ? (string) $role : null;
	}

	/**
	 * Whether identity can access team.
	 *
	 * @param int         $team_id Team id.
	 * @param int|null    $user_id User id.
	 * @param string|null $email Member email.
	 * @return bool
	 */
	public function user_can_access_team( int $team_id, ?int $user_id = null, ?string $email = null ): bool {
		return null !== $this->get_user_role_in_team( $team_id, $user_id, $email );
	}

	/**
	 * Whether identity can administer team.
	 *
	 * @param int         $team_id Team id.
	 * @param int|null    $user_id User id.
	 * @param string|null $email Member email.
	 * @return bool
	 */
	public function user_can_admin_team( int $team_id, ?int $user_id = null, ?string $email = null ): bool {
		return 'admin' === $this->get_user_role_in_team( $team_id, $user_id, $email );
	}

	/**
	 * Is user super admin within plugin permissions.
	 *
	 * @param int|null $user_id User id.
	 * @return bool
	 */
	public function is_super_admin( ?int $user_id = null ): bool {
		$user_id = absint( $user_id ?? get_current_user_id() );
		if ( $user_id <= 0 ) {
			return false;
		}

		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return false;
		}

		return user_can( $user, 'manage_options' );
	}

	/**
	 * Get normalized quick schedule hour options for a team.
	 *
	 * @param int $team_id Team id.
	 * @return int[]
	 */
	public function get_team_quick_schedule_hours( int $team_id ): array {
		$team = $this->get( $team_id );
		if ( ! $team ) {
			return array( 4, 12, 48 );
		}

		return $this->parse_quick_schedule_hours( (string) $team->quick_schedule_hours );
	}

	/**
	 * Validate whether an hour value is allowed for a team.
	 *
	 * @param int $team_id Team id.
	 * @param int $hours Hours value.
	 * @return bool
	 */
	public function is_quick_hour_allowed( int $team_id, int $hours ): bool {
		if ( $hours <= 0 ) {
			return false;
		}

		return in_array( $hours, $this->get_team_quick_schedule_hours( $team_id ), true );
	}

	/**
	 * Check whether team slug already exists.
	 *
	 * @param string $slug Team slug.
	 * @return bool
	 */
	private function slug_exists( string $slug ): bool {
		global $wpdb;
		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}trt_teams WHERE slug = %s LIMIT 1",
				$slug
			)
		);

		return ! empty( $found );
	}

	/**
	 * Get the configured Slack mention tag for a team, converted to Slack format.
	 *
	 * @param int $team_id Team ID.
	 * @return string e.g. '<!channel>', '<!here>', or '' if none.
	 */
	public function get_team_mention_tag( int $team_id ): string {
		$team = $this->get_team( $team_id );
		if ( ! $team ) {
			return '';
		}
		return $this->mention_tag_to_slack_format( $team->slack_mention_tag );
	}

	/**
	 * Convert a stored mention tag value to Slack mrkdwn format.
	 *
	 * @param string $tag Stored value (e.g. '@channel', '@here').
	 * @return string
	 */
	public function mention_tag_to_slack_format( string $tag ): string {
		$map = array(
			'@channel' => '<!channel>',
			'@here'    => '<!here>',
		);

		if ( isset( $map[ $tag ] ) ) {
			return $map[ $tag ];
		}

		return $tag;
	}

	/**
	 * Sanitize a mention tag input.
	 *
	 * Accepts '', '@channel', '@here'. Rejects anything with spaces or URL-unsafe chars.
	 *
	 * @param string $tag Raw input.
	 * @return string
	 */
	public function sanitize_mention_tag( string $tag ): string {
		$tag = trim( sanitize_text_field( $tag ) );
		if ( '' === $tag ) {
			return '';
		}

		// Allow only @channel, @here — enforce safe charset.
		if ( ! preg_match( '/^@[a-zA-Z0-9._\-]{1,50}$/', $tag ) ) {
			return '';
		}

		return $tag;
	}

	/**
	 * Normalize quick schedule hours input for storage.
	 *
	 * @param string $raw Raw value.
	 * @return string
	 */
	private function normalize_quick_schedule_hours( string $raw ): string {
		return implode( ',', $this->parse_quick_schedule_hours( $raw ) );
	}

	/**
	 * Parse and sanitize quick schedule hours.
	 *
	 * @param string $raw Raw value.
	 * @return int[]
	 */
	private function parse_quick_schedule_hours( string $raw ): array {
		$parts = preg_split( '/[\s,]+/', $raw );
		$hours = array();

		foreach ( $parts as $part ) {
			$val = absint( $part );
			if ( $val > 0 && $val <= 720 ) {
				$hours[] = $val;
			}
		}

		$hours = array_values( array_unique( $hours ) );
		sort( $hours );

		if ( empty( $hours ) ) {
			$hours = array( 4, 12, 48 );
		}

		return $hours;
	}
}
