<?php
/**
 * Plugin Name:       Moyasar Payments
 * Plugin URI:        https://moyasar.com
 * Description:       Accept payments via Credit Card, Apple Pay, STC Pay, and Samsung Pay using Moyasar.
 * Version:           8.4.1
 * Author:            Moyasar
 * Author URI:        https://moyasar.com
 * Text Domain:       moyasar-payments
 * Domain Path:       /i18n/languages
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.5
 * Requires Plugins:  woocommerce
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Current Plugin Version.
 */
define( 'MOYASAR_WC_VERSION', '8.4.1' );

/**
 * Plugin Path.
 */
define( 'MOYASAR_WC_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );

/**
 * Plugin URL.
 */
define( 'MOYASAR_WC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * The code that runs during plugin activation.
 */
function activate_moyasar_payments() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-moyasar-activator.php';
	Moyasar_Activator::activate();
}

/**
 * The code that runs during plugin deactivation.
 */
function deactivate_moyasar_payments() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-moyasar-deactivator.php';
	Moyasar_Deactivator::deactivate();
}

register_activation_hook( __FILE__, 'activate_moyasar_payments' );
register_deactivation_hook( __FILE__, 'deactivate_moyasar_payments' );

/**
 * The core plugin class that is used to define internationalization,
 * admin-specific hooks, and public-facing site hooks.
 */
require plugin_dir_path( __FILE__ ) . 'includes/class-moyasar.php';

/**
 * Begins execution of the plugin.
 */
function run_moyasar_payments() {
	$plugin = new Moyasar_Run();
	$plugin->run();
}
add_action( 'before_woocommerce_init', function() {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
	}
} );
run_moyasar_payments();
