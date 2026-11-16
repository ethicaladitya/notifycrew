<?php
// Run against a disposable WordPress with NotifyCrew active: wp eval-file tests/smoke/site-alerts.php
// Smoke test: site alerts reach Slack once per new update, automatic-update failures are reported, crash alerts never
// carry the recovery-mode login link and return WordPress's email unchanged, and nothing is sent while alerts are off.
use Aditya\NotifyCrew\Services\{Slack_Service, Site_Alerts_Service};
$fail  = 0;
$check = function ( $label, $ok, $got = '' ) use ( &$fail ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . ": $label" . ( $ok ? '' : " (got: $got)" ) . "\n";
	$fail += $ok ? 0 : 1;
};

$old_opts    = get_option( 'ncrw_options', array() );
$old_plugins = get_site_transient( 'update_plugins' );
Slack_Service::get_instance()->save_mode( 'webhook' );
Slack_Service::get_instance()->save_webhook( 'https://hooks.slack.com/services/T000/B000/XXXX' );
delete_option( Site_Alerts_Service::NOTIFIED_OPTION );
delete_option( Site_Alerts_Service::LAST_ERROR_OPTION );

$sent = array();
$code = 200;
add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( &$sent, &$code ) {
	if ( false === strpos( $url, 'hooks.slack.com' ) ) {
		return $pre;
	}
	$sent[] = (string) $args['body'];
	return array( 'response' => array( 'code' => $code, 'message' => 'x' ), 'body' => 200 === $code ? 'ok' : 'invalid_token', 'headers' => array(), 'cookies' => array() );
}, 10, 3 );
$alerts = Site_Alerts_Service::get_instance();
$set    = function ( array $opts ) use ( $old_opts ) {
	update_option( 'ncrw_options', array_merge( (array) $old_opts, $opts ) );
};

// A fake plugin update in WordPress's update data.
$fake           = new stdClass();
$fake->response = array(
	'hello.php' => (object) array( 'slug' => 'hello-dolly', 'new_version' => '9.9.9', 'package' => '' ),
);
$fake->checked  = array();
$fake->last_checked = time();

// Off: nothing.
$set( array( 'alerts_updates' => 0, 'alerts_auto_updates' => 'off', 'alerts_fatal' => 0 ) );
set_site_transient( 'update_plugins', $fake );
$alerts->check_updates();
$check( 'alerts off: no update message', 0 === count( $sent ), count( $sent ) );
$check( 'alerts off: no check queued', false === wp_next_scheduled( Site_Alerts_Service::CHECK_HOOK ) );

// On: one message, then none for the same version.
$set( array( 'alerts_updates' => 1 ) );
$fake->last_checked = time() + 1; // WordPress stamps every check, so the saved value changes and the hook fires.
set_site_transient( 'update_plugins', $fake );
$check( 'saving update data queues a check', false !== wp_next_scheduled( Site_Alerts_Service::CHECK_HOOK ) );
wp_clear_scheduled_hook( Site_Alerts_Service::CHECK_HOOK );
$alerts->check_updates();
$check( 'new plugin update announced once', 1 === count( $sent ) && false !== strpos( end( $sent ), '9.9.9' ), count( $sent ) . ' ' . substr( (string) end( $sent ), 0, 200 ) );
$alerts->check_updates();
$check( 'same version not announced again', 1 === count( $sent ), count( $sent ) );

// A newer version is news again; a Slack failure is remembered and retried.
$fake->response['hello.php']->new_version = '10.0.0';
set_site_transient( 'update_plugins', $fake );
wp_clear_scheduled_hook( Site_Alerts_Service::CHECK_HOOK );
$code = 403;
$alerts->check_updates();
$last = get_option( Site_Alerts_Service::LAST_ERROR_OPTION );
$check( 'Slack failure recorded for the settings page', is_array( $last ) && false !== strpos( $last['message'], '403' ), wp_json_encode( $last ) );
$code = 200;
$alerts->check_updates();
$check( 'failed announcement retried on the next check', 3 === count( $sent ) && false !== strpos( end( $sent ), '10.0.0' ), count( $sent ) );
$check( 'success clears the recorded failure', false === get_option( Site_Alerts_Service::LAST_ERROR_OPTION, false ) );

// Automatic updates: failures only.
$set( array( 'alerts_updates' => 0, 'alerts_auto_updates' => 'failures' ) );
$ok_item   = (object) array( 'name' => 'Akismet', 'result' => true );
$bad_item  = (object) array( 'name' => 'Broken Plugin', 'result' => new WP_Error( 'x', 'Could not copy file.' ) );
$before    = count( $sent );
$alerts->on_automatic_updates( array( 'plugin' => array( $ok_item ) ) );
$check( 'failures-only: a clean run sends nothing', count( $sent ) === $before, count( $sent ) - $before );
$alerts->on_automatic_updates( array( 'plugin' => array( $ok_item, $bad_item ) ) );
$body = (string) end( $sent );
$check( 'failures-only: a failure is reported with its reason', count( $sent ) === $before + 1 && false !== strpos( $body, 'Broken Plugin' ) && false !== strpos( $body, 'Could not copy file.' ) && false === strpos( $body, 'Akismet' ), substr( $body, 0, 300 ) );
$set( array( 'alerts_auto_updates' => 'all' ) );
$alerts->on_automatic_updates( array( 'plugin' => array( $ok_item ), 'translation' => array( (object) array( 'name' => 'fr_FR', 'result' => true ) ) ) );
$body = (string) end( $sent );
$check( 'all: a clean run is reported, translations left out', count( $sent ) === $before + 2 && false !== strpos( $body, 'Akismet' ) && false === strpos( $body, 'fr_FR' ), substr( $body, 0, 300 ) );

