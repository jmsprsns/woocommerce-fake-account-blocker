<?php
/**
 * Privacy: suggested privacy-policy text, and the sign-up log in WordPress's
 * "Export Personal Data" and "Erase Personal Data" tools.
 *
 * @package WooCommerce_Fake_Account_Blocker
 */

defined( 'ABSPATH' ) || exit;

/**
 * Privacy tools integration.
 */
final class WooCommerce_Fake_Account_Blocker_Privacy {

	/**
	 * Log rows handled per page of an export request.
	 */
	const BATCH = 100;

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_init', array( $this, 'policy_text' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
	}

	/**
	 * Suggested text for Settings > Privacy > Policy Guide.
	 *
	 * @return void
	 */
	public function policy_text() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$text = '<p>' . __( 'To stop automated and fake sign-ups, when you create an account we check the sign-up form for signs of a bot and check that your email address is a permanent one that can receive email, which involves a DNS lookup of its domain. We don\'t use any outside service for this.', 'fake-account-blocker' ) . '</p>'
			. '<p>' . __( 'We record each sign-up attempt with your email address, username, IP address and browser, so we can limit how many accounts one network creates and review refused sign-ups. These records are deleted automatically after a set period.', 'fake-account-blocker' ) . '</p>';

		wp_add_privacy_policy_content( __( 'WooCommerce Fake Account Blocker', 'fake-account-blocker' ), wp_kses_post( $text ) );
	}

	/**
	 * Add the exporter.
	 *
	 * @param array $exporters Exporters.
	 * @return array
	 */
	public function register_exporter( $exporters ) {
		$exporters['fake-account-blocker'] = array(
			'exporter_friendly_name' => __( 'Sign-up attempts', 'fake-account-blocker' ),
			'callback'               => array( $this, 'export' ),
		);

		return $exporters;
	}

	/**
	 * Add the eraser.
	 *
	 * @param array $erasers Erasers.
	 * @return array
	 */
	public function register_eraser( $erasers ) {
		$erasers['fake-account-blocker'] = array(
			'eraser_friendly_name' => __( 'Sign-up attempts', 'fake-account-blocker' ),
			'callback'             => array( $this, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * Export the log rows for an email address.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page (1-based).
	 * @return array{data:array,done:bool}
	 */
	public function export( $email, $page = 1 ) {
		$page   = max( 1, (int) $page );
		$rows   = WooCommerce_Fake_Account_Blocker_Log::for_email( (string) $email, self::BATCH, ( $page - 1 ) * self::BATCH );
		$labels = WooCommerce_Fake_Account_Blocker_Admin::reason_labels();
		$data   = array();

		foreach ( $rows as $row ) {
			$reason = (string) $row['reason'];
			$data[] = array(
				'group_id'    => 'fake-account-blocker',
				'group_label' => __( 'Sign-up attempts', 'fake-account-blocker' ),
				'item_id'     => 'wcfab-' . (int) $row['id'],
				'data'        => array(
					array(
						'name'  => __( 'Date (UTC)', 'fake-account-blocker' ),
						'value' => (string) $row['created_at'],
					),
					array(
						'name'  => __( 'Result', 'fake-account-blocker' ),
						'value' => (string) $row['result'],
					),
					array(
						'name'  => __( 'Reason', 'fake-account-blocker' ),
						'value' => isset( $labels[ $reason ] ) ? $labels[ $reason ] : $reason,
					),
					array(
						'name'  => __( 'Email', 'fake-account-blocker' ),
						'value' => (string) $row['email'],
					),
					array(
						'name'  => __( 'Username', 'fake-account-blocker' ),
						'value' => (string) $row['username'],
					),
					array(
						'name'  => __( 'IP address', 'fake-account-blocker' ),
						'value' => (string) $row['ip'],
					),
					array(
						'name'  => __( 'Browser', 'fake-account-blocker' ),
						'value' => (string) $row['user_agent'],
					),
				),
			);
		}

		return array(
			'data' => $data,
			'done' => count( $rows ) < self::BATCH,
		);
	}

	/**
	 * Erase the log rows for an email address.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page (unused: everything goes in one pass).
	 * @return array{items_removed:bool,items_retained:bool,messages:array,done:bool}
	 */
	public function erase( $email, $page = 1 ) {
		$removed = WooCommerce_Fake_Account_Blocker_Log::erase_email( (string) $email );

		return array(
			'items_removed'  => $removed > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}
}
