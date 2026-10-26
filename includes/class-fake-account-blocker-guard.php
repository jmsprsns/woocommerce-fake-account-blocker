<?php
/**
 * The sign-up guard: hooks into every way a customer account gets created
 * and refuses the fake ones.
 *
 * - WooCommerce's My Account sign-up form: every check, including the bot
 *   checks printed into the form.
 * - Accounts created at checkout (classic and block), on the order
 *   confirmation page and by other WooCommerce sign-up code: the email and
 *   name checks and the blocked IPs, as there's no form of ours to look at.
 *   The per-IP limits never apply here: behind a proxy that isn't set up,
 *   every shopper shares a few addresses, and a paying customer mustn't be
 *   refused because of that.
 * - WordPress's own wp-login.php registration form: every check.
 *
 * @package WooCommerce_Fake_Account_Blocker
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sign-up checks.
 */
final class WooCommerce_Fake_Account_Blocker_Guard {

	/**
	 * How long an IP stays locked out, in seconds.
	 */
	const LOCK_SECONDS = 3600;

	/**
	 * While locked out, log the refusals at most this often, in seconds,
	 * so a bot hammering the form can't fill the log.
	 */
	const LOCK_LOG_EVERY = 600;

	/**
	 * Plugin instance.
	 *
	 * @var WooCommerce_Fake_Account_Blocker_Plugin
	 */
	private $plugin;

	/**
	 * Whether this request came through the My Account form, which is
	 * checked before WooCommerce creates the account.
	 *
	 * @var bool
	 */
	private static $account_form = false;

	/**
	 * A sign-up that passed (or, in test mode, was only logged), waiting for
	 * its account to be created.
	 *
	 * @var array<string,mixed>|null
	 */
	private static $pending = null;

	/**
	 * Constructor.
	 *
	 * @param WooCommerce_Fake_Account_Blocker_Plugin $plugin Plugin instance.
	 */
	public function __construct( WooCommerce_Fake_Account_Blocker_Plugin $plugin ) {
		$this->plugin = $plugin;

		add_action( 'woocommerce_register_form', array( $this, 'render_account_fields' ) );
		add_action( 'register_form', array( $this, 'render_wordpress_fields' ) );

		add_filter( 'woocommerce_process_registration_errors', array( $this, 'check_account_form' ), 20, 4 );
		add_filter( 'woocommerce_registration_errors', array( $this, 'check_woocommerce' ), 20, 3 );
		add_filter( 'registration_errors', array( $this, 'check_wordpress' ), 20, 3 );
		add_action( 'user_register', array( $this, 'record' ) );

		// WP Rocket's "Delay JavaScript execution" would hold the inline script
		// back, and combining inline scripts would move it out of the form.
		add_filter( 'rocket_delay_js_exclusions', array( $this, 'exclude_from_delay' ) );
		add_filter( 'rocket_excluded_inline_js_content', array( $this, 'exclude_from_delay' ) );
	}

	/**
	 * Fields for WooCommerce's My Account sign-up form.
	 *
	 * @return void
	 */
	public function render_account_fields() {
		if ( $this->plugin->get_setting( 'protect_account' ) && $this->form_checks_on() ) {
			WooCommerce_Fake_Account_Blocker_Form::render( 'account' );
		}
	}

	/**
	 * Fields for WordPress's wp-login.php registration form.
	 *
	 * @return void
	 */
	public function render_wordpress_fields() {
		if ( $this->plugin->get_setting( 'protect_wp' ) && $this->form_checks_on() ) {
			WooCommerce_Fake_Account_Blocker_Form::render( 'wordpress' );
		}
	}

	/**
	 * Check a sign-up from WooCommerce's My Account form.
	 *
	 * @param mixed  $errors   WP_Error from earlier filters.
	 * @param string $username Username, if the form asks for one.
	 * @param string $password Password (unused).
	 * @param string $email    Email address.
	 * @return mixed
	 */
	public function check_account_form( $errors, $username = '', $password = '', $email = '' ) {
		self::$account_form = true;

		if ( ! $this->plugin->get_setting( 'protect_account' ) ) {
			return $errors;
		}

		return $this->run( $errors, 'account', $email, $username, true );
	}

