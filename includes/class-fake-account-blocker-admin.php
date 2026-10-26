<?php
/**
 * Admin side: the Content Powered > WooCommerce Fake Account Blocker page and
 * the Plugins-screen link.
 *
 * @package WooCommerce_Fake_Account_Blocker
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings page controller.
 */
final class WooCommerce_Fake_Account_Blocker_Admin {

	/**
	 * Settings page slug, also used as the settings group.
	 */
	const PAGE = 'fake-account-blocker';

	/**
	 * Lucide icon shown beside the menu item and page title.
	 */
	const ICON = 'shield-user';

	/**
	 * Settings tabs: id => Lucide icon.
	 */
	const TABS = array(
		'overview'   => 'layout-dashboard',
		'protection' => 'shield-check',
		'email'      => 'mail-x',
		'accounts'   => 'user-x',
		'advanced'   => 'sliders-horizontal',
	);

	/**
	 * Nonce action for the page's AJAX requests.
	 */
	const NONCE = 'fake_account_blocker_admin';

	/**
	 * Rows per page in the sign-up log and the accounts list.
	 */
	const PER_PAGE = 25;

	/**
	 * Plugin instance.
	 *
	 * @var WooCommerce_Fake_Account_Blocker_Plugin
	 */
	private $plugin;

	/**
	 * Hook suffix of the settings page.
	 *
	 * @var string
	 */
	private $hook_suffix = '';

	/**
	 * Constructor.
	 *
	 * @param WooCommerce_Fake_Account_Blocker_Plugin $plugin Plugin instance.
	 */
	public function __construct( WooCommerce_Fake_Account_Blocker_Plugin $plugin ) {
		$this->plugin = $plugin;

		WooCommerce_Fake_Account_Blocker_Menu::init();
		WooCommerce_Fake_Account_Blocker_Feedback::init();

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_head', array( $this, 'print_menu_icon_style' ) );

		add_filter(
			'plugin_action_links_' . plugin_basename( FAKE_ACCOUNT_BLOCKER_FILE ),
			array( $this, 'add_settings_link' )
		);
	}

