<?php
/**
 * Plugin Name:       BumpMint - Order Bump for WooCommerce
 * Plugin URI:        https://github.com/andrewaires/bumpmint
 * Description:       Create targeted WooCommerce order bumps with flexible display rules, secure server-side discounts, and multiple checkout positions.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            Andrew Aires
 * Author URI:        https://andrewaires.com.br
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       bumpmint-order-bump-for-woocommerce
 * Requires Plugins:  woocommerce
 * WC requires at least: 8.0
 * WC tested up to:   10.9
 *
 * @package BumpMint
 */

// Prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin constants.
define( 'BUMPMINT_VERSION', '1.0.0' );
define( 'BUMPMINT_PLUGIN_FILE', __FILE__ );
define( 'BUMPMINT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BUMPMINT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'BUMPMINT_OPTION_KEY', 'bumpmint_rules' );

/**
 * Main plugin class.
 *
 * Uses the singleton pattern to ensure only one instance is loaded,
 * and initializes the plugin only after confirming WooCommerce is active.
 */
final class BumpMint_Plugin {

	/**
	 * Single class instance.
	 *
	 * @var BumpMint_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Returns (or creates) the single plugin instance.
	 *
	 * @return BumpMint_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor: registers initialization hooks.
	 */
	private function __construct() {
		add_action( 'init', array( $this, 'init' ) );
		add_action( 'before_woocommerce_init', array( $this, 'declare_hpos_compatibility' ) );

		$plugin_basename = plugin_basename( BUMPMINT_PLUGIN_FILE );
		add_filter( "plugin_action_links_{$plugin_basename}", array( $this, 'add_settings_link' ) );
	}

	/**
	 * Initializes the plugin only when WooCommerce is active.
	 */
	public function init() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( $this, 'woocommerce_missing_notice' ) );
			return;
		}

		require_once BUMPMINT_PLUGIN_DIR . 'includes/class-bumpmint-conditions.php';
		require_once BUMPMINT_PLUGIN_DIR . 'includes/class-bumpmint-positions.php';
		require_once BUMPMINT_PLUGIN_DIR . 'includes/class-bumpmint-rules.php';
		require_once BUMPMINT_PLUGIN_DIR . 'includes/class-bumpmint-admin.php';
		require_once BUMPMINT_PLUGIN_DIR . 'includes/class-bumpmint-checkout.php';

		new BumpMint_Admin();
		new BumpMint_Checkout();
	}

	/**
	 * Declares compatibility with WooCommerce features.
	 *
	 * - custom_order_tables (HPOS): the plugin does not read or write order data
	 *   directly in the legacy table, so it is compatible.
	 * - cart_checkout_blocks: the plugin uses the classic
	 *   woocommerce_review_order_before_payment hook, which is not fired by
	 *   block checkout. It is declared incompatible to prevent unexpected
	 *   behavior in that scenario.
	 */
	public function declare_hpos_compatibility() {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				BUMPMINT_PLUGIN_FILE,
				true
			);
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'cart_checkout_blocks',
				BUMPMINT_PLUGIN_FILE,
				false
			);
		}
	}

	/**
	 * Displays an admin notice when WooCommerce is not active.
	 */
	public function woocommerce_missing_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>' .
			esc_html__( 'BumpMint requires WooCommerce to be installed and active.', 'bumpmint-order-bump-for-woocommerce' ) .
			'</p></div>';
	}

	/**
	 * Adds a Settings link to the Plugins screen.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public function add_settings_link( $links ) {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=bumpmint' ) ),
			esc_html__( 'Settings', 'bumpmint-order-bump-for-woocommerce' )
		);
		array_unshift( $links, $settings_link );
		return $links;
	}
}

BumpMint_Plugin::instance();

/**
 * Runs on plugin activation and ensures the rules option exists.
 */
function bumpmint_activate() {
	if ( false === get_option( BUMPMINT_OPTION_KEY, false ) ) {
		add_option( BUMPMINT_OPTION_KEY, array() );
	}
}
register_activation_hook( __FILE__, 'bumpmint_activate' );
