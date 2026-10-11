<?php
/**
 * The four calls the form makes.
 *
 * Every step of the sign-in is one POST to `admin-ajax.php`, so the page never reloads and the
 * visitor never loses what they typed:
 *
 *   vira_sms_nonce     a fresh token for the form (a cached page cannot keep one alive)
 *   vira_sms_send_code mobile in, a code goes out by SMS
 *   vira_sms_verify_code code in; an existing account is signed in, a new number gets a ticket
 *                        that lets it finish registering, and nothing else can use that ticket
 *   vira_sms_register  name and family name, then the account is made and signed in
 *
 * Each one checks the form token, refuses anything but POST, and is counted against a per-address
 * ceiling so a script cannot use the form as a way to spend the owner's credit.
 *
 * @package Vira_WordPress_SMS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Answers the form.
 */
class Vira_SMS_Ajax {

	/**
	 * The nonce action the form is built with.
	 */
	const NONCE = 'vira_sms_form';

	/**
	 * Transient prefix for the ticket handed out after a code is verified.
	 */
	const TICKET = 'vira_sms_ticket_';

	/**
	 * How long that ticket lives, in seconds.
	 */
	const TICKET_TTL = 900;

	/**
	 * The longest name the form accepts.
	 */
	const NAME_LIMIT = 60;

	/**
	 * Hook every action, for visitors and for signed-in users alike.
	 *
	 * @return void
	 */
	public function register() {
		$map = array(
			'vira_sms_nonce'    => 'nonce',
			'vira_sms_send_code' => 'send_code',
			'vira_sms_verify_code' => 'verify_code',
			'vira_sms_register' => 'complete_registration',
		);

		foreach ( $map as $action => $method ) {
			add_action( 'wp_ajax_' . $action, array( $this, $method ) );
			add_action( 'wp_ajax_nopriv_' . $action, array( $this, $method ) );
		}
	}

	/**
	 * A fresh form token.
	 *
	 * The page holding the form may be served from the site's cache long after it was built, and
	 * a token baked into a page that old would be rejected. The form asks for a new one when it
	 * loads, which is why the markup's own token is a fallback rather than the only chance.
	 *
	 * @return void
	 */
	public function nonce() {
		$this->guard( false );

		wp_send_json_success( array( 'nonce' => wp_create_nonce( self::NONCE ) ) );
	}

	/**
	 * Send a code to a number.
	 *
	 * @return void
	 */
	public function send_code() {
		$this->guard();

		$mobile = Vira_SMS_Phone::normalize( (string) $this->post( 'mobile' ) );

		if ( '' === $mobile ) {
			$this->fail( 'vira_sms_bad_mobile', __( 'شماره موبایل را درست وارد کنید؛ مثلاً ۰۹۱۲۳۴۵۶۷۸۹.', 'vira-wordpress-sms' ) );
		}

		$code = Vira_SMS_OTP::issue( $mobile );

		if ( is_wp_error( $code ) ) {
			$this->fail( $code->get_error_code(), $code->get_error_message(), $code->get_error_data() );
		}

		$sender = new Vira_SMS_Sender();
		$sent   = $sender->send_code( $mobile, $code );

		if ( is_wp_error( $sent ) ) {
			/*
			 * A message that never left should not cost the visitor their next attempt, so the
			 * throttle for this number is cleared along with the code that went nowhere.
			 */
			Vira_SMS_OTP::forget( $mobile );
			$this->fail( $sent->get_error_code(), $sent->get_error_message(), $sent->get_error_data() );
		}

		$this->count_attempt();

		wp_send_json_success(
			array(
				'masked'       => Vira_SMS_Phone::mask( $mobile ),
				'length'       => Vira_SMS_Settings::code_length(),
				'ttl'          => Vira_SMS_Settings::code_ttl(),
				'resend_after' => Vira_SMS_Settings::resend_wait(),
			)
		);
	}

	/**
	 * Check a code and take the visitor to the next step.
	 *
	 * @return void
	 */
	public function verify_code() {
		$this->guard();

		$mobile = Vira_SMS_Phone::normalize( (string) $this->post( 'mobile' ) );

		if ( '' === $mobile ) {
			$this->fail( 'vira_sms_bad_mobile', __( 'شماره موبایل را دوباره وارد کنید.', 'vira-wordpress-sms' ) );
		}

		$code = preg_replace( '/\D/', '', Vira_SMS_Phone::to_ascii_digits( (string) $this->post( 'code' ) ) );
		$code = (string) $code;

		if ( '' === $code ) {
			$this->fail( 'vira_sms_empty_code', __( 'کد پیامک‌شده را وارد کنید.', 'vira-wordpress-sms' ) );
		}

		$checked = Vira_SMS_OTP::verify( $mobile, $code );

		if ( is_wp_error( $checked ) ) {
			$this->fail( $checked->get_error_code(), $checked->get_error_message(), $checked->get_error_data() );
		}

		$user = Vira_SMS_Users::find_by_mobile( $mobile );

		if ( $user instanceof WP_User ) {
			Vira_SMS_Users::login( $user );

			wp_send_json_success(
				array(
					'state'    => 'logged_in',
					'redirect' => Vira_SMS_Settings::redirect_url(),
					'message'  => __( 'خوش آمدید! در حال ورود به حساب…', 'vira-wordpress-sms' ),
				)
			);
		}

		// A number nobody has an account for yet: it may finish registering with this ticket.
		$ticket = wp_generate_password( 32, false );

		set_transient(
			self::TICKET . md5( $ticket ),
			array(
				'mobile'  => $mobile,
				'created' => time(),
			),
			self::TICKET_TTL
		);

		wp_send_json_success(
			array(
				'state'  => 'register',
				'ticket' => $ticket,
				'name'   => __( 'شماره شما تأیید شد', 'vira-wordpress-sms' ),
			)
		);
	}

