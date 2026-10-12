<?php
/**
 * The plugin's settings: one option, read through typed getters.
 *
 * Everything the plugin can be configured with lives in a single option so a site can be
 * backed up, moved or compared in one row. The defaults are the ones a fresh install should
 * behave with: an empty connection (nothing is ever sent until a key is entered), a five
 * digit code that lives for two minutes, and a font inherited from the theme.
 *
 * @package Vira_WordPress_SMS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the plugin's settings.
 */
class Vira_SMS_Settings {

	/**
	 * The option name every setting is stored under.
	 */
	const OPTION = 'vira_sms_settings';

	/**
	 * The pattern placeholder the one-time code is written into by default.
	 */
	const DEFAULT_CODE_PARAM = 'code';

	/**
	 * Cached copy of the stored settings for the current request.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * The shape of the settings, with the value a fresh install starts from.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults() {
		return array(
			// Connection.
			'base_url'       => 'https://edge.ippanel.com/v1',
			'api_key'        => '',
			'sender'         => '',
			'pattern_code'   => '',
			'code_param'     => self::DEFAULT_CODE_PARAM,
			'extra_params'   => '',

			// The one-time code.
			'code_length'    => 5,
			'code_ttl'       => 120,
			'resend_wait'    => 60,
			'max_attempts'   => 5,

			// Where the visitor lands, where the other sign-in doors lead, and how the form looks.
			'redirect_url'   => '',
			'login_url'      => '',
			'font'           => '',
		);
	}

	/**
	 * Every stored setting, merged over the defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function all() {
		if ( null === self::$cache ) {
			$stored     = get_option( self::OPTION, array() );
			$stored     = is_array( $stored ) ? $stored : array();
			self::$cache = array_merge( self::defaults(), $stored );
		}

		return self::$cache;
	}

	/**
	 * One setting.
	 *
	 * @param string $key     Setting name.
	 * @param mixed  $default Value when the setting is missing.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$all = self::all();

		if ( ! array_key_exists( $key, $all ) ) {
			return $default;
		}

		return $all[ $key ];
	}

	/**
	 * Forget the cached copy, so a save in the same request is visible immediately.
	 *
	 * @return void
	 */
	public static function flush() {
		self::$cache = null;
	}

	/**
	 * The base URL of the SMS service, without a trailing slash.
	 *
	 * @return string
	 */
	public static function base_url() {
		$url = trim( (string) self::get( 'base_url' ) );

		if ( '' === $url ) {
			$url = self::defaults()['base_url'];
		}

		return untrailingslashit( $url );
	}

	/**
	 * The access key used in the Authorization header.
	 *
	 * @return string
	 */
	public static function api_key() {
		return trim( (string) self::get( 'api_key' ) );
	}

	/**
	 * The pattern placeholder that receives the one-time code.
	 *
	 * @return string
	 */
	public static function code_param() {
		$param = trim( (string) self::get( 'code_param' ) );

		return '' === $param ? self::DEFAULT_CODE_PARAM : $param;
	}

	/**
	 * Extra parameters to send with the pattern, as key => value.
	 *
	 * Written one per line as `key=value`, which is the least surprising way to keep a small
	 * map in a settings field and the easiest to review in a diff.
	 *
	 * @return array<string, string>
	 */
	public static function extra_params() {
		$raw   = (string) self::get( 'extra_params' );
		$pairs = array();

		$lines = preg_split( '/\R/u', $raw );
		$lines = is_array( $lines ) ? $lines : array();

		foreach ( $lines as $line ) {
			$line = trim( $line );

			if ( '' === $line || false === strpos( $line, '=' ) ) {
				continue;
			}

			list( $key, $value ) = explode( '=', $line, 2 );
			$key                 = trim( $key );

			if ( '' !== $key ) {
				$pairs[ $key ] = trim( $value );
			}
		}

		return $pairs;
	}

	/**
	 * How many digits the one-time code has.
	 *
	 * @return int
	 */
	public static function code_length() {
		$length = (int) self::get( 'code_length' );

		return ( $length >= 3 && $length <= 8 ) ? $length : 5;
	}

	/**
	 * How long a code stays valid, in seconds.
	 *
	 * @return int
	 */
	public static function code_ttl() {
		$ttl = (int) self::get( 'code_ttl' );

		return $ttl >= 30 ? $ttl : 120;
	}

	/**
	 * How long a visitor waits before another code can be requested, in seconds.
	 *
	 * @return int
	 */
	public static function resend_wait() {
		$wait = (int) self::get( 'resend_wait' );

		return $wait >= 0 ? $wait : 60;
	}

	/**
	 * How many wrong codes a visitor may enter before the code is thrown away.
	 *
	 * @return int
	 */
	public static function max_attempts() {
		$attempts = (int) self::get( 'max_attempts' );

		return $attempts >= 1 ? $attempts : 5;
	}

	/**
	 * Where the visitor is sent once signed in. Empty means the site's front page.
	 *
	 * @return string
	 */
	public static function redirect_url() {
		$url = trim( (string) self::get( 'redirect_url' ) );

		if ( '' === $url ) {
			return home_url( '/' );
		}

		return $url;
	}

