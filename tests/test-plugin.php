<?php
/**
 * The plugin's own logic, checked against a live WordPress.
 *
 * Nothing leaves the server: the HTTP calls are intercepted, and the two things this file does
 * write - a test connection in the settings for the length of the run, and the one-time codes it
 * makes - are put back and thrown away before it finishes. It can therefore be run on a
 * production site without spending a single message of the owner's credit.
 *
 *     wp eval-file tests/test-plugin.php
 *
 * Exits non-zero when a check fails.
 *
 * @package Vira_WordPress_SMS
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

foreach ( array( 'Vira_SMS_Phone', 'Vira_SMS_Settings', 'Vira_SMS_OTP', 'Vira_SMS_Gateway_IPPanel', 'Vira_SMS_Shortcode', 'Vira_SMS_Users' ) as $needed ) {
	if ( ! class_exists( $needed ) ) {
		WP_CLI::error( 'The plugin must be active to run these checks.' );
	}
}

$failures = 0;

$check = function ( $label, $condition, $detail = '' ) use ( &$failures ) {
	printf( "  %-66s %s%s\n", $label, $condition ? 'PASS' : 'FAIL', ( ! $condition && '' !== $detail ) ? '  (' . $detail . ')' : '' );

	if ( ! $condition ) {
		$failures++;
	}
};

WP_CLI::log( "\n== The mobile number ==" );

$numbers = array(
	'09123456789'      => '9123456789',
	'0912 345 6789'    => '9123456789',
	'۰۹۱۲۳۴۵۶۷۸۹'       => '9123456789',
	'٠٩١٢٣٤٥٦٧٨٩'       => '9123456789',
	'+989123456789'    => '9123456789',
	'+98 912 345 6789' => '9123456789',
	'00989123456789'   => '9123456789',
	'989123456789'     => '9123456789',
	'9123456789'       => '9123456789',
	'0912-345-6789'    => '9123456789',
	'0812345678'       => '',
	'912345678'        => '',
	'91234567890'      => '',
	'hello'            => '',
	''                 => '',
);

foreach ( $numbers as $input => $expected ) {
	$got = Vira_SMS_Phone::normalize( $input );
	$check( sprintf( 'normalize("%s") is "%s"', $input, $expected ), $got === $expected, 'got "' . $got . '"' );
}

$check( 'a number is the user name without its trunk zero', '9123456789' === Vira_SMS_Phone::to_login( '9123456789' ) );
$check( 'a number is E.164 for the service', '+989123456789' === Vira_SMS_Phone::to_e164( '9123456789' ) );
$check( 'a number is shown back with its trunk zero', '09123456789' === Vira_SMS_Phone::to_display( '9123456789' ) );
$check( 'a number is masked inside a sentence', '0912***6789' === Vira_SMS_Phone::mask( '9123456789' ) );

WP_CLI::log( "\n== The sender line ==" );

$check( '3000505 becomes +983000505', '+983000505' === Vira_SMS_Gateway_IPPanel::normalize_sender( '3000505' ) );
$check( '03000505 becomes +983000505', '+983000505' === Vira_SMS_Gateway_IPPanel::normalize_sender( '03000505' ) );
$check( '983000505 becomes +983000505', '+983000505' === Vira_SMS_Gateway_IPPanel::normalize_sender( '983000505' ) );
$check( '+98 300 0505 is left as it is', '+983000505' === Vira_SMS_Gateway_IPPanel::normalize_sender( '+98 300 0505' ) );

WP_CLI::log( "\n== The settings ==" );

$clean = Vira_SMS_Settings::sanitize(
	array(
		'code_length'      => '99',
		'code_ttl_minutes' => '5',
		'extra_params'     => "name=محمود\n\nbroken line\n  code = 999  ",
		'font'             => 'Vazirmatn"; color:red;',
		'base_url'         => 'https://edge.ippanel.com/v1/',
		'redirect_url'     => '',
	)
);

$check( 'the code length is held between 3 and 8', 8 === $clean['code_length'], (string) $clean['code_length'] );
$check( 'the lifetime is stored in seconds', 300 === $clean['code_ttl'], (string) $clean['code_ttl'] );
$check( 'extra parameters keep only key=value lines', "name=محمود\ncode=999" === $clean['extra_params'], $clean['extra_params'] );
$check( 'a font name cannot carry CSS syntax', false === strpos( $clean['font'], ';' ) && false === strpos( $clean['font'], ':' ) && false === strpos( $clean['font'], '{' ), $clean['font'] );
$check( 'an empty redirect is stored as empty', '' === $clean['redirect_url'] );

$stored_redirect = Vira_SMS_Settings::get( 'redirect_url' );

$check(
	'the redirect is the configured page, or the front page',
	( '' === $stored_redirect ? home_url( '/' ) : $stored_redirect ) === Vira_SMS_Settings::redirect_url(),
	Vira_SMS_Settings::redirect_url()
);

/*
 * A Persian value is the normal case for these parameters - the pattern's own text is Persian -
 * so it is read back the same way it was stored.
 */