	/**
	 * Finish registering a verified number.
	 *
	 * @return void
	 */
	public function complete_registration() {
		$this->guard();

		$ticket = sanitize_text_field( (string) $this->post( 'ticket' ) );
		$stored = '' === $ticket ? false : get_transient( self::TICKET . md5( $ticket ) );

		if ( ! is_array( $stored ) || empty( $stored['mobile'] ) ) {
			$this->fail( 'vira_sms_no_ticket', __( 'این مرحله منقضی شده است. لطفاً از ابتدا شماره موبایل خود را وارد کنید.', 'vira-wordpress-sms' ) );
		}

		$first_name = sanitize_text_field( (string) $this->post( 'first_name' ) );
		$last_name  = sanitize_text_field( (string) $this->post( 'last_name' ) );

		if ( '' === trim( $first_name ) || '' === trim( $last_name ) ) {
			$this->fail( 'vira_sms_names_required', __( 'نام و نام خانوادگی را وارد کنید.', 'vira-wordpress-sms' ) );
		}

		if ( mb_strlen( $first_name ) > self::NAME_LIMIT || mb_strlen( $last_name ) > self::NAME_LIMIT ) {
			$this->fail( 'vira_sms_names_long', __( 'نام یا نام خانوادگی بیش از حد بلند است.', 'vira-wordpress-sms' ) );
		}

		$user = Vira_SMS_Users::create( (string) $stored['mobile'], $first_name, $last_name );

		if ( is_wp_error( $user ) ) {
			$this->fail( $user->get_error_code(), $user->get_error_message() );
		}

		delete_transient( self::TICKET . md5( $ticket ) );
		$this->count_attempt();

		Vira_SMS_Users::login( $user );

		wp_send_json_success(
			array(
				'state'    => 'logged_in',
				'redirect' => Vira_SMS_Settings::redirect_url(),
				'message'  => __( 'ثبت نام انجام شد. در حال ورود به حساب…', 'vira-wordpress-sms' ),
			)
		);
	}

	/**
	 * Read one form field.
	 *
	 * @param string $key Field name.
	 * @return string
	 */
	private function post( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- every caller verifies the token first.
		return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? wp_unslash( (string) $_POST[ $key ] ) : '';
	}

	/**
	 * Stop a request that is not allowed to be answered.
	 *
	 * @param bool $check_token Whether the form token must be verified too.
	 * @return void
	 */
	private function guard( $check_token = true ) {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';

		if ( 'POST' !== strtoupper( $method ) ) {
			$this->fail( 'vira_sms_bad_method', __( 'درخواست نامعتبر است.', 'vira-wordpress-sms' ) );
		}

		if ( $check_token ) {
			$token = sanitize_text_field( (string) $this->post( 'nonce' ) );

			if ( '' === $token || ! wp_verify_nonce( $token, self::NONCE ) ) {
				$this->fail( 'vira_sms_bad_nonce', __( 'نشست این صفحه منقضی شده است. لطفاً صفحه را یک بار تازه کنید.', 'vira-wordpress-sms' ) );
			}
		}

		$this->throttle();
	}

	/**
	 * How many codes one address may ask for in an hour.
	 *
	 * @return string
	 */
	private function address_key() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		return '' === $ip ? '' : 'vira_sms_ip_' . md5( $ip );
	}

	/**
	 * Refuse the request when this address has asked for too many codes.
	 *
	 * @return void
	 */
	private function throttle() {
		$key = $this->address_key();

		if ( '' === $key ) {
			return;
		}

		$limit = (int) apply_filters( 'vira_sms_ip_hourly_limit', 20 );

		if ( (int) get_transient( $key ) >= $limit ) {
			$this->fail( 'vira_sms_ip_limit', __( 'تعداد درخواست‌های شما در یک ساعت گذشته زیاد بوده است. لطفاً کمی بعد دوباره تلاش کنید.', 'vira-wordpress-sms' ) );
		}
	}

	/**
	 * Count a code sent from this address.
	 *
	 * @return void
	 */
	private function count_attempt() {
		$key = $this->address_key();

		if ( '' === $key ) {
			return;
		}

		set_transient( $key, (int) get_transient( $key ) + 1, HOUR_IN_SECONDS );
	}

	/**
	 * Answer with a reason the form can show the visitor.
	 *
	 * @param string $code    Machine readable reason.
	 * @param string $message Persian message.
	 * @param mixed  $data    Anything extra for the form.
	 * @return void
	 */
	private function fail( $code, $message, $data = null ) {
		wp_send_json_error(
			array(
				'code'    => $code,
				'message' => $message,
				'data'    => $data,
			)
		);
	}
}
