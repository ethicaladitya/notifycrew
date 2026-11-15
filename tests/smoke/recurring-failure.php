<?php
// Run against a disposable WordPress with NotifyCrew active (it deletes all reminders): wp eval-file tests/smoke/recurring-failure.php
// Smoke test: a daily reminder whose every Slack delivery fails must still produce tomorrow's occurrence.
use Aditya\NotifyCrew\Services\{Slack_Service, Team_Service, Reminder_Service, Cron_Service};
global $wpdb;
wp_set_current_user( 1 );
$wpdb->query( "DELETE FROM {$wpdb->prefix}ncrw_reminders" );
Slack_Service::get_instance()->save_mode( 'webhook' );
Slack_Service::get_instance()->save_webhook( 'https://hooks.slack.com/services/T000/B000/XXXX' );
$team = Team_Service::get_instance()->create_team( 'QA ' . wp_generate_password( 4, false ) );
$team_id = is_object( $team ) ? $team->id : (int) $team;
$id = Reminder_Service::get_instance()->create( array(
	'team_id' => $team_id, 'title' => 'Daily standup', 'remind_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ),
	'recurrence' => 'daily', 'recurrence_interval' => 1, 'recurrence_end_type' => 'never',
) );
if ( ! $id ) { echo "FAIL: could not create reminder\n"; exit( 1 ); }
$http = 0;
add_filter( 'pre_http_request', function () use ( &$http ) { $http++;
	return array( 'response' => array( 'code' => 500, 'message' => 'Server Error' ), 'body' => 'slack down', 'headers' => array(), 'cookies' => array() ); } );
add_filter( 'pre_wp_mail', '__return_false' );
for ( $i = 0; $i < 6; $i++ ) {
	$wpdb->query( "UPDATE {$wpdb->prefix}ncrw_reminders SET next_attempt_at = NULL WHERE next_attempt_at IS NOT NULL" );
	Cron_Service::get_instance()->process();
}
$rows = $wpdb->get_results( "SELECT id, status, attempts, remind_at FROM {$wpdb->prefix}ncrw_reminders ORDER BY id" );
foreach ( $rows as $r ) { echo "  #{$r->id} {$r->status} attempts={$r->attempts} remind_at={$r->remind_at}\n"; }
echo "  slack calls: $http\n";
$next = count( $rows ) === 2 && 'pending' === $rows[1]->status
	&& abs( strtotime( $rows[1]->remind_at . ' UTC' ) - strtotime( $rows[0]->remind_at . ' UTC' ) - DAY_IN_SECONDS ) < 5;
echo $next ? "PASS: series continues after 5 failed attempts\n" : "FAIL: no next occurrence\n";
exit( $next ? 0 : 1 );
