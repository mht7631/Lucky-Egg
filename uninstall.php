<?php
/**
 * Uninstall routine: removes every table, option, transient and scheduled event of Lucky Egg.
 *
 * @package LuckyEgg
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Removes Lucky Egg data for the current site.
 */
function lucky_egg_uninstall_site() {
	global $wpdb;

	$tables = array(
		$wpdb->prefix . 'lucky_egg_entries',
		$wpdb->prefix . 'lucky_egg_rate_limits',
		$wpdb->prefix . 'lucky_egg_campaigns',
	);

	foreach ( $tables as $table ) {
		// Table names are built from $wpdb->prefix and fixed suffixes only.
		$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	delete_option( 'lucky_egg_settings' );
	delete_option( 'lucky_egg_db_version' );
	delete_option( 'lucky_egg_log' );
	delete_option( 'lucky_egg_revoke_legacy_sessions' );

	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_lucky_egg_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_lucky_egg_' ) . '%'
		)
	);

	wp_clear_scheduled_hook( 'lucky_egg_cleanup' );
	wp_clear_scheduled_hook( 'lucky_egg_sms_sweep' );
	wp_clear_scheduled_hook( 'lucky_egg_revoke_legacy_sessions' );
	wp_unschedule_hook( 'lucky_egg_send_sms' );
}

if ( is_multisite() ) {
	$lucky_egg_sites = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $lucky_egg_sites as $lucky_egg_site_id ) {
		switch_to_blog( (int) $lucky_egg_site_id );
		lucky_egg_uninstall_site();
		restore_current_blog();
	}
} else {
	lucky_egg_uninstall_site();
}

// User accounts (including ones auto-created by 1.0.0) are intentionally kept; only plugin-specific user meta is removed.
delete_metadata( 'user', 0, 'lucky_egg_phone', '', true );
delete_metadata( 'user', 0, 'lucky_egg_name', '', true );
delete_metadata( 'user', 0, 'lucky_egg_created', '', true );
