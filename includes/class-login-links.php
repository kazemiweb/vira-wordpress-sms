<?php
/**
 * Sending the visitor to the plugin's form instead of a second, unrelated sign-in form.
 *
 * The WooCommerce account page shows its own user-name-and-password form to a guest, and signing
 * out drops the visitor back on it. With a page named in the settings, the front end points at
 * the plugin's form instead: links that would ask for a password go there, a guest who lands on
 * the account page is taken there, and signing out ends there too.
 *
 * `wp-login.php` itself is left alone - staff sign in there, and a locked-out owner must never be
 * sent somewhere that cannot authenticate them. Empty setting, no behaviour: the plugin acts as if
 * this file did not exist.
 *
 * @package Vira_WordPress_SMS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the front end's sign-in doors pointing at the form.
 */
class Vira_SMS_Login_Links {

	/**
	 * Hook the three places a visitor can be sent to a sign-in form.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'login_url', array( __CLASS__, 'login_url' ), 10, 3 );
		add_filter( 'logout_redirect', array( __CLASS__, 'logout_redirect' ), 10, 3 );
		add_action( 'template_redirect', array( __CLASS__, 'send_guests_to_the_form' ), 1 );
	}

	/**
	 * The page that holds the form, or an empty string when none is set.
	 *
	 * @return string
	 */
	private static function page() {
		return Vira_SMS_Settings::login_url();
	}

	/**
	 * Is the visitor already on the form's own page? Compared by path, to avoid a redirect loop.
	 *
	 * @param string $page The form's page.
	 * @return bool
	 */
	private static function is_the_form_page( $page ) {
		$here = (string) wp_parse_url( home_url( add_query_arg( array() ) ), PHP_URL_PATH );
		$there = (string) wp_parse_url( $page, PHP_URL_PATH );

		if ( '' === $here || '' === $there ) {
			return false;
		}

		return untrailingslashit( $here ) === untrailingslashit( $there );
	}

	/**
	 * A front-end link that asks for a password points at the form instead.
	 *
	 * @param string $login_url    The URL WordPress built.
	 * @param string $redirect     Where the visitor was to be sent afterwards.
	 * @param bool   $force_reauth Whether a fresh sign-in was requested.
	 * @return string
	 */
	public static function login_url( $login_url, $redirect, $force_reauth ) {
		$page = self::page();

		if ( '' === $page || is_admin() ) {
			return $login_url;
		}

		// On the login screen itself nothing is rewritten: a failed password must stay there.
		if ( isset( $GLOBALS['pagenow'] ) && 'wp-login.php' === $GLOBALS['pagenow'] ) {
			return $login_url;
		}

		// Staff keep their own door, and the account page's own login flow keeps working.
		if ( $redirect ) {
			$path = (string) wp_parse_url( $redirect, PHP_URL_PATH );

			if ( false !== strpos( $path, 'wp-login.php' ) || false !== strpos( $path, 'wp-admin' ) ) {
				return $login_url;
			}
		}

		return $page;
	}

	/**
	 * Signing out ends on the form, not on a sign-in form the visitor has never been shown.
	 *
	 * @param string        $redirect_to           Where WordPress was going to send them.
	 * @param string        $requested_redirect_to What the link asked for.
	 * @param WP_User|WP_Error|null $user          The user who signed out.
	 * @return string
	 */
	public static function logout_redirect( $redirect_to, $requested_redirect_to, $user ) {
		$page = self::page();

		return ( '' === $page ) ? $redirect_to : $page;
	}

	/**
	 * A guest visiting the WooCommerce account page is taken to the form.
	 *
	 * Runs early so no other handler gets to print the stock login form first.
	 *
	 * @return void
	 */
	public static function send_guests_to_the_form() {
		$page = self::page();

		if ( '' === $page || is_user_logged_in() ) {
			return;
		}

		// Only a plain visit is redirected; a submitted form is left to whoever handles it.
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET';

		if ( 'GET' !== $method ) {
			return;
		}

		if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) {
			return;
		}

		if ( self::is_the_form_page( $page ) ) {
			return;
		}

		wp_safe_redirect( $page, 302 );
		exit;
	}
}
