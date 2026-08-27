<?php
/**
 * Plugin Name: Woo PowerTranz
 * Plugin URI: https://icronos.dev
 * Description: PowerTranz payment gateway for WooCommerce.
 * Version: 1.0.0
 * Author: iCronos
 * Author URI: https://icronos.dev
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: woo-powertranz
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * WC requires at least: 8.0
 * WC tested up to: 9.0
 *
 * @package Woo_PowerTranz
 */

// Prevent direct file access for security.
defined( 'ABSPATH' ) || exit;

/**
 * Define plugin constants for use throughout the plugin.
 */
define( 'WOO_POWERTRANZ_VERSION', '1.0.0' );
define( 'WOO_POWERTRANZ_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WOO_POWERTRANZ_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WOO_POWERTRANZ_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Load plugin text domain for translations.
 *
 * Loads the translation files from the /languages directory.
 * Priority: Spanish (es_ES) is the primary user-facing language.
 */
add_action( 'init', 'woo_powertranz_load_textdomain' );

/**
 * Load the plugin text domain for translation.
 *
 * @return void
 */
function woo_powertranz_load_textdomain(): void {
	load_plugin_textdomain(
		'woo-powertranz',
		false,
		dirname( WOO_POWERTRANZ_PLUGIN_BASENAME ) . '/languages'
	);
}

/**
 * Check if WooCommerce is active before initializing the gateway.
 *
 * This prevents fatal errors if WooCommerce is deactivated while this plugin is active.
 * We hook into 'plugins_loaded' to ensure WooCommerce has loaded first.
 */
add_action( 'plugins_loaded', 'woo_powertranz_init', 11 );

/**
 * Initialize the Woo PowerTranz payment gateway.
 *
 * This function checks for WooCommerce availability and loads the gateway class.
 * Priority 11 ensures WooCommerce has fully loaded (default priority is 10).
 *
 * @return void
 */
function woo_powertranz_init(): void {
	// Bail early if WooCommerce is not active.
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'woo_powertranz_missing_wc_notice' );
		return;
	}

	// Ensure the WC_Payment_Gateway class exists before extending it.
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		return;
	}

	// Include the API client class (no WooCommerce dependencies).
	require_once WOO_POWERTRANZ_PLUGIN_DIR . 'includes/class-woo-powertranz-api-client.php';

	// Include the rate limiter class for card testing prevention.
	require_once WOO_POWERTRANZ_PLUGIN_DIR . 'includes/class-woo-powertranz-rate-limiter.php';

	// Include the gateway class file.
	require_once WOO_POWERTRANZ_PLUGIN_DIR . 'includes/class-wc-gateway-woo-powertranz.php';

	// Include and initialize the admin order handler.
	require_once WOO_POWERTRANZ_PLUGIN_DIR . 'includes/class-woo-powertranz-admin-order.php';
	Woo_PowerTranz_Admin_Order::init();

	// Register the gateway with WooCommerce.
	add_filter( 'woocommerce_payment_gateways', 'woo_powertranz_add_gateway_class' );
}

/**
 * Add the Woo PowerTranz gateway to WooCommerce's list of available payment gateways.
 *
 * WooCommerce uses this filter to populate the payment methods in:
 * - WooCommerce → Settings → Payments
 * - Checkout page payment method selection
 *
 * @param array $gateways Array of registered gateway class names.
 * @return array Modified array with Woo PowerTranz gateway added.
 */
function woo_powertranz_add_gateway_class( array $gateways ): array {
	$gateways[] = 'WC_Gateway_Woo_PowerTranz';
	return $gateways;
}

/**
 * Display admin notice when WooCommerce is not active.
 *
 * This helps administrators understand why the plugin isn't working.
 *
 * @return void
 */
function woo_powertranz_missing_wc_notice(): void {
	?>
	<div class="notice notice-error">
		<p>
			<?php
			printf(
				/* translators: %s: WooCommerce plugin name */
				esc_html__( 'Woo PowerTranz requires %s to be installed and active.', 'woo-powertranz' ),
				'<strong>WooCommerce</strong>'
			);
			?>
		</p>
	</div>
	<?php
}

/**
 * Declare compatibility with WooCommerce High-Performance Order Storage (HPOS).
 *
 * HPOS is WooCommerce's new order storage system that uses custom database tables
 * instead of WordPress post meta for better performance. Declaring compatibility
 * prevents warnings in WooCommerce → Status → Features.
 *
 * @see https://woocommerce.com/document/high-performance-order-storage/
 */
add_action(
	'before_woocommerce_init',
	function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				__FILE__,
				true
			);
		}
	}
);

/**
 * Add a "Settings" link to the plugin action links on the Plugins page.
 *
 * This provides quick access to the gateway configuration from the plugins list.
 *
 * @param array $links Array of plugin action links.
 * @return array Modified array with settings link added.
 */
add_filter( 'plugin_action_links_' . WOO_POWERTRANZ_PLUGIN_BASENAME, 'woo_powertranz_plugin_links' );

/**
 * Add settings link to plugin action links.
 *
 * @param array $links Existing plugin action links.
 * @return array Modified links array.
 */
function woo_powertranz_plugin_links( array $links ): array {
	$settings_link = sprintf(
		'<a href="%s">%s</a>',
		esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=woo_powertranz' ) ),
		esc_html__( 'Settings', 'woo-powertranz' )
	);

	// Add settings link at the beginning of the array.
	array_unshift( $links, $settings_link );

	return $links;
}
