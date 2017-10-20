<?php
/**
* @package MPESA For WooCommerce
* @version 1.6
* @author Mauko Maunde
**/
/*
Plugin Name: MPESA For WooCommerce
Plugin URI: http://wordpress.org/plugins/wc-mpesa/
Description: This plugin extends WooCommerce functionality to integrate MPESA for making payments, checking account balance transaction status and reversals. It also adds Kenyan Counties to the WooCommerce states list.
Author: Mauko Maunde
Version: 0.1
Author URI: https://mauko.co.ke/
*/

require_once( 'MPESA.php' );
$mpesa = new \Safaricom\Mpesa\Mpesa( get_option( 'mpesa_key' ), get_option( 'mpesa_secret' ), true );

if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) return;

add_action( 'plugins_loaded', 'wc_mpesa_gateway_init', 11 );

function wc_mpesa_add_to_gateways( $gateways ) {
	$gateways[] = 'WC_Gateway_MPESA';
	return $gateways;
}

add_filter( 'woocommerce_payment_gateways', 'wc_mpesa_add_to_gateways' );

function wc_mpesa_add_to_states( $states ) {
	$states['KE'] = array();
	return $states;
}

//add_filter( 'woocommerce_countries', 'wc_mpesa_add_to_states' );

function wc_mpesa_gateway_init() {

	/**
	* @class WC_Gateway_Offline
	* @extends WC_Payment_Gateway
	**/
	class WC_Gateway_MPESA extends WC_Payment_Gateway {

		/**
		 * Constructor for the gateway.
		 */
		public function __construct() {
			// Setup general properties
			$this->setup_properties();

			// Load the settings
			$this->init_form_fields();
			$this->init_settings();

			// Get settings
			$this->title              = $this->get_option( 'title' );
			$this->description        = $this->get_option( 'description' );
			$this->instructions       = $this->get_option( 'instructions' );
			$this->enable_for_methods = $this->get_option( 'enable_for_methods', array() );
			$this->enable_for_virtual = $this->get_option( 'enable_for_virtual', 'yes' ) === 'yes' ? true : false;

			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
			add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page' ) );
			add_filter( 'woocommerce_payment_complete_order_status', array( $this, 'change_payment_complete_order_status' ), 10, 3 );

			// Customer Emails
			add_action( 'woocommerce_email_before_order_table', array( $this, 'email_instructions' ), 10, 3 );
		}

		/**
		 * Setup general properties for the gateway.
		 */
		protected function setup_properties() {
			$this->id                 = 'mpesa';
			$this->icon               = apply_filters( 'woocommerce_cod_icon', '' );
			$this->method_title       = __( 'Lipa Na MPESA', 'woocommerce' );
			$this->method_description = __( 'Have your customers pay conveniently using Safaricom MPESA.', 'woocommerce' );
			$this->has_fields         = false;
		}

		/**
		 * Initialise Gateway Settings Form Fields.
		 */
		public function init_form_fields() {
			$shipping_methods = array();

			foreach ( WC()->shipping()->load_shipping_methods() as $method ) {
				$shipping_methods[ $method->id ] = $method->get_method_title();
			}

			$this -> form_fields = array(
				'enabled' => array(
					'title'       => __( 'Enable/Disable', 'woocommerce' ),
					'label'       => __( 'Enable MPESA', 'woocommerce' ),
					'type'        => 'checkbox',
					'description' => '',
					'default'     => 'no',
				),
				'key' => array(
					'title'       => __( 'App Key', 'woocommerce' ),
					'type'        => 'text',
					'description' => __( 'Your App Key From Safaricom Daraja.', 'woocommerce' ),
					'default'     => __( 'Your app key', 'woocommerce' ),
					'desc_tip'    => true,
				),
				'secret' => array(
					'title'       => __( 'App Secret', 'woocommerce' ),
					'type'        => 'text',
					'description' => __( 'Your App Secret From Safaricom Daraja.', 'woocommerce' ),
					'default'     => __( 'Your app secret', 'woocommerce' ),
					'desc_tip'    => true,
				),
				'business' => array(
					'title'       => __( 'Business Name', 'woocommerce' ),
					'type'        => 'text',
					'description' => __( 'Your MPESA Business Name.', 'woocommerce' ),
					'default'     => __( 'Your Business Name', 'woocommerce' ),
					'desc_tip'    => true,
				),
				'shortcode' => array(
					'title'       => __( 'MPESA Shortcode', 'woocommerce' ),
					'type'        => 'text',
					'description' => __( 'Your MPESA Business Shortcode.', 'woocommerce' ),
					'default'     => __( 'Your MPESA Shortcode', 'woocommerce' ),
					'desc_tip'    => true,
				),
				'title' => array(
					'title'       => __( 'Title', 'woocommerce' ),
					'type'        => 'text',
					'description' => __( 'Payment method description that the customer will see on your checkout.', 'woocommerce' ),
					'default'     => __( 'Lipa Na MPESA', 'woocommerce' ),
					'desc_tip'    => true,
				),
				'description' => array(
					'title'       => __( 'Description', 'woocommerce' ),
					'type'        => 'textarea',
					'description' => __( 'Payment method description that the customer will see on your website.', 'woocommerce' ),
					'default'     => __( 'Press the button below. You will get a pop-up on your phone asking you to confirm the payment.
Enter your service PIN to proceed.
You will receive a confirmation message shortly thereafter.', 'woocommerce' ),
					'desc_tip'    => true,
				),
				'instructions' => array(
					'title'       => __( 'Instructions', 'woocommerce' ),
					'type'        => 'textarea',
					'description' => __( 'Instructions that will be added to the thank you page.', 'woocommerce' ),
					'default'     => __( 'Lipa Na MPESA.', 'woocommerce' ),
					'desc_tip'    => true,
				),
				'enable_for_methods' => array(
					'title'             => __( 'Enable for shipping methods', 'woocommerce' ),
					'type'              => 'multiselect',
					'class'             => 'wc-enhanced-select',
					'css'               => 'width: 400px;',
					'default'           => '',
					'description'       => __( 'If MPESA is only available for certain methods, set it up here. Leave blank to enable for all methods.', 'woocommerce' ),
					'options'           => $shipping_methods,
					'desc_tip'          => true,
					'custom_attributes' => array(
						'data-placeholder' => __( 'Select shipping methods', 'woocommerce' ),
					),
				),
				'enable_for_virtual' => array(
					'title'             => __( 'Accept for virtual orders', 'woocommerce' ),
					'label'             => __( 'Accept MPESA if the order is virtual', 'woocommerce' ),
					'type'              => 'checkbox',
					'default'           => 'yes',
				),
		   );
		}

		/**
		 * Check If The Gateway Is Available For Use.
		 *
		 * @return bool
		 */
		public function is_available() {
			$order          = null;
			$needs_shipping = false;

			// Test if shipping is needed first
			if ( WC()->cart && WC()->cart->needs_shipping() ) {
				$needs_shipping = true;
			} elseif ( is_page( wc_get_page_id( 'checkout' ) ) && 0 < get_query_var( 'order-pay' ) ) {
				$order_id = absint( get_query_var( 'order-pay' ) );
				$order    = wc_get_order( $order_id );

				// Test if order needs shipping.
				if ( 0 < sizeof( $order->get_items() ) ) {
					foreach ( $order->get_items() as $item ) {
						$_product = $item->get_product();
						if ( $_product && $_product->needs_shipping() ) {
							$needs_shipping = true;
							break;
						}
					}
				}
			}

			$needs_shipping = apply_filters( 'woocommerce_cart_needs_shipping', $needs_shipping );

			// Virtual order, with virtual disabled
			if ( ! $this->enable_for_virtual && ! $needs_shipping ) {
				return false;
			}

			// Only apply if all packages are being shipped via chosen method, or order is virtual.
			if ( ! empty( $this->enable_for_methods ) && $needs_shipping ) {
				$chosen_shipping_methods = array();

				if ( is_object( $order ) ) {
					$chosen_shipping_methods = array_unique( array_map( 'wc_get_string_before_colon', $order->get_shipping_methods() ) );
				} elseif ( $chosen_shipping_methods_session = WC()->session->get( 'chosen_shipping_methods' ) ) {
					$chosen_shipping_methods = array_unique( array_map( 'wc_get_string_before_colon', $chosen_shipping_methods_session ) );
				}

				if ( 0 < count( array_diff( $chosen_shipping_methods, $this->enable_for_methods ) ) ) {
					return false;
				}
			}

			return parent::is_available();
		}


		/**
		 * Process the payment and return the result.
		 *
		 * @param int $order_id
		 * @return array
		 */
		public function process_payment( $order_id ) {
			$order = wc_get_order( $order_id );

			// Mark as processing or on-hold (payment won't be taken until delivery)
			$order->update_status( apply_filters( 'woocommerce_cod_process_payment_order_status', $order->has_downloadable_item() ? 'on-hold' : 'processing', $order ), __( 'Payment to be made upon delivery.', 'woocommerce' ) );

			// Reduce stock levels
			wc_reduce_stock_levels( $order_id );

			// Remove cart
			WC()->cart->empty_cart();

			// Return thankyou redirect
			return array(
				'result' 	=> 'success',
				'redirect'	=> $this->get_return_url( $order ),
			);
		}

		/**
		 * Output for the order received page.
		 */
		public function thankyou_page() {
			if ( $this->instructions ) {
				echo wpautop( wptexturize( $this->instructions ) );
			}
		}

		/**
		 * Change payment complete order status to completed for MPESA orders.
		 *
		 * @since  3.1.0
		 * @param  string $status
		 * @param  int $order_id
		 * @param  WC_Order $order
		 * @return string
		 */
		public function change_payment_complete_order_status( $status, $order_id = 0, $order = false ) {
			if ( $order && 'mpesa' === $order->get_payment_method() ) {
				$status = 'completed';
			}
			return $status;
		}

		/**
		 * Add content to the WC emails.
		 *
		 * @access public
		 * @param WC_Order $order
		 * @param bool $sent_to_admin
		 * @param bool $plain_text
		 */
		public function email_instructions( $order, $sent_to_admin, $plain_text = false ) {
			if ( $this->instructions && ! $sent_to_admin && $this->id === $order->get_payment_method() ) {
				echo wpautop( wptexturize( $this->instructions ) ) . PHP_EOL;
			}
		}
	}
}