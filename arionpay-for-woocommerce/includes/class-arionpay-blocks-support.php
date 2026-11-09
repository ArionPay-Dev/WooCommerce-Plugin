<?php
/**
 * Checkout block integration.
 *
 * @package ArionPay_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * Makes the gateway selectable on the Checkout block.
 */
final class ArionPay_Blocks_Support extends AbstractPaymentMethodType {

	/**
	 * Payment method name (the gateway id).
	 *
	 * @var string
	 */
	protected $name = 'arionpay';

	/**
	 * Load the saved gateway settings.
	 */
	public function initialize() {
		$settings       = get_option( 'woocommerce_arionpay_settings', array() );
		$this->settings = is_array( $settings ) ? $settings : array();
	}

	/**
	 * Only offer the method when it is enabled and fully configured.
	 *
	 * @return bool
	 */
	public function is_active() {
		return isset( $this->settings['enabled'] ) && 'yes' === $this->settings['enabled'] && WC_Gateway_ArionPay::credentials_present( $this->settings );
	}

	/**
	 * Register the plain JS that adds the method to the block.
	 *
	 * @return string[]
	 */
	public function get_payment_method_script_handles() {
		wp_register_script(
			'arionpay-for-woocommerce-blocks',
			plugins_url( 'assets/js/blocks.js', ARIONPAY_WC_FILE ),
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities' ),
			ARIONPAY_WC_VERSION,
			true
		);
		return array( 'arionpay-for-woocommerce-blocks' );
	}

	/**
	 * Data passed to the block script.
	 *
	 * @return array
	 */
	public function get_payment_method_data() {
		$title = isset( $this->settings['title'] ) ? (string) $this->settings['title'] : '';
		return array(
			'title'       => '' !== $title ? $title : __( 'Cryptocurrency (ArionPay)', 'arionpay-for-woocommerce' ),
			'description' => WC_Gateway_ArionPay::display_description( $this->settings ),
			'supports'    => array( 'products' ),
		);
	}
}