	/**
	 * Check an account WooCommerce is about to create some other way: at
	 * checkout, on the order confirmation page, or from another plugin.
	 *
	 * @param mixed  $errors   WP_Error from earlier filters.
	 * @param string $username Username (generated from the email by now).
	 * @param string $email    Email address.
	 * @return mixed
	 */
	public function check_woocommerce( $errors, $username = '', $email = '' ) {
		// My Account sign-ups were checked already, before this point.
		if ( self::$account_form || ! $this->plugin->get_setting( 'protect_checkout' ) ) {
			return $errors;
		}

		return $this->run( $errors, 'checkout', $email, $username, false );
	}

	/**
	 * Check a sign-up through WordPress's registration. The bot checks only
	 * run on wp-login.php itself, where the form always prints them.
	 *
	 * @param mixed  $errors WP_Error from WordPress.
	 * @param string $login  Username.
	 * @param string $email  Email address.
	 * @return mixed
	 */
	public function check_wordpress( $errors, $login = '', $email = '' ) {
		if ( ! $this->plugin->get_setting( 'protect_wp' ) ) {
			return $errors;
		}

		$on_login_page = isset( $GLOBALS['pagenow'] ) && 'wp-login.php' === $GLOBALS['pagenow'];

		return $this->run( $errors, 'wordpress', $email, $login, $on_login_page );
	}

	/**
	 * Run the checks and refuse the sign-up if one fails. Never throws: a bug
	 * here lets the sign-up through rather than stopping a real customer.
	 *
	 * @param mixed  $errors   WP_Error so far.
	 * @param string $context  account, checkout or wordpress.
	 * @param string $email    Email address.
	 * @param string $username Username.
	 * @param bool   $form     Whether to run the bot checks on the sent form.
	 * @return mixed
	 */
	private function run( $errors, $context, $email, $username, $form ) {
		// Not ours to touch, or already refused by WordPress, WooCommerce or another plugin.
		if ( ! is_wp_error( $errors ) || $errors->get_error_code() ) {
			return $errors;
		}

		try {
			if ( $this->skip( $context ) ) {
				return $errors;
			}

			$signup = $this->signup( $context, $email, $username, $form );

			if ( self::limits_apply( $context ) && $this->locked_out( $signup ) ) {
				$errors->add( 'fake_account_blocker', $this->message( 'lockout', $context ) );
				return $errors;
			}

			$verdict = $this->verdict( $signup );

			/**
			 * Filter the decision on a sign-up.
			 *
			 * @param array|null $verdict Null lets it through. To refuse it, return
			 *                            array( 'reason' => 'my_reason', 'detail' => 'shown in the log' ).
			 * @param array      $signup  context, email, username, names, ip, ip_key, user_agent.
			 */
			$filtered = apply_filters( 'fake_account_blocker_verdict', $verdict, $signup );
			$verdict  = is_array( $filtered ) && ! empty( $filtered['reason'] ) && is_string( $filtered['reason'] )
				? WooCommerce_Fake_Account_Blocker_Rules::verdict( sanitize_key( $filtered['reason'] ), isset( $filtered['detail'] ) && is_scalar( $filtered['detail'] ) ? (string) $filtered['detail'] : '' )
				: null;

			if ( null === $verdict || $this->plugin->is_test_mode() ) {
				self::$pending = array(
					'signup'  => $signup,
					'verdict' => $verdict,
				);
				return $errors;
			}

			$this->refuse( $verdict, $signup );
			$errors->add( 'fake_account_blocker', $this->message( $verdict['reason'], $context ) );
		} catch ( \Throwable $e ) {
			WooCommerce_Fake_Account_Blocker_Plugin::log( 'check', $e );
		}

		return $errors;
	}