$before_read = get_option( Vira_SMS_Settings::OPTION, null );

try {
	update_option( Vira_SMS_Settings::OPTION, array_merge( Vira_SMS_Settings::defaults(), array( 'extra_params' => $clean['extra_params'] ) ) );
	Vira_SMS_Settings::flush();

	$read = Vira_SMS_Settings::extra_params();

	$check( 'a Persian value is read back unharmed', array( 'name' => 'محمود', 'code' => '999' ) === $read, (string) wp_json_encode( $read ) );
} finally {
	if ( null === $before_read ) {
		delete_option( Vira_SMS_Settings::OPTION );
	} else {
		update_option( Vira_SMS_Settings::OPTION, $before_read );
	}

	Vira_SMS_Settings::flush();
}

WP_CLI::log( "\n== An unconfigured site ==" );

$before = get_option( Vira_SMS_Settings::OPTION, null );

try {
	update_option( Vira_SMS_Settings::OPTION, Vira_SMS_Settings::defaults() );
	Vira_SMS_Settings::flush();

	$sender = new Vira_SMS_Sender();
	$unset  = $sender->send_code( '9123456789', '12345' );

	$check( 'a site with no key refuses to send', is_wp_error( $unset ) && 'vira_sms_not_configured' === $unset->get_error_code() );

	WP_CLI::log( "\n== The request sent to the service ==" );

	// A test connection, never stored for longer than this run.
	update_option(
		Vira_SMS_Settings::OPTION,
		array_merge( Vira_SMS_Settings::defaults(), array( 'api_key' => 'TEST-KEY', 'sender' => '3000505', 'pattern_code' => 'PATTERN-CODE' ) )
	);
	Vira_SMS_Settings::flush();

	$captured = array();

	$intercept = function ( $pre, $args, $url ) use ( &$captured ) {
		$captured = array(
			'url'  => $url,
			'args' => $args,
		);

		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( array( 'data' => array( 'message_outbox_ids' => array( 1 ) ), 'meta' => array( 'status' => true, 'message' => 'انجام شد' ) ) ),
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'cookies'  => array(),
			'filename' => null,
		);
	};

	add_filter( 'pre_http_request', $intercept, 10, 3 );

	$gateway = new Vira_SMS_Gateway_IPPanel();
	$result  = $gateway->send_pattern( 'PATTERN-CODE', '3000505', '9123456789', array( 'code' => '12345' ) );

	$check( 'the request goes to {base_url}/api/send', isset( $captured['url'] ) && '/api/send' === substr( $captured['url'], -9 ), isset( $captured['url'] ) ? $captured['url'] : 'no request' );

	$body = isset( $captured['args']['body'] ) ? json_decode( $captured['args']['body'], true ) : array();

	$check( 'the method is POST', 'POST' === $captured['args']['method'] );
	$check( 'the content type is JSON', 'application/json' === $captured['args']['headers']['Content-Type'] );
	$check( 'the access key travels as Authorization', 'TEST-KEY' === $captured['args']['headers']['Authorization'] );
	$check( 'the sending type is a pattern', 'pattern' === ( $body['sending_type'] ?? '' ) );
	$check( 'the sender goes as E.164', '+983000505' === ( $body['from_number'] ?? '' ) );
	$check( 'the pattern code is sent', 'PATTERN-CODE' === ( $body['code'] ?? '' ) );
	$check( 'the recipient goes as E.164', array( '+989123456789' ) === ( $body['recipients'] ?? array() ) );
	$check( 'the code travels as a pattern value', '12345' === ( $body['params']['code'] ?? '' ) );
	$check( 'a successful answer is read as success', is_array( $result ) && array( 1 ) === $result['outbox'], is_wp_error( $result ) ? $result->get_error_message() : '' );

	remove_filter( 'pre_http_request', $intercept, 10 );

	WP_CLI::log( "\n== The service's answers ==" );

	add_filter(
		'pre_http_request',
		function () {
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode( array( 'data' => null, 'meta' => array( 'status' => false, 'message' => 'اطلاعات وارد شده صحیح نمی باشد', 'message_code' => '400-1' ) ) ),
				'response' => array( 'code' => 401, 'message' => 'Unauthorized' ),
				'cookies'  => array(),
				'filename' => null,
			);
		},
		10,
		0
	);

	$bad = ( new Vira_SMS_Gateway_IPPanel() )->send_pattern( 'PATTERN-CODE', '3000505', '9123456789', array( 'code' => '12345' ) );

	$check( 'a rejected key is read as an error', is_wp_error( $bad ) );
	$check( 'the service\'s own words are passed on', is_wp_error( $bad ) && false !== strpos( $bad->get_error_message(), 'اطلاعات وارد شده صحیح نمی باشد' ) );

	remove_all_filters( 'pre_http_request' );

	add_filter(
		'pre_http_request',
		function () {
			return new WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect' );
		},
		10,
		0
	);

	$down = ( new Vira_SMS_Gateway_IPPanel() )->send_pattern( 'PATTERN-CODE', '3000505', '9123456789', array( 'code' => '12345' ) );

	$check( 'an unreachable service is read as an error', is_wp_error( $down ) );

	remove_all_filters( 'pre_http_request' );
} finally {
	if ( null === $before ) {
		delete_option( Vira_SMS_Settings::OPTION );
	} else {
		update_option( Vira_SMS_Settings::OPTION, $before );
	}

	Vira_SMS_Settings::flush();

	remove_all_filters( 'pre_http_request' );
}

