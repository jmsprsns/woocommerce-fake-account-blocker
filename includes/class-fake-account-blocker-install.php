<?php
/**
 * The sign-up log table: created on activation, upgraded when the schema
 * changes.
 *
 * @package WooCommerce_Fake_Account_Blocker
 */

defined( 'ABSPATH' ) || exit;

/**
 * Schema management.
 */
final class WooCommerce_Fake_Account_Blocker_Install {

	/**
	 * Bump when the table changes; dbDelta then runs on the next request.
	 */
	const DB_VERSION = '1';

	/**
	 * Option holding the installed schema version.
	 */
	const DB_OPTION = 'fake_account_blocker_db_version';

	/**
	 * Create or update the table when the stored version is behind. Cheap:
	 * one autoloaded option read on most requests.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( get_option( self::DB_OPTION ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Create or update the table.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$collate = $wpdb->get_charset_collate();
		$log     = WooCommerce_Fake_Account_Blocker_Log::table();

		// One row per checked sign-up: "blocked" (refused), "allowed" (account
		// created) or "logged" (would have been refused, but test mode is on).
		dbDelta(
			"CREATE TABLE {$log} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				created_at datetime NOT NULL,
				result varchar(10) NOT NULL DEFAULT 'blocked',
				reason varchar(32) NOT NULL DEFAULT '',
				detail varchar(191) NOT NULL DEFAULT '',
				context varchar(16) NOT NULL DEFAULT '',
				ip varchar(45) NOT NULL DEFAULT '',
				ip_key varchar(45) NOT NULL DEFAULT '',
				email varchar(100) NOT NULL DEFAULT '',
				username varchar(60) NOT NULL DEFAULT '',
				user_agent varchar(255) NOT NULL DEFAULT '',
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY ip_key (ip_key,created_at),
				KEY result (result,created_at),
				KEY created_at (created_at),
				KEY email (email)
			) {$collate};"
		);

		update_option( self::DB_OPTION, self::DB_VERSION );
	}
}
