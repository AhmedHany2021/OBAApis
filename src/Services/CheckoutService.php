<?php

namespace OBA\APIsIntegration\Services;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use OBA\APIsIntegration\Database\CartTable;
use OBA\APIsIntegration\Helpers\CartHelper;

/**
 * Checkout service
 *
 * @package OBA\APIsIntegration\Services
 */
class CheckoutService {

	/**
	 * Cart table instance
	 *
	 * @var CartTable
	 */
	private $cart_table;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->cart_table = new CartTable();
	}

	/**
	 * Get checkout data (cart summary, addresses, payment methods)
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_checkout_data( $request ) {
		$user_id = $this->get_authenticated_user( $request );
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		if ( ! class_exists( 'WC_Cart' ) ) {
			return new WP_Error(
				'woocommerce_required',
				__( 'WooCommerce is required for checkout operations.', 'oba-apis-integration' ),
				[ 'status' => 400 ]
			);
		}

		// Get cart items
		$cart_items = $this->cart_table->get_cart_items( $user_id );
		if ( empty( $cart_items ) ) {
			return new WP_Error(
				'empty_cart',
				__( 'Cart is empty. Cannot proceed with checkout.', 'oba-apis-integration' ),
				[ 'status' => 400 ]
			);
		}

		// Calculate totals
		$subtotal = 0;
		$shipping_total = 0;
		$tax_total = 0;
		$total = 0;
		$formatted_items = [];

		foreach ( $cart_items as $item ) {
			$product = wc_get_product( $item->product_id );
			if ( $product ) {
				$item_total = $product->get_price() * $item->quantity;
				$subtotal += $item_total;
				$total += $item_total;

				$formatted_items[] = [
					'product_id' => $item->product_id,
					'variation_id' => $item->variation_id,
					'quantity' => $item->quantity,
					'name' => $product->get_name(),
					'price' => $product->get_price(),
					'subtotal' => $item_total,
					'image' => wp_get_attachment_image_src( $product->get_image_id() )[0] ?? '',
					'options' => maybe_unserialize( $item->options ),
				];
			}
		}

		// Get available payment methods
		$payment_methods = $this->get_available_payment_methods();

		// Get available shipping methods
		$shipping_methods = $this->get_available_shipping_methods();

		// Get user addresses
		$user_addresses = $this->get_user_addresses( $user_id );

		return new WP_REST_Response( [
			'success' => true,
			'data' => [
				'cart' => [
					'items' => $formatted_items,
					'subtotal' => $subtotal,
					'shipping_total' => $shipping_total,
					'tax_total' => $tax_total,
					'total' => $total,
					'currency' => get_woocommerce_currency(),
					'currency_symbol' => get_woocommerce_currency_symbol(),
				],
				'payment_methods' => $payment_methods,
				'shipping_methods' => $shipping_methods,
				'addresses' => $user_addresses,
			],
		] );
	}

	/**
	 * Process checkout and create order
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function process_checkout( $request ) {
		$user_id = $this->get_authenticated_user( $request );
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		if ( ! class_exists( 'WC_Order' ) ) {
			return new WP_Error(
				'woocommerce_required',
				__( 'WooCommerce is required for checkout operations.', 'oba-apis-integration' ),
				[ 'status' => 400 ]
			);
		}

		// Get checkout data
		$checkout_data = $request->get_json_params();
		if ( empty( $checkout_data ) ) {
			return new WP_Error(
				'invalid_checkout_data',
				__( 'Checkout data is required.', 'oba-apis-integration' ),
				[ 'status' => 400 ]
			);
		}

		// Validate required fields
		$required_fields = [ 'billing_address', 'payment_method' ];
		foreach ( $required_fields as $field ) {
			if ( empty( $checkout_data[ $field ] ) ) {
				return new WP_Error(
					'missing_required_field',
					sprintf( __( 'Missing required field: %s', 'oba-apis-integration' ), $field ),
					[ 'status' => 400 ]
				);
			}
		}

		// Validate cart is not empty
		$cart_items = $this->cart_table->get_cart_items( $user_id );
		if ( empty( $cart_items ) ) {
			return new WP_Error(
				'empty_cart',
				__( 'Cart is empty. Cannot proceed with checkout.', 'oba-apis-integration' ),
				[ 'status' => 400 ]
			);
		}

		try {
			// Create order
			$order = wc_create_order( [
				'customer_id' => $user_id,
			] );

			// Add items from cart
			foreach ( $cart_items as $item ) {
				$product = wc_get_product( $item->product_id );
				if ( ! $product ) {
					$order->delete( true );
					return new WP_Error(
						'invalid_product',
						sprintf( __( 'Invalid product ID: %d', 'oba-apis-integration' ), $item->product_id ),
						[ 'status' => 400 ]
					);
				}

				$order->add_product( $product, $item->quantity, [
					'variation_id' => $item->variation_id,
					'options' => maybe_unserialize( $item->options ),
				] );
			}

			// Set billing address
			$billing_address = $checkout_data['billing_address'];
			$order->set_address( $billing_address, 'billing' );

			// Set shipping address if provided
			if ( ! empty( $checkout_data['shipping_address'] ) ) {
				$order->set_address( $checkout_data['shipping_address'], 'shipping' );
			} else {
				// Use billing address as shipping address
				$order->set_address( $billing_address, 'shipping' );
			}

			// Set payment method
			$order->set_payment_method( $checkout_data['payment_method'] );

			// Set shipping method if provided
			if ( ! empty( $checkout_data['shipping_method'] ) ) {
				$order->set_shipping_method( $checkout_data['shipping_method'] );
			}

			// Set order notes if provided
			if ( ! empty( $checkout_data['order_notes'] ) ) {
				$order->add_order_note( sanitize_textarea_field( $checkout_data['order_notes'] ) );
			}

			// Calculate totals
			$order->calculate_totals();

			// Set order status based on payment method
			$payment_method = $checkout_data['payment_method'];
			if ( in_array( $payment_method, [ 'stripe', 'paypal' ], true ) ) {
				$order->set_status( 'pending' );
			} else {
				$order->set_status( 'processing' );
			}

			// Save order
			$order->save();

			// Clear cart after successful order creation
			$this->cart_table->clear_cart( $user_id );

			// Format order response
			$formatted_order = $this->format_order( $order, true );

			return new WP_REST_Response( [
				'success' => true,
				'message' => __( 'Order created successfully.', 'oba-apis-integration' ),
				'data' => $formatted_order,
			], 201 );

		} catch ( \Exception $e ) {
			return new WP_Error(
				'checkout_failed',
				$e->getMessage(),
				[ 'status' => 500 ]
			);
		}
	}

	/**
	 * Validate checkout data
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function validate_checkout( $request ) {
		$user_id = $this->get_authenticated_user( $request );
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$checkout_data = $request->get_json_params();
		if ( empty( $checkout_data ) ) {
			return new WP_Error(
				'invalid_checkout_data',
				__( 'Checkout data is required.', 'oba-apis-integration' ),
				[ 'status' => 400 ]
			);
		}

		$errors = [];

		// Validate billing address
		if ( ! empty( $checkout_data['billing_address'] ) ) {
			$billing_errors = $this->validate_address( $checkout_data['billing_address'], 'billing' );
			if ( ! empty( $billing_errors ) ) {
				$errors['billing_address'] = $billing_errors;
			}
		}

		// Validate shipping address
		if ( ! empty( $checkout_data['shipping_address'] ) ) {
			$shipping_errors = $this->validate_address( $checkout_data['shipping_address'], 'shipping' );
			if ( ! empty( $shipping_errors ) ) {
				$errors['shipping_address'] = $shipping_errors;
			}
		}

		// Validate payment method
		if ( ! empty( $checkout_data['payment_method'] ) ) {
			$payment_methods = $this->get_available_payment_methods();
			if ( ! in_array( $checkout_data['payment_method'], array_keys( $payment_methods ), true ) ) {
				$errors['payment_method'] = __( 'Invalid payment method.', 'oba-apis-integration' );
			}
		}

		// Validate cart
		$cart_items = $this->cart_table->get_cart_items( $user_id );
		if ( empty( $cart_items ) ) {
			$errors['cart'] = __( 'Cart is empty.', 'oba-apis-integration' );
		}

		if ( ! empty( $errors ) ) {
			return new WP_REST_Response( [
				'success' => false,
				'errors' => $errors,
			], 400 );
		}

		return new WP_REST_Response( [
			'success' => true,
			'message' => __( 'Checkout data is valid.', 'oba-apis-integration' ),
		] );
	}

	/**
	 * Get available payment methods
	 *
	 * @return array
	 */
	private function get_available_payment_methods() {
		$payment_methods = [];

		// Check if WooCommerce payment gateways are available
		if ( class_exists( 'WC_Payment_Gateways' ) ) {
			$gateways = WC()->payment_gateways()->payment_gateways();
			foreach ( $gateways as $gateway ) {
				if ( $gateway->enabled === 'yes' ) {
					$payment_methods[ $gateway->id ] = [
						'id' => $gateway->id,
						'title' => $gateway->title,
						'description' => $gateway->description,
						'method_title' => $gateway->method_title,
					];
				}
			}
		}

		// Add default payment methods if none found
		if ( empty( $payment_methods ) ) {
			$payment_methods = [
				'stripe' => [
					'id' => 'stripe',
					'title' => 'Credit Card (Stripe)',
					'description' => 'Pay securely with your credit card.',
					'method_title' => 'Stripe',
				],
				'paypal' => [
					'id' => 'paypal',
					'title' => 'PayPal',
					'description' => 'Pay with your PayPal account.',
					'method_title' => 'PayPal',
				],
				'cod' => [
					'id' => 'cod',
					'title' => 'Cash on Delivery',
					'description' => 'Pay when you receive your order.',
					'method_title' => 'Cash on Delivery',
				],
			];
		}

		return $payment_methods;
	}

	/**
	 * Get available shipping methods
	 *
	 * @return array
	 */
	private function get_available_shipping_methods() {
		$shipping_methods = [];

		// Check if WooCommerce shipping is available
		if ( class_exists( 'WC_Shipping' ) ) {
			$shipping = WC()->shipping();
			$methods = $shipping->get_shipping_methods();

			foreach ( $methods as $method ) {
				if ( $method->enabled === 'yes' ) {
					$shipping_methods[ $method->id ] = [
						'id' => $method->id,
						'title' => $method->title,
						'description' => $method->description,
						'method_title' => $method->method_title,
					];
				}
			}
		}

		// Add default shipping methods if none found
		if ( empty( $shipping_methods ) ) {
			$shipping_methods = [
				'flat_rate' => [
					'id' => 'flat_rate',
					'title' => 'Flat Rate',
					'description' => 'Fixed shipping cost.',
					'method_title' => 'Flat Rate',
				],
				'free_shipping' => [
					'id' => 'free_shipping',
					'title' => 'Free Shipping',
					'description' => 'Free shipping for orders over a certain amount.',
					'method_title' => 'Free Shipping',
				],
			];
		}

		return $shipping_methods;
	}

	/**
	 * Get user addresses
	 *
	 * @param int $user_id User ID.
	 * @return array
	 */
	private function get_user_addresses( $user_id ) {
		$addresses = [];

		if ( class_exists( 'WC_Customer' ) ) {
			$customer = new \WC_Customer( $user_id );

			$addresses['billing'] = [
				'first_name' => $customer->get_billing_first_name(),
				'last_name' => $customer->get_billing_last_name(),
				'company' => $customer->get_billing_company(),
				'address_1' => $customer->get_billing_address_1(),
				'address_2' => $customer->get_billing_address_2(),
				'city' => $customer->get_billing_city(),
				'state' => $customer->get_billing_state(),
				'postcode' => $customer->get_billing_postcode(),
				'country' => $customer->get_billing_country(),
				'email' => $customer->get_billing_email(),
				'phone' => $customer->get_billing_phone(),
			];

			$addresses['shipping'] = [
				'first_name' => $customer->get_shipping_first_name(),
				'last_name' => $customer->get_shipping_last_name(),
				'company' => $customer->get_shipping_company(),
				'address_1' => $customer->get_shipping_address_1(),
				'address_2' => $customer->get_shipping_address_2(),
				'city' => $customer->get_shipping_city(),
				'state' => $customer->get_shipping_state(),
				'postcode' => $customer->get_shipping_postcode(),
				'country' => $customer->get_shipping_country(),
			];
		}

		return $addresses;
	}

	/**
	 * Validate address
	 *
	 * @param array  $address Address data.
	 * @param string $type Address type (billing or shipping).
	 * @return array
	 */
	private function validate_address( $address, $type ) {
		$errors = [];
		$required_fields = [];

		if ( 'billing' === $type ) {
			$required_fields = [ 'first_name', 'last_name', 'address_1', 'city', 'state', 'postcode', 'country', 'email' ];
		} else {
			$required_fields = [ 'first_name', 'last_name', 'address_1', 'city', 'state', 'postcode', 'country' ];
		}

		foreach ( $required_fields as $field ) {
			if ( empty( $address[ $field ] ) ) {
				$errors[ $field ] = sprintf( __( '%s is required.', 'oba-apis-integration' ), ucfirst( str_replace( '_', ' ', $field ) ) );
			}
		}

		// Validate email format for billing
		if ( 'billing' === $type && ! empty( $address['email'] ) && ! is_email( $address['email'] ) ) {
			$errors['email'] = __( 'Invalid email format.', 'oba-apis-integration' );
		}

		// Validate phone format for billing
		if ( 'billing' === $type && ! empty( $address['phone'] ) && ! preg_match( '/^[\+]?[1-9][\d]{0,15}$/', $address['phone'] ) ) {
			$errors['phone'] = __( 'Invalid phone number format.', 'oba-apis-integration' );
		}

		return $errors;
	}

	/**
	 * Format order for API response
	 *
	 * @param \WC_Order $order Order object.
	 * @param bool      $detailed Whether to include detailed information.
	 * @return array
	 */
	private function format_order( $order, $detailed = false ) {
		$order_data = [
			'id' => $order->get_id(),
			'number' => $order->get_order_number(),
			'status' => $order->get_status(),
			'date_created' => $order->get_date_created()->format( 'c' ),
			'date_modified' => $order->get_date_modified()->format( 'c' ),
			'total' => $order->get_total(),
			'currency' => $order->get_currency(),
			'customer_id' => $order->get_customer_id(),
			'billing_address' => [
				'first_name' => $order->get_billing_first_name(),
				'last_name' => $order->get_billing_last_name(),
				'company' => $order->get_billing_company(),
				'address_1' => $order->get_billing_address_1(),
				'address_2' => $order->get_billing_address_2(),
				'city' => $order->get_billing_city(),
				'state' => $order->get_billing_state(),
				'postcode' => $order->get_billing_postcode(),
				'country' => $order->get_billing_country(),
				'email' => $order->get_billing_email(),
				'phone' => $order->get_billing_phone(),
			],
			'shipping_address' => [
				'first_name' => $order->get_shipping_first_name(),
				'last_name' => $order->get_shipping_last_name(),
				'company' => $order->get_shipping_company(),
				'address_1' => $order->get_shipping_address_1(),
				'address_2' => $order->get_shipping_address_2(),
				'city' => $order->get_shipping_city(),
				'state' => $order->get_shipping_state(),
				'postcode' => $order->get_shipping_postcode(),
				'country' => $order->get_shipping_country(),
			],
			'payment_method' => $order->get_payment_method(),
			'payment_method_title' => $order->get_payment_method_title(),
			'shipping_method' => $order->get_shipping_method(),
			'subtotal' => $order->get_subtotal(),
			'shipping_total' => $order->get_shipping_total(),
			'tax_total' => $order->get_total_tax(),
			'discount_total' => $order->get_total_discount(),
		];

		if ( $detailed ) {
			// Add line items
			$items = [];
			foreach ( $order->get_items() as $item ) {
				$items[] = [
					'product_id' => $item->get_product_id(),
					'variation_id' => $item->get_variation_id(),
					'name' => $item->get_name(),
					'quantity' => $item->get_quantity(),
					'subtotal' => $item->get_subtotal(),
					'total' => $item->get_total(),
				];
			}
			$order_data['items'] = $items;
		}

		return $order_data;
	}

	/**
	 * Get authenticated user from request
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return int|WP_Error
	 */
	private function get_authenticated_user( $request ) {
		$user = $request->get_param( 'current_user' );
		
		if ( ! $user ) {
			return new WP_Error(
				'authentication_required',
				__( 'Authentication required.', 'oba-apis-integration' ),
				[ 'status' => 401 ]
			);
		}

		return $user->ID;
	}
} 