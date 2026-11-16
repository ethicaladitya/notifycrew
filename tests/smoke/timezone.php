<?php
// Run against a disposable WordPress with NotifyCrew active (it deletes all reminders): wp eval-file tests/smoke/timezone.php
// Smoke test: times are typed and shown in the site timezone, stored in UTC, recurrences keep wall-clock time across DST,
// and Slack gets a <!date> token so each reader sees their own local time.
use Aditya\NotifyCrew\Time;
use Aditya\NotifyCrew\Services\{Slack_Service, Team_Service, Reminder_Service, Cron_Service};
global $wpdb;
wp_set_current_user( 1 );
$old_tz     = get_option( 'timezone_string' );
$old_offset = get_option( 'gmt_offset' );
$fail       = 0;
$check      = function ( $label, $ok, $got = '' ) use ( &$fail ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . ": $label" . ( $ok ? '' : " (got: $got)" ) . "\n";
	$fail += $ok ? 0 : 1;
};

update_option( 'timezone_string', 'Asia/Kolkata' );
$check( 'IST 10:00 input stored as 04:30 UTC', '2026-11-16 04:30:00' === Time::local_input_to_utc( '2026-11-16T10:00' ), Time::local_input_to_utc( '2026-11-16T10:00' ) );
$check( 'explicit offset wins over site timezone', '2026-11-16 10:00:00' === Time::local_input_to_utc( '2026-11-16T10:00:00+00:00' ), Time::local_input_to_utc( '2026-11-16T10:00:00+00:00' ) );
$check( 'garbage input rejected', '' === Time::local_input_to_utc( 'next tuesday-ish' ) );
$check( 'impossible date rejected', '' === Time::local_input_to_utc( '2026-02-31T10:00' ), Time::local_input_to_utc( '2026-02-31T10:00' ) );
$check( 'stored UTC shown back as IST input', '2026-11-16T10:00' === Time::utc_to_local_input( '2026-11-16 04:30:00' ), Time::utc_to_local_input( '2026-11-16 04:30:00' ) );
$check( 'display shows site time', false !== strpos( Time::display( '2026-11-16 04:30:00', 'H:i' ), '10:00' ), Time::display( '2026-11-16 04:30:00', 'H:i' ) );

// DST: a daily 10:00 New York reminder on the day before clocks go forward (2026-03-08) stays at 10:00 local.
update_option( 'timezone_string', 'America/New_York' );
$wpdb->query( "DELETE FROM {$wpdb->prefix}ncrw_reminders" );
Slack_Service::get_instance()->save_mode( 'webhook' );
Slack_Service::get_instance()->save_webhook( 'https://hooks.slack.com/services/T000/B000/XXXX' );
$team    = Team_Service::get_instance()->create_team( 'TZ ' . wp_generate_password( 4, false ) );
$team_id = is_object( $team ) ? $team->id : (int) $team;
$id      = Reminder_Service::get_instance()->create( array(
	'team_id' => $team_id, 'title' => 'Standup', 'remind_at' => Time::local_input_to_utc( '2026-03-07T10:00' ),
	'recurrence' => 'daily', 'recurrence_interval' => 1, 'recurrence_end_type' => 'never',
) );
$check( 'EST 10:00 stored as 15:00 UTC', '2026-03-07 15:00:00' === $wpdb->get_var( $wpdb->prepare( "SELECT remind_at FROM {$wpdb->prefix}ncrw_reminders WHERE id = %d", $id ) ) );
$body = '';
add_filter( 'pre_http_request', function ( $pre, $args ) use ( &$body ) {
	$body = (string) $args['body'];
	return array( 'response' => array( 'code' => 200, 'message' => 'OK' ), 'body' => 'ok', 'headers' => array(), 'cookies' => array() );
}, 10, 2 );
add_filter( 'pre_wp_mail', '__return_false' );
Cron_Service::get_instance()->process();
$next = $wpdb->get_var( $wpdb->prepare( "SELECT remind_at FROM {$wpdb->prefix}ncrw_reminders WHERE id <> %d ORDER BY id DESC LIMIT 1", $id ) );
$check( 'next occurrence is 10:00 EDT (14:00 UTC) after DST starts', '2026-03-08 14:00:00' === $next, (string) $next );
$ts = strtotime( '2026-03-07 15:00:00 UTC' );
$check( 'Slack message carries a <!date> token', false !== strpos( $body, '<!date^' . $ts . '^' ), substr( $body, 0, 300 ) );

update_option( 'timezone_string', $old_tz );
update_option( 'gmt_offset', $old_offset );
$wpdb->query( "DELETE FROM {$wpdb->prefix}ncrw_reminders" );
exit( $fail ? 1 : 0 );
