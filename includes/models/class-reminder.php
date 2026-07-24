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

	/** @var int */
	public int $id = 0;

	/** @var int */
	public int $team_id = 0;

	/** @var int */
	public int $user_id = 0;

	/** @var string */
	public string $member_email = '';

	/** @var string */
	public string $title = '';

	/** @var string  DateTime string (YYYY-MM-DD HH:MM:SS) */
	public string $remind_at = '';

	/** @var string */
	public string $task_link = '';

	/** @var string */
	public string $comments = '';

	/** @var string pending|sent|completed */
	public string $status = 'pending';

	/** @var int */
	public int $attempts = 0;

	/** @var string|null */
	public ?string $next_attempt_at = null;

	/** @var string */
	public string $created_at = '';

	/**
	 * Build a Reminder from a raw DB row (stdClass).
	 *
	 * @param \stdClass $row Raw database row.
	 * @return static
	 */
	public static function from_row( \stdClass $row ): static {
		$instance                  = new static();
		$instance->id              = (int) $row->id;
		$instance->team_id         = (int) $row->team_id;
		$instance->user_id         = (int) $row->user_id;
		$instance->member_email    = isset( $row->member_email ) ? (string) $row->member_email : '';
		$instance->title           = (string) $row->title;
		$instance->remind_at       = (string) $row->remind_at;
		$instance->task_link       = (string) $row->task_link;
		$instance->comments        = (string) $row->comments;
		$instance->status          = (string) $row->status;
		$instance->attempts        = (int) $row->attempts;
		$instance->next_attempt_at = isset( $row->next_attempt_at ) ? (string) $row->next_attempt_at : null;
		$instance->created_at      = (string) $row->created_at;
		return $instance;
	}
}
