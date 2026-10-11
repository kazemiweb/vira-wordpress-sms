<?php
/**
 * The one-time code: issued, stored hashed, spent once, and thrown away when it expires.
 *
 * Nothing here is kept in plain text. A code is stored as an HMAC of itself under a salt of
 * this installation, alongside the moment it stops being valid and the number of wrong guesses
 * it has taken, so a database read cannot reveal a code that is still usable. Every attempt to
 * send is throttled twice: once per number, which is what stops a visitor being bombarded, and
 * once per hour, which is what stops the SMS credit being drained.
 *
 * @package Vira_WordPress_SMS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Issues and checks one-time codes.
 */
class Vira_SMS_OTP {

	/**
	 * How many codes one number may ask for in an hour.
	 */
	const HOURLY_LIMIT = 5;

	/**
	 * Transient holding the live code for a number.
	 *
	 * @param string $mobile Ten digits.
	 * @return string
	 */
	private static function code_key( $mobile ) {
		return 'vira_sms_code_' . md5( $mobile );
	}

	/**
	 * Transient holding the moment the next code may be requested.
	 *
	 * @param string $mobile Ten digits.
	 * @return string
	 */
	private static function wait_key( $mobile ) {
		return 'vira_sms_wait_' . md5( $mobile );
	}

	/**
	 * Transient counting the codes requested in the current hour.
	 *
	 * @param string $mobile Ten digits.
	 * @return string
	 */
	private static function hourly_key( $mobile ) {
		return 'vira_sms_hour_' . md5( $mobile );
	}

	/**
	 * The HMAC of a code.
	 *
	 * @param string $mobile Ten digits.
	 * @param string $code   The code.
	 * @return string
	 */
	private static function hash( $mobile, $code ) {
		return hash_hmac( 'sha256', $mobile . '|' . $code, wp_salt( 'vira_sms_otp' ) );
	}

	/**
	 * Whether a code may be requested for this number right now.
	 *
	 * @param string $mobile Ten digits.
	 * @return true|WP_Error
	 */
	public static function can_send( $mobile ) {
		$wait = (int) get_transient( self::wait_key( $mobile ) );

		if ( $wait > time() ) {
			return new WP_Error(
				'vira_sms_too_soon',
				sprintf(
					/* translators: %s: number of seconds. */
					__( 'کد قبلی هنوز معتبر است. %s ثانیه دیگر می‌توانید کد تازه بگیرید.', 'vira-wordpress-sms' ),
					number_format_i18n( $wait - time() )
				),
				array( 'wait' => $wait - time() )
			);
		}

		$limit = (int) apply_filters( 'vira_sms_hourly_limit', self::HOURLY_LIMIT );
		$used  = (int) get_transient( self::hourly_key( $mobile ) );

		if ( $used >= $limit ) {
			return new WP_Error(
				'vira_sms_hourly_limit',
				__( 'تعداد درخواست کد برای این شماره در یک ساعت گذشته بیش از حد مجاز است. لطفاً کمی بعد دوباره تلاش کنید.', 'vira-wordpress-sms' )
			);
		}

		return true;
	}

	/**
	 * Issue a code for a number and remember it, hashed.
	 *
	 * @param string $mobile Ten digits.
	 * @return string|WP_Error The code to send, or the reason it was not issued.
	 */
	public static function issue( $mobile ) {
		$allowed = self::can_send( $mobile );

		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$length = Vira_SMS_Settings::code_length();
		$ttl    = Vira_SMS_Settings::code_ttl();
		$max    = 0;

		// A code of `$length` digits, drawn from a range that has no leading zero to lose.
		for ( $i = 0; $i < $length; $i++ ) {
			$max = ( $max * 10 ) + 9;
		}

		$code = (string) wp_rand( (int) str_pad( '1', $length, '0' ), $max );

		set_transient(
			self::code_key( $mobile ),
			array(
				'hash'     => self::hash( $mobile, $code ),
				'attempts' => 0,
				'expires'  => time() + $ttl,
			),
			$ttl
		);

		set_transient( self::wait_key( $mobile ), time() + Vira_SMS_Settings::resend_wait(), max( 60, Vira_SMS_Settings::resend_wait() + 60 ) );

		$used = (int) get_transient( self::hourly_key( $mobile ) );
		set_transient( self::hourly_key( $mobile ), $used + 1, HOUR_IN_SECONDS );

		return $code;
	}

	/**
	 * Check a code, spending it when it is correct.
	 *
	 * @param string $mobile Ten digits.
	 * @param string $code   What the visitor typed.
	 * @return true|WP_Error
	 */
	public static function verify( $mobile, $code ) {
		$stored = get_transient( self::code_key( $mobile ) );

		if ( ! is_array( $stored ) || empty( $stored['hash'] ) || empty( $stored['expires'] ) ) {
			return new WP_Error( 'vira_sms_no_code', __( 'کدی برای این شماره ثبت نشده است. لطفاً یک کد تازه بگیرید.', 'vira-wordpress-sms' ) );
		}

		if ( (int) $stored['expires'] < time() ) {
			self::clear( $mobile );

			return new WP_Error( 'vira_sms_expired', __( 'زمان این کد به پایان رسیده است. لطفاً کد تازه بگیرید.', 'vira-wordpress-sms' ) );
		}

		if ( (int) $stored['attempts'] >= Vira_SMS_Settings::max_attempts() ) {
			self::clear( $mobile );

			return new WP_Error( 'vira_sms_too_many', __( 'تعداد تلاش‌های نادرست زیاد شد. لطفاً یک کد تازه بگیرید.', 'vira-wordpress-sms' ) );
		}

		if ( ! hash_equals( $stored['hash'], self::hash( $mobile, $code ) ) ) {
			$stored['attempts'] = (int) $stored['attempts'] + 1;
			$remaining          = max( 0, Vira_SMS_Settings::max_attempts() - $stored['attempts'] );
			$ttl                = max( 1, (int) $stored['expires'] - time() );

			set_transient( self::code_key( $mobile ), $stored, $ttl );

			return new WP_Error(
				'vira_sms_wrong_code',
				sprintf(
					/* translators: %s: number of remaining attempts. */
					__( 'کد وارد شده درست نیست. %s تلاش دیگر می‌توانید امتحان کنید.', 'vira-wordpress-sms' ),
					number_format_i18n( $remaining )
				),
				array( 'remaining' => $remaining )
			);
		}

		// Correct: a code is good once.
		self::clear( $mobile );

		return true;
	}

	/**
	 * Forget the code and the throttle for a number.
	 *
	 * @param string $mobile Ten digits.
	 * @return void
	 */
	public static function clear( $mobile ) {
		delete_transient( self::code_key( $mobile ) );
	}

	/**
	 * Forget everything about a number, including its counters.
	 *
	 * @param string $mobile Ten digits.
	 * @return void
	 */
	public static function forget( $mobile ) {
		delete_transient( self::code_key( $mobile ) );
		delete_transient( self::wait_key( $mobile ) );
		delete_transient( self::hourly_key( $mobile ) );
	}
}
