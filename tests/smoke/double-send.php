<?php
// Run against a disposable WordPress with NotifyCrew active (it deletes all reminders): wp eval-file tests/smoke/double-send.php
// Smoke test: overlapping runs (WP-Cron, Retry Now, REST /process) never send a reminder twice, a dead run's lock is
// taken over, and one run sends a bounded batch and schedules the rest.
use Aditya\NotifyCrew\Services\{Slack_Service, Team_Service, Reminder_Service, Cron_Service};
global $wpdb;
wp_set_current_user( 1 );
$fail  = 0;
$check = function ( $label, $ok, $got = '' ) use ( &$fail ) {
	echo ( $ok ? '  ok   ' : '  FAIL ' ) . $label . ( '' !== $got ? " ($got)" : '' ) . "\n";
	if ( ! $ok ) { $fail++; }
};
$table = $wpdb->prefix . 'ncrw_reminders';
$wpdb->query( "DELETE FROM $table" );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", Cron_Service::LOCK_OPTION ) );
wp_clear_scheduled_hook( Cron_Service::HOOK . '_more' );
Slack_Service::get_instance()->save_mode( 'webhook' );
Slack_Service::get_instance()->save_webhook( 'https://hooks.slack.com/services/T000/B000/XXXX' );
$team    = Team_Service::get_instance()->create_team( 'QA ' . wp_generate_password( 4, false ) );
$team_id = is_object( $team ) ? $team->id : (int) $team;
$make    = function ( $n ) use ( $team_id ) {
	for ( $i = 0; $i < $n; $i++ ) {
		Reminder_Service::get_instance()->create( array( 'team_id' => $team_id, 'title' => "Due $i", 'remind_at' => gmdate( 'Y-m-d H:i:s', time() - 60 - $i ) ) );
	}
};
$lock_row = function () use ( $wpdb ) {
	return $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", Cron_Service::LOCK_OPTION ) );
};
$http   = 0;
$nested = array();
// Slack answers "ok"; while a send is in flight, a second run starts (what WP-Cron + Retry Now do on a busy site).
add_filter( 'pre_http_request', function () use ( &$http, &$nested ) {
	$http++;
	if ( 1 === $http ) { $nested[] = Cron_Service::get_instance()->process(); }
	return array( 'response' => array( 'code' => 200, 'message' => 'OK' ), 'body' => 'ok', 'headers' => array(), 'cookies' => array() );
} );
add_filter( 'pre_wp_mail', '__return_false' );

// 1. Overlap: three due reminders, a second run starts during the first send.
$make( 3 );
$ran = Cron_Service::get_instance()->process();
$check( 'first run processes', true === $ran );
$check( 'overlapping run is turned away', array( false ) === $nested, wp_json_encode( $nested ) );
$check( 'each reminder sent once', 3 === $http, "$http Slack calls" );
$check( 'all three marked sent', 3 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE status = 'sent'" ) );
$check( 'lock released after the run', null === $lock_row() );

// 2. A live lock from another run: nothing is sent.
$wpdb->query( "DELETE FROM $table" ); $http = 2; // > 1 so the filter doesn't nest again
$make( 1 );
$wpdb->insert( $wpdb->options, array( 'option_name' => Cron_Service::LOCK_OPTION, 'option_value' => time() . '|other', 'autoload' => 'no' ) );
$check( 'held lock: run skipped', false === Cron_Service::get_instance()->process() );
$check( 'held lock: nothing sent', 2 === $http, ( $http - 2 ) . ' Slack calls' );
$check( 'held lock: other run keeps its lock', '|other' === substr( (string) $lock_row(), -6 ) );

// 3. A lock left by a run that died 20 minutes ago is taken over.
$wpdb->update( $wpdb->options, array( 'option_value' => ( time() - 20 * MINUTE_IN_SECONDS ) . '|dead' ), array( 'option_name' => Cron_Service::LOCK_OPTION ) );
$check( 'stale lock: run takes over', true === Cron_Service::get_instance()->process() );
$check( 'stale lock: reminder sent once', 3 === $http, ( $http - 2 ) . ' Slack calls' );
$check( 'stale lock: released', null === $lock_row() );

// 4. Claims are atomic: the same snapshot can be claimed once.
$wpdb->query( "DELETE FROM $table" );
$make( 1 );
$due = Reminder_Service::get_instance()->get_due_for_processing();
$check( 'first claim wins', 1 === count( $due ) && Reminder_Service::get_instance()->claim( $due[0], 600 ) );
$check( 'second claim of the same row loses', ! Reminder_Service::get_instance()->claim( $due[0], 600 ) );
$check( 'claimed row is not due', array() === Reminder_Service::get_instance()->get_due_for_processing() );

// 5. Batches: 30 due, one run sends 25 and schedules a follow-up run for the rest.
$wpdb->query( "DELETE FROM $table" ); $http = 2;
$make( 30 );
Cron_Service::get_instance()->process();
$check( 'one run sends a batch of 25', 27 === $http, ( $http - 2 ) . ' Slack calls' );
$check( 'follow-up run scheduled', false !== wp_next_scheduled( Cron_Service::HOOK . '_more' ) );
Cron_Service::get_instance()->process();
$check( 'follow-up sends the other 5', 32 === $http && 30 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE status = 'sent'" ), ( $http - 2 ) . ' Slack calls' );

wp_clear_scheduled_hook( Cron_Service::HOOK . '_more' );
$wpdb->query( "DELETE FROM $table" );
echo $fail ? "FAIL: $fail check(s)\n" : "PASS: no duplicate sends\n";
exit( $fail ? 1 : 0 );
