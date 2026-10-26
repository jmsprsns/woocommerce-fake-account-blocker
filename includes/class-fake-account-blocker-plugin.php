<?php
/**
 * Main plugin orchestrator: owns the settings and boots every component.
 *
 * @package WooCommerce_Fake_Account_Blocker
 */

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin singleton.
 */
final class WooCommerce_Fake_Account_Blocker_Plugin {

	/**
	 * Option key holding the plugin's settings array.
	 */
	const OPTION = 'fake_account_blocker_settings';

	/**
	 * Daily housekeeping: prune the log, refresh Cloudflare's IP ranges.
	 */
	const CRON_DAILY = 'fake_account_blocker_daily';

	/**
	 * Single shared instance.
	 *
	 * @var WooCommerce_Fake_Account_Blocker_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Settings with defaults filled in, cached for the request.
	 *
	 * @var array<string,mixed>|null
	 */
	private $settings = null;

	/**
	 * Get (and lazily create) the shared instance.
	 *
	 * @return WooCommerce_Fake_Account_Blocker_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Wire everything up.
	 */
	private function __construct() {
		WooCommerce_Fake_Account_Blocker_Install::maybe_upgrade();

		add_action( 'update_option_' . self::OPTION, array( $this, 'flush_settings' ) );
		add_action( self::CRON_DAILY, array( $this, 'daily' ) );

		// A new theme's sign-up forms have to show they print the bot-check
		// fields before those checks are enforced on them.
		add_action( 'switch_theme', array( 'WooCommerce_Fake_Account_Blocker_Form', 'forget_seen' ) );

		if ( is_admin() ) {
			new WooCommerce_Fake_Account_Blocker_Admin( $this );
			new WooCommerce_Fake_Account_Blocker_Cleanup( $this );
		}

		new WooCommerce_Fake_Account_Blocker_Privacy();

		if ( $this->is_enabled() ) {
			new WooCommerce_Fake_Account_Blocker_Guard( $this );
		}

		if ( ! wp_next_scheduled( self::CRON_DAILY ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_DAILY );
		}
	}

	/**
	 * Default value for every setting.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		return array(
			'enabled'          => 1,

			// "block" refuses fake sign-ups; "log" only records them (test mode).
			'mode'             => 'block',

			// Where to check.
			'protect_account'  => 1,
			'protect_checkout' => 1,
			'protect_wp'       => 1,

			// Bot checks, on the sign-up forms only.
			'honeypot'         => 1,
			'timing'           => 1,
			'min_seconds'      => 2,
			'js_check'         => 1,

			// Limits per IP.
			'rate_limit'       => 1,
			'rate_max'         => 3,
			'rate_hours'       => 1,
			'lockout'          => 1,
			'lockout_after'    => 5,

			// Email addresses and names.
			'disposable'       => 1,
			'mx_check'         => 1,
			'spam_names'       => 1,
			'blocked_domains'  => '',
			'allowed_domains'  => '',
			'blocked_words'    => '',
			'blocked_ips'      => '',

			// Shown for refusals that don't have their own message.
			'message'          => '',

			// Visitor IP.
			'trusted_proxies'  => '',
			'proxy_header'     => 'x-forwarded-for',
			'trust_private'    => 1,

			// Data.
			'retention_days'   => 30,
			'keep_data'        => 0,
		);
	}

	/**
	 * All settings, with defaults filled in.
	 *
	 * @return array<string,mixed>
	 */
	public function get_settings() {
		if ( null === $this->settings ) {
			$saved          = get_option( self::OPTION, array() );
			$this->settings = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
		}

		return $this->settings;
	}

	/**
	 * A single setting.
	 *
	 * @param string $key Setting key from {@see defaults()}.
	 * @return mixed Null for an unknown key.
	 */
	public function get_setting( $key ) {
		$settings = $this->get_settings();

		return isset( $settings[ $key ] ) ? $settings[ $key ] : null;
	}

	/**
	 * Forget the cached settings after they're saved.
	 *
	 * @return void
	 */
	public function flush_settings() {
		$this->settings = null;
	}

	/**
	 * Whether sign-ups are checked at all. The FAKE_ACCOUNT_BLOCKER_DISABLED
	 * constant turns every check off from wp-config.php, whatever the setting says.
	 *
	 * @return bool
	 */
	public function is_enabled() {
		if ( defined( 'FAKE_ACCOUNT_BLOCKER_DISABLED' ) && FAKE_ACCOUNT_BLOCKER_DISABLED ) {
			return false;
		}

		return (bool) $this->get_setting( 'enabled' );
	}

	/**
	 * Whether fake sign-ups are only logged, not refused.
	 *
	 * @return bool
	 */
	public function is_test_mode() {
		return 'log' === $this->get_setting( 'mode' );
	}

	/**
	 * Daily housekeeping. Never throws: a failure here mustn't stop other cron jobs.
	 *
	 * @return void
	 */
	public function daily() {
		try {
			WooCommerce_Fake_Account_Blocker_Log::prune( (int) $this->get_setting( 'retention_days' ) );
			WooCommerce_Fake_Account_Blocker_Request::maybe_refresh_cloudflare_ranges();
		} catch ( \Throwable $e ) {
			self::log( 'daily', $e );
		}
	}

	/**
	 * Log a caught error when WP_DEBUG is on. Every sign-up check catches its
	 * own errors and ends up here, so a bug in this plugin can never stop a
	 * real customer from creating an account.
	 *
	 * @param string     $context Where it happened.
	 * @param \Throwable $error   The caught error.
	 * @return void
	 */
	public static function log( $context, $error ) {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug-only logging.
			sprintf(
				'[WooCommerce Fake Account Blocker] %s: %s in %s:%d',
				$context,
				$error->getMessage(),
				$error->getFile(),
				$error->getLine()
			)
		);
	}
}
