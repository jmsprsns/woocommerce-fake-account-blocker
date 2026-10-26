<?php
/**
 * Spam Accounts: find the fake customer accounts a site already has, and
 * delete the ones the admin picks.
 *
 * Only plain customer accounts are ever listed or deleted: the customer role
 * and nothing else, no orders (in either order storage, any status, guest
 * orders under the same email included), and nothing they've published.
 * Every one of those is checked again on the server right before deleting.
 *
 * @package WooCommerce_Fake_Account_Blocker
 */

defined( 'ABSPATH' ) || exit;

/**
 * Existing-account scan and clean-up.
 */
final class WooCommerce_Fake_Account_Blocker_Cleanup {

	/**
	 * Option holding the last scan and what it found.
	 */
	const OPTION = 'fake_account_blocker_scan';

	/**
	 * Accounts read per query.
	 */
	const BATCH = 200;

	/**
	 * Seconds each scan request works for before reporting progress.
	 */
	const TIME_BUDGET = 8;

	/**
	 * Most accounts one scan lists.
	 */
	const MAX_FLAGGED = 10000;

	/**
	 * Most accounts deleted per request.
	 */
	const DELETE_BATCH = 50;

	/**
	 * Plugin instance.
	 *
	 * @var WooCommerce_Fake_Account_Blocker_Plugin
	 */
	private $plugin;

	/**
	 * Which tables exist, once per request.
	 *
	 * @var array<string,bool>
	 */
	private static $tables = array();

	/**
	 * Constructor.
	 *
	 * @param WooCommerce_Fake_Account_Blocker_Plugin $plugin Plugin instance.
	 */
	public function __construct( WooCommerce_Fake_Account_Blocker_Plugin $plugin ) {
		$this->plugin = $plugin;

		add_action( 'wp_ajax_fake_account_blocker_scan', array( $this, 'ajax_scan' ) );
		add_action( 'wp_ajax_fake_account_blocker_delete', array( $this, 'ajax_delete' ) );
	}

	/**
	 * The last scan.
	 *
	 * @return array{started:int,finished:int,offset:int,total:int,dns:int,deleted:int,flagged:array<int,string[]>}
	 */
	public static function state() {
		$state = get_option( self::OPTION );

		$state = is_array( $state ) ? wp_parse_args( $state, self::blank() ) : self::blank();

		$state['flagged'] = is_array( $state['flagged'] ) ? $state['flagged'] : array();

		return $state;
	}

	/**
	 * A scan that hasn't started.
	 *
	 * @return array<string,mixed>
	 */
	private static function blank() {
		return array(
			'started'  => 0,
			'finished' => 0,
			'offset'   => 0,
			'total'    => 0,
			'dns'      => 0,
			'deleted'  => 0,
			'flagged'  => array(),
		);
	}

	/**
	 * AJAX: scan the next stretch of customer accounts.
	 *
	 * @return void
	 */
	public function ajax_scan() {
		check_ajax_referer( WooCommerce_Fake_Account_Blocker_Admin::NONCE, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'list_users' ) ) {
			wp_send_json_error( array( 'message' => __( 'Sorry, you are not allowed to do that.', 'fake-account-blocker' ) ), 403 );
		}