	/**
	 * The page holding the form, used wherever the front end would ask for a password.
	 *
	 * Empty means the plugin leaves every sign-in link, the account page and the logout flow
	 * exactly as the site already has them.
	 *
	 * @return string
	 */
	public static function login_url() {
		$url = trim( (string) self::get( 'login_url' ) );

		return ( '' === $url ) ? '' : $url;
	}

	/**
	 * The font every part of the form is drawn in. Empty means the theme's own font.
	 *
	 * @return string
	 */
	public static function font() {
		$font = trim( (string) self::get( 'font' ) );

		if ( '' === $font ) {
			return '';
		}

		// A font name may be a stack; keep only characters a font stack can contain.
		$font = preg_replace( '/[^A-Za-z0-9\s,"\'\-_]/u', '', $font );

		return trim( (string) $font );
	}

	/**
	 * Whether the connection is filled in far enough to attempt a send.
	 *
	 * @return bool
	 */
	public static function is_configured() {
		return ( '' !== self::api_key() && '' !== trim( (string) self::get( 'sender' ) ) && '' !== trim( (string) self::get( 'pattern_code' ) ) );
	}

	/**
	 * Clean a submitted settings array before it is stored.
	 *
	 * @param mixed $input Raw value from the settings form.
	 * @return array<string, mixed>
	 */
	public static function sanitize( $input ) {
		$current  = self::all();
		$defaults = self::defaults();
		$input    = is_array( $input ) ? $input : array();
		$clean    = array();

		$clean['base_url'] = isset( $input['base_url'] ) ? esc_url_raw( trim( (string) $input['base_url'] ) ) : $current['base_url'];

		if ( '' === $clean['base_url'] ) {
			$clean['base_url'] = $defaults['base_url'];
		}

		/*
		 * The key field is submitted empty on purpose most of the time: the stored key is never
		 * printed back into the page, so an empty submission means "leave it as it is". A filled
		 * submission replaces it.
		 */
		$submitted_key  = isset( $input['api_key'] ) ? trim( (string) $input['api_key'] ) : '';
		$clean['api_key'] = ( '' === $submitted_key ) ? $current['api_key'] : sanitize_text_field( $submitted_key );

		// An empty field means "keep it", so removing the key needs a control of its own.
		if ( ! empty( $input['clear_api_key'] ) ) {
			$clean['api_key'] = '';
		}

		$clean['sender']       = isset( $input['sender'] ) ? sanitize_text_field( trim( (string) $input['sender'] ) ) : $current['sender'];
		$clean['pattern_code'] = isset( $input['pattern_code'] ) ? sanitize_text_field( trim( (string) $input['pattern_code'] ) ) : $current['pattern_code'];
		$clean['code_param']   = isset( $input['code_param'] ) ? sanitize_text_field( trim( (string) $input['code_param'] ) ) : $current['code_param'];

		if ( '' === $clean['code_param'] ) {
			$clean['code_param'] = $defaults['code_param'];
		}

		// Extra parameters: one `key=value` per line, keys and values stripped of markup.
		$lines = preg_split( '/\R/u', isset( $input['extra_params'] ) ? (string) $input['extra_params'] : $current['extra_params'] );
		$lines = is_array( $lines ) ? $lines : array();
		$kept  = array();

		foreach ( (array) $lines as $line ) {
			$line = trim( sanitize_text_field( $line ) );

			if ( '' === $line ) {
				continue;
			}

			if ( false === strpos( $line, '=' ) ) {
				continue;
			}

			list( $key, $value ) = explode( '=', $line, 2 );
			$key                 = trim( $key );

			if ( '' !== $key ) {
				$kept[] = $key . '=' . trim( $value );
			}
		}

		$clean['extra_params'] = implode( "\n", $kept );

		$clean['code_length'] = isset( $input['code_length'] ) ? min( 8, max( 3, (int) $input['code_length'] ) ) : $current['code_length'];
		$clean['code_ttl']    = isset( $input['code_ttl'] ) ? max( 30, (int) $input['code_ttl'] ) : $current['code_ttl'];

		if ( isset( $input['code_ttl_minutes'] ) ) {
			$clean['code_ttl'] = max( 1, (int) $input['code_ttl_minutes'] ) * 60;
		}

		$clean['resend_wait']  = isset( $input['resend_wait'] ) ? max( 0, (int) $input['resend_wait'] ) : $current['resend_wait'];
		$clean['max_attempts'] = isset( $input['max_attempts'] ) ? max( 1, min( 10, (int) $input['max_attempts'] ) ) : $current['max_attempts'];

		$redirect              = isset( $input['redirect_url'] ) ? trim( (string) $input['redirect_url'] ) : $current['redirect_url'];
		$clean['redirect_url'] = ( '' === $redirect ) ? '' : esc_url_raw( $redirect );

		$login              = isset( $input['login_url'] ) ? trim( (string) $input['login_url'] ) : $current['login_url'];
		$clean['login_url'] = ( '' === $login ) ? '' : esc_url_raw( $login );

		$font         = isset( $input['font'] ) ? (string) $input['font'] : $current['font'];
		$clean['font'] = trim( (string) preg_replace( '/[^A-Za-z0-9\s,"\'\-_]/u', '', $font ) );

		self::flush();

		return $clean;
	}
}
