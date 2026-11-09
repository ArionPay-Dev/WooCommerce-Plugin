<?php
/**
 * Plugin Name:          ArionPay for WooCommerce
 * Plugin URI:           https://arionpay.com
 * Description:          Accept cryptocurrency in WooCommerce through ArionPay, a non-custodial payment gateway. Customers pay on a hosted ArionPay page and the money goes straight to your own wallets.
 * Version:              1.0.0
 * Requires at least:    6.5
 * Requires PHP:         7.4
 * Requires Plugins:     woocommerce
 * Author:               ArionPay
 * Author URI:           https://arionpay.com
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          arionpay-for-woocommerce
 * Domain Path:          /languages
 * WC requires at least: 8.2
 * WC tested up to:      11.2
 *
 * @package ArionPay_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

define( 'ARIONPAY_WC_VERSION', '1.0.0' );
define( 'ARIONPAY_WC_FILE', __FILE__ );
define( 'ARIONPAY_WC_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Declare compatibility with WooCommerce's custom order tables (HPOS) and the Cart and Checkout blocks.
 */
function arionpay_wc_declare_compatibility() {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', ARIONPAY_WC_FILE, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', ARIONPAY_WC_FILE, true );
	}
}
add_action( 'before_woocommerce_init', 'arionpay_wc_declare_compatibility' );

/**
 * Show a short notice when WooCommerce is missing.
 */
function arionpay_wc_missing_notice() {
	echo '<div class="notice notice-error"><p>' . esc_html__( 'ArionPay for WooCommerce needs the WooCommerce plugin to be installed and active.', 'arionpay-for-woocommerce' ) . '</p></div>';
}

/**
 * Add the gateway to WooCommerce.
 *
 * @param array $gateways Gateway class names.
 * @return array
 */
function arionpay_wc_add_gateway( $gateways ) {
	$gateways[] = 'WC_Gateway_ArionPay';
	return $gateways;
}

/**
 * Load the plugin once WooCommerce is available.
 */
function arionpay_wc_init() {
	load_plugin_textdomain( 'arionpay-for-woocommerce', false, dirname( plugin_basename( ARIONPAY_WC_FILE ) ) . '/languages' );

	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		add_action( 'admin_notices', 'arionpay_wc_missing_notice' );
		return;
	}

	require_once ARIONPAY_WC_DIR . 'includes/class-arionpay-signature.php';
	require_once ARIONPAY_WC_DIR . 'includes/class-arionpay-api.php';
	require_once ARIONPAY_WC_DIR . 'includes/class-arionpay-order-sync.php';
	require_once ARIONPAY_WC_DIR . 'includes/class-arionpay-webhook.php';
	require_once ARIONPAY_WC_DIR . 'includes/class-wc-gateway-arionpay.php';

	add_filter( 'woocommerce_payment_gateways', 'arionpay_wc_add_gateway' );

	// Webhook endpoint: /?wc-api=arionpay (or /wc-api/arionpay/ with pretty permalinks).
	add_action( 'woocommerce_api_arionpay', array( 'ArionPay_Webhook', 'handle' ) );

	add_filter( 'woocommerce_order_actions', array( 'WC_Gateway_ArionPay', 'add_order_action' ), 10, 2 );
	add_action( 'woocommerce_order_action_arionpay_check_status', array( 'WC_Gateway_ArionPay', 'run_order_action' ) );
}
add_action( 'plugins_loaded', 'arionpay_wc_init', 11 );

/**
 * Register the payment method for the Checkout block.
 *
 * @param \Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $registry Blocks payment method registry.
 */
function arionpay_wc_register_blocks( $registry ) {
	if ( ! class_exists( '\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
		return;
	}
	require_once ARIONPAY_WC_DIR . 'includes/class-arionpay-blocks-support.php';
	$registry->register( new ArionPay_Blocks_Support() );
}
add_action( 'woocommerce_blocks_payment_method_type_registration', 'arionpay_wc_register_blocks' );
