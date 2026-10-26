<?php
/**
 * The sign-up log: every refused sign-up, and every account created through
 * a checked form (the per-IP limits count those).
 *
 * @package WooCommerce_Fake_Account_Blocker
 */

defined( 'ABSPATH' ) || exit;

/**
 * Log storage and queries.
 */
final class WooCommerce_Fake_Account_Blocker_Log {

	/**
	 * Most rows kept, however short the retention. A bot flood can't fill
	 * the database: past this, the oldest rows go first.
	 */
	const MAX_ROWS = 50000;

	/**
	 * Option counting refused sign-ups whose rows have been pruned, so the
	 * all-time total survives the retention period.
	 */
	const PRUNED_OPTION = 'fake_account_blocker_pruned';

	/**
	 * Row results.
	 */
	const BLOCKED = 'blocked';
	const ALLOWED = 'allowed';
	const LOGGED  = 'logged';

	/**
	 * The table name with the site's prefix.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'wcfab_log';
	}

	/**
	 * Record a checked sign-up.
	 *
	 * @param string                    $result  One of BLOCKED, ALLOWED or LOGGED.
	 * @param array<string,string>|null $verdict Reason and detail, or null for a clean sign-up.
	 * @param array<string,mixed>       $signup  Sign-up details from the guard.
	 * @param int                       $user_id Account created, if any.
	 * @return void
	 */
	public static function insert( $result, $verdict, array $signup, $user_id = 0 ) {
		global $wpdb;

		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Our own table.
			self::table(),
			array(
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
				'result'     => $result,
				'reason'     => self::clip( is_array( $verdict ) ? $verdict['reason'] : '', 32 ),
				'detail'     => self::clip( is_array( $verdict ) ? $verdict['detail'] : '', 191 ),
				'context'    => self::clip( isset( $signup['context'] ) ? $signup['context'] : '', 16 ),
				'ip'         => self::clip( isset( $signup['ip'] ) ? $signup['ip'] : '', 45 ),
				'ip_key'     => self::clip( isset( $signup['ip_key'] ) ? $signup['ip_key'] : '', 45 ),
				'email'      => self::clip( isset( $signup['email'] ) ? $signup['email'] : '', 100 ),
				'username'   => self::clip( isset( $signup['username'] ) ? $signup['username'] : '', 60 ),
				'user_agent' => self::clip( isset( $signup['user_agent'] ) ? $signup['user_agent'] : '', 255 ),
				'user_id'    => absint( $user_id ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' )
		);

		// Keep the table bounded during a flood, not just at the daily prune.
		if ( $inserted && 0 === (int) $wpdb->insert_id % 500 ) {
			self::cap();
		}
	}

	/**
	 * Accounts created from an IP (or IPv6 /64) in the last few hours, through
	 * the sign-up forms (accounts made at checkout don't count).
	 *
	 * @param string $ip_key Address from WooCommerce_Fake_Account_Blocker_Request::ip_key().
	 * @param int    $hours  Window.
	 * @return int
	 */
	public static function count_created( $ip_key, $hours ) {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Our own table; fresh count needed.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE ip_key = %s AND created_at >= %s AND result IN ('allowed','logged') AND context <> 'checkout'", $ip_key, self::since( $hours * HOUR_IN_SECONDS ) ) );
	}

	/**
	 * Refused sign-ups from an IP (or IPv6 /64) in the last few minutes.
	 *
	 * @param string $ip_key  Address.
	 * @param int    $seconds Window.
	 * @return int
	 */
	public static function count_blocked( $ip_key, $seconds ) {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Our own table; fresh count needed.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE ip_key = %s AND created_at >= %s AND result = 'blocked'", $ip_key, self::since( $seconds ) ) );
	}

	/**
	 * Counts for the Overview tab.
	 *
	 * @return array{blocked_day:int,blocked_month:int,logged_month:int,created_month:int,blocked_total:int}
	 */
	public static function totals() {
		global $wpdb;

		$table = self::table();
		$day   = self::since( DAY_IN_SECONDS );
		$month = self::since( 30 * DAY_IN_SECONDS );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Our own table.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					SUM( result = 'blocked' AND created_at >= %s ) AS blocked_day,
					SUM( result = 'blocked' AND created_at >= %s ) AS blocked_month,
					SUM( result = 'logged' AND created_at >= %s ) AS logged_month,
					SUM( result IN ('allowed','logged') AND created_at >= %s ) AS created_month,
					SUM( result = 'blocked' ) AS blocked_total
				FROM {$table}",
				$day,
				$month,
				$month,
				$month
			),
			ARRAY_A
		);
		// phpcs:enable

		$row = is_array( $row ) ? $row : array();
		$out = array();
		foreach ( array( 'blocked_day', 'blocked_month', 'logged_month', 'created_month', 'blocked_total' ) as $key ) {
			$out[ $key ] = isset( $row[ $key ] ) ? (int) $row[ $key ] : 0;
		}

