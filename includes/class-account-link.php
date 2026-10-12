<?php
/**
 * The shortcode a header can hold: a sign-in button to a visitor, an account button to a member.
 *
 * `[vira_sms_account_button]` prints one link. A visitor sees «ورود / ثبت‌نام» pointing at the page
 * named in the settings (the one holding the form), and someone who is signed in sees «حساب کاربری»
 * pointing at their account. Nothing else changes on the site.
 *
 * The markup carries a single class and inherits the theme's font and colour; the few rules needed
 * to make it look like a button are printed once, with the link, and a theme can override them with
 * one rule of its own.
 *
 * @package Vira_WordPress_SMS
 */

defined( 'ABSPATH' ) || exit;

/**
 * The header button.
 */
class Vira_SMS_Account_Link {

	/**
	 * The shortcode.
	 */
	const TAG = 'vira_sms_account_button';

	/**
	 * Whether the small style block has already been printed on this page.
	 *
	 * @var bool
	 */
	private static $styled = false;

	/**
	 * Add the shortcode.
	 *
	 * @return void
	 */
	public function register() {
		add_shortcode( self::TAG, array( $this, 'render' ) );
	}

	/**
	 * The link, for whoever is looking at it.
	 *
	 * @return string
	 */
	public function render() {
		$signed_in = is_user_logged_in();

		/**
		 * Filters the two button labels.
		 *
		 * @param array<string, string> $labels    The labels, keyed `guest` and `member`.
		 * @param bool                  $signed_in Whether the visitor is signed in.
		 */
		$labels = apply_filters(
			'vira_sms_account_button_labels',
			array(
				'guest'  => __( 'ورود / ثبت‌نام', 'vira-wordpress-sms' ),
				'member' => __( 'حساب کاربری', 'vira-wordpress-sms' ),
			),
			$signed_in
		);

		$url  = $signed_in ? self::account_url() : self::sign_in_url();
		$key  = $signed_in ? 'member' : 'guest';
		$text = isset( $labels[ $key ] ) ? $labels[ $key ] : '';

		return self::styles() . sprintf(
			'<a class="vira-sms-account %s" href="%s">%s</a>',
			$signed_in ? 'is-member' : 'is-guest',
			esc_url( $url ),
			esc_html( $text )
		);
	}

	/**
	 * Where the visitor signs in: the page named in the settings, or WordPress' own door.
	 *
	 * @return string
	 */
	private static function sign_in_url() {
		$url = Vira_SMS_Settings::login_url();

		return ( '' === $url ) ? wp_login_url() : $url;
	}

	/**
	 * Where a signed-in visitor goes: the page named in the settings, the shop's account page, or
	 * their own profile when the shop is not there.
	 *
	 * @return string
	 */
	private static function account_url() {
		$url = Vira_SMS_Settings::account_url();

		if ( '' !== $url ) {
			return $url;
		}

		if ( function_exists( 'wc_get_page_permalink' ) ) {
			$shop = wc_get_page_permalink( 'myaccount' );

			if ( $shop ) {
				return $shop;
			}
		}

		return admin_url( 'profile.php' );
	}

	/**
	 * Just enough to look like a button, printed once per page, in the theme's own colours.
	 *
	 * @return string
	 */
	private static function styles() {
		if ( self::$styled ) {
			return '';
		}

		self::$styled = true;

		return '<style>'
			. '.vira-sms-account{display:inline-flex;align-items:center;padding:.45em 1.1em;'
			. 'font:inherit;line-height:1.7;color:inherit;text-decoration:none;'
			. 'border:1px solid currentColor;border-radius:999px;transition:opacity .15s ease}'
			. '.vira-sms-account:hover,.vira-sms-account:focus{opacity:.72;color:inherit}'
			. '</style>';
	}
}
