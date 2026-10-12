<?php
/**
 * Plugin Name:       افزونه ورود و ثبت نام پیامکی ویرا
 * Plugin URI:        https://vira-team.com
 * Description:       این افزونه برای ورود و ثبت نام با استفاده از پیامک و کد یک بار مصرف می‌باشد و با افزونه ووکامرس نیز سازگاری کامل دارد. این افزونه در حال حاضر با سامانه پیام کوتاه ippanel هماهنگ می‌باشد.
 * Version:           0.4.0
 * Author:            محمود کاظمی
 * Author URI:        https://vira-team.com
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Text Domain:       vira-wordpress-sms
 * Domain Path:       /languages
 *
 * @package Vira_WordPress_SMS
 */

defined( 'ABSPATH' ) || exit;

/**
 * The version WordPress reads when a script or stylesheet is enqueued.
 */
define( 'VIRA_SMS_VERSION', '0.4.0' );

/**
 * Absolute path to the main plugin file.
 */
define( 'VIRA_SMS_FILE', __FILE__ );

/**
 * Absolute path to the plugin directory, with a trailing slash.
 */
define( 'VIRA_SMS_PATH', plugin_dir_path( __FILE__ ) );

/**
 * Public URL of the plugin directory, with a trailing slash.
 */
define( 'VIRA_SMS_URL', plugin_dir_url( __FILE__ ) );

require_once VIRA_SMS_PATH . 'includes/class-settings.php';
require_once VIRA_SMS_PATH . 'includes/class-phone.php';
require_once VIRA_SMS_PATH . 'includes/class-otp.php';
require_once VIRA_SMS_PATH . 'includes/gateways/class-ippanel.php';
require_once VIRA_SMS_PATH . 'includes/class-sms-sender.php';
require_once VIRA_SMS_PATH . 'includes/class-users.php';
require_once VIRA_SMS_PATH . 'includes/class-ajax.php';
require_once VIRA_SMS_PATH . 'includes/class-shortcode.php';
require_once VIRA_SMS_PATH . 'includes/class-account-link.php';
require_once VIRA_SMS_PATH . 'includes/class-login-links.php';
require_once VIRA_SMS_PATH . 'includes/class-plugin.php';

if ( is_admin() ) {
	require_once VIRA_SMS_PATH . 'admin/class-settings-page.php';
}

/**
 * The plugin's single entry point.
 *
 * @return Vira_SMS_Plugin
 */
function vira_sms() {
	return Vira_SMS_Plugin::instance();
}

vira_sms();