// Crash alert: no recovery link, email returned unchanged.
$set( array( 'alerts_fatal' => 1, 'alerts_auto_updates' => 'off' ) );
$email = array( 'to' => 'admin@example.test', 'subject' => 'x', 'message' => 'Log in: https://example.test/wp-login.php?action=enter_recovery_mode&rm_token=SECRET', 'headers' => '' );
$back  = $alerts->on_recovery_mode_email( $email );
$body  = (string) end( $sent );
$check( 'crash alert sent', count( $sent ) === $before + 3, count( $sent ) - $before );
$check( 'crash alert has no recovery-mode link', false === strpos( $body, 'rm_token' ) && false === strpos( $body, 'enter_recovery_mode' ), substr( $body, 0, 300 ) );
$check( 'WordPress email returned unchanged', $back === $email );
$set( array( 'alerts_fatal' => 0 ) );
$alerts->on_recovery_mode_email( $email );
$check( 'crash alerts off: nothing sent', count( $sent ) === $before + 3, count( $sent ) - $before );

// Test button.
$check( 'test alert sends', true === $alerts->send_test() && false !== strpos( (string) end( $sent ), 'Test alert' ) );

// Slack mrkdwn escaping of names.
$fake->response = array( 'x/x.php' => (object) array( 'new_version' => '1.0' ) );
set_site_transient( 'update_plugins', $fake );
wp_clear_scheduled_hook( Site_Alerts_Service::CHECK_HOOK );
$set( array( 'alerts_updates' => 1 ) );
$alerts->check_updates();
$check( 'every message is valid Slack JSON with a section block', ! in_array( null, array_map( function ( $b ) { $j = json_decode( $b, true ); return isset( $j['blocks'][0]['text']['text'] ) ? 1 : null; }, $sent ), true ) );

// Review fixes (1.2.0): escaped link labels, decoded site name, capped long messages, remembered fatal error.
$old_name = get_option( 'blogname' );
update_option( 'blogname', "Joe's Pizza & Co" );
$set( array( 'alerts_auto_updates' => 'failures', 'alerts_updates' => 0 ) );
$many = array();
for ( $i = 1; $i <= 30; $i++ ) {
	$many[] = (object) array( 'name' => "Plugin $i", 'result' => new WP_Error( 'x', str_repeat( 'Disk full while copying files. ', 8 ) ) );
}
$before = count( $sent );
$alerts->on_automatic_updates( array( 'plugin' => $many ) );
$j    = json_decode( (string) end( $sent ), true );
$text = (string) ( $j['blocks'][0]['text']['text'] ?? '' );
$check( 'long failure report stays under Slack\'s 3000-character limit', count( $sent ) === $before + 1 && mb_strlen( $text ) <= 3000, mb_strlen( $text ) );
$check( 'long failure report says how many more', false !== strpos( $text, 'more' ), substr( $text, -120 ) );
$check( 'site name decoded and escaped for Slack', false !== strpos( $text, "Joe's Pizza &amp; Co" ) && false === strpos( $text, '&#039;' ), substr( $text, 0, 120 ) );
$check( 'link label ">" escaped so the link stays whole', false !== strpos( $text, '|Open Dashboard &gt; Updates>' ), substr( $text, -80 ) );
update_option( 'blogname', $old_name );

$set( array( 'alerts_fatal' => 1, 'alerts_auto_updates' => 'off' ) );
// WordPress's email ends with an underlined "Error Details" block built from the error it handled.
$crash_email            = $email;
$crash_email['message'] = "Howdy!\n\nSome text.\n\nError Details\n=============\nAn error of type E_ERROR was caused in line 7 of the file " . ABSPATH . "wp-content/plugins/broken/broken.php. Error message: Uncaught Error: the real crash\nStack trace:\n#0 {main}";
trigger_error( 'a later notice', E_USER_NOTICE ); // phpcs:ignore -- replaces error_get_last() on purpose.
$back = $alerts->on_recovery_mode_email( $crash_email );
$check( 'crash email returned unchanged', $back === $crash_email );
$j    = json_decode( (string) end( $sent ), true );
$body = (string) ( $j['blocks'][0]['text']['text'] ?? '' );
$check( 'crash alert names the error WordPress handled, not a later notice', false !== strpos( $body, 'the real crash' ) && false === strpos( $body, 'a later notice' ), substr( $body, 0, 300 ) );
$check( 'crash alert path is relative', false !== strpos( $body, 'the file wp-content/plugins/broken/broken.php' ) && false === strpos( $body, ABSPATH ), substr( $body, 0, 300 ) );
$check( 'stack trace left out', false === strpos( $body, 'Stack trace' ), substr( $body, 0, 300 ) );
$check( 'crash hook registered', false !== has_filter( 'recovery_mode_email', array( $alerts, 'on_recovery_mode_email' ) ) );

update_option( 'ncrw_options', $old_opts );
set_site_transient( 'update_plugins', $old_plugins );
delete_option( Site_Alerts_Service::NOTIFIED_OPTION );
wp_clear_scheduled_hook( Site_Alerts_Service::CHECK_HOOK );
exit( $fail ? 1 : 0 );