	/**
	 * Add a "Settings" link to the plugin's row on the Plugins screen.
	 *
	 * @param array $links Existing action links (Deactivate, etc.).
	 * @return array
	 */
	public function add_settings_link( $links ) {
		if ( ! is_array( $links ) ) {
			return $links;
		}

		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ),
			esc_html__( 'Settings', 'fake-account-blocker' )
		);

		array_unshift( $links, $settings_link );

		return $links;
	}

	/**
	 * Add the page under the shared Content Powered menu.
	 *
	 * @return void
	 */
	public function register_menu() {
		$icon       = WooCommerce_Fake_Account_Blocker_Icons::get( self::ICON, 17, 'fake-account-blocker-menu-icon' );
		$menu_title = $icon . '<span class="fake-account-blocker-menu-label">' . esc_html__( 'WooCommerce Fake Account Blocker', 'fake-account-blocker' ) . '</span>';

		$this->hook_suffix = add_submenu_page(
			WooCommerce_Fake_Account_Blocker_Menu::SLUG,
			__( 'WooCommerce Fake Account Blocker', 'fake-account-blocker' ),
			$menu_title,
			'manage_options',
			self::PAGE,
			array( $this, 'render_page' )
		);

		if ( $this->hook_suffix ) {
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ), 20 );
			add_filter( 'admin_body_class', array( $this, 'body_class' ) );
		}
	}

	/**
	 * Mark the settings page's <body>, so its header can run edge to edge.
	 *
	 * @param string $classes Space-separated body classes.
	 * @return string
	 */
	public function body_class( $classes ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		return $screen && $screen->id === $this->hook_suffix ? $classes . ' wcfab-settings-page' : $classes;
	}

	/**
	 * Register the setting. The tabs are switched in the browser, so one
	 * form saves every tab at once.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			self::PAGE,
			WooCommerce_Fake_Account_Blocker_Plugin::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => WooCommerce_Fake_Account_Blocker_Plugin::defaults(),
			)
		);
	}

	/**
	 * Clean the submitted settings before they are saved.
	 *
	 * @param mixed $input Raw form values.
	 * @return array<string,mixed>
	 */
	public function sanitize( $input ) {
		$in       = is_array( $input ) ? $input : array();
		$defaults = WooCommerce_Fake_Account_Blocker_Plugin::defaults();
		$flag     = function ( $key ) use ( $in ) {
			return empty( $in[ $key ] ) ? 0 : 1;
		};
		$number   = function ( $key, $min, $max ) use ( $in, $defaults ) {
			$value = isset( $in[ $key ] ) && is_numeric( $in[ $key ] ) ? (int) $in[ $key ] : (int) $defaults[ $key ];
			return max( $min, min( $max, $value ) );
		};
		// options.php has already unslashed the values.
		$text = function ( $key ) use ( $in ) {
			return isset( $in[ $key ] ) && is_string( $in[ $key ] ) ? $in[ $key ] : '';
		};

		$out = array(
			'enabled'          => $flag( 'enabled' ),
			'mode'             => 'log' === $text( 'mode' ) ? 'log' : 'block',
			'protect_account'  => $flag( 'protect_account' ),
			'protect_checkout' => $flag( 'protect_checkout' ),
			'protect_wp'       => $flag( 'protect_wp' ),
			'honeypot'         => $flag( 'honeypot' ),
			'timing'           => $flag( 'timing' ),
			'min_seconds'      => $number( 'min_seconds', 0, 60 ),
			'js_check'         => $flag( 'js_check' ),
			'rate_limit'       => $flag( 'rate_limit' ),
			'rate_max'         => $number( 'rate_max', 1, 100 ),
			'rate_hours'       => $number( 'rate_hours', 1, 168 ),
			'lockout'          => $flag( 'lockout' ),
			'lockout_after'    => $number( 'lockout_after', 2, 100 ),
			'disposable'       => $flag( 'disposable' ),
			'mx_check'         => $flag( 'mx_check' ),
			'spam_names'       => $flag( 'spam_names' ),
			'blocked_domains'  => '',
			'allowed_domains'  => '',
			'blocked_words'    => implode( "\n", WooCommerce_Fake_Account_Blocker_Rules::word_list( sanitize_textarea_field( $text( 'blocked_words' ) ) ) ),
			'blocked_ips'      => '',
			'message'          => substr( sanitize_text_field( $text( 'message' ) ), 0, 300 ),
			'trusted_proxies'  => '',
			'proxy_header'     => array_key_exists( $text( 'proxy_header' ), WooCommerce_Fake_Account_Blocker_Request::PROXY_HEADERS ) ? $text( 'proxy_header' ) : $defaults['proxy_header'],
			'trust_private'    => $flag( 'trust_private' ),
			'retention_days'   => $number( 'retention_days', 0, 3650 ),
			'keep_data'        => $flag( 'keep_data' ),
		);

		if ( WooCommerce_Fake_Account_Blocker_Guard::default_message() === $out['message'] ) {
			$out['message'] = '';
		}

		foreach ( array( 'blocked_domains', 'allowed_domains' ) as $key ) {
			$invalid     = array();
			$out[ $key ] = WooCommerce_Fake_Account_Blocker_Rules::sanitize_domain_list( $text( $key ), $invalid );
			if ( $invalid ) {
				add_settings_error(
					self::PAGE,
					'wcfab-' . $key,
					sprintf(
						/* translators: %s: rejected entries. */
						__( 'These aren\'t valid domains and were left out: %s', 'fake-account-blocker' ),
						implode( ', ', array_map( 'sanitize_text_field', array_slice( $invalid, 0, 10 ) ) )
					),
					'warning'
				);
			}
		}

		// IP lists: keep the valid IPs and ranges, say which were dropped.
		foreach ( array(
			/* translators: %s: rejected entries. */
			'blocked_ips'     => __( 'These blocked IPs aren\'t valid IPs or ranges and were left out: %s', 'fake-account-blocker' ),
			/* translators: %s: rejected entries. */
			'trusted_proxies' => __( 'These trusted proxies aren\'t valid IPs or ranges and were left out: %s', 'fake-account-blocker' ),
		) as $key => $warning ) {
			$raw     = $text( $key );
			$entries = preg_split( '/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY );
			$valid   = WooCommerce_Fake_Account_Blocker_Request::parse_ranges( $raw );
			$invalid = array_diff( is_array( $entries ) ? $entries : array(), $valid );

			$out[ $key ] = implode( "\n", array_slice( $valid, 0, 500 ) );
			if ( $invalid ) {
				add_settings_error( self::PAGE, 'wcfab-' . $key, sprintf( $warning, implode( ', ', array_map( 'sanitize_text_field', array_slice( $invalid, 0, 10 ) ) ) ), 'warning' );
			}
		}

		return $out;
	}

	/**
	 * Tiny inline style so the menu icon sits neatly beside its label, even
	 * when a long plugin name wraps onto a second line.
	 *
	 * @return void
	 */
	public function print_menu_icon_style() {
		echo '<style id="fake-account-blocker-menu-icon-style">'
			. '#adminmenu a:has(>.fake-account-blocker-menu-icon){display:flex;align-items:flex-start;gap:7px}'
			. '#adminmenu .fake-account-blocker-menu-icon{flex:none;margin-top:1px;opacity:.7}'
			. '#adminmenu li.current .fake-account-blocker-menu-icon,#adminmenu a:hover .fake-account-blocker-menu-icon,#adminmenu a:focus .fake-account-blocker-menu-icon{opacity:1}'
			. '</style>';
	}

	/**
	 * Load the settings-page styles and scripts.
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( $hook !== $this->hook_suffix ) {
			return;
		}

		wp_enqueue_style( 'fake-account-blocker-admin', FAKE_ACCOUNT_BLOCKER_URL . 'assets/admin.css', array(), FAKE_ACCOUNT_BLOCKER_VERSION );
		wp_enqueue_script( 'fake-account-blocker-admin', FAKE_ACCOUNT_BLOCKER_URL . 'assets/admin.js', array(), FAKE_ACCOUNT_BLOCKER_VERSION, true );

		wp_localize_script(
			'fake-account-blocker-admin',
			'fake_account_blocker_admin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE ),
				'i18n'    => array(
					'starting'      => __( 'Starting...', 'fake-account-blocker' ),
					'failed'        => __( 'Something went wrong. Reload the page and try again.', 'fake-account-blocker' ),
					'confirmOne'    => __( 'Permanently delete this account? This can\'t be undone.', 'fake-account-blocker' ),
					/* translators: %d: number of accounts. */
					'confirmPicked' => __( 'Permanently delete %d accounts? This can\'t be undone.', 'fake-account-blocker' ),
					/* translators: %d: number of accounts. */
					'confirmAll'    => __( 'Permanently delete all %d accounts the scan found? This can\'t be undone.', 'fake-account-blocker' ),
					/* translators: %d: number of accounts deleted so far. */
					'deleting'      => __( 'Deleting... %d done', 'fake-account-blocker' ),
					'deletedOne'    => __( '1 account deleted.', 'fake-account-blocker' ),
					/* translators: %d: number of accounts deleted. */
					'deleted'       => __( '%d accounts deleted.', 'fake-account-blocker' ),
				),
			)
		);
	}

	/**
	 * Inline icon.
	 *
	 * @param string $name Lucide icon.
	 * @param int    $size Size in px.
	 * @return string
	 */
	private static function icon( $name, $size = 18 ) {
		return WooCommerce_Fake_Account_Blocker_Icons::get( $name, $size );
	}

	/**
	 * Why a sign-up was refused, in words.
	 *
	 * @return array<string,string>
	 */
	public static function reason_labels() {
		return array(
			'honeypot'       => __( 'Filled in the hidden honeypot field', 'fake-account-blocker' ),
			'no_token'       => __( 'Sent without loading the form', 'fake-account-blocker' ),
			'too_fast'       => __( 'Sent too fast for a person', 'fake-account-blocker' ),
			'no_js'          => __( 'Didn\'t run JavaScript', 'fake-account-blocker' ),
			'expired'        => __( 'Form had expired', 'fake-account-blocker' ),
			'rate_limit'     => __( 'Too many new accounts from one IP', 'fake-account-blocker' ),
			'lockout'        => __( 'IP locked out after repeated attempts', 'fake-account-blocker' ),
			'blocked_ip'     => __( 'IP on your block list', 'fake-account-blocker' ),
			'disposable'     => __( 'Disposable email address', 'fake-account-blocker' ),
			'no_mx'          => __( 'Email domain can\'t receive email', 'fake-account-blocker' ),
			'blocked_domain' => __( 'Email domain on your block list', 'fake-account-blocker' ),
			'blocked_word'   => __( 'Contains a blocked word', 'fake-account-blocker' ),
			'spam_name'      => __( 'Link or web address in a name', 'fake-account-blocker' ),
		);
	}

	/**
	 * A Lucide icon for each reason.
	 *
	 * @param string $reason Reason code.
	 * @return string
	 */
	private static function reason_icon( $reason ) {
		$icons = array(
			'honeypot'       => 'eye-off',
			'no_token'       => 'file-code',
			'too_fast'       => 'timer-off',
			'no_js'          => 'braces',
			'expired'        => 'clock',
			'rate_limit'     => 'gauge',
			'lockout'        => 'lock',
			'blocked_ip'     => 'network',
			'disposable'     => 'mail-x',
			'no_mx'          => 'mail-question-mark',
			'blocked_domain' => 'at-sign',
			'blocked_word'   => 'whole-word',
			'spam_name'      => 'link',
		);

		return isset( $icons[ $reason ] ) ? $icons[ $reason ] : 'shield-x';
	}

	/**
	 * A reason's label, or the code itself for one added by a developer.
	 *
	 * @param string $reason Reason code.
	 * @return string
	 */
	private static function reason_label( $reason ) {
		$labels = self::reason_labels();

		return isset( $labels[ $reason ] ) ? $labels[ $reason ] : (string) $reason;
	}

	/**
	 * Where a sign-up came from, in words.
	 *
	 * @param string $context Context code.
	 * @return string
	 */
	private static function context_label( $context ) {
		$labels = array(
			'account'   => __( 'My Account', 'fake-account-blocker' ),
			'checkout'  => __( 'Checkout', 'fake-account-blocker' ),
			'wordpress' => __( 'WordPress', 'fake-account-blocker' ),
		);

		return isset( $labels[ $context ] ) ? $labels[ $context ] : (string) $context;
	}

	/**
	 * The page's boxes, in order: the tab each one is on, its icon, title,
	 * intro and what draws it.
	 *
	 * @return array<string,array<string,string>>
	 */
	private function sections() {
		return array(
			'stats'    => array(
				'panel'  => 'overview',
				'icon'   => 'chart-column',
				'title'  => __( 'At a glance', 'fake-account-blocker' ),
				'intro'  => '',
				'render' => 'render_stats',
			),
			'reasons'  => array(
				'panel'  => 'overview',
				'icon'   => 'chart-bar',
				'title'  => __( 'Why sign-ups were refused, last 30 days', 'fake-account-blocker' ),
				'intro'  => '',
				'render' => 'render_reasons',
			),
			'log'      => array(
				'panel'  => 'overview',
				'icon'   => 'scroll-text',
				'title'  => __( 'Recent sign-ups', 'fake-account-blocker' ),
				'intro'  => __( 'If a real customer tells you they couldn\'t sign up, find them here to see which check stopped them.', 'fake-account-blocker' ),
				'render' => 'render_log',
			),
			'mode'     => array(
				'panel'  => 'protection',
				'icon'   => 'toggle-right',
				'title'  => __( 'When a sign-up looks fake', 'fake-account-blocker' ),
				'intro'  => '',
				'render' => 'render_mode',
			),
			'forms'    => array(
				'panel'  => 'protection',
				'icon'   => 'app-window',
				'title'  => __( 'Where to check', 'fake-account-blocker' ),
				'intro'  => '',
				'render' => 'render_forms',
			),
			'bots'     => array(
				'panel'  => 'protection',
				'icon'   => 'bot-off',
				'title'  => __( 'Bot checks', 'fake-account-blocker' ),
				'intro'  => __( 'These run on the sign-up forms. Customers never see them, there\'s no puzzle to solve, and they work with page caching.', 'fake-account-blocker' ),
				'render' => 'render_bots',
			),
			'limits'   => array(
				'panel'  => 'protection',
				'icon'   => 'gauge',
				'title'  => __( 'Limits per IP', 'fake-account-blocker' ),
				'intro'  => __( 'Bots that get past everything else still give themselves away by volume. These limits apply to the sign-up forms, not checkout, and IPv6 addresses count per /64, the block one connection usually has.', 'fake-account-blocker' ),
				'render' => 'render_limits',
			),
			'email'    => array(
				'panel'  => 'email',
				'icon'   => 'mail-x',
				'title'  => __( 'Email addresses', 'fake-account-blocker' ),
				'intro'  => __( 'Fake accounts are usually made with throwaway addresses or with domains that don\'t exist. Both are caught without any outside service.', 'fake-account-blocker' ),
				'render' => 'render_email',
			),
			'names'    => array(
				'panel'  => 'email',
				'icon'   => 'user-pen',
				'title'  => __( 'Names', 'fake-account-blocker' ),
				'intro'  => '',
				'render' => 'render_names',
			),
			'lists'    => array(
				'panel'  => 'email',
				'icon'   => 'list-x',
				'title'  => __( 'Your own lists', 'fake-account-blocker' ),
				'intro'  => '',
				'render' => 'render_lists',
			),
			'scan'     => array(
				'panel'  => 'accounts',
				'icon'   => 'scan-search',
				'title'  => __( 'Find fake accounts you already have', 'fake-account-blocker' ),
				'intro'  => __( 'Checks every customer account against the same rules new sign-ups go through: disposable addresses, links in names, and your own lists. Customers who have placed an order are never listed.', 'fake-account-blocker' ),
				'render' => 'render_scan',
			),
			'results'  => array(
				'panel'  => 'accounts',
				'icon'   => 'users',
				'title'  => __( 'Accounts that look fake', 'fake-account-blocker' ),
				'intro'  => '',
				'render' => 'render_results',
			),
			'message'  => array(
				'panel'  => 'advanced',
				'icon'   => 'message-square-warning',
				'title'  => __( 'Message shown to refused sign-ups', 'fake-account-blocker' ),
				'intro'  => '',
				'render' => 'render_message',
			),
			'visitor'  => array(
				'panel'  => 'advanced',
				'icon'   => 'router',
				'title'  => __( 'Your visitors\' real IP', 'fake-account-blocker' ),
				'intro'  => __( 'Headers like X-Forwarded-For can be faked by anyone, so they\'re only believed from Cloudflare (checked against Cloudflare\'s published addresses) or from proxies you list here. A bot can\'t dodge the limits, or get someone else locked out, by sending a fake one.', 'fake-account-blocker' ),
				'render' => 'render_visitor_ip',
			),
			'data'     => array(
				'panel'  => 'advanced',
				'icon'   => 'database',
				'title'  => __( 'Data', 'fake-account-blocker' ),
				'intro'  => '',
				'render' => 'render_data',
			),
			'devnotes' => array(
				'panel'  => 'advanced',
				'icon'   => 'code',
				'title'  => __( 'For developers', 'fake-account-blocker' ),
				'intro'  => '',
				'render' => 'render_developer',
			),
		);
	}

	/**
	 * Translated tab label.
	 *
	 * @param string $id Tab ID.
	 * @return string
	 */
	private function tab_label( $id ) {
		$labels = array(
			'overview'   => __( 'Overview', 'fake-account-blocker' ),
			'protection' => __( 'Protection', 'fake-account-blocker' ),
			'email'      => __( 'Email & Names', 'fake-account-blocker' ),
			'accounts'   => __( 'Spam Accounts', 'fake-account-blocker' ),
			'advanced'   => __( 'Advanced', 'fake-account-blocker' ),
		);

		return isset( $labels[ $id ] ) ? $labels[ $id ] : $id;
	}

	/**
	 * Render the settings page: a header with the tabs, then WordPress's
	 * two-column box layout (settings on the left, Save and status on the right).
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wcfab-header">
			<div class="wcfab-header-title">
				<span class="wcfab-header-icon"><?php echo self::icon( self::ICON, 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
				<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
				<span class="wcfab-version"><?php echo esc_html( 'v' . FAKE_ACCOUNT_BLOCKER_VERSION ); ?></span>
			</div>
			<nav class="wcfab-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Settings sections', 'fake-account-blocker' ); ?>">
				<?php foreach ( self::TABS as $id => $icon ) : ?>
					<a href="#<?php echo esc_attr( $id ); ?>" class="wcfab-tab" role="tab" data-wcfab-tab="<?php echo esc_attr( $id ); ?>">
						<?php echo self::icon( $icon, 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
						<?php echo esc_html( $this->tab_label( $id ) ); ?>
					</a>
				<?php endforeach; ?>
			</nav>
		</div>

		<div class="wrap wcfab-wrap">
			<hr class="wp-header-end">

			<?php
			// Pages outside the Settings menu have to show "Settings saved." themselves.
			settings_errors();
			?>

			<form action="options.php" method="post" class="wcfab-form">
				<?php settings_fields( self::PAGE ); ?>

				<div id="poststuff">
					<div id="post-body" class="metabox-holder columns-2">
						<div id="post-body-content">
							<?php
							foreach ( $this->sections() as $id => $section ) {
								$this->render_section( $id, $section );
							}
							?>
						</div>

						<div id="postbox-container-1" class="postbox-container">
							<div class="wcfab-sidebar">
								<?php $this->render_sidebar(); ?>
							</div>
						</div>
					</div>
					<br class="clear">
				</div>
			</form>
		</div>
		<?php
	}

	/**
	 * One settings box.
	 *
	 * @param string               $id      Section ID.
	 * @param array<string,string> $section Section details from {@see sections()}.
	 * @return void
	 */
	private function render_section( $id, array $section ) {
		?>
		<div class="postbox wcfab-box" id="wcfab-<?php echo esc_attr( $id ); ?>" data-wcfab-panel="<?php echo esc_attr( $section['panel'] ); ?>">
			<div class="postbox-header">
				<h2 class="hndle">
					<?php echo self::icon( $section['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<?php echo esc_html( $section['title'] ); ?>
				</h2>
			</div>
			<div class="inside">
				<?php if ( '' !== $section['intro'] ) : ?>
					<p class="wcfab-intro"><?php echo esc_html( $section['intro'] ); ?></p>
				<?php endif; ?>
				<?php call_user_func( array( $this, $section['render'] ) ); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Name attribute for a setting.
	 *
	 * @param string $key Setting key.
	 * @return string
	 */
	private static function name( $key ) {
		return WooCommerce_Fake_Account_Blocker_Plugin::OPTION . '[' . $key . ']';
	}

	/**
	 * Checkbox with a label.
	 *
	 * @param string $key   Setting key.
	 * @param string $label Label.
	 * @param string $desc  Description under it.
	 * @return void
	 */
	private function checkbox( $key, $label, $desc = '' ) {
		printf(
			'<label class="wcfab-check"><input type="checkbox" name="%1$s" value="1" %2$s> <span>%3$s</span></label>',
			esc_attr( self::name( $key ) ),
			checked( 1, (int) $this->plugin->get_setting( $key ), false ),
			esc_html( $label )
		);

		if ( '' !== $desc ) {
			echo '<p class="description">' . esc_html( $desc ) . '</p>';
		}
	}

	/**
	 * Number input.
	 *
	 * @param string $key    Setting key.
	 * @param int    $min    Minimum.
	 * @param int    $max    Maximum.
	 * @param string $suffix Text after the box.
	 * @return void
	 */
	private function number( $key, $min, $max, $suffix = '' ) {
		printf(
			'<input type="number" class="small-text" id="wcfab-%1$s" name="%2$s" value="%3$d" min="%4$d" max="%5$d" step="1"> %6$s',
			esc_attr( $key ),
			esc_attr( self::name( $key ) ),
			(int) $this->plugin->get_setting( $key ),
			(int) $min,
			(int) $max,
			esc_html( $suffix )
		);
	}

	/**
	 * Textarea for a list setting.
	 *
	 * @param string $key         Setting key.
	 * @param string $placeholder Example entries.
	 * @return void
	 */
	private function textarea( $key, $placeholder ) {
		printf(
			'<textarea class="large-text code" rows="4" id="wcfab-%1$s" name="%2$s" placeholder="%3$s">%4$s</textarea>',
			esc_attr( $key ),
			esc_attr( self::name( $key ) ),
			esc_attr( $placeholder ),
			esc_textarea( (string) $this->plugin->get_setting( $key ) )
		);
	}

	/**
	 * Form table row.
	 *
	 * @param string   $label     Row label.
	 * @param callable $content   Prints the row's controls.
	 * @param string   $label_for ID the label points at.
	 * @param string   $depends   Setting that has to be on for this row to apply.
	 * @return void
	 */
	private function row( $label, $content, $label_for = '', $depends = '' ) {
		echo '<tr' . ( '' !== $depends ? ' data-wcfab-depends="' . esc_attr( $depends ) . '"' : '' ) . '><th scope="row">';
		echo '' !== $label_for ? '<label for="' . esc_attr( $label_for ) . '">' . esc_html( $label ) . '</label>' : esc_html( $label );
		echo '</th><td>';
		call_user_func( $content );
		echo '</td></tr>';
	}

	/**
	 * A switchable option as a card: checkbox, icon, title and explanation,
	 * with an optional status line and extra controls under it.
	 *
	 * @param string        $key    Setting key.
	 * @param string        $icon   Lucide icon.
	 * @param string        $title  Title.
	 * @param string        $text   Explanation.
	 * @param string        $status Status line (plain text), or ''. Starting
	 *                              with "+" marks it as good news (a green dot).
	 * @param callable|null $extra  Prints controls that belong to this option.
	 * @return void
	 */
	private function option( $key, $icon, $title, $text, $status = '', $extra = null ) {
		$status_ok = 0 === strpos( $status, '+' );
		$status    = ltrim( $status, '+' );
		?>
		<div class="wcfab-option">
			<label class="wcfab-option-main">
				<input type="checkbox" name="<?php echo esc_attr( self::name( $key ) ); ?>" value="1" <?php checked( 1, (int) $this->plugin->get_setting( $key ) ); ?>>
				<span class="wcfab-option-icon"><?php echo self::icon( $icon ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
				<span class="wcfab-option-text">
					<strong><?php echo esc_html( $title ); ?></strong>
					<span><?php echo esc_html( $text ); ?></span>
					<?php if ( '' !== $status ) : ?>
						<em class="wcfab-option-status<?php echo $status_ok ? ' is-ok' : ''; ?>"><?php echo esc_html( $status ); ?></em>
					<?php endif; ?>
				</span>
			</label>
			<?php if ( is_callable( $extra ) ) : ?>
				<div class="wcfab-option-extra" data-wcfab-depends="<?php echo esc_attr( $key ); ?>">
					<?php call_user_func( $extra ); ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * A callout line.
	 *
	 * @param string $icon    Lucide icon.
	 * @param string $text    Plain text.
	 * @param string $variant "" or "is-warning".
	 * @return void
	 */
	private static function callout( $icon, $text, $variant = '' ) {
		echo '<p class="wcfab-callout ' . esc_attr( $variant ) . '">' . self::icon( $icon, 16 ) . '<span>' . esc_html( $text ) . '</span></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG.
	}

	/**
	 * Overview: the headline counts.
	 *
	 * @return void
	 */
	public function render_stats() {
		$totals = WooCommerce_Fake_Account_Blocker_Log::totals();
		$test   = $this->plugin->is_test_mode();
		$tiles  = array(
			array( 'shield-x', $totals['blocked_day'], __( 'Refused, last 24 hours', 'fake-account-blocker' ), 'high' ),
			$test
				? array( 'flask-conical', $totals['logged_month'], __( 'Would have been refused, 30 days', 'fake-account-blocker' ), 'medium' )
				: array( 'shield-ban', $totals['blocked_month'], __( 'Refused, last 30 days', 'fake-account-blocker' ), '' ),
			array( 'user-plus', $totals['created_month'], __( 'New accounts, last 30 days', 'fake-account-blocker' ), 'good' ),
			array( 'shield-check', $totals['blocked_total'], __( 'Fake sign-ups stopped, all time', 'fake-account-blocker' ), '' ),
		);
		?>
		<div class="wcfab-stats">
			<?php foreach ( $tiles as $tile ) : ?>
				<div class="wcfab-stat<?php echo '' !== $tile[3] ? ' is-' . esc_attr( $tile[3] ) : ''; ?>">
					<span class="wcfab-stat-icon"><?php echo self::icon( $tile[0], 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
					<span class="wcfab-stat-num"><?php echo esc_html( number_format_i18n( (int) $tile[1] ) ); ?></span>
					<span class="wcfab-stat-label"><?php echo esc_html( $tile[2] ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
		if ( ! $this->plugin->is_enabled() ) {
			self::callout( 'power', __( 'Protection is switched off, so new sign-ups aren\'t being checked. Turn it on in the Save box.', 'fake-account-blocker' ), 'is-warning' );
		} elseif ( $test ) {
			self::callout( 'flask-conical', __( 'Test mode is on: nothing is refused. Sign-ups that would have been are listed below as "Logged", so you can check for false alarms before you switch to refusing them on the Protection tab.', 'fake-account-blocker' ) );
		} elseif ( ! class_exists( 'WooCommerce' ) ) {
			self::callout( 'info', __( 'WooCommerce isn\'t active, so only WordPress\'s own registration form is being checked.', 'fake-account-blocker' ) );
		}

		$probe = WooCommerce_Fake_Account_Blocker_Request::probe();
		if ( '' !== $probe['warning'] && ( $this->plugin->get_setting( 'rate_limit' ) || $this->plugin->get_setting( 'lockout' ) ) ) {
			self::callout( 'triangle-alert', __( 'Your site may be behind a proxy, firewall or CDN that isn\'t set up here. If it is, visitors all seem to share its address, and the per-IP limits on the sign-up forms could refuse real people. Check "Your visitors\' real IP" on the Advanced tab.', 'fake-account-blocker' ), 'is-warning' );
		}
	}

	/**
	 * Overview: refusals by reason.
	 *
	 * @return void
	 */
	public function render_reasons() {
		$reasons = WooCommerce_Fake_Account_Blocker_Log::reasons( 30 );

		if ( ! $reasons ) {
			echo '<p class="wcfab-empty">' . self::icon( 'circle-check', 18 ) . esc_html__( 'Nothing refused in the last 30 days.', 'fake-account-blocker' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG.
			return;
		}

		$most  = max( $reasons );
		$total = array_sum( $reasons );
		?>
		<div class="wcfab-bars">
			<?php foreach ( $reasons as $reason => $count ) : ?>
				<div class="wcfab-bar">
					<span class="wcfab-bar-label"><?php echo self::icon( self::reason_icon( $reason ), 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php echo esc_html( self::reason_label( $reason ) ); ?></span>
					<span class="wcfab-bar-track"><span class="wcfab-bar-fill" style="width:<?php echo esc_attr( (string) max( 2, round( $count / $most * 100 ) ) ); ?>%"></span></span>
					<span class="wcfab-bar-num"><?php echo esc_html( number_format_i18n( $count ) ); ?> <span class="wcfab-muted"><?php echo esc_html( round( $count / $total * 100 ) . '%' ); ?></span></span>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Overview: the sign-up log.
	 *
	 * @return void
	 */
	public function render_log() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only paging and filtering.
		$filter = isset( $_GET['wcfab_log'] ) && 'all' === $_GET['wcfab_log'] ? 'all' : 'flagged';
		$page   = isset( $_GET['wcfab_paged'] ) ? max( 1, absint( $_GET['wcfab_paged'] ) ) : 1;
		// phpcs:enable

		$total = WooCommerce_Fake_Account_Blocker_Log::count_rows( $filter );
		$pages = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$page  = min( $page, $pages );
		$rows  = WooCommerce_Fake_Account_Blocker_Log::recent( $filter, self::PER_PAGE, ( $page - 1 ) * self::PER_PAGE );
		$base  = admin_url( 'admin.php?page=' . self::PAGE );
		?>
		<div class="wcfab-filters">
			<a href="<?php echo esc_url( $base . '#overview' ); ?>" class="<?php echo 'flagged' === $filter ? 'is-active' : ''; ?>"><?php esc_html_e( 'Refused', 'fake-account-blocker' ); ?></a>
			<a href="<?php echo esc_url( add_query_arg( 'wcfab_log', 'all', $base ) . '#overview' ); ?>" class="<?php echo 'all' === $filter ? 'is-active' : ''; ?>"><?php esc_html_e( 'All sign-ups', 'fake-account-blocker' ); ?></a>
		</div>

		<?php if ( ! $rows ) : ?>
			<p class="wcfab-empty"><?php echo self::icon( 'circle-check', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php echo esc_html( 'all' === $filter ? __( 'No sign-ups checked yet.', 'fake-account-blocker' ) : __( 'No refused sign-ups yet.', 'fake-account-blocker' ) ); ?></p>
			<?php
			return;
		endif;

		$user_ids = array_filter( array_map( 'absint', wp_list_pluck( $rows, 'user_id' ) ) );
		if ( $user_ids ) {
			cache_users( $user_ids );
		}
		?>
		<table class="widefat striped wcfab-table wcfab-log">
			<thead>
				<tr>
					<th><?php esc_html_e( 'When', 'fake-account-blocker' ); ?></th>
					<th><?php esc_html_e( 'Email', 'fake-account-blocker' ); ?></th>
					<th><?php esc_html_e( 'IP', 'fake-account-blocker' ); ?></th>
					<th><?php esc_html_e( 'Form', 'fake-account-blocker' ); ?></th>
					<th><?php esc_html_e( 'Result', 'fake-account-blocker' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<?php
					$time    = strtotime( $row['created_at'] . ' UTC' );
					$user    = (int) $row['user_id'] ? get_userdata( (int) $row['user_id'] ) : false;
					$results = array(
						'blocked' => array( 'is-bad', __( 'Refused', 'fake-account-blocker' ) ),
						'logged'  => array( 'is-warn', __( 'Logged', 'fake-account-blocker' ) ),
						'allowed' => array( 'is-good', __( 'Account created', 'fake-account-blocker' ) ),
					);
					$result  = isset( $results[ $row['result'] ] ) ? $results[ $row['result'] ] : array( 'is-none', $row['result'] );
					?>
					<tr>
						<td>
							<span title="<?php echo esc_attr( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time ) ); ?>">
								<?php
								/* translators: %s: time since, e.g. "5 mins". */
								echo esc_html( sprintf( __( '%s ago', 'fake-account-blocker' ), human_time_diff( $time ) ) );
								?>
							</span>
						</td>
						<td>
							<?php if ( $user ) : ?>
								<a href="<?php echo esc_url( get_edit_user_link( $user->ID ) ); ?>"><?php echo esc_html( '' !== $row['email'] ? $row['email'] : $user->user_email ); ?></a>
							<?php else : ?>
								<?php echo esc_html( '' !== $row['email'] ? $row['email'] : '-' ); ?>
							<?php endif; ?>
							<?php if ( '' !== $row['username'] ) : ?>
								<span class="wcfab-muted"><?php echo esc_html( $row['username'] ); ?></span>
							<?php endif; ?>
						</td>
						<td><code><?php echo esc_html( '' !== $row['ip'] ? $row['ip'] : '?' ); ?></code></td>
						<td><?php echo esc_html( self::context_label( $row['context'] ) ); ?></td>
						<td>
							<span class="wcfab-tag <?php echo esc_attr( $result[0] ); ?>"><?php echo esc_html( $result[1] ); ?></span>
							<?php if ( '' !== $row['reason'] ) : ?>
								<span class="wcfab-reason"><?php echo esc_html( self::reason_label( $row['reason'] ) ); ?></span>
							<?php endif; ?>
							<?php if ( '' !== $row['detail'] ) : ?>
								<span class="wcfab-muted"><?php echo esc_html( $row['detail'] ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		self::pagination( $page, $pages, $total, 'wcfab_paged', 'all' === $filter ? array( 'wcfab_log' => 'all' ) : array(), 'overview' );
	}

	/**
	 * Previous and next links under a table.
	 *
	 * @param int                  $page   Current page.
	 * @param int                  $pages  Pages.
	 * @param int                  $total  Rows.
	 * @param string               $param  Query arg for the page.
	 * @param array<string,string> $args   Other query args to keep.
	 * @param string               $tab    Tab to come back to.
	 * @return void
	 */
	private static function pagination( $page, $pages, $total, $param, array $args, $tab ) {
		if ( $pages < 2 ) {
			return;
		}

		$base = add_query_arg( $args, admin_url( 'admin.php?page=' . self::PAGE ) );
		?>
		<div class="wcfab-pages">
			<span class="wcfab-muted">
				<?php
				/* translators: 1: current page, 2: number of pages, 3: number of rows. */
				echo esc_html( sprintf( __( 'Page %1$s of %2$s (%3$s)', 'fake-account-blocker' ), number_format_i18n( $page ), number_format_i18n( $pages ), number_format_i18n( $total ) ) );
				?>
			</span>
			<?php if ( $page > 1 ) : ?>
				<a class="button" href="<?php echo esc_url( add_query_arg( $param, $page - 1, $base ) . '#' . $tab ); ?>"><?php echo self::icon( 'chevron-left', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Newer', 'fake-account-blocker' ); ?></a>
			<?php endif; ?>
			<?php if ( $page < $pages ) : ?>
				<a class="button" href="<?php echo esc_url( add_query_arg( $param, $page + 1, $base ) . '#' . $tab ); ?>"><?php esc_html_e( 'Older', 'fake-account-blocker' ); ?><?php echo self::icon( 'chevron-right', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></a>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Protection: refuse or only log.
	 *
	 * @return void
	 */
	public function render_mode() {
		$current = $this->plugin->is_test_mode() ? 'log' : 'block';
		$modes   = array(
			'block' => array( 'shield-ban', __( 'Refuse them', 'fake-account-blocker' ), __( 'The visitor sees a short error and no account is created. Reasons a real person could fix, like a typo in the email address, get their own message.', 'fake-account-blocker' ) ),
			'log'   => array( 'flask-conical', __( 'Only log them (test mode)', 'fake-account-blocker' ), __( 'Nothing is refused. Sign-ups that would have been are logged on the Overview tab, so you can check for false alarms first.', 'fake-account-blocker' ) ),
		);
		?>
		<div class="wcfab-options">
			<?php foreach ( $modes as $value => $mode ) : ?>
				<div class="wcfab-option">
					<label class="wcfab-option-main">
						<input type="radio" name="<?php echo esc_attr( self::name( 'mode' ) ); ?>" value="<?php echo esc_attr( $value ); ?>" <?php checked( $current, $value ); ?>>
						<span class="wcfab-option-icon"><?php echo self::icon( $mode[0] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
						<span class="wcfab-option-text">
							<strong><?php echo esc_html( $mode[1] ); ?></strong>
							<span><?php echo esc_html( $mode[2] ); ?></span>
						</span>
					</label>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Protection: which sign-up paths are checked.
	 *
	 * @return void
	 */
	public function render_forms() {
		$woo = class_exists( 'WooCommerce' );

		if ( ! $woo ) {
			$account_status = __( 'WooCommerce isn\'t active.', 'fake-account-blocker' );
		} elseif ( 'yes' !== get_option( 'woocommerce_enable_myaccount_registration' ) ) {
			$account_status = __( 'Sign-up on the My Account page is switched off in WooCommerce > Settings > Accounts & Privacy.', 'fake-account-blocker' );
		} elseif ( ! WooCommerce_Fake_Account_Blocker_Form::seen( 'account' ) ) {
			$account_status = __( 'The bot checks start once the form has been shown with the plugin active: open your My Account page while logged out.', 'fake-account-blocker' );
		} else {
			$account_status = '+' . __( 'Bot checks are running on this form.', 'fake-account-blocker' );
		}

		if ( ! $woo ) {
			$checkout_status = __( 'WooCommerce isn\'t active.', 'fake-account-blocker' );
		} elseif ( 'yes' !== get_option( 'woocommerce_enable_signup_and_login_from_checkout' ) && 'yes' !== get_option( 'woocommerce_enable_delayed_account_creation' ) ) {
			$checkout_status = __( 'Account creation at checkout is switched off in WooCommerce\'s settings right now.', 'fake-account-blocker' );
		} else {
			$checkout_status = '';
		}

		if ( ! get_option( 'users_can_register' ) ) {
			$wp_status = __( 'Not in use: "Anyone can register" is off in Settings > General.', 'fake-account-blocker' );
		} elseif ( ! WooCommerce_Fake_Account_Blocker_Form::seen( 'wordpress' ) ) {
			$wp_status = __( 'The bot checks start once the form has been shown with the plugin active.', 'fake-account-blocker' );
		} else {
			$wp_status = '+' . __( 'Bot checks are running on this form.', 'fake-account-blocker' );
		}

		echo '<div class="wcfab-options">';
		$this->option( 'protect_account', 'user-plus', __( 'WooCommerce My Account sign-up form', 'fake-account-blocker' ), __( 'Every check, including the bot checks.', 'fake-account-blocker' ), $account_status );
		$this->option( 'protect_checkout', 'shopping-cart', __( 'Accounts created at checkout', 'fake-account-blocker' ), __( 'The email and name checks and your blocked IPs, on the classic and block checkouts, the order confirmation page and other WooCommerce sign-up forms. The per-IP limits never stop a paying customer.', 'fake-account-blocker' ), $checkout_status );
		$this->option( 'protect_wp', 'log-in', __( 'WordPress registration form (wp-login.php)', 'fake-account-blocker' ), __( 'Every check, including the bot checks. Bots find this form even on shops that don\'t link to it.', 'fake-account-blocker' ), $wp_status );
		echo '</div>';
	}

	/**
	 * Protection: honeypot, timing and JavaScript.
	 *
	 * @return void
	 */
	public function render_bots() {
		echo '<div class="wcfab-options">';

		$this->option( 'honeypot', 'eye-off', __( 'Honeypot field', 'fake-account-blocker' ), __( 'A hidden field people never see. Bots fill in every field they find, and give themselves away.', 'fake-account-blocker' ) );

		$this->option(
			'timing',
			'hourglass',
			__( 'Form timing', 'fake-account-blocker' ),
			__( 'The form carries a signed timestamp. Sign-ups sent without loading the form first, or faster than anyone could type, are refused.', 'fake-account-blocker' ),
			'',
			function () {
				echo '<label for="wcfab-min_seconds">' . esc_html__( 'Minimum time on the form', 'fake-account-blocker' ) . '</label> ';
				$this->number( 'min_seconds', 0, 60, __( 'seconds', 'fake-account-blocker' ) );
			}
		);

		$this->option( 'js_check', 'braces', __( 'JavaScript check', 'fake-account-blocker' ), __( 'Most bots don\'t run JavaScript. A tiny inline script (no extra file) fills in a hidden field that they leave empty. People with JavaScript switched off are asked to turn it on.', 'fake-account-blocker' ) );

		echo '</div>';
	}

	/**
	 * Protection: per-IP limits.
	 *
	 * @return void
	 */
	public function render_limits() {
		echo '<table class="form-table" role="presentation">';

		$this->row(
			__( 'New accounts', 'fake-account-blocker' ),
			function () {
				$this->checkbox( 'rate_limit', __( 'Limit how many accounts one IP can create', 'fake-account-blocker' ) );
				echo '<p class="wcfab-inline" data-wcfab-depends="rate_limit">';
				$this->number( 'rate_max', 1, 100, __( 'accounts every', 'fake-account-blocker' ) );
				$this->number( 'rate_hours', 1, 168, __( 'hours', 'fake-account-blocker' ) );
				echo '</p>';
				echo '<p class="description">' . esc_html__( 'Real customers make one account. A family or an office sharing a connection might make two or three.', 'fake-account-blocker' ) . '</p>';
			}
		);

		$this->row(
			__( 'Repeat offenders', 'fake-account-blocker' ),
			function () {
				$this->checkbox( 'lockout', __( 'Lock out IPs that keep getting refused', 'fake-account-blocker' ) );
				echo '<p class="wcfab-inline" data-wcfab-depends="lockout">';
				$this->number( 'lockout_after', 2, 100, __( 'refused sign-ups in an hour locks the IP out for an hour', 'fake-account-blocker' ) );
				echo '</p>';
				echo '<p class="description">' . esc_html__( 'A locked-out IP is refused straight away, without any checks, and only a few of its attempts are logged, so a bot hammering the form can\'t slow your site down or fill the log.', 'fake-account-blocker' ) . '</p>';
			}
		);

		echo '</table>';
	}

	/**
	 * Email & Names: disposable addresses and dead domains.
	 *
	 * @return void
	 */
	public function render_email() {
		echo '<div class="wcfab-options">';

		$this->option(
			'disposable',
			'mail-x',
			__( 'Block disposable email addresses', 'fake-account-blocker' ),
			sprintf(
				/* translators: %s: number of domains. */
				__( '%s throwaway services like Mailinator, YOPmail and 10 Minute Mail, from the open disposable-email-domains list and updated with each release. Privacy relays that forward to a real inbox, like Hide My Email and Firefox Relay, are allowed.', 'fake-account-blocker' ),
				number_format_i18n( WooCommerce_Fake_Account_Blocker_Rules::disposable_count() )
			)
		);

		$this->option( 'mx_check', 'mail-question-mark', __( 'Check the email domain can receive mail', 'fake-account-blocker' ), __( 'A quick DNS lookup for the domain\'s mail servers catches made-up domains and typos like gmial.con, and tells the customer to check their address. If your server\'s DNS isn\'t answering, the check is skipped rather than refusing anyone.', 'fake-account-blocker' ) );

		echo '</div>';
	}

	/**
	 * Email & Names: links in names.
	 *
	 * @return void
	 */
	public function render_names() {
		echo '<div class="wcfab-options">';
		$this->option( 'spam_names', 'link', __( 'Block links in names', 'fake-account-blocker' ), __( 'Spam bots put links and ads in the name fields, so they show up in the "Welcome" email your shop sends. Real names never contain web addresses.', 'fake-account-blocker' ) );
		echo '</div>';
	}

	/**
	 * Email & Names: the admin's own block and allow lists.
	 *
	 * @return void
	 */
	public function render_lists() {
		echo '<table class="form-table" role="presentation">';

		$this->row(
			__( 'Blocked email domains', 'fake-account-blocker' ),
			function () {
				$this->textarea( 'blocked_domains', "example.com\n.ru" );
				echo '<p class="description">' . esc_html__( 'One per line. A domain also covers its subdomains; .ru blocks every address ending in .ru.', 'fake-account-blocker' ) . '</p>';
			},
			'wcfab-blocked_domains'
		);

		$this->row(
			__( 'Always allowed domains', 'fake-account-blocker' ),
			function () {
				$this->textarea( 'allowed_domains', 'mycompany.com' );
				echo '<p class="description">' . esc_html__( 'Skips the domain checks (disposable, mail and your block list) for these, for example your own company or a school with an unusual mail setup.', 'fake-account-blocker' ) . '</p>';
			},
			'wcfab-allowed_domains'
		);

		$this->row(
			__( 'Blocked words', 'fake-account-blocker' ),
			function () {
				$this->textarea( 'blocked_words', "casino\ncrypto bonus" );
				echo '<p class="description">' . esc_html__( 'One word or phrase per line. Matched anywhere in the email address, username or names, ignoring case.', 'fake-account-blocker' ) . '</p>';
			},
			'wcfab-blocked_words'
		);

		$this->row(
			__( 'Blocked IPs', 'fake-account-blocker' ),
			function () {
				$this->textarea( 'blocked_ips', "203.0.113.7\n198.51.100.0/24" );
				echo '<p class="description">' . esc_html__( 'IPs or CIDR ranges, one per line. They can\'t create an account at all.', 'fake-account-blocker' ) . '</p>';
			},
			'wcfab-blocked_ips'
		);

		echo '</table>';
	}

	/**
	 * Spam Accounts: the scanner.
	 *
	 * @return void
	 */
	public function render_scan() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			self::callout( 'info', __( 'WooCommerce isn\'t active, so there are no customer accounts to check.', 'fake-account-blocker' ) );
			return;
		}

		$state = WooCommerce_Fake_Account_Blocker_Cleanup::state();
		?>
		<label class="wcfab-check">
			<input type="checkbox" id="wcfab-scan-dns" value="1" <?php checked( ! $state['started'] || $state['dns'] ); ?>>
			<span><?php esc_html_e( 'Also check that each email domain can receive mail (slower)', 'fake-account-blocker' ); ?></span>
		</label>

		<p class="wcfab-scan-actions">
			<button type="button" class="button button-primary" data-wcfab-scan>
				<?php echo self::icon( 'scan-search', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php echo esc_html( $state['started'] ? __( 'Scan again', 'fake-account-blocker' ) : __( 'Scan customer accounts', 'fake-account-blocker' ) ); ?>
			</button>
		</p>

		<div class="wcfab-progress" data-wcfab-progress hidden>
			<div class="wcfab-progress-track"><span class="wcfab-progress-fill" data-wcfab-progress-fill></span></div>
			<p class="wcfab-progress-text" data-wcfab-progress-text aria-live="polite"></p>
		</div>

		<?php if ( $state['started'] ) : ?>
			<p class="description">
				<?php
				if ( $state['finished'] ) {
					echo esc_html(
						sprintf(
							/* translators: 1: time since, 2: accounts checked, 3: accounts found. */
							__( 'Last scan %1$s ago: %2$s customer accounts checked, %3$s looked fake.', 'fake-account-blocker' ),
							human_time_diff( (int) $state['finished'] ),
							number_format_i18n( (int) $state['offset'] ),
							number_format_i18n( count( $state['flagged'] ) + (int) $state['deleted'] )
						)
					);
				} else {
					echo esc_html(
						sprintf(
							/* translators: 1: accounts checked, 2: all accounts. */
							__( 'The last scan stopped after %1$s of %2$s accounts. Scan again to finish it.', 'fake-account-blocker' ),
							number_format_i18n( (int) $state['offset'] ),
							number_format_i18n( (int) $state['total'] )
						)
					);
				}
				?>
			</p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Spam Accounts: what the last scan found.
	 *
	 * @return void
	 */
	public function render_results() {
		$state   = WooCommerce_Fake_Account_Blocker_Cleanup::state();
		$flagged = $state['flagged'];

		if ( ! $state['started'] ) {
			echo '<p class="wcfab-empty is-neutral">' . self::icon( 'scan-search', 18 ) . esc_html__( 'Run a scan to list accounts here.', 'fake-account-blocker' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG.
			return;
		}

		if ( ! $flagged ) {
			echo '<p class="wcfab-empty">' . self::icon( 'circle-check', 18 ) . esc_html( $state['deleted'] ? __( 'All cleaned up. No fake-looking accounts left from the last scan.', 'fake-account-blocker' ) : __( 'No fake-looking accounts found.', 'fake-account-blocker' ) ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG.
			return;
		}

		$total = count( $flagged );
		$pages = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$page  = isset( $_GET['wcfab_apaged'] ) ? min( $pages, max( 1, absint( $_GET['wcfab_apaged'] ) ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only paging.
		$ids   = array_slice( array_keys( $flagged ), ( $page - 1 ) * self::PER_PAGE, self::PER_PAGE );

		cache_users( $ids );
		?>
		<div class="wcfab-bulk">
			<button type="button" class="button wcfab-button-danger" data-wcfab-delete="picked" disabled>
				<?php echo self::icon( 'trash-2', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Delete selected', 'fake-account-blocker' ); ?>
			</button>
			<button type="button" class="button" data-wcfab-delete="all" data-count="<?php echo esc_attr( (string) $total ); ?>">
				<?php
				/* translators: %s: number of accounts. */
				echo esc_html( sprintf( _n( 'Delete the %s account found', 'Delete all %s accounts found', $total, 'fake-account-blocker' ), number_format_i18n( $total ) ) );
				?>
			</button>
			<span class="wcfab-bulk-status" data-wcfab-delete-status aria-live="polite"></span>
		</div>

		<table class="widefat striped wcfab-table wcfab-accounts">
			<thead>
				<tr>
					<td class="check-column"><input type="checkbox" data-wcfab-pick-all aria-label="<?php esc_attr_e( 'Select all on this page', 'fake-account-blocker' ); ?>"></td>
					<th><?php esc_html_e( 'Account', 'fake-account-blocker' ); ?></th>
					<th><?php esc_html_e( 'Name', 'fake-account-blocker' ); ?></th>
					<th><?php esc_html_e( 'Registered', 'fake-account-blocker' ); ?></th>
					<th><?php esc_html_e( 'Why it looks fake', 'fake-account-blocker' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $ids as $id ) : ?>
					<?php
					$user = get_userdata( $id );
					if ( ! $user ) {
						continue;
					}
					$name = trim( get_user_meta( $id, 'first_name', true ) . ' ' . get_user_meta( $id, 'last_name', true ) );
					$name = '' !== $name ? $name : (string) $user->display_name;
					?>
					<tr data-wcfab-user="<?php echo esc_attr( (string) $id ); ?>">
						<th scope="row" class="check-column"><input type="checkbox" value="<?php echo esc_attr( (string) $id ); ?>" data-wcfab-pick aria-label="<?php echo esc_attr( $user->user_email ); ?>"></th>
						<td>
							<a href="<?php echo esc_url( get_edit_user_link( $id ) ); ?>"><strong><?php echo esc_html( $user->user_email ); ?></strong></a>
							<span class="wcfab-muted"><?php echo esc_html( $user->user_login ); ?></span>
						</td>
						<td class="wcfab-name"><?php echo esc_html( function_exists( 'mb_strimwidth' ) ? mb_strimwidth( $name, 0, 60, '...' ) : substr( $name, 0, 60 ) ); ?></td>
						<td><?php echo esc_html( mysql2date( get_option( 'date_format' ), $user->user_registered ) ); ?></td>
						<td>
							<?php foreach ( (array) $flagged[ $id ] as $reason ) : ?>
								<span class="wcfab-tag is-bad"><?php echo esc_html( self::reason_label( $reason ) ); ?></span>
							<?php endforeach; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		self::pagination( $page, $pages, $total, 'wcfab_apaged', array(), 'accounts' );
		?>
		<p class="description wcfab-note">
			<?php
			echo is_multisite()
				? esc_html__( 'On a multisite network, accounts are removed from this site; the network keeps them. Only customers with no orders and nothing published are listed, and each is checked again right before it\'s removed.', 'fake-account-blocker' )
				: esc_html__( 'Deleting is permanent, the same as from the Users screen. Only customers with no orders and nothing published are listed, and each is checked again right before it\'s deleted.', 'fake-account-blocker' );
			?>
		</p>
		<?php
	}

	/**
	 * Advanced: the refusal message.
	 *
	 * @return void
	 */
	public function render_message() {
		echo '<table class="form-table" role="presentation">';

		$this->row(
			__( 'Message', 'fake-account-blocker' ),
			function () {
				printf(
					'<input type="text" class="large-text" id="wcfab-message" name="%1$s" value="%2$s" placeholder="%3$s" maxlength="300">',
					esc_attr( self::name( 'message' ) ),
					esc_attr( (string) $this->plugin->get_setting( 'message' ) ),
					esc_attr( WooCommerce_Fake_Account_Blocker_Guard::default_message() )
				);
				echo '<p class="description">' . esc_html__( 'Shown when a sign-up is refused by a check that shouldn\'t explain itself to a bot. Leave empty for the default. Disposable addresses, domains that can\'t take mail, JavaScript, a form sent again too quickly and the per-IP limit each get their own message, so a real person knows what to fix.', 'fake-account-blocker' ) . '</p>';
			},
			'wcfab-message'
		);

		echo '</table>';
	}

	/**
	 * Advanced: what the server sees, and trusted proxies.
	 *
	 * @return void
	 */
	public function render_visitor_ip() {
		$probe   = WooCommerce_Fake_Account_Blocker_Request::probe();
		$sources = array(
			'direct'     => __( 'Direct connection (REMOTE_ADDR)', 'fake-account-blocker' ),
			'cloudflare' => __( 'Cloudflare, verified', 'fake-account-blocker' ),
			'proxy'      => __( 'Your trusted proxy', 'fake-account-blocker' ),
		);
		?>
		<div class="wcfab-probe">
			<h3><?php echo self::icon( 'monitor-smartphone', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Your own visit, right now', 'fake-account-blocker' ); ?></h3>
			<table class="wcfab-table wcfab-table-kv">
				<tbody>
					<tr><th><?php esc_html_e( 'IP the plugin uses', 'fake-account-blocker' ); ?></th><td><code><?php echo esc_html( '' !== $probe['ip'] ? $probe['ip'] : '?' ); ?></code></td></tr>
					<tr><th><?php esc_html_e( 'Found from', 'fake-account-blocker' ); ?></th><td><?php echo esc_html( isset( $sources[ $probe['source'] ] ) ? $sources[ $probe['source'] ] : $probe['source'] ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Connected from', 'fake-account-blocker' ); ?></th><td><code><?php echo esc_html( $probe['remote'] ); ?></code></td></tr>
					<?php foreach ( $probe['headers'] as $header => $value ) : ?>
						<tr><th><?php echo esc_html( $header ); ?></th><td><code><?php echo esc_html( $value ); ?></code></td></tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php
			if ( 'private' === $probe['warning'] ) {
				self::callout( 'triangle-alert', __( 'Your server sits behind a proxy on a private network, and every visitor will show up with its address, so the per-IP limits would count everyone together. Tick "Trust proxies on private networks" below.', 'fake-account-blocker' ), 'is-warning' );
			} elseif ( 'untrusted' === $probe['warning'] ) {
				self::callout( 'info', __( 'This request carries a forwarding header from an address that isn\'t trusted. If your site is behind a load balancer or a firewall service like Sucuri, add its IP or range below; if not, this is just your browser or network adding it, and ignoring it is right.', 'fake-account-blocker' ) );
			}
			?>
		</div>

		<table class="form-table" role="presentation">
			<?php
			$this->row(
				__( 'Trusted proxies', 'fake-account-blocker' ),
				function () {
					$this->textarea( 'trusted_proxies', "203.0.113.10\n198.51.100.0/24" );
					echo '<p class="description">' . esc_html__( 'IPs or CIDR ranges of your load balancers or proxies, one per line. Cloudflare is recognised automatically.', 'fake-account-blocker' ) . '</p>';
				},
				'wcfab-trusted_proxies'
			);

			$this->row(
				__( 'Header they send', 'fake-account-blocker' ),
				function () {
					$current = (string) $this->plugin->get_setting( 'proxy_header' );
					echo '<select id="wcfab-proxy_header" name="' . esc_attr( self::name( 'proxy_header' ) ) . '">';
					foreach ( array(
						'x-forwarded-for' => 'X-Forwarded-For',
						'x-real-ip'       => 'X-Real-IP',
						'true-client-ip'  => 'True-Client-IP',
					) as $value => $label ) {
						echo '<option value="' . esc_attr( $value ) . '" ' . selected( $current, $value, false ) . '>' . esc_html( $label ) . '</option>';
					}
					echo '</select>';
				},
				'wcfab-proxy_header'
			);

			$this->row(
				__( 'Private networks', 'fake-account-blocker' ),
				function () {
					$this->checkbox( 'trust_private', __( 'Trust proxies on private networks', 'fake-account-blocker' ), __( 'A connection from a 10.x, 172.16-31.x, 192.168.x or loopback address can only come from inside your hosting, so its forwarding header is believed. Most managed WordPress hosts need this.', 'fake-account-blocker' ) );
				}
			);
			?>
		</table>
		<?php
	}

	/**
	 * Advanced: retention.
	 *
	 * @return void
	 */
	public function render_data() {
		echo '<table class="form-table" role="presentation">';

		$this->row(
			__( 'Keep the sign-up log', 'fake-account-blocker' ),
			function () {
				$this->number( 'retention_days', 0, 3650, __( 'days (0 = forever)', 'fake-account-blocker' ) );
				echo '<p class="description">' . esc_html(
					sprintf(
						/* translators: %s: maximum number of rows. */
						__( 'Older entries are deleted daily. However long you keep it, the log never grows past the newest %s entries.', 'fake-account-blocker' ),
						number_format_i18n( WooCommerce_Fake_Account_Blocker_Log::MAX_ROWS )
					)
				) . '</p>';
			},
			'wcfab-retention_days'
		);

		$this->row(
			__( 'When deleting the plugin', 'fake-account-blocker' ),
			function () {
				$this->checkbox( 'keep_data', __( 'Keep the plugin\'s data and settings', 'fake-account-blocker' ), __( 'Normally, deleting the plugin from the Plugins screen removes its log, settings and scan results.', 'fake-account-blocker' ) );
			}
		);

		echo '</table>';
	}

	/**
	 * Advanced: hooks reference.
	 *
	 * @return void
	 */
	public function render_developer() {
		$hooks = array(
			'fake_account_blocker_verdict'            => __( 'Refuse or allow a sign-up yourself (return null, or a reason and detail).', 'fake-account-blocker' ),
			'fake_account_blocker_skip'               => __( 'Skip every check for some sign-ups.', 'fake-account-blocker' ),
			'fake_account_blocker_blocked'            => __( 'Action after a sign-up is refused (reason, sign-up, detail).', 'fake-account-blocker' ),
			'fake_account_blocker_message'            => __( 'Change the message a refused sign-up sees.', 'fake-account-blocker' ),
			'fake_account_blocker_disposable_domains' => __( 'Add to or trim the disposable domain list.', 'fake-account-blocker' ),
			'fake_account_blocker_client_ip'          => __( 'Override the visitor IP.', 'fake-account-blocker' ),
			'fake_account_blocker_form_lifetime'      => __( 'How long a sign-up form stays valid (default two days).', 'fake-account-blocker' ),
		);
		?>
		<table class="widefat striped wcfab-table wcfab-hooks">
			<tbody>
				<?php foreach ( $hooks as $hook => $what ) : ?>
					<tr><td><code><?php echo esc_html( $hook ); ?></code></td><td><?php echo esc_html( $what ); ?></td></tr>
				<?php endforeach; ?>
				<tr><td><code>FAKE_ACCOUNT_BLOCKER_DISABLED</code></td><td><?php esc_html_e( 'Define as true in wp-config.php to switch every check off.', 'fake-account-blocker' ); ?></td></tr>
			</tbody>
		</table>
		<p class="description"><?php esc_html_e( 'The log is the wcfab_log table. Shop managers, administrators, WP-CLI and scheduled jobs are never checked.', 'fake-account-blocker' ); ?></p>
		<?php
	}

	/**
	 * Sidebar: Save, status, and where updates come from.
	 *
	 * @return void
	 */
	private function render_sidebar() {
		$plugin = $this->plugin;
		$probe  = WooCommerce_Fake_Account_Blocker_Request::probe();
		$bots   = count( array_filter( array( $plugin->get_setting( 'honeypot' ), $plugin->get_setting( 'timing' ), $plugin->get_setting( 'js_check' ) ) ) );
		$forms  = count( array_filter( array( $plugin->get_setting( 'protect_account' ), $plugin->get_setting( 'protect_checkout' ), $plugin->get_setting( 'protect_wp' ) ) ) );
		$hours  = (int) $plugin->get_setting( 'rate_hours' );
		$status = array(
			array( $plugin->is_enabled(), $plugin->is_enabled() ? __( 'Checking new sign-ups', 'fake-account-blocker' ) : __( 'Protection is off', 'fake-account-blocker' ) ),
			array( ! $plugin->is_test_mode(), $plugin->is_test_mode() ? __( 'Test mode: logging only', 'fake-account-blocker' ) : __( 'Refusing fake sign-ups', 'fake-account-blocker' ) ),
			/* translators: %d: number of sign-up paths checked (of 3). */
			array( $forms > 0, sprintf( __( '%d of 3 sign-up paths checked', 'fake-account-blocker' ), $forms ) ),
			/* translators: %d: number of bot checks switched on (of 3). */
			array( $bots > 0, sprintf( __( '%d of 3 bot checks on', 'fake-account-blocker' ), $bots ) ),
			array(
				(bool) $plugin->get_setting( 'disposable' ),
				$plugin->get_setting( 'disposable' )
					/* translators: %s: number of domains. */
					? sprintf( __( 'Blocking %s disposable domains', 'fake-account-blocker' ), number_format_i18n( WooCommerce_Fake_Account_Blocker_Rules::disposable_count() ) )
					: __( 'Disposable addresses allowed', 'fake-account-blocker' ),
			),
			array( (bool) $plugin->get_setting( 'mx_check' ), $plugin->get_setting( 'mx_check' ) ? __( 'Checking email domains take mail', 'fake-account-blocker' ) : __( 'Not checking email domains', 'fake-account-blocker' ) ),
			array(
				(bool) $plugin->get_setting( 'rate_limit' ),
				$plugin->get_setting( 'rate_limit' )
					/* translators: 1: number of accounts, 2: number of hours. */
					? sprintf( _n( 'Up to %1$d accounts per IP every %2$d hour', 'Up to %1$d accounts per IP every %2$d hours', $hours, 'fake-account-blocker' ), (int) $plugin->get_setting( 'rate_max' ), $hours )
					: __( 'No per-IP limit', 'fake-account-blocker' ),
			),
			array( '' === $probe['warning'], 'cloudflare' === $probe['source'] ? __( 'Visitor IPs via Cloudflare', 'fake-account-blocker' ) : ( 'proxy' === $probe['source'] ? __( 'Visitor IPs via your proxy', 'fake-account-blocker' ) : ( '' !== $probe['warning'] ? __( 'Visitor IPs: check the Advanced tab', 'fake-account-blocker' ) : __( 'Visitor IPs: direct', 'fake-account-blocker' ) ) ) ),
		);
		?>
		<div class="postbox wcfab-save-box">
			<div class="postbox-header">
				<h2 class="hndle">
					<?php echo self::icon( 'save' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<?php esc_html_e( 'Save', 'fake-account-blocker' ); ?>
				</h2>
			</div>
			<div class="inside">
				<label class="wcfab-master">
					<input type="checkbox" name="<?php echo esc_attr( self::name( 'enabled' ) ); ?>" value="1" <?php checked( 1, (int) $plugin->get_setting( 'enabled' ) ); ?>>
					<span><?php esc_html_e( 'Protection on', 'fake-account-blocker' ); ?></span>
				</label>
				<?php if ( defined( 'FAKE_ACCOUNT_BLOCKER_DISABLED' ) && FAKE_ACCOUNT_BLOCKER_DISABLED ) : ?>
					<p class="wcfab-callout is-warning"><?php esc_html_e( 'Switched off by FAKE_ACCOUNT_BLOCKER_DISABLED in wp-config.php.', 'fake-account-blocker' ); ?></p>
				<?php endif; ?>
				<p><?php esc_html_e( 'Settings on every tab are saved together.', 'fake-account-blocker' ); ?></p>
				<?php submit_button( __( 'Save Changes', 'fake-account-blocker' ), 'primary large', 'submit', false ); ?>
			</div>
		</div>

		<div class="postbox">
			<div class="postbox-header">
				<h2 class="hndle">
					<?php echo self::icon( 'activity' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<?php esc_html_e( 'Status', 'fake-account-blocker' ); ?>
				</h2>
			</div>
			<div class="inside">
				<ul class="wcfab-checklist">
					<?php foreach ( $status as $item ) : ?>
						<li class="<?php echo $item[0] ? 'is-ok' : 'is-off'; ?>"><?php echo self::icon( $item[0] ? 'circle-check' : 'circle-dot', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><?php echo esc_html( $item[1] ); ?></span></li>
					<?php endforeach; ?>
				</ul>
			</div>
		</div>

		<?php
		$this->render_updates_box();
	}

	/**
	 * Show where updates come from, so it's easy to confirm the repo is wired up.
	 *
	 * @return void
	 */
	private function render_updates_box() {
		$repo_label = preg_replace( '#^https?://[^/]+/#', '', untrailingslashit( FAKE_ACCOUNT_BLOCKER_REPO ) );
		$check_url  = WooCommerce_Fake_Account_Blocker_Updater::check_now_url();
		?>
		<div class="postbox wcfab-updates-box">
			<div class="postbox-header">
				<h2 class="hndle">
					<?php echo self::icon( 'refresh-cw' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<?php esc_html_e( 'Updates', 'fake-account-blocker' ); ?>
				</h2>
			</div>
			<div class="inside">
				<p>
					<?php
					/* translators: %s: installed plugin version. */
					printf( esc_html__( 'Installed version: %s', 'fake-account-blocker' ), '<strong>' . esc_html( FAKE_ACCOUNT_BLOCKER_VERSION ) . '</strong>' );
					?>
				</p>

				<?php if ( ! WooCommerce_Fake_Account_Blocker_Updater::has_repo() ) : ?>
					<p>
						<?php
						printf(
							/* translators: 1: header name, 2: main plugin file. */
							esc_html__( 'No repo set. Add it to the %1$s header in %2$s to turn on automatic updates.', 'fake-account-blocker' ),
							'<code>Update URI</code>',
							'<code>' . esc_html( plugin_basename( FAKE_ACCOUNT_BLOCKER_FILE ) ) . '</code>'
						);
						?>
					</p>
				<?php elseif ( WooCommerce_Fake_Account_Blocker_Updater::is_dev_copy() ) : ?>
					<p>
						<?php
						printf(
							/* translators: 1: repo, 2: constant name. */
							esc_html__( 'Releases come from %1$s, but update checks are off on this development copy so an update can\'t overwrite your work. Define %2$s as false in wp-config.php to test updates here.', 'fake-account-blocker' ),
							'<a href="' . esc_url( FAKE_ACCOUNT_BLOCKER_REPO ) . '" target="_blank" rel="noopener">' . esc_html( $repo_label ) . '</a>',
							'<code>FAKE_ACCOUNT_BLOCKER_DEV_MODE</code>'
						);
						?>
					</p>
				<?php else : ?>
					<p>
						<?php
						printf(
							/* translators: %s: repo. */
							esc_html__( 'Updates come from the latest release of %s.', 'fake-account-blocker' ),
							'<a href="' . esc_url( FAKE_ACCOUNT_BLOCKER_REPO ) . '" target="_blank" rel="noopener">' . esc_html( $repo_label ) . '</a>'
						);
						?>
					</p>
					<?php if ( $check_url ) : ?>
						<p><a class="button" href="<?php echo esc_url( $check_url ); ?>"><?php esc_html_e( 'Check for updates', 'fake-account-blocker' ); ?></a></p>
					<?php endif; ?>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}
