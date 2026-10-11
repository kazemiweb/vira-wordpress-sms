<?php
/**
 * The ippanel Edge API: one POST, one pattern, one recipient.
 *
 * The service's own documentation describes the request this class builds:
 *
 *   POST {base_url}/api/send
 *   Authorization: <access key>
 *   {
 *     "sending_type": "pattern",
 *     "from_number": "+983********",
 *     "code": "<pattern code>",
 *     "recipients": ["+989*********"],
 *     "params": { "<placeholder>": "<value>" }
 *   }
 *
 * A send counts as accepted only when the response carries `meta.status: true`; anything else -
 * a rejected key, a malformed request, an unreachable host - comes back as a WP_Error that
 * carries the service's own words, so the visitor and the site owner read the same reason the
 * plugin read.
 *
 * @package Vira_WordPress_SMS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sends the one-time code through an ippanel pattern.
 */
class Vira_SMS_Gateway_IPPanel {

	/**
	 * How long to wait for the service, in seconds.
	 */
	const TIMEOUT = 15;

	/**
	 * Put the sender number in the E.164 shape the service requires.
	 *
	 * The owner may store the line as `3000505`, `03000505`, `983000505` or `+983000505`; all four
	 * describe the same number and the service only accepts the last one.
	 *
	 * @param string $sender Stored sender.
	 * @return string
	 */
	public static function normalize_sender( $sender ) {
		$sender = Vira_SMS_Phone::to_ascii_digits( $sender );
		$sender = preg_replace( '/[^0-9+]/', '', (string) $sender );

		if ( '' === $sender ) {
			return '';
		}

		if ( 0 === strpos( $sender, '+' ) ) {
			return $sender;
		}

		if ( 0 === strpos( $sender, '00' ) ) {
			return '+' . ltrim( $sender, '0' );
		}

		if ( 0 === strpos( $sender, '0' ) ) {
			return '+' . Vira_SMS_Phone::COUNTRY_CODE . ltrim( $sender, '0' );
		}

		if ( 0 === strpos( $sender, Vira_SMS_Phone::COUNTRY_CODE ) ) {
			return '+' . $sender;
		}

		return '+' . Vira_SMS_Phone::COUNTRY_CODE . $sender;
	}

	/**
	 * Send one pattern message.
	 *
	 * @param string                $pattern_code Pattern code as defined in the account.
	 * @param string                $sender       Sender line.
	 * @param string                $recipient    Recipient, ten digits without a country code.
	 * @param array<string, string> $params       Values for the pattern's placeholders.
	 * @return array<string, mixed>|WP_Error
	 */
	public function send_pattern( $pattern_code, $sender, $recipient, array $params ) {
		$api_key = Vira_SMS_Settings::api_key();

		if ( '' === $api_key ) {
			return new WP_Error( 'vira_sms_no_key', __( 'کلید دسترسی سامانه پیامک در تنظیمات افزونه ثبت نشده است.', 'vira-wordpress-sms' ) );
		}

		$from       = self::normalize_sender( $sender );
		$recipients = Vira_SMS_Phone::to_e164( $recipient );

		if ( '' === $from || '' === $recipients ) {
			return new WP_Error( 'vira_sms_bad_connection', __( 'شماره فرستنده یا شماره گیرنده درست نیست. تنظیمات افزونه را بررسی کنید.', 'vira-wordpress-sms' ) );
		}

		$payload = array(
			'sending_type' => 'pattern',
			'from_number'  => $from,
			'code'         => (string) $pattern_code,
			'recipients'   => array( $recipients ),
			'params'       => (object) $params,
		);

		/**
		 * Filter the body just before it is sent.
		 *
		 * @param array  $payload The request body.
		 * @param string $recipient Ten digits.
		 */
		$payload = apply_filters( 'vira_sms_request_payload', $payload, $recipient );

		$response = wp_remote_post(
			Vira_SMS_Settings::base_url() . '/api/send',
			array(
				'timeout'     => self::TIMEOUT,
				'redirection' => 0,
				'headers'     => array(
					'Authorization' => $api_key,
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
				),
				'body'        => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'vira_sms_unreachable',
				sprintf(
					/* translators: %s: the transport error. */
					__( 'ارتباط با سامانه پیامک برقرار نشد: %s', 'vira-wordpress-sms' ),
					$response->get_error_message()
				)
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$meta   = is_array( $body ) && isset( $body['meta'] ) && is_array( $body['meta'] ) ? $body['meta'] : array();
		$ok     = ( $status >= 200 && $status < 300 ) && ! empty( $meta['status'] );

		if ( $ok ) {
			return array(
				'status'   => $status,
				'outbox'   => isset( $body['data']['message_outbox_ids'] ) ? (array) $body['data']['message_outbox_ids'] : array(),
				'message'  => isset( $meta['message'] ) ? (string) $meta['message'] : '',
			);
		}

		// The service explains itself in Persian; pass that through rather than inventing one.
		$reason = '';

		if ( ! empty( $meta['message'] ) ) {
			$reason = (string) $meta['message'];
		} elseif ( is_array( $body ) && isset( $body['message'] ) ) {
			$reason = (string) $body['message'];
		}

		if ( '' === $reason ) {
			$reason = sprintf(
				/* translators: %s: HTTP status code. */
				__( 'پاسخ ناموفق از سامانه پیامک (کد %s).', 'vira-wordpress-sms' ),
				number_format_i18n( $status )
			);
		}

		return new WP_Error(
			'vira_sms_gateway_error',
			sprintf(
				/* translators: %s: the reason the service gave. */
				__( 'ارسال پیامک انجام نشد: %s', 'vira-wordpress-sms' ),
				$reason
			),
			array(
				'status' => $status,
				'code'   => isset( $meta['message_code'] ) ? (string) $meta['message_code'] : '',
				'errors' => isset( $meta['errors'] ) ? $meta['errors'] : array(),
			)
		);
	}
}
