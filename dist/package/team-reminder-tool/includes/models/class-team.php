<?php
/**
 * Team model.
 *
 * @package Aditya\ReminderTool
 */

namespace Aditya\ReminderTool\Models;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Team
 */
class Team {

	/** @var int */
	public int $id = 0;

	/** @var string */
	public string $name = '';

	/** @var string */
	public string $slug = '';

	/** @var string */
	public string $slack_channel = '';

	/** @var string */
	public string $slack_mention_tag = '';

	/** @var string */
	public string $quick_schedule_hours = '4,12,48';

	/** @var int */
	public int $created_by = 0;

	/** @var string */
	public string $created_at = '';

	/**
	 * Build team from DB row.
	 *
	 * @param \stdClass $row DB row.
	 * @return static
	 */
	public static function from_row( \stdClass $row ): static {
		$instance                = new static();
		$instance->id            = (int) $row->id;
		$instance->name          = (string) $row->name;
		$instance->slug          = (string) $row->slug;
		$instance->slack_channel     = isset( $row->slack_channel ) ? (string) $row->slack_channel : '';
		$instance->slack_mention_tag = isset( $row->slack_mention_tag ) ? (string) $row->slack_mention_tag : '';
		$instance->quick_schedule_hours = isset( $row->quick_schedule_hours ) ? (string) $row->quick_schedule_hours : '4,12,48';
		$instance->created_by    = (int) $row->created_by;
		$instance->created_at    = (string) $row->created_at;
		return $instance;
	}
}
