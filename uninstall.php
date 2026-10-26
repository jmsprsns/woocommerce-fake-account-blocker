<?php
/**
 * Runs when the plugin is deleted from the Plugins screen: remove its data,
 * unless "Keep the plugin's data and settings" is ticked. On a multisite
 * this covers every site.
 *
 * @package WooCommerce_Fake_Account_Blocker
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Remove the plugin's table, settings, scan results and cached lookups for the current site.
 *
 * @return void
 */
function fake_account_blocker_uninstall_site() {
	global $wpdb;

	$settings = get_option( 'fake_account_blocker_settings' );
	if ( is_array( $settings ) && ! empty( $settings['keep_data'] ) ) {
		return;
	}

	// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Removing our own table and rows on uninstall.
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wcfab_log" );

	// Cached DNS answers and IP lockouts.
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_wcfab\\_%' OR option_name LIKE '\\_transient\\_timeout\\_wcfab\\_%'" );
	// phpcs:enable

	foreach ( array(
		'fake_account_blocker_settings',
		'fake_account_blocker_db_version',
		'fake_account_blocker_forms_seen',
		'fake_account_blocker_scan',
		'fake_account_blocker_pruned',
		'fake_account_blocker_cf_ranges',
	) as $option ) {
		delete_option( $option );
	}

	wp_clear_scheduled_hook( 'fake_account_blocker_daily' );
}

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $fake_account_blocker_site ) {
		switch_to_blog( $fake_account_blocker_site );
		fake_account_blocker_uninstall_site();
		restore_current_blog();
	}
} else {
	fake_account_blocker_uninstall_site();
}

// Plugin Update Checker's cached update state.
delete_site_option( 'external_updates-fake-account-blocker' );