		$out['blocked_total'] += (int) get_option( self::PRUNED_OPTION, 0 );

		return $out;
	}

	/**
	 * Refused (or, in test mode, flagged) sign-ups by reason.
	 *
	 * @param int $days Days back.
	 * @return array<string,int> Reason => count, most first.
	 */
	public static function reasons( $days ) {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Our own table.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT reason, COUNT(*) AS n FROM {$table} WHERE result IN ('blocked','logged') AND created_at >= %s GROUP BY reason ORDER BY n DESC LIMIT 20", self::since( $days * DAY_IN_SECONDS ) ), ARRAY_A );

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (string) $row['reason'] ] = (int) $row['n'];
		}

		return $out;
	}

	/**
	 * Recent rows, newest first.
	 *
	 * @param string $filter "flagged" (refused or logged) or "all".
	 * @param int    $limit  Rows per page.
	 * @param int    $offset Rows to skip.
	 * @return array<int,array<string,string>>
	 */
	public static function recent( $filter, $limit, $offset = 0 ) {
		global $wpdb;

		$table = self::table();
		$where = 'all' === $filter ? '1=1' : "result IN ('blocked','logged')";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Our own table; $where is one of two fixed strings.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d", absint( $limit ), absint( $offset ) ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * How many rows {@see recent()} can page through.
	 *
	 * @param string $filter "flagged" or "all".
	 * @return int
	 */
	public static function count_rows( $filter ) {
		global $wpdb;

		$table = self::table();
		$where = 'all' === $filter ? '1=1' : "result IN ('blocked','logged')";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Our own table; $where is one of two fixed strings.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where}" );
	}

	/**
	 * Delete rows older than the retention period, then cap the table.
	 *
	 * @param int $days Retention in days; 0 keeps everything (up to MAX_ROWS).
	 * @return void
	 */
	public static function prune( $days ) {
		global $wpdb;

		$days  = absint( $days );
		$table = self::table();

		if ( $days > 0 ) {
			$cutoff = self::since( $days * DAY_IN_SECONDS );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Our own table.
			$blocked = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE created_at < %s AND result = 'blocked'", $cutoff ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff ) );
			// phpcs:enable

			self::add_pruned( $blocked );
		}

		self::cap();
	}

	/**
	 * Drop the oldest rows past MAX_ROWS.
	 *
	 * @return void
	 */
	public static function cap() {
		global $wpdb;

		$table = self::table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Our own table.
		$edge = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} ORDER BY id DESC LIMIT 1 OFFSET %d", self::MAX_ROWS ) );
		if ( $edge < 1 ) {
			return;
		}

		$blocked = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE id <= %d AND result = 'blocked'", $edge ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id <= %d", $edge ) );
		// phpcs:enable

		self::add_pruned( $blocked );
	}

	/**
	 * Rows for an email address, for the personal data exporter.
	 *
	 * @param string $email  Email address.
	 * @param int    $limit  Rows per page.
	 * @param int    $offset Rows to skip.
	 * @return array<int,array<string,string>>
	 */
	public static function for_email( $email, $limit, $offset ) {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Our own table.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE email = %s ORDER BY id ASC LIMIT %d OFFSET %d", self::clip( strtolower( $email ), 100 ), absint( $limit ), absint( $offset ) ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Delete every row for an email address, for the personal data eraser.
	 *
	 * @param string $email Email address.
	 * @return int Rows deleted.
	 */
	public static function erase_email( $email ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Our own table.
		$deleted = $wpdb->delete( self::table(), array( 'email' => self::clip( strtolower( $email ), 100 ) ), array( '%s' ) );

		return (int) $deleted;
	}

	/**
	 * Remember how many refused sign-ups were pruned.
	 *
	 * @param int $count Rows removed.
	 * @return void
	 */
	private static function add_pruned( $count ) {
		if ( $count > 0 ) {
			update_option( self::PRUNED_OPTION, (int) get_option( self::PRUNED_OPTION, 0 ) + $count, false );
		}
	}

	/**
	 * A GMT datetime some seconds ago, in the table's format.
	 *
	 * @param int $seconds Seconds back.
	 * @return string
	 */
	private static function since( $seconds ) {
		return gmdate( 'Y-m-d H:i:s', time() - absint( $seconds ) );
	}

	/**
	 * A string cut to a column's length, as valid UTF-8 (MySQL refuses the
	 * whole row otherwise).
	 *
	 * @param mixed $value  Value.
	 * @param int   $length Most characters.
	 * @return string
	 */
	public static function clip( $value, $length ) {
		$value = wp_check_invalid_utf8( is_scalar( $value ) ? (string) $value : '', true );

		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $length, 'UTF-8' ) : substr( $value, 0, $length );
	}
}
