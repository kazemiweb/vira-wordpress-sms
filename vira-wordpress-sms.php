<?php
/**
 * Plugin Name:       افزونه ورود و ثبت نام پیامکی ویرا
 * Plugin URI:        https://vira-team.com
 * Description:       این افزونه برای ورود و ثبت نام با استفاده از پیامک و کد یک بار مصرف می‌باشد و با افزونه ووکامرس نیز سازگاری کامل دارد. این افزونه در حال حاضر با سامانه پیام کوتاه ippanel هماهنگ می‌باشد.
 * Version:           0.1.0
 * Author:            محمود کاظمی
 * Author URI:        https://vira-team.com
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Text Domain:       vira-wordpress-sms
 * Domain Path:       /languages
 *
 * @package Vira_WordPress_SMS
 */

defined( 'ABSPATH' ) || exit;

/**
 * The version WordPress reads when the stylesheet or a script is enqueued.
 */
define( 'VIRA_SMS_VERSION', '0.1.0' );

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

/*
 * Nothing is registered yet, on purpose.
 *
 * This commit carries the plugin's identity and the agreed brief, so the plugin can be
 * installed, listed and reviewed before any behaviour exists. The sign-in flow, the
 * one-time code, the settings screen and the ippanel gateway all arrive as their own
 * reviewed commits, and none of them starts until the open questions in README.md are
 * answered.
 */