	/**
	 * Sign-ups the plugin leaves alone: accounts made by shop staff, by
	 * WP-CLI or by scheduled jobs (imports, syncs), and anything a developer
	 * excludes.
	 *
	 * @param string $context Where the sign-up came from.
	 * @return bool
	 */
	private function skip( $context ) {
		if ( is_user_logged_in() && ( current_user_can( 'create_users' ) || current_user_can( 'manage_woocommerce' ) ) ) {
			return true;
		}

		if ( ( defined( 'WP_CLI' ) && WP_CLI ) || wp_doing_cron() ) {
			return true;
		}

		/**
		 * Filter whether to skip the checks for a sign-up.
		 *
		 * @param bool   $skip    Default false.
		 * @param string $context account, checkout or wordpress.
		 */
		return (bool) apply_filters( 'fake_account_blocker_skip', false, $context );
	}

	/**
	 * Everything the checks look at.
	 *
	 * @param string $context  Where the sign-up came from.
	 * @param string $email    Email address.
	 * @param string $username Username.
	 * @param bool   $form     Whether to run the bot checks.
	 * @return array<string,mixed>
	 */
	private function signup( $context, $email, $username, $form ) {
		$ip = WooCommerce_Fake_Account_Blocker_Request::ip();

		return array(
			'context'    => $context,
			'email'      => substr( strtolower( trim( is_string( $email ) ? $email : '' ) ), 0, 254 ),
			'username'   => substr( trim( is_string( $username ) ? $username : '' ), 0, 100 ),
			'names'      => $this->names( $context ),
			'ip'         => $ip,
			'ip_key'     => WooCommerce_Fake_Account_Blocker_Request::ip_key( $ip ),
			'user_agent' => WooCommerce_Fake_Account_Blocker_Request::user_agent(),
			'form'       => (bool) $form,
		);
	}

	/**
	 * The checks, cheapest and surest first. The DNS lookup comes last.
	 *
	 * @param array<string,mixed> $signup Sign-up details.
	 * @return array{reason:string,detail:string}|null
	 */
	private function verdict( array $signup ) {
		$settings = $this->plugin->get_settings();

		if ( '' !== $signup['ip'] ) {
			foreach ( WooCommerce_Fake_Account_Blocker_Request::parse_ranges( (string) $settings['blocked_ips'] ) as $range ) {
				if ( WooCommerce_Fake_Account_Blocker_Request::in_range( $signup['ip'], $range ) ) {
					return WooCommerce_Fake_Account_Blocker_Rules::verdict( 'blocked_ip', $range );
				}
			}
		}

		if ( $signup['form'] && $this->form_checks_on() ) {
			$verdict = WooCommerce_Fake_Account_Blocker_Form::check( $signup['context'], $settings );
			if ( null !== $verdict ) {
				return $verdict;
			}
		}

		if ( ! empty( $settings['rate_limit'] ) && '' !== $signup['ip_key'] && self::limits_apply( $signup['context'] ) ) {
			$made = WooCommerce_Fake_Account_Blocker_Log::count_created( $signup['ip_key'], max( 1, (int) $settings['rate_hours'] ) );
			if ( $made >= max( 1, (int) $settings['rate_max'] ) ) {
				/* translators: %d: accounts already created from the IP in the window. */
				return WooCommerce_Fake_Account_Blocker_Rules::verdict( 'rate_limit', sprintf( _n( '%d account already made', '%d accounts already made', $made, 'fake-account-blocker' ), $made ) );
			}
		}

		return WooCommerce_Fake_Account_Blocker_Rules::check( $signup, $settings );
	}

	/**
	 * Whether the per-IP limits (new accounts and lockouts) apply: on the
	 * sign-up forms, never at checkout.
	 *
	 * @param string $context Where the sign-up came from.
	 * @return bool
	 */
	private static function limits_apply( $context ) {
		return 'checkout' !== $context;
	}

