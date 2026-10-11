<?php
/**
 * The settings screen: connection, pattern, code and appearance.
 *
 * Everything is stored in one option and saved through the Settings API, so the screen inherits
 * WordPress's own nonce handling. The access key is never printed back into the page - an empty
 * key field means "keep the key that is stored", which is why the screen says so and offers a
 * checkbox for the one case the field cannot express: removing it.
 *
 * @package Vira_WordPress_SMS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Draws the settings screen.
 */
class Vira_SMS_Settings_Page {

	/**
	 * The page slug.
	 */
	const SLUG = 'vira-sms';

	/**
	 * The settings group the form posts to.
	 */
	const GROUP = 'vira_sms_settings_group';

	/**
	 * Hook the screen and the saving.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'settings' ) );
	}

	/**
	 * Add the entry under Settings.
	 *
	 * @return void
	 */
	public function menu() {
		add_options_page(
			__( 'ورود و ثبت نام پیامکی ویرا', 'vira-wordpress-sms' ),
			__( 'ورود پیامکی ویرا', 'vira-wordpress-sms' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Register the option and its cleaning function.
	 *
	 * @return void
	 */
	public function settings() {
		register_setting(
			self::GROUP,
			Vira_SMS_Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'Vira_SMS_Settings', 'sanitize' ),
				'default'           => Vira_SMS_Settings::defaults(),
			)
		);
	}

	/**
	 * Draw one text-like field.
	 *
	 * @param array<string, mixed> $args Field description.
	 * @return void
	 */
	private function field( $args ) {
		$key   = $args['key'];
		$value = Vira_SMS_Settings::get( $key );
		$type  = isset( $args['type'] ) ? $args['type'] : 'text';
		$name  = Vira_SMS_Settings::OPTION . '[' . $key . ']';

		if ( 'textarea' === $type ) {
			printf(
				'<textarea id="vira-sms-%1$s" class="large-text code" rows="%3$d" name="%2$s" placeholder="%4$s">%5$s</textarea>',
				esc_attr( $key ),
				esc_attr( $name ),
				(int) ( isset( $args['rows'] ) ? $args['rows'] : 4 ),
				esc_attr( isset( $args['placeholder'] ) ? $args['placeholder'] : '' ),
				esc_textarea( (string) $value )
			);
		} elseif ( 'password' === $type ) {
			printf(
				'<input type="password" id="vira-sms-%1$s" class="regular-text" name="%2$s" value="" autocomplete="new-password" placeholder="%3$s" />',
				esc_attr( $key ),
				esc_attr( $name ),
				esc_attr( isset( $args['placeholder'] ) ? $args['placeholder'] : '' )
			);
		} else {
			printf(
				'<input type="%6$s" id="vira-sms-%1$s" class="%7$s" name="%2$s" value="%3$s" placeholder="%4$s" %8$s />',
				esc_attr( $key ),
				esc_attr( $name ),
				'number' === $type ? esc_attr( (string) (int) $value ) : esc_attr( (string) $value ),
				esc_attr( isset( $args['placeholder'] ) ? $args['placeholder'] : '' ),
				'', // unused, kept for argument order clarity.
				'number' === $type ? 'number' : 'text',
				'number' === $type ? 'small-text' : 'regular-text',
				'number' === $type ? 'min="' . esc_attr( (string) ( isset( $args['min'] ) ? $args['min'] : 1 ) ) . '" max="' . esc_attr( (string) ( isset( $args['max'] ) ? $args['max'] : 9999 ) ) . '"' : ''
			);
		}

		if ( ! empty( $args['description'] ) ) {
			echo '<p class="description">' . wp_kses_post( $args['description'] ) . '</p>';
		}
	}

	/**
	 * Draw the screen.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$configured = Vira_SMS_Settings::is_configured();
		$key_stored = '' !== Vira_SMS_Settings::api_key();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'ورود و ثبت نام پیامکی ویرا', 'vira-wordpress-sms' ); ?></h1>

			<?php if ( ! $configured ) : ?>
				<div class="notice notice-warning">
					<p><?php esc_html_e( 'تا وقتی کلید دسترسی، شماره فرستنده و کد الگو ثبت نشود، فرم ورود کد پیامکی نمی‌فرستد.', 'vira-wordpress-sms' ); ?></p>
				</div>
			<?php endif; ?>

			<p>
				<?php esc_html_e( 'برای گذاشتن فرم ورود و ثبت نام در هر برگه، این کد کوتاه را داخل آن برگه بگذارید:', 'vira-wordpress-sms' ); ?>
				<code><?php echo esc_html( '[' . Vira_SMS_Shortcode::TAG . ']' ); ?></code>
			</p>

			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>

				<h2><?php esc_html_e( 'اتصال به سامانه پیامک', 'vira-wordpress-sms' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="vira-sms-base_url"><?php esc_html_e( 'نشانی سامانه', 'vira-wordpress-sms' ); ?></label></th>
						<td>
							<?php
							$this->field(
								array(
									'key'         => 'base_url',
									'description' => __( 'نشانی پایه‌ی API سامانه. برای ippanel برابر است با https://edge.ippanel.com/v1', 'vira-wordpress-sms' ),
								)
							);
							?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="vira-sms-api_key"><?php esc_html_e( 'کلید دسترسی', 'vira-wordpress-sms' ); ?></label></th>
						<td>
							<?php
							$this->field(
								array(
									'key'         => 'api_key',
									'type'        => 'password',
									'placeholder' => $key_stored
										? __( 'ثبت شده است؛ برای تغییر، کلید تازه را وارد کنید', 'vira-wordpress-sms' )
										: __( 'کلید دسترسی ساخته‌شده در پنل سامانه', 'vira-wordpress-sms' ),
									'description' => __( 'از پنل ippanel، بخش Developers و سپس Access Keys ساخته می‌شود و منقضی نمی‌شود.', 'vira-wordpress-sms' ),
								)
							);
							?>
							<label style="display:block;margin-top:6px;">
								<input type="checkbox" name="<?php echo esc_attr( Vira_SMS_Settings::OPTION ); ?>[clear_api_key]" value="1" />
								<?php esc_html_e( 'کلید ذخیره‌شده پاک شود', 'vira-wordpress-sms' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="vira-sms-sender"><?php esc_html_e( 'شماره فرستنده', 'vira-wordpress-sms' ); ?></label></th>
						<td>
							<?php
							$this->field(
								array(
									'key'         => 'sender',
									'placeholder' => '+983000505',
									'description' => __( 'خط فرستنده‌ی ثبت‌شده در حساب شما. می‌توانید با صفر یا کد کشور هم بنویسید؛ پیش از ارسال به قالب بین‌المللی تبدیل می‌شود.', 'vira-wordpress-sms' ),
								)
							);
							?>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'الگوی پیام', 'vira-wordpress-sms' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="vira-sms-pattern_code"><?php esc_html_e( 'کد الگو', 'vira-wordpress-sms' ); ?></label></th>
						<td>
							<?php
							$this->field(
								array(
									'key'         => 'pattern_code',
									'description' => __( 'کد الگویی که در پنل سامانه ساخته‌اید و متن پیامک را نگه می‌دارد.', 'vira-wordpress-sms' ),
								)
							);
							?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="vira-sms-code_param"><?php esc_html_e( 'نام متغیر کد', 'vira-wordpress-sms' ); ?></label></th>
						<td>
							<?php
							$this->field(
								array(
									'key'         => 'code_param',
									'placeholder' => 'code',
									'description' => __( 'همان نامی که در متن الگو داخل آکولاد گذاشته‌اید؛ پیش‌فرض code است.', 'vira-wordpress-sms' ),
								)
							);
							?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="vira-sms-extra_params"><?php esc_html_e( 'متغیرهای دیگر الگو', 'vira-wordpress-sms' ); ?></label></th>
						<td>
							<?php
							$this->field(
								array(
									'key'         => 'extra_params',
									'type'        => 'textarea',
									'rows'        => 4,
									'placeholder' => "name=محمود",
									'description' => __( 'هر متغیر در یک خط، به شکل نام=مقدار. لازم نیست چیزی بنویسید مگر الگوی شما متغیر دیگری هم داشته باشد.', 'vira-wordpress-sms' ),
								)
							);
							?>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'کد یک بار مصرف', 'vira-wordpress-sms' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="vira-sms-code_length"><?php esc_html_e( 'تعداد ارقام کد', 'vira-wordpress-sms' ); ?></label></th>
						<td>
							<?php
							$this->field(
								array(
									'key' => 'code_length',
									'type' => 'number',
									'min'  => 3,
									'max'  => 8,
								)
							);
							?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="vira-sms-code_ttl"><?php esc_html_e( 'اعتبار کد (دقیقه)', 'vira-wordpress-sms' ); ?></label></th>
						<td>
							<?php
							$minutes = max( 1, (int) round( Vira_SMS_Settings::code_ttl() / 60 ) );
							?>
							<input type="number" id="vira-sms-code_ttl" class="small-text" min="1" max="60"
								name="<?php echo esc_attr( Vira_SMS_Settings::OPTION ); ?>[code_ttl_minutes]"
								value="<?php echo esc_attr( (string) $minutes ); ?>" />
							<p class="description"><?php esc_html_e( 'پس از این مدت، کد بی‌اعتبار می‌شود و کاربر باید کد تازه بگیرد.', 'vira-wordpress-sms' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="vira-sms-resend_wait"><?php esc_html_e( 'فاصله بین دو ارسال (ثانیه)', 'vira-wordpress-sms' ); ?></label></th>
						<td>
							<?php
							$this->field(
								array(
									'key'         => 'resend_wait',
									'type'        => 'number',
									'min'         => 0,
									'max'         => 600,
									'description' => __( 'تا پایان این مدت، دکمه‌ی ارسال دوباره در فرم فعال نمی‌شود.', 'vira-wordpress-sms' ),
								)
							);
							?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="vira-sms-max_attempts"><?php esc_html_e( 'حداکثر تلاش نادرست', 'vira-wordpress-sms' ); ?></label></th>
						<td>
							<?php
							$this->field(
								array(
									'key' => 'max_attempts',
									'type' => 'number',
									'min'  => 1,
									'max'  => 10,
								)
							);
							?>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'نمایش فرم', 'vira-wordpress-sms' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="vira-sms-redirect_url"><?php esc_html_e( 'نشانی پس از ورود', 'vira-wordpress-sms' ); ?></label></th>
						<td>
							<?php
							$this->field(
								array(
									'key'         => 'redirect_url',
									'placeholder' => home_url( '/' ),
									'description' => __( 'کاربر پس از ورود یا ثبت نام به این نشانی می‌رود. خالی بگذارید تا به صفحه‌ی اصلی برود.', 'vira-wordpress-sms' ),
								)
							);
							?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="vira-sms-font"><?php esc_html_e( 'نام فونت', 'vira-wordpress-sms' ); ?></label></th>
						<td>
							<?php
							$this->field(
								array(
									'key'         => 'font',
									'placeholder' => 'Vazirmatn',
									'description' => __( 'همان نامی که قالب سایت با آن فونت را بارگذاری می‌کند. خالی بگذارید تا فرم از فونت قالب استفاده کند.', 'vira-wordpress-sms' ),
								)
							);
							?>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
