<?php
/**
 * The plugin's wiring: which class answers which call.
 *
 * Nothing here reaches into WordPress beyond adding the shortcode, the four endpoints, the
 * settings screen, the plugin's own translations and the front end's sign-in links. `wp-login.php`
 * and the checkout are never touched; the account page is only ever pointed at the form.
 *
 * @package Vira_WordPress_SMS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Starts the parts of the plugin that have something to do.
 */
class Vira_SMS_Plugin {

	/**
	 * The single instance.
	 *
	 * @var Vira_SMS_Plugin|null
	 */
	private static $instance = null;

	/**
	 * The instance, made once.
	 *
	 * @return Vira_SMS_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Hook everything.
	 */
	private function __construct() {
		$shortcode = new Vira_SMS_Shortcode();
		$ajax      = new Vira_SMS_Ajax();

		$shortcode->register();
		$ajax->register();

		Vira_SMS_Login_Links::init();

		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( VIRA_SMS_FILE ), array( $this, 'action_links' ) );

		if ( class_exists( 'Vira_SMS_Settings_Page' ) ) {
			$settings = new Vira_SMS_Settings_Page();
			$settings->register();
		}
	}

	/**
	 * Load any translation placed in `languages/`.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'vira-wordpress-sms', false, dirname( plugin_basename( VIRA_SMS_FILE ) ) . '/languages' );
	}

	/**
	 * A settings link beside the plugin's name on the plugins screen.
	 *
	 * @param string[] $links Existing links.
	 * @return string[]
	 */
	public function action_links( $links ) {
		$url = add_query_arg( 'page', Vira_SMS_Settings_Page::SLUG, admin_url( 'options-general.php' ) );

		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'تنظیمات', 'vira-wordpress-sms' ) . '</a>' );

		return $links;
	}
}