	/**
	 * Whether the sign-up's IP is locked out after repeated refusals. Logged
	 * now and then, not every time.
	 *
	 * @param array<string,mixed> $signup Sign-up details.
	 * @return bool
	 */
	private function locked_out( array $signup ) {
		if ( '' === $signup['ip_key'] || ! $this->plugin->get_setting( 'lockout' ) || $this->plugin->is_test_mode() ) {
			return false;
		}

		$key  = self::lock_key( $signup['ip_key'] );
		$lock = get_transient( $key );

		if ( ! is_array( $lock ) || empty( $lock['until'] ) || (int) $lock['until'] <= time() ) {
			return false;
		}

		if ( time() - (int) $lock['logged'] >= self::LOCK_LOG_EVERY ) {
			$left = (int) $lock['until'] - time();

			WooCommerce_Fake_Account_Blocker_Log::insert( WooCommerce_Fake_Account_Blocker_Log::BLOCKED, WooCommerce_Fake_Account_Blocker_Rules::verdict( 'lockout', human_time_diff( time(), (int) $lock['until'] ) ), $signup );

			$lock['logged'] = time();
			set_transient( $key, $lock, max( 1, $left ) );
		}

		return true;
	}

	/**
	 * Log a refusal, and lock the IP out once it's been refused too often.
	 *
	 * @param array{reason:string,detail:string} $verdict Why.
	 * @param array<string,mixed>                $signup  Sign-up details.
	 * @return void
	 */
	private function refuse( array $verdict, array $signup ) {
		WooCommerce_Fake_Account_Blocker_Log::insert( WooCommerce_Fake_Account_Blocker_Log::BLOCKED, $verdict, $signup );

		/**
		 * Fires after a sign-up is refused.
		 *
		 * @param string $reason Reason code.
		 * @param array  $signup Sign-up details.
		 * @param string $detail What matched.
		 */
		do_action( 'fake_account_blocker_blocked', $verdict['reason'], $signup, $verdict['detail'] );

		$after = max( 2, (int) $this->plugin->get_setting( 'lockout_after' ) );

		if ( '' !== $signup['ip_key'] && $this->plugin->get_setting( 'lockout' )
			&& WooCommerce_Fake_Account_Blocker_Log::count_blocked( $signup['ip_key'], HOUR_IN_SECONDS ) >= $after ) {
			// The first attempt it refuses gets logged, so the lockout shows up.
			set_transient(
				self::lock_key( $signup['ip_key'] ),
				array(
					'until'  => time() + self::LOCK_SECONDS,
					'logged' => 0,
				),
				self::LOCK_SECONDS
			);
		}
	}

	/**
	 * Record the account a checked sign-up created, so the per-IP limit can
	 * count it (and, in test mode, so you can see what would have been refused).
	 *
	 * @param int $user_id New user.
	 * @return void
	 */
	public function record( $user_id ) {
		if ( null === self::$pending ) {
			return;
		}

		$pending       = self::$pending;
		self::$pending = null;

		try {
			$result = null === $pending['verdict'] ? WooCommerce_Fake_Account_Blocker_Log::ALLOWED : WooCommerce_Fake_Account_Blocker_Log::LOGGED;
			WooCommerce_Fake_Account_Blocker_Log::insert( $result, $pending['verdict'], $pending['signup'], (int) $user_id );
		} catch ( \Throwable $e ) {
			WooCommerce_Fake_Account_Blocker_Plugin::log( 'record', $e );
		}
	}

