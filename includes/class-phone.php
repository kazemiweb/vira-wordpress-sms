<?php
/**
 * Persian and Arabic-Indic digits, and the one mobile structure this plugin accepts.
 *
 * The plugin signs people in with an Iranian mobile number and nothing else, so the number is
 * normalised in exactly one place: whatever a visitor types - Persian digits, Arabic digits,
 * spaces, a leading zero, a country code - becomes the same ten digits, which are then used as
 * the user name, as the E.164 number the SMS service expects, and as the key the one-time code
 * is filed under.
 *
 * @package Vira_WordPress_SMS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Turns anything a visitor can type into the number this plugin agrees on.
 */
class Vira_SMS_Phone {

	/**
	 * The country code the SMS service wants, without the plus.
	 */
	const COUNTRY_CODE = '98';

	/**
	 * Replace Persian and Arabic-Indic digits with their ASCII equivalents.
	 *
	 * @param string $value Raw input.
	 * @return string
	 */
	public static function to_ascii_digits( $value ) {
		$persian = array( '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹' );
		$arabic  = array( '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩' );
		$ascii   = array( '0', '1', '2', '3', '4', '5', '6', '7', '8', '9' );

		return str_replace( array_merge( $persian, $arabic ), array_merge( $ascii, $ascii ), (string) $value );
	}

	/**
	 * The ten digits of an Iranian mobile, or an empty string when the input is not one.
	 *
	 * Accepts `0912 345 6789`, `۰۹۱۲۳۴۵۶۷۸۹`, `+98 912 345 6789`, `00989123456789` and
	 * `9123456789` alike, and returns `9123456789` for all of them.
	 *
	 * @param string $value Raw input.
	 * @return string
	 */
	public static function normalize( $value ) {
		$value = self::to_ascii_digits( $value );
		$value = preg_replace( '/[^0-9+]/', '', $value );
		$value = (string) $value;

		if ( '' === $value ) {
			return '';
		}

		// Strip the country code in the three shapes it arrives in.
		if ( 0 === strpos( $value, '+' ) ) {
			$value = ltrim( $value, '+' );
		}

		if ( 0 === strpos( $value, '00' . self::COUNTRY_CODE ) ) {
			$value = substr( $value, strlen( '00' . self::COUNTRY_CODE ) );
		} elseif ( 0 === strpos( $value, self::COUNTRY_CODE ) && strlen( $value ) > 10 ) {
			$value = substr( $value, strlen( self::COUNTRY_CODE ) );
		}

		// Drop the trunk zero, and any that a visitor typed twice.
		$value = ltrim( $value, '0' );

		if ( ! self::is_mobile( $value ) ) {
			return '';
		}

		return $value;
	}

	/**
	 * Whether ten digits are a structurally valid Iranian mobile number.
	 *
	 * The country's mobile numbers are ten digits beginning with nine; the plugin deliberately
	 * checks no more than that, because a number that is valid today can be wrong tomorrow and
	 * the SMS itself is the real test.
	 *
	 * @param string $digits Ten digits, no country code.
	 * @return bool
	 */
	public static function is_mobile( $digits ) {
		return (bool) preg_match( '/^9\d{9}$/', (string) $digits );
	}

	/**
	 * The user name for a mobile number: the number itself, without its leading zero.
	 *
	 * @param string $digits Ten digits, no country code.
	 * @return string
	 */
	public static function to_login( $digits ) {
		return self::is_mobile( $digits ) ? (string) $digits : '';
	}

	/**
	 * The number as the SMS service wants it.
	 *
	 * @param string $digits Ten digits, no country code.
	 * @return string
	 */
	public static function to_e164( $digits ) {
		return self::is_mobile( $digits ) ? '+' . self::COUNTRY_CODE . $digits : '';
	}

	/**
	 * The number as a visitor recognises it, with the trunk zero.
	 *
	 * @param string $digits Ten digits, no country code.
	 * @return string
	 */
	public static function to_display( $digits ) {
		return self::is_mobile( $digits ) ? '0' . $digits : '';
	}

	/**
	 * A number safe to show back in a message, with the middle hidden.
	 *
	 * @param string $digits Ten digits, no country code.
	 * @return string
	 */
	public static function mask( $digits ) {
		if ( ! self::is_mobile( $digits ) ) {
			return '';
		}

		return '0' . substr( $digits, 0, 3 ) . '***' . substr( $digits, -4 );
	}
}
