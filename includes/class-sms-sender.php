<?php
/**
 * Turns "this number needs a code" into one pattern message.
 *
 * The pattern itself lives in the owner's ippanel account; the plugin only fills in the values.
 * The code always wins over an extra parameter that happens to share its name, so a mistake in
 * the settings cannot overwrite the number the visitor is about to type.
 *
 * @package Vira_WordPress_SMS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sends the one-time code.
 */
class Vira_SMS_Sender {

	/**
	 * Send a code to a number.
	 *
	 * @param string $mobile Ten digits.
	 * @param string $code   The code, as issued.
	 * @return array<string, mixed>|WP_Error
	 */
	public function send_code( $mobile, $code ) {
		if ( ! Vira_SMS_Settings::is_configured() ) {
			return new WP_Error(
				'vira_sms_not_configured',
				__( 'سامانه پیامک هنوز در تنظیمات افزونه کامل نشده است. لطفاً با مدیر سایت تماس بگیرید.', 'vira-wordpress-sms' )
			);
		}

		$params                      = Vira_SMS_Settings::extra_params();
		$params[ Vira_SMS_Settings::code_param() ] = (string) $code;

		/**
		 * Filter the pattern values just before the send.
		 *
		 * @param array  $params Placeholder values.
		 * @param string $mobile Ten digits.
		 */
		$params = apply_filters( 'vira_sms_pattern_params', $params, $mobile );

		$gateway = new Vira_SMS_Gateway_IPPanel();

		return $gateway->send_pattern(
			(string) Vira_SMS_Settings::get( 'pattern_code' ),
			(string) Vira_SMS_Settings::get( 'sender' ),
			$mobile,
			$params
		);
	}
}
