<?php
/**
 * Cleanup when the plugin is deleted from the plugins screen.
 *
 * Only what the plugin itself created is removed: its settings, the transient codes and tickets
 * it was holding, and the counter transients behind the throttles. Accounts and their meta are
 * left alone - the people who signed in with their mobile numbers keep their accounts, and the
 * numbers stay on those accounts for whatever uses them next.
 *
 * @package Vira_WordPress_SMS
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'vira_sms_settings' );

global $wpdb;

/*
 * Transients are two rows each: the value, and the timeout that carries the name. Both go.
 */
$like = $wpdb->esc_like( '_transient_vira_sms_' ) . '%';

$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall cleanup.
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$like,
		$wpdb->esc_like( '_transient_timeout_vira_sms_' ) . '%'
	)
);
