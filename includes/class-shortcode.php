<?php
/**
 * The shortcode a page is built with: `[vira_sms_login]`.
 *
 * It prints the three steps of the sign-in - number, code, name - with every step after the first
 * hidden until it is needed, and loads the stylesheet and script that drive them only on a page
 * that actually carries the form. A visitor who is already signed in sees where they are and a
 * way on, rather than a form they cannot use.
 *
 * @package Vira_WordPress_SMS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers and renders the form.
 */
class Vira_SMS_Shortcode {

	/**
	 * The shortcode the owner types into a page.
	 */
	const TAG = 'vira_sms_login';

	/**
	 * Hook the shortcode.
	 *
	 * @return void
	 */
	public function register() {
		add_shortcode( self::TAG, array( $this, 'render' ) );
	}

	/**
	 * Render the form.
	 *
	 * @param array<string, string> $atts Shortcode attributes; none are used.
	 * @return string
	 */
	public function render( $atts = array() ) {
		unset( $atts );

		$redirect = Vira_SMS_Settings::redirect_url();
		$font     = Vira_SMS_Settings::font();

		if ( is_user_logged_in() ) {
			$user  = wp_get_current_user();
			$name  = trim( $user->first_name . ' ' . $user->last_name );
			$style = '' === $font ? '' : ' style="--vira-sms-font:' . esc_attr( $font ) . ';"';

			return '<div class="vira-sms vira-sms--signed-in"' . $style . '>' . // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- style is escaped above.
				'<p class="vira-sms__note">' .
				esc_html(
					'' === $name
						? __( 'شما وارد حساب کاربری خود هستید.', 'vira-wordpress-sms' )
						: sprintf(
							/* translators: %s: the visitor's name. */
							__( '%s عزیز، شما وارد حساب کاربری خود هستید.', 'vira-wordpress-sms' ),
							$name
						)
				) .
				'</p><p class="vira-sms__note"><a href="' . esc_url( $redirect ) . '">' .
				esc_html__( 'ادامه', 'vira-wordpress-sms' ) .
				'</a></p></div>';
		}

		wp_enqueue_style( 'vira-sms', VIRA_SMS_URL . 'assets/css/vira-sms.css', array(), VIRA_SMS_VERSION );
		wp_enqueue_script( 'vira-sms', VIRA_SMS_URL . 'assets/js/vira-sms.js', array(), VIRA_SMS_VERSION, true );

		wp_localize_script(
			'vira-sms',
			'viraSmsData',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( Vira_SMS_Ajax::NONCE ),
				'codeLength'  => Vira_SMS_Settings::code_length(),
				'resendAfter' => Vira_SMS_Settings::resend_wait(),
				'strings'     => array(
					'sending'      => __( 'در حال ارسال کد…', 'vira-wordpress-sms' ),
					'verifying'    => __( 'در حال بررسی کد…', 'vira-wordpress-sms' ),
					'registering'  => __( 'در حال ساخت حساب…', 'vira-wordpress-sms' ),
					'continue'     => __( 'ادامه', 'vira-wordpress-sms' ),
					'confirm'      => __( 'تأیید کد', 'vira-wordpress-sms' ),
					'register'     => __( 'ثبت نام و ورود', 'vira-wordpress-sms' ),
					'resend'       => __( 'ارسال دوباره کد', 'vira-wordpress-sms' ),
					'resendIn'     => __( 'ارسال دوباره تا %s ثانیه دیگر', 'vira-wordpress-sms' ),
					'codeSentTo'   => __( 'کد به شماره %s پیامک شد.', 'vira-wordpress-sms' ),
					'changeNumber' => __( 'تغییر شماره', 'vira-wordpress-sms' ),
					'networkError' => __( 'ارتباط با سایت برقرار نشد. اتصال اینترنت خود را بررسی کنید.', 'vira-wordpress-sms' ),
					'retrying'     => __( 'در حال تلاش دوباره…', 'vira-wordpress-sms' ),
				),
			)
		);

		$style = '' === $font ? '' : ' style="--vira-sms-font:' . esc_attr( $font ) . ';"';

		ob_start();
		?>
		<div class="vira-sms" data-vira-sms<?php echo $style; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>>
			<form class="vira-sms__form" novalidate>
				<div class="vira-sms__step" data-step="mobile">
					<label class="vira-sms__label" for="vira-sms-mobile"><?php esc_html_e( 'شماره موبایل', 'vira-wordpress-sms' ); ?></label>
					<input
						type="tel"
						id="vira-sms-mobile"
						class="vira-sms__input vira-sms__input--mobile"
						name="mobile"
						inputmode="numeric"
						autocomplete="tel-national"
						dir="ltr"
						placeholder="۰۹۱۲۳۴۵۶۷۸۹"
						maxlength="11"
						required
					/>
					<button type="submit" class="vira-sms__button">
						<span class="vira-sms__button-text"><?php esc_html_e( 'ادامه', 'vira-wordpress-sms' ); ?></span>
						<span class="vira-sms__spinner" aria-hidden="true"></span>
					</button>
					<p class="vira-sms__hint"><?php esc_html_e( 'با شماره موبایل خود وارد شوید یا ثبت نام کنید.', 'vira-wordpress-sms' ); ?></p>
				</div>

				<div class="vira-sms__step" data-step="code" hidden>
					<p class="vira-sms__sent" data-sent-to></p>
					<label class="vira-sms__label" for="vira-sms-code"><?php esc_html_e( 'کد پیامک‌شده', 'vira-wordpress-sms' ); ?></label>
					<input
						type="text"
						id="vira-sms-code"
						class="vira-sms__input vira-sms__input--code"
						name="code"
						inputmode="numeric"
						autocomplete="one-time-code"
						dir="ltr"
						maxlength="<?php echo esc_attr( (string) Vira_SMS_Settings::code_length() ); ?>"
						required
					/>
					<button type="submit" class="vira-sms__button">
						<span class="vira-sms__button-text"><?php esc_html_e( 'تأیید کد', 'vira-wordpress-sms' ); ?></span>
						<span class="vira-sms__spinner" aria-hidden="true"></span>
					</button>
					<div class="vira-sms__actions">
						<button type="button" class="vira-sms__link" data-action="resend" disabled><?php esc_html_e( 'ارسال دوباره کد', 'vira-wordpress-sms' ); ?></button>
						<button type="button" class="vira-sms__link" data-action="back"><?php esc_html_e( 'تغییر شماره', 'vira-wordpress-sms' ); ?></button>
					</div>
				</div>

				<div class="vira-sms__step" data-step="profile" hidden>
					<p class="vira-sms__sent" data-verified></p>
					<label class="vira-sms__label" for="vira-sms-first"><?php esc_html_e( 'نام', 'vira-wordpress-sms' ); ?></label>
					<input type="text" id="vira-sms-first" class="vira-sms__input" name="first_name" autocomplete="given-name" maxlength="60" required />
					<label class="vira-sms__label" for="vira-sms-last"><?php esc_html_e( 'نام خانوادگی', 'vira-wordpress-sms' ); ?></label>
					<input type="text" id="vira-sms-last" class="vira-sms__input" name="last_name" autocomplete="family-name" maxlength="60" required />
					<button type="submit" class="vira-sms__button">
						<span class="vira-sms__button-text"><?php esc_html_e( 'ثبت نام و ورود', 'vira-wordpress-sms' ); ?></span>
						<span class="vira-sms__spinner" aria-hidden="true"></span>
					</button>
				</div>

				<p class="vira-sms__message" data-message role="status" aria-live="polite"></p>
			</form>
		</div>
		<?php

		return (string) ob_get_clean();
	}
}