WP_CLI::log( "\n== The one-time code ==" );

$fake = '9000000001';
Vira_SMS_OTP::forget( $fake );

$issued = Vira_SMS_OTP::issue( $fake );

$check( 'a code is issued', is_string( $issued ) && strlen( $issued ) === Vira_SMS_Settings::code_length(), is_wp_error( $issued ) ? $issued->get_error_message() : (string) $issued );
$check( 'the code is made of digits', is_string( $issued ) && ctype_digit( $issued ) );
$check( 'a wrong code is refused', is_wp_error( Vira_SMS_OTP::verify( $fake, '0000000' ) ) );
$check( 'the right code is accepted', true === Vira_SMS_OTP::verify( $fake, $issued ) );
$check( 'a code works only once', is_wp_error( Vira_SMS_OTP::verify( $fake, $issued ) ) );

Vira_SMS_OTP::forget( $fake );
Vira_SMS_OTP::issue( $fake );
$second = Vira_SMS_OTP::issue( $fake );

$check( 'a second code waits for the throttle', is_wp_error( $second ) && 'vira_sms_too_soon' === $second->get_error_code() );

$code = get_transient( 'vira_sms_code_' . md5( $fake ) );

$check( 'a stored code is not kept in the clear', is_array( $code ) && isset( $code['hash'] ) && ! isset( $code['code'] ) && $code['hash'] !== $issued );

Vira_SMS_OTP::forget( $fake );

WP_CLI::log( "\n== The accounts ==" );

$check( 'a number nobody registered has no account', false === Vira_SMS_Users::find_by_mobile( $fake ) );
$check( 'WordPress lets an account exist with no address at all', false === email_exists( '' ) );
$check( 'a user name is the number, not an email', '9123456789' === Vira_SMS_Phone::to_login( '9123456789' ) );

$expected_role = get_role( 'customer' ) ? 'customer' : 'subscriber';

$check( 'a new account takes the shop\'s customer role', $expected_role === Vira_SMS_Users::new_user_role(), Vira_SMS_Users::new_user_role() );

WP_CLI::log( "\n== The other sign-in doors ==" );

$check( 'the sign-in page setting starts empty', '' === Vira_SMS_Settings::login_url() );
$check( 'with no page set, a login link is left alone', 'https://example.test/wp-login.php' === Vira_SMS_Login_Links::login_url( 'https://example.test/wp-login.php', '', false ) );
$check( 'with no page set, signing out is left alone', 'https://example.test/goodbye' === Vira_SMS_Login_Links::logout_redirect( 'https://example.test/goodbye', '', null ) );

// Point the setting at a page for a moment, then put the option back exactly as it was.
$stored    = get_option( Vira_SMS_Settings::OPTION, false );
$form_page = home_url( '/sign-in-page-check/' );

update_option( Vira_SMS_Settings::OPTION, array_merge( (array) $stored, array( 'login_url' => $form_page ) ) );
Vira_SMS_Settings::flush();

