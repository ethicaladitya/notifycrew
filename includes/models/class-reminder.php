<?php
/**
 * Reminder model (plain data object).
 *
 * @package Aditya\NotifyCrew
 */

namespace Aditya\NotifyCrew\Models;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Reminder
 *
 * Represents a single reminder record.
 */
class Reminder {

	/**
	 * Reminder ID.
	 *
	 * @var int
	 */
	public int $id = 0;

	/**
	 * Owning team ID.
	 *
	 * @var int
	 */
	public int $team_id = 0;

	/**
	 * WordPress user ID that created the reminder.
	 *
	 * @var int
	 */
	public int $user_id = 0;

	/**
	 * Email address of the member the reminder belongs to.
	 *
	 * @var string
	 */
	public string $member_email = '';

	/**
	 * Reminder title.
	 *
	 * @var string
	 */
	public string $title = '';

	/**
	 * DateTime string (YYYY-MM-DD HH:MM:SS) the reminder is scheduled for.
	 *
	 * @var string
	 */
	public string $remind_at = '';

	/**
	 * Recurrence type: none|daily|weekly|monthly|custom.
	 *
	 * @var string
	 */
	public string $recurrence = 'none';

	/**
	 * Number of recurrence units between occurrences.
	 *
	 * @var int
	 */
	public int $recurrence_interval = 1;

	/**
	 * Recurrence end condition type: none|occurrences|date.
	 *
	 * @var string
	 */
	public string $recurrence_end_type = 'none';

	/**
	 * Maximum number of occurrences, when recurrence_end_type is "occurrences".
	 *
	 * @var int|null
	 */
	public ?int $recurrence_end_occurrences = null;

	/**
	 * Series end date, when recurrence_end_type is "date".
	 *
	 * @var string|null
	 */
	public ?string $recurrence_end_date = null;

	/**
	 * Related task, ticket, or Slack link.
	 *
	 * @var string
	 */
	public string $task_link = '';

	/**
	 * Free-form comments.
	 *
	 * @var string
	 */
	public string $comments = '';

	/**
	 * Delivery status: pending|sent|completed.
	 *
	 * @var string
	 */
	public string $status = 'pending';

	/**
	 * Number of delivery attempts made so far.
	 *
	 * @var int
	 */
	public int $attempts = 0;

	/**
	 * Timestamp of the next retry attempt, if any.
	 *
	 * @var string|null
	 */
	public ?string $next_attempt_at = null;

	/**
	 * Row creation timestamp.
	 *
	 * @var string
	 */
	public string $created_at = '';

	/**
	 * Build a Reminder from a raw DB row (stdClass).
	 *
	 * @param \stdClass $row Raw database row.
	 * @return static
	 */
	public static function from_row( \stdClass $row ): static {
		$instance                             = new static();
		$instance->id                         = (int) $row->id;
		$instance->team_id                    = (int) $row->team_id;
		$instance->user_id                    = (int) $row->user_id;
		$instance->member_email               = isset( $row->member_email ) ? (string) $row->member_email : '';
		$instance->title                      = (string) $row->title;
		$instance->remind_at                  = (string) $row->remind_at;
		$instance->recurrence                 = isset( $row->recurrence ) ? (string) $row->recurrence : 'none';
		$instance->recurrence_interval        = isset( $row->recurrence_interval ) ? (int) $row->recurrence_interval : 1;
		$instance->recurrence_end_type        = isset( $row->recurrence_end_type ) ? (string) $row->recurrence_end_type : 'none';
		$instance->recurrence_end_occurrences = isset( $row->recurrence_end_occurrences ) && null !== $row->recurrence_end_occurrences ? (int) $row->recurrence_end_occurrences : null;
		$instance->recurrence_end_date        = isset( $row->recurrence_end_date ) && null !== $row->recurrence_end_date ? (string) $row->recurrence_end_date : null;
		$instance->task_link                  = (string) $row->task_link;
		$instance->comments                   = (string) $row->comments;
		$instance->status                     = (string) $row->status;
		$instance->attempts                   = (int) $row->attempts;
		$instance->next_attempt_at            = isset( $row->next_attempt_at ) ? (string) $row->next_attempt_at : null;
		$instance->created_at                 = (string) $row->created_at;
		return $instance;
	}

	/**
	 * Convert the model to a plain associative array.
	 *
	 * @return array
	 */
	public function to_array(): array {
		return array(
			'id'                         => $this->id,
			'team_id'                    => $this->team_id,
			'user_id'                    => $this->user_id,
			'member_email'               => $this->member_email,
			'title'                      => $this->title,
			'remind_at'                  => $this->remind_at,
			'recurrence'                 => $this->recurrence,
			'recurrence_interval'        => $this->recurrence_interval,
			'recurrence_end_type'        => $this->recurrence_end_type,
			'recurrence_end_occurrences' => $this->recurrence_end_occurrences,
			'recurrence_end_date'        => $this->recurrence_end_date,
			'task_link'                  => $this->task_link,
			'comments'                   => $this->comments,
			'status'                     => $this->status,
			'attempts'                   => $this->attempts,
			'next_attempt_at'            => $this->next_attempt_at,
			'created_at'                 => $this->created_at,
		);
	}
}
