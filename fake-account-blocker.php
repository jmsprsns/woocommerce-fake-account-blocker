<?php
/**
 * Plugin Name:       WooCommerce Fake Account Blocker
 * Plugin URI:        https://www.contentpowered.com
 * Description:       Stops bot registrations and spam customer accounts on WooCommerce, and finds and removes the fake accounts you already have.
 * Version:           1.0.1
 * Requires at least: 5.8
 * Requires PHP:      7.2
 * Author:            Content Powered
 * Author URI:        https://www.contentpowered.com/wordpress-plugins/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       fake-account-blocker
 * Update URI:        https://github.com/jmsprsns/woocommerce-fake-account-blocker
 * WC requires at least: 6.0
 * WC tested up to:   11.2
 *
 * @package WooCommerce_Fake_Account_Blocker
 */

defined( 'ABSPATH' ) || exit;

// Version and repo are read from the header above, so a release only needs
// "Version" bumped and the GitHub repo lives in one place ("Update URI").
// The Update URI header also stops WordPress.org offering a same-slug plugin
// as an "update" that would overwrite this one.
$fake_account_blocker_headers = get_file_data(
	__FILE__,
	array(
		'version' => 'Version',
		'repo'    => 'Update URI',
	)
);

define( 'FAKE_ACCOUNT_BLOCKER_VERSION', $fake_account_blocker_headers['version'] );
define( 'FAKE_ACCOUNT_BLOCKER_REPO', $fake_account_blocker_headers['repo'] );
define( 'FAKE_ACCOUNT_BLOCKER_FILE', __FILE__ );
define( 'FAKE_ACCOUNT_BLOCKER_DIR', plugin_dir_path( __FILE__ ) );
define( 'FAKE_ACCOUNT_BLOCKER_URL', plugin_dir_url( __FILE__ ) );

unset( $fake_account_blocker_headers );

require_once FAKE_ACCOUNT_BLOCKER_DIR . 'includes/class-fake-account-blocker-icons.php';
require_once FAKE_ACCOUNT_BLOCKER_DIR . 'includes/class-fake-account-blocker-updater.php';
require_once FAKE_ACCOUNT_BLOCKER_DIR . 'includes/class-fake-account-blocker-menu.php';
require_once FAKE_ACCOUNT_BLOCKER_DIR . 'includes/class-fake-account-blocker-feedback.php';
require_once FAKE_ACCOUNT_BLOCKER_DIR . 'includes/class-fake-account-blocker-install.php';
require_once FAKE_ACCOUNT_BLOCKER_DIR . 'includes/class-fake-account-blocker-request.php';
require_once FAKE_ACCOUNT_BLOCKER_DIR . 'includes/class-fake-account-blocker-log.php';
require_once FAKE_ACCOUNT_BLOCKER_DIR . 'includes/class-fake-account-blocker-rules.php';
require_once FAKE_ACCOUNT_BLOCKER_DIR . 'includes/class-fake-account-blocker-form.php';
require_once FAKE_ACCOUNT_BLOCKER_DIR . 'includes/class-fake-account-blocker-guard.php';
require_once FAKE_ACCOUNT_BLOCKER_DIR . 'includes/class-fake-account-blocker-cleanup.php';
require_once FAKE_ACCOUNT_BLOCKER_DIR . 'includes/class-fake-account-blocker-privacy.php';
require_once FAKE_ACCOUNT_BLOCKER_DIR . 'includes/class-fake-account-blocker-admin.php';
require_once FAKE_ACCOUNT_BLOCKER_DIR . 'includes/class-fake-account-blocker-plugin.php';

// Built right away, outside any hook, so WP-Cron background updates and
// remote management tools see new releases too (not only logged-in admins).
WooCommerce_Fake_Account_Blocker_Updater::init();

/**
 * Boot the plugin once all other plugins have loaded.
 *
 * @return WooCommerce_Fake_Account_Blocker_Plugin
 */
function fake_account_blocker_plugin() {
	return WooCommerce_Fake_Account_Blocker_Plugin::instance();
}
add_action( 'plugins_loaded', 'fake_account_blocker_plugin' );

/**
 * Tell WooCommerce this plugin works with its order tables (HPOS) and the
 * Cart and Checkout blocks.
 *
 * @return void
 */
function fake_account_blocker_declare_compatibility() {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', FAKE_ACCOUNT_BLOCKER_FILE, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', FAKE_ACCOUNT_BLOCKER_FILE, true );
	}
}
add_action( 'before_woocommerce_init', 'fake_account_blocker_declare_compatibility' );

/**
 * Create the log table on activation.
 *
 * Network activation and new sites on a multisite are covered too: the
 * table is created on each site's first request (see
 * WooCommerce_Fake_Account_Blocker_Install::maybe_upgrade()).
 *
 * @return void
 */
function fake_account_blocker_activate() {
	WooCommerce_Fake_Account_Blocker_Install::install();
}
register_activation_hook( FAKE_ACCOUNT_BLOCKER_FILE, 'fake_account_blocker_activate' );

/**
 * Clean up when the plugin is deactivated.
 *
 * Settings and the log stay put (uninstall.php removes them).
 *
 * @return void
 */
function fake_account_blocker_deactivate() {
	wp_clear_scheduled_hook( WooCommerce_Fake_Account_Blocker_Plugin::CRON_DAILY );
}
register_deactivation_hook( FAKE_ACCOUNT_BLOCKER_FILE, 'fake_account_blocker_deactivate' );