	/**
	 * What the visitor is told. Reasons a real person can fix get their own
	 * message; the rest share one that gives nothing away to a bot.
	 *
	 * @param string $reason  Reason code.
	 * @param string $context Where the sign-up came from.
	 * @return string Plain text.
	 */
	private function message( $reason, $context ) {
		$custom  = trim( (string) $this->plugin->get_setting( 'message' ) );
		$generic = '' !== $custom ? $custom : self::default_message();

		$messages = array(
			'disposable' => __( 'Temporary and disposable email addresses can\'t be used to create an account. Please use your regular email address.', 'fake-account-blocker' ),
			'no_mx'      => __( 'That email address can\'t receive email. Please check it for typos.', 'fake-account-blocker' ),
			'no_js'      => __( 'Please turn on JavaScript in your browser, reload the page and try again.', 'fake-account-blocker' ),
			'expired'    => __( 'This form has expired. Please reload the page and try again.', 'fake-account-blocker' ),
			// After any error the form comes back already filled in, and the
			// timer restarts; someone who clicks again at once needs to know to pause.
			'too_fast'   => __( 'Please take a moment to check your details, then try again.', 'fake-account-blocker' ),
			'rate_limit' => __( 'Too many accounts have been created from your network recently. Please try again later.', 'fake-account-blocker' ),
		);

		$message = isset( $messages[ $reason ] ) ? $messages[ $reason ] : $generic;

		if ( 'checkout' === $context && 'yes' === get_option( 'woocommerce_enable_guest_checkout' ) ) {
			$message .= ' ' . __( 'You can also check out without creating an account.', 'fake-account-blocker' );
		}

		/**
		 * Filter the message shown when a sign-up is refused.
		 *
		 * @param string $message Plain text.
		 * @param string $reason  Reason code.
		 * @param string $context account, checkout or wordpress.
		 */
		return (string) apply_filters( 'fake_account_blocker_message', $message, $reason, $context );
	}

	/**
	 * The message for refusals without one of their own.
	 *
	 * @return string
	 */
	public static function default_message() {
		return __( 'Sorry, we couldn\'t create your account. If you think this is a mistake, please contact us.', 'fake-account-blocker' );
	}

	/**
	 * Name fields sent with the sign-up. Only pattern-matched, never stored.
	 *
	 * @param string $context Where the sign-up came from.
	 * @return array<string,string> Field => value.
	 */
	private function names( $context ) {
		$names = array();
		$keys  = array( 'first_name', 'last_name', 'billing_first_name', 'billing_last_name', 'shipping_first_name', 'shipping_last_name', 'account_first_name', 'account_last_name', 'account_display_name', 'display_name', 'nickname' );

		foreach ( $keys as $key ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only pattern match on a form WordPress and WooCommerce verify; never stored or shown.
			$value = isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? substr( wp_unslash( $_POST[ $key ] ), 0, 500 ) : '';
			if ( '' !== trim( $value ) ) {
				$names[ $key ] = $value;
			}
		}

		// The block checkout sends JSON, which WooCommerce has put on the customer by now.
		if ( 'checkout' === $context && function_exists( 'WC' ) && WC()->customer instanceof WC_Customer ) {
			$getters = array(
				'billing_first_name'  => 'get_billing_first_name',
				'billing_last_name'   => 'get_billing_last_name',
				'shipping_first_name' => 'get_shipping_first_name',
				'shipping_last_name'  => 'get_shipping_last_name',
			);
			foreach ( $getters as $key => $getter ) {
				$value = isset( $names[ $key ] ) ? '' : substr( (string) WC()->customer->$getter(), 0, 500 );
				if ( '' !== trim( $value ) ) {
					$names[ $key ] = $value;
				}
			}
		}

		return $names;
	}

	/**
	 * Whether any bot check is switched on.
	 *
	 * @return bool
	 */
	private function form_checks_on() {
		return $this->plugin->get_setting( 'honeypot' ) || $this->plugin->get_setting( 'timing' ) || $this->plugin->get_setting( 'js_check' );
	}

	/**
	 * Transient key for an IP's lockout.
	 *
	 * @param string $ip_key Address.
	 * @return string
	 */
	private static function lock_key( $ip_key ) {
		return 'wcfab_lock_' . md5( (string) $ip_key );
	}

	/**
	 * Keep WP Rocket from delaying the inline script until the visitor
	 * moves the mouse.
	 *
	 * @param mixed $excluded Patterns WP Rocket won't delay.
	 * @return mixed
	 */
	public function exclude_from_delay( $excluded ) {
		if ( is_array( $excluded ) ) {
			$excluded[] = 'wcfab';
		}

		return $excluded;
	}
}