$check( 'the setting is read back', $form_page === Vira_SMS_Settings::login_url() );
$check( 'a front-end login link now points at the form', $form_page === Vira_SMS_Login_Links::login_url( 'https://example.test/wp-login.php', home_url( '/shop/' ), false ) );
$check( 'the admin side keeps its own door', 'https://example.test/wp-login.php' === Vira_SMS_Login_Links::login_url( 'https://example.test/wp-login.php', admin_url( 'edit.php' ), false ) );
$check( 'signing out now ends on the form', $form_page === Vira_SMS_Login_Links::logout_redirect( home_url( '/my-account/' ), '', null ) );

if ( false === $stored ) {
	delete_option( Vira_SMS_Settings::OPTION );
} else {
	update_option( Vira_SMS_Settings::OPTION, $stored );
}

Vira_SMS_Settings::flush();

$check( 'the setting is put back as it was', '' === Vira_SMS_Settings::login_url() );

WP_CLI::log( "\n== The header button ==" );

$stored = get_option( Vira_SMS_Settings::OPTION, false );
$page   = home_url( '/sign-in-check/' );
$mine   = home_url( '/account-check/' );

update_option( Vira_SMS_Settings::OPTION, array_merge( (array) $stored, array( 'login_url' => $page, 'account_url' => $mine ) ) );
Vira_SMS_Settings::flush();

$guest = do_shortcode( '[' . Vira_SMS_Account_Link::TAG . ']' );

$check( 'a visitor is offered sign-in', false !== strpos( $guest, 'ورود / ثبت‌نام' ) );
$check( 'the sign-in link is the page from the settings', false !== strpos( $guest, esc_url( $page ) ) );

wp_set_current_user( 1 );

$member = do_shortcode( '[' . Vira_SMS_Account_Link::TAG . ']' );

$check( 'a signed-in visitor is offered their account', false !== strpos( $member, 'حساب کاربری' ) );
$check( 'the account link is the page from the settings', false !== strpos( $member, esc_url( $mine ) ) );
$check( 'the two states print different links', $guest !== $member );

update_option( Vira_SMS_Settings::OPTION, array_merge( (array) $stored, array( 'login_url' => '', 'account_url' => '' ) ) );
Vira_SMS_Settings::flush();

$fallback = do_shortcode( '[' . Vira_SMS_Account_Link::TAG . ']' );
$shop     = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : '';

wp_set_current_user( 0 );

$check( 'with no setting the account button still leads somewhere', false !== strpos( $fallback, 'href="' ) && false === strpos( $fallback, 'href=""' ) );

if ( $shop ) {
	$check( 'and that somewhere is the shop account page', false !== strpos( $fallback, esc_url( $shop ) ) );
}

if ( false === $stored ) {
	delete_option( Vira_SMS_Settings::OPTION );
} else {
	update_option( Vira_SMS_Settings::OPTION, $stored );
}

Vira_SMS_Settings::flush();

$check( 'the settings are back as they were', '' === Vira_SMS_Settings::login_url() && '' === Vira_SMS_Settings::account_url() );

WP_CLI::log( "\n== The form ==" );

$html = do_shortcode( '[' . Vira_SMS_Shortcode::TAG . ']' );

$check( 'the form renders', false !== strpos( $html, 'data-vira-sms' ) );
$check( 'it asks for the mobile number', false !== strpos( $html, 'name="mobile"' ) );
$check( 'it asks for the code', false !== strpos( $html, 'name="code"' ) );
$check( 'it asks for the given name', false !== strpos( $html, 'name="first_name"' ) );
$check( 'it asks for the family name', false !== strpos( $html, 'name="last_name"' ) );
$check( 'it never asks for an email', false === strpos( $html, 'name="email"' ) && false === stripos( $html, 'type="email"' ) );
$check( 'it never asks for a password', false === strpos( $html, 'type="password"' ) );
$check( 'it has three steps', 3 === substr_count( $html, ' data-step=' ) );
$check( 'only the first step is shown', 2 === substr_count( $html, 'hidden>' ), (string) substr_count( $html, 'hidden>' ) );
$check( 'every button carries its own spinner', 3 === substr_count( $html, 'vira-sms__spinner' ) );
$check( 'the stylesheet is loaded with it', wp_style_is( 'vira-sms', 'enqueued' ) );
$check( 'the script is loaded with it', wp_script_is( 'vira-sms', 'enqueued' ) );

WP_CLI::log( '' );

if ( $failures ) {
	WP_CLI::error( $failures . ' check(s) failed.' );
}

WP_CLI::success( 'Every check passed; nothing was sent.' );