		if ( ! class_exists( 'WooCommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'WooCommerce isn\'t active, so there are no customer accounts to check.', 'fake-account-blocker' ) ) );
		}

		$state = self::state();

		if ( ! empty( $_POST['restart'] ) || ! $state['started'] ) {
			$state            = self::blank();
			$state['started'] = time();
			$state['dns']     = empty( $_POST['dns'] ) ? 0 : 1;
			$state['total']   = self::count_customers();
		}

		if ( ! $state['finished'] ) {
			$state = $this->scan( $state );
		}

		update_option( self::OPTION, $state, false );

		wp_send_json_success(
			array(
				'done'    => (bool) $state['finished'],
				'checked' => (int) $state['offset'],
				'total'   => (int) max( $state['total'], $state['offset'] ),
				'found'   => count( $state['flagged'] ),
				/* translators: 1: accounts checked so far, 2: all customer accounts, 3: accounts that look fake. */
				'message' => sprintf( __( 'Checked %1$s of %2$s customer accounts. %3$s look fake so far.', 'fake-account-blocker' ), number_format_i18n( $state['offset'] ), number_format_i18n( max( $state['total'], $state['offset'] ) ), number_format_i18n( count( $state['flagged'] ) ) ),
			)
		);
	}

	/**
	 * Work through accounts until the time budget runs out or none are left.
	 *
	 * @param array<string,mixed> $state Scan so far.
	 * @return array<string,mixed>
	 */
	private function scan( array $state ) {
		$lists    = $this->lists();
		$deadline = microtime( true ) + self::TIME_BUDGET;

		while ( microtime( true ) < $deadline ) {
			$users = get_users(
				array(
					'role'        => 'customer',
					'orderby'     => 'ID',
					'order'       => 'ASC',
					'number'      => self::BATCH,
					'offset'      => (int) $state['offset'],
					'count_total' => false,
				)
			);

			$done = 0;
			foreach ( $users as $user ) {
				$reasons = self::reasons_for( $user, $lists, (bool) $state['dns'] );

				if ( $reasons && count( $state['flagged'] ) < self::MAX_FLAGGED && self::deletable( $user ) ) {
					$state['flagged'][ (int) $user->ID ] = $reasons;
				}

				++$state['offset'];
				++$done;

				if ( microtime( true ) >= $deadline ) {
					break;
				}
			}

			// A short last page, read to the end: that's everyone.
			if ( count( $users ) < self::BATCH && count( $users ) === $done ) {
				$state['finished'] = time();
				break;
			}
		}

		return $state;
	}

	/**
	 * The admin's lists, worked out once per request.
	 *
	 * @return array<string,mixed>
	 */
	private function lists() {
		return array(
			'allowed' => WooCommerce_Fake_Account_Blocker_Rules::domain_set( (string) $this->plugin->get_setting( 'allowed_domains' ) ),
			'blocked' => WooCommerce_Fake_Account_Blocker_Rules::domain_set( (string) $this->plugin->get_setting( 'blocked_domains' ) ),
			'words'   => (string) $this->plugin->get_setting( 'blocked_words' ),
		);
	}

	/**
	 * Why an existing account looks fake. The scan always looks for
	 * disposable addresses and links in names, whatever the sign-up
	 * settings, and honours the allowed and blocked lists.
	 *
	 * @param WP_User             $user  Account.
	 * @param array<string,mixed> $lists From {@see lists()}.
	 * @param bool                $dns   Also check the email domain takes mail.
	 * @return string[] Reason codes; empty when it looks fine.
	 */
	private static function reasons_for( $user, array $lists, $dns ) {
		if ( array( 'customer' ) !== array_values( (array) $user->roles ) ) {
			return array();
		}

		$email   = strtolower( (string) $user->user_email );
		$domain  = WooCommerce_Fake_Account_Blocker_Rules::email_domain( $email );
		$allowed = '' !== $domain && '' !== WooCommerce_Fake_Account_Blocker_Rules::domain_matches( $domain, $lists['allowed'] );
		$reasons = array();

		if ( '' !== $domain && ! $allowed ) {
			if ( '' !== WooCommerce_Fake_Account_Blocker_Rules::domain_matches( $domain, $lists['blocked'] ) ) {
				$reasons[] = 'blocked_domain';
			}
			if ( '' !== WooCommerce_Fake_Account_Blocker_Rules::disposable( $domain ) ) {
				$reasons[] = 'disposable';
			}
		}

		$names = array(
			'first_name'         => (string) get_user_meta( $user->ID, 'first_name', true ),
			'last_name'          => (string) get_user_meta( $user->ID, 'last_name', true ),
			'billing_first_name' => (string) get_user_meta( $user->ID, 'billing_first_name', true ),
			'billing_last_name'  => (string) get_user_meta( $user->ID, 'billing_last_name', true ),
			'display_name'       => (string) $user->display_name,
		);

		if ( '' !== WooCommerce_Fake_Account_Blocker_Rules::blocked_word( array_merge( array( $email, (string) $user->user_login ), array_values( $names ) ), $lists['words'] ) ) {
			$reasons[] = 'blocked_word';
		}

		$spam = WooCommerce_Fake_Account_Blocker_Rules::spam_name( $user->user_login, true );
		foreach ( $names as $field => $value ) {
			$spam = $spam || WooCommerce_Fake_Account_Blocker_Rules::spam_name( $value, WooCommerce_Fake_Account_Blocker_Rules::is_strict_field( $field ) );
		}
		if ( $spam ) {
			$reasons[] = 'spam_name';
		}

		if ( $dns && '' !== $domain && ! $allowed && ! in_array( 'disposable', $reasons, true ) && false === WooCommerce_Fake_Account_Blocker_Rules::can_receive_mail( $domain ) ) {
			$reasons[] = 'no_mx';
		}

		return $reasons;
	}

	/**
	 * AJAX: delete accounts the last scan listed.
	 *
	 * @return void
	 */
	public function ajax_delete() {
		check_ajax_referer( WooCommerce_Fake_Account_Blocker_Admin::NONCE, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( is_multisite() ? 'remove_users' : 'delete_users' ) ) {
			wp_send_json_error( array( 'message' => __( 'Sorry, you are not allowed to delete users.', 'fake-account-blocker' ) ), 403 );
		}

		$state = self::state();

		// "all" works through everything the scan found, a batch at a time.
		if ( ! empty( $_POST['all'] ) ) {
			$ids = array_keys( $state['flagged'] );
		} else {
			$ids = isset( $_POST['ids'] ) ? wp_parse_id_list( wp_unslash( $_POST['ids'] ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- wp_parse_id_list() keeps positive integers only.
		}
		$ids = array_slice( array_filter( array_map( 'absint', $ids ) ), 0, self::DELETE_BATCH );

		require_once ABSPATH . 'wp-admin/includes/user.php';

		$deleted = array();
		$kept    = array();

		foreach ( $ids as $id ) {
			// Only accounts the scan found can be deleted from here.
			if ( ! isset( $state['flagged'][ $id ] ) ) {
				$kept[] = $id;
				continue;
			}

			$user = get_userdata( $id );

			if ( ! $user ) {
				unset( $state['flagged'][ $id ] );
				continue;
			}

			// Something changed since the scan (an order, a new role): keep it.
			if ( ! self::deletable( $user ) ) {
				unset( $state['flagged'][ $id ] );
				$kept[] = $id;
				continue;
			}

			$done = false;
			if ( is_multisite() ) {
				// On a network the account is shared, so it's only taken off this site.
				$done = current_user_can( 'remove_user', $id ) ? remove_user_from_blog( $id, get_current_blog_id() ) : false;
			} elseif ( current_user_can( 'delete_user', $id ) ) {
				$done = wp_delete_user( $id );
			}

			// Either way it leaves the list, so "delete all" can't get stuck on it.
			unset( $state['flagged'][ $id ] );
			if ( true === $done ) {
				$deleted[] = $id;
				++$state['deleted'];
			} else {
				$kept[] = $id;
			}
		}

		update_option( self::OPTION, $state, false );

		wp_send_json_success(
			array(
				'deleted'   => $deleted,
				'kept'      => $kept,
				'remaining' => count( $state['flagged'] ),
			)
		);
	}

	/**
	 * Whether an account is safe to delete: a plain customer who has never
	 * ordered or published anything, and isn't you.
	 *
	 * @param WP_User $user Account.
	 * @return bool
	 */
	public static function deletable( $user ) {
		if ( ! $user instanceof WP_User || ! $user->ID ) {
			return false;
		}

		if ( array( 'customer' ) !== array_values( (array) $user->roles ) || (int) $user->ID === get_current_user_id() || is_super_admin( $user->ID ) ) {
			return false;
		}

		return ! self::has_orders( (int) $user->ID, (string) $user->user_email ) && ! self::has_posts( (int) $user->ID );
	}

	/**
	 * Whether an account has any order: in either order storage, any type
	 * (subscriptions too) and any status (trash and drafts too), or a guest
	 * order placed with the same email in the order tables.
	 *
	 * @param int    $user_id User ID.
	 * @param string $email   Email address.
	 * @return bool True when it can't be told (WooCommerce not active).
	 */
	private static function has_orders( $user_id, $email ) {
		global $wpdb;

		if ( ! class_exists( 'WooCommerce' ) ) {
			return true;
		}

		$orders = $wpdb->prefix . 'wc_orders';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Existence checks across both order storages; table names aren't user input.
		if ( self::table_exists( $orders ) ) {
			if ( $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$orders} WHERE customer_id = %d LIMIT 1", $user_id ) ) ) {
				return true;
			}
			if ( '' !== $email && $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$orders} WHERE billing_email = %s LIMIT 1", $email ) ) ) {
				return true;
			}
		}

		$found = $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->postmeta} WHERE meta_key = '_customer_user' AND meta_value = %s LIMIT 1", (string) $user_id ) );
		// phpcs:enable

		return (bool) $found;
	}

	/**
	 * Whether a user is the author of any post, page, product or other
	 * content, which deleting them would delete too.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	private static function has_posts( $user_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Existence check over every post type and status.
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->posts} WHERE post_author = %d LIMIT 1", $user_id ) );
	}

	/**
	 * How many accounts have the customer role.
	 *
	 * @return int
	 */
	private static function count_customers() {
		$query = new WP_User_Query(
			array(
				'role'        => 'customer',
				'number'      => 1,
				'fields'      => 'ID',
				'count_total' => true,
			)
		);

		return (int) $query->get_total();
	}

	/**
	 * Whether a table exists.
	 *
	 * @param string $table Full table name.
	 * @return bool
	 */
	private static function table_exists( $table ) {
		global $wpdb;

		if ( ! isset( self::$tables[ $table ] ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Schema check.
			self::$tables[ $table ] = $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		}

		return self::$tables[ $table ];
	}
}
