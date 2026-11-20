<?php
// Run against a disposable WordPress with NotifyCrew active (it deletes all reminders): wp eval-file tests/smoke/slack-block-limits.php
// Smoke test: long titles, notes and links must still make a message Slack accepts (header <= 150, field <= 2000,
// section <= 3000 characters, emoji counting as two), short ones must go out unchanged, and a title longer than
// the 255-character column must be saved shortened instead of failing.
use Aditya\NotifyCrew\Services\{Slack_Service, Team_Service, Reminder_Service, Cron_Service};
global $wpdb;
wp_set_current_user( 1 );
$wpdb->query( "DELETE FROM {$wpdb->prefix}ncrw_reminders" );
Slack_Service::get_instance()->save_mode( 'webhook' );
Slack_Service::get_instance()->save_webhook( 'https://hooks.slack.com/services/T000/B000/XXXX' );
$team    = Team_Service::get_instance()->create_team( 'QA ' . wp_generate_password( 4, false ) );
$team_id = is_object( $team ) ? $team->id : (int) $team;

$fail  = 0;
$check = function ( $ok, $label ) use ( &$fail ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . ": $label\n";
	if ( ! $ok ) {
		$fail++;
	}
};
$len = array( Slack_Service::class, 'text_length' );

// Helper: emoji count as two, cut text ends with an ellipsis and fits.
$check( 2 === $len( '🔔' ) && 5 === $len( 'abcde' ), 'text_length counts emoji as two units' );
$check( 'short' === Slack_Service::fit_text( 'short', 150 ), 'fit_text leaves short text alone' );
$cut = Slack_Service::fit_text( str_repeat( '😀', 100 ), 150 );
$check( $len( $cut ) <= 150 && '…' === mb_substr( $cut, -1 ), 'fit_text cuts emoji text to the limit with an ellipsis' );

// Capture every payload sent to Slack and answer like Slack does.
$payloads = array();
add_filter(
	'pre_http_request',
	function ( $pre, $args ) use ( &$payloads ) {
		$payloads[] = json_decode( $args['body'], true );
		return array( 'response' => array( 'code' => 200, 'message' => 'OK' ), 'body' => 'ok', 'headers' => array(), 'cookies' => array() );
	},
	10,
	2
);
add_filter( 'pre_wp_mail', '__return_false' );

$long_title = str_repeat( 'Quarterly 🚀 review ', 20 ); // 400 characters with emoji.
$long_notes = str_repeat( "Step: check the release notes & the changelog <for each plugin>.\n", 90 ); // ~5,900.
$long_link  = 'https://example.com/tickets/' . str_repeat( 'a', 1990 );
$past       = gmdate( 'Y-m-d H:i:s', time() - 60 );

$long_id = Reminder_Service::get_instance()->create(
	array( 'team_id' => $team_id, 'title' => $long_title, 'remind_at' => $past, 'task_link' => $long_link, 'comments' => $long_notes, 'recurrence' => 'none' )
);
$check( (bool) $long_id, 'a 400-character title is saved instead of failing' );
$stored = (string) $wpdb->get_var( $wpdb->prepare( "SELECT title FROM {$wpdb->prefix}ncrw_reminders WHERE id = %d", $long_id ) );
$check( '' !== $stored && mb_strlen( $stored ) <= 255, 'stored title is at most 255 characters (' . mb_strlen( $stored ) . ')' );

// A title that fits the column but not Slack's header, so the payload check doesn't depend on the title cap.
$mid_title = str_repeat( 'Sprint 🧪 retro ', 15 ); // 225 characters with emoji.
$mid_id    = Reminder_Service::get_instance()->create(
	array( 'team_id' => $team_id, 'title' => $mid_title, 'remind_at' => $past, 'task_link' => $long_link, 'comments' => $long_notes, 'recurrence' => 'none' )
);
$check( (bool) $mid_id, 'a 225-character title is saved' );

$short_id = Reminder_Service::get_instance()->create(
	array( 'team_id' => $team_id, 'title' => 'Daily standup', 'remind_at' => $past, 'task_link' => 'https://example.com/t/1', 'comments' => 'Bring blockers.', 'recurrence' => 'none' )
);

Cron_Service::get_instance()->process();
$check( 3 === count( $payloads ), 'all three reminders were sent once (' . count( $payloads ) . ' payloads)' );

$by_title = array();
foreach ( $payloads as $p ) {
	$text = (string) $p['text'];
	$by_title[ false !== strpos( $text, 'Daily standup' ) ? 'short' : ( false !== strpos( $text, 'Sprint' ) ? 'mid' : 'long' ) ] = $p;
}

// Every block of every payload within Slack's limits.
$within = true;
foreach ( $payloads as $p ) {
	foreach ( (array) $p['blocks'] as $b ) {
		if ( 'header' === $b['type'] && $len( $b['text']['text'] ) > Slack_Service::MAX_HEADER ) {
			$within = false;
		}
		if ( 'section' === $b['type'] && isset( $b['text'] ) && $len( $b['text']['text'] ) > Slack_Service::MAX_SECTION ) {
			$within = false;
		}
		foreach ( (array) ( $b['fields'] ?? array() ) as $f ) {
			if ( $len( $f['text'] ) > Slack_Service::MAX_FIELD ) {
				$within = false;
			}
		}
	}
}
$check( $within, 'every header, field and section is within Slack limits' );

$long = $by_title['long'] ?? array( 'blocks' => array(), 'text' => '' );
$mid  = $by_title['mid'] ?? array( 'blocks' => array(), 'text' => '' );
$check( false !== strpos( wp_json_encode( $mid['blocks'] ), 'https:\/\/example.com\/tickets\/' . str_repeat( 'a', 1990 ) ), 'the full long link is still in the message' );
$check( false !== strpos( (string) $long['text'], $stored ) && false !== strpos( (string) $mid['text'], trim( $mid_title ) ), 'notification text carries the whole stored title' );
$mid_header = $mid['blocks'][0]['text']['text'] ?? '';
$check( 0 === strpos( $mid_header, ':bell: Sprint' ) && '…' === mb_substr( $mid_header, -1 ), 'a long header is shortened with an ellipsis' );

$short  = $by_title['short'] ?? array( 'blocks' => array() );
$header = $short['blocks'][0]['text']['text'] ?? '';
$check( ':bell: Daily standup' === $header, 'a short title is unchanged in the header' );
$check( false !== strpos( wp_json_encode( $short['blocks'] ), 'Bring blockers.' ) && false === strpos( wp_json_encode( $short['blocks'] ), '…' ), 'short notes are unchanged' );

$statuses = $wpdb->get_col( "SELECT status FROM {$wpdb->prefix}ncrw_reminders" );
$check( ! in_array( 'pending', $statuses, true ) && ! in_array( 'failed', $statuses, true ), 'no reminder left pending or failed (' . implode( ',', $statuses ) . ')' );

exit( $fail ? 1 : 0 );
