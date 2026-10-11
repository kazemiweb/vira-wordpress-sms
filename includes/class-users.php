<?php
/**
 * The accounts behind the mobile numbers.
 *
 * A mobile number is the account. Nothing else identifies a visitor, so the number is the user
 * name (ten digits, no trunk zero) and is also kept in user meta, which is what lets the plugin
 * recognise someone whose account was made elsewhere - at a WooCommerce checkout, for instance -
 * by the same number.
 *
 * A new account gets no password to remember and no email address to invent, but WordPress
 * insists on a unique address for every row, so a placeholder on the site's own domain is
 * derived from the number. That address is never shown to the visitor and never written to.
 * No mail is sent when an account is created here.
 *
 * @package Vira_WordPress_SMS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Finds, creates and signs in the account behind a mobile number.
 */
class Vira_SMS_Users {

	/**
	 * The meta key holding a number this plugin signed someone up with.
	 */
	const MOBILE_META = 'vira_sms_mobile';

	/**
	 * The account for a number, or false.
	 *
	 * @param string $mobile Ten digits.
	 * @return WP_User|false
	 */
	public static function find_by_mobile( $mobile ) {
		if ( ! Vira_SMS_Phone::is_mobile( $mobile ) ) {
			return false;
		}

		// The number is the user name, which is the normal case.
		$user = get_user_by( 'login', $mobile );

		if ( $user instanceof WP_User ) {
			return $user;
		}

		// An account made elsewhere may still carry the number.
		$found = get_users(
			array(
				'number'     => 1,
				'fields'     => 'all',
				'meta_key'   => self::MOBILE_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one lookup, keyed by a ten digit number.
				'meta_value' => $mobile, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- see above.
			)
		);

		return $found ? $found[0] : false;
	}

	/**
	 * Whether a number already has an account.
	 *
	 * @param string $mobile Ten digits.
	 * @return bool
	 */
	public static function is_registered( $mobile ) {
		return (bool) self::find_by_mobile( $mobile );
	}

	/**
	 * The role a new account gets.
	 *
	 * A customer when WooCommerce is there to own that role, a subscriber otherwise. Filterable,
	 * because the shop owner may keep a role of their own for these accounts.
	 *
	 * @return string
	 */
	public static function new_user_role() {
		/*
		 * The shop's own customer role when the shop has one, so these accounts behave like any
		 * other customer of the store; a subscriber otherwise. The role itself is checked rather
		 * than a WooCommerce function, because those are not all loaded outside the admin.
		 */
		$role = get_role( 'customer' ) ? 'customer' : 'subscriber';

		/**
		 * Filter the role given to an account created by the plugin.
		 *
		 * @param string $role Role slug.
		 */
		return (string) apply_filters( 'vira_sms_new_user_role', $role );
	}

	/**
	 * Create the account for a number.
	 *
	 * @param string $mobile     Ten digits.
	 * @param string $first_name Given name.
	 * @param string $last_name  Family name.
	 * @return WP_User|WP_Error
	 */
	public static function create( $mobile, $first_name, $last_name ) {
		if ( ! Vira_SMS_Phone::is_mobile( $mobile ) ) {
			return new WP_Error( 'vira_sms_bad_mobile', __( 'شماره موبایل درست نیست.', 'vira-wordpress-sms' ) );
		}

		$existing = self::find_by_mobile( $mobile );

		if ( $existing instanceof WP_User ) {
			return $existing;
		}

		$user_id = wp_insert_user(
			array(
				'user_login'   => Vira_SMS_Phone::to_login( $mobile ),
				/*
				 * No address at all, the way a mobile-only account is meant to be: the number is
				 * the account. WordPress allows it - the user_email column is indexed but not
				 * unique, and its existence check ignores an empty value, so any number of
				 * accounts can live without one. Nothing is invented on the visitor's behalf.
				 */
				'user_email'   => '',
				'user_pass'    => wp_generate_password( 32, true, true ),
				'first_name'   => $first_name,
				'last_name'    => $last_name,
				'display_name' => trim( $first_name . ' ' . $last_name ),
				'role'         => self::new_user_role(),
			)
		);

		if ( is_wp_error( $user_id ) ) {
			return new WP_Error(
				'vira_sms_user_failed',
				sprintf(
					/* translators: %s: the reason WordPress gave. */
					__( 'ساخت حساب کاربری انجام نشد: %s', 'vira-wordpress-sms' ),
					$user_id->get_error_message()
				)
			);
		}

		update_user_meta( $user_id, self::MOBILE_META, $mobile );
		update_user_meta( $user_id, 'vira_sms_registered_at', time() );

		$user = get_user_by( 'id', $user_id );

		if ( ! $user instanceof WP_User ) {
			return new WP_Error( 'vira_sms_user_failed', __( 'ساخت حساب کاربری انجام نشد.', 'vira-wordpress-sms' ) );
		}

		/**
		 * Fires once an account has been created by the plugin.
		 *
		 * Somewhere for other code to react; the plugin itself never mails the new account.
		 *
		 * @param WP_User $user   The new account.
		 * @param string  $mobile Ten digits.
		 */
		do_action( 'vira_sms_user_registered', $user, $mobile );

		return $user;
	}

	/**
	 * Sign an account in, exactly as WordPress would.
	 *
	 * @param WP_User $user Account.
	 * @return void
	 */
	public static function login( $user ) {
		if ( ! $user instanceof WP_User ) {
			return;
		}

		wp_clear_auth_cookie();
		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, true );
		update_user_meta( $user->ID, self::MOBILE_META, Vira_SMS_Phone::normalize( $user->user_login ) );

		/**
		 * The same action WordPress fires after a password login, so plugins that expect it
		 * (the shop's own "last login" bookkeeping, for one) keep working.
		 *
		 * @param string  $user_login The user name.
		 * @param WP_User $user       The account.
		 */
		do_action( 'wp_login', $user->user_login, $user );
	}
}
