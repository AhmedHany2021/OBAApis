<?php

namespace OBA\APIsIntegration\Services;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * Order service
 *
 * @package OBA\APIsIntegration\Services
 */
class OrderService {

	/**
	 * Get user orders
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_orders( $request ) {
		$user = $request->get_attribute( 'current_user' );
		
		if ( ! $user ) {
			return new WP_Error(
				'authentication_required',
				__( 'Authentication required.', 'oba-apis-integration' ),
				[ 'status' => 401 ]
			);
		}

		if ( ! class_exists( 'WC_Order' ) ) {
			return new WP_Error(
				'woocommerce_required',
				__( 'WooCommerce is required for order operations.', 'oba-apis-integration' ),
				[ 'status' => 400 ]
			);
		}

		// Get pagination parameters
		$page = max( 1, absint( $request->get_param( 'page' ) ?: 1 ) );
		$per_page = min( 50, max( 1, absint( $request->get_param( 'per_page' ) ?: 10 ) ) );
		$status = $request->get_param( 'status' );
		$orderby = $request->get_param( 'orderby' ) ?: 'date';
		$order = $request->get_param( 'order' ) ?: 'DESC';

		// Build query args
		$args = [
			'customer_id' => $user->ID,
			'limit' => $per_page,
			'offset' => ( $page - 1 ) * $per_page,
			'orderby' => $orderby,
			'order' => $order,
			'return' => 'objects',
		];

		// Add status filter
		if ( ! empty( $status ) ) {
			$args['status'] = $status;
		}

		// Get orders
		$orders = wc_get_orders( $args );
		$total_orders = wc_get_orders( array_merge( $args, [ 'limit' => -1, 'return' => 'ids' ] ) );
		$total_count = count( $total_orders );

		// Format orders
		$formatted_orders = [];
		foreach ( $orders as $order ) {
			$formatted_orders[] = $this->format_order( $order );
		}

		return new WP_REST_Response( [
			'success' => true,
			'data' => $formatted_orders,
			'pagination' => [
				'page' => $page,
				'per_page' => $per_page,
				'total' => $total_count,
				'total_pages' => ceil( $total_count / $per_page ),
			],
		], 200 );
	}

	/**
	 * Get specific order
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_order( $request ) {
		$user = $request->get_param( 'current_user' );
		$order_id = absint( $request->get_param( 'id' ) );
		
		if ( ! $user ) {
			return new WP_Error(
				'authentication_required',
				__( 'Authentication required.', 'oba-apis-integration' ),
				[ 'status' => 401 ]
			);
		}

		if ( ! class_exists( 'WC_Order' ) ) {
			return new WP_Error(
				'woocommerce_required',
				__( 'WooCommerce is required for order operations.', 'oba-apis-integration' ),
				[ 'status' => 400 ]
			);
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return new WP_Error(
				'order_not_found',
				__( 'Order not found.', 'oba-apis-integration' ),
				[ 'status' => 404 ]
			);
		}

		// Check if user owns this order
		if ( $order->get_customer_id() !== $user->ID && ! $user->has_cap( 'manage_woocommerce' ) ) {
			return new WP_Error(
				'order_access_denied',
				__( 'Access denied. You can only view your own orders.', 'oba-apis-integration' ),
				[ 'status' => 403 ]
			);
		}

		$formatted_order = $this->format_order( $order, true );

		return new WP_REST_Response( [
			'success' => true,
			'data' => $formatted_order,
		], 200 );
	}

	/**
	 * Create new order
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_order( $request ) {
		$user = $request->get_param( 'current_user' );
		
		if ( ! $user ) {
			return new WP_Error(
				'authentication_required',
				__( 'Authentication required.', 'oba-apis-integration' ),
				[ 'status' => 401 ]
			);
		}

		if ( ! class_exists( 'WC_Order' ) ) {
			return new WP_Error(
				'woocommerce_required',
				__( 'WooCommerce is required for order operations.', 'oba-apis-integration' ),
				[ 'status' => 400 ]
			);
		}

		// Get order data
		$order_data = $request->get_json_params();
		if ( empty( $order_data ) ) {
			return new WP_Error(
				'invalid_order_data',
				__( 'Order data is required.', 'oba-apis-integration' ),
				[ 'status' => 400 ]
			);
		}

		// Validate required fields
		$required_fields = [ 'items', 'billing_address' ];
		foreach ( $required_fields as $field ) {
			if ( empty( $order_data[ $field ] ) ) {
				return new WP_Error(
					'missing_required_field',
					sprintf( __( 'Missing required field: %s', 'oba-apis-integration' ), $field ),
					[ 'status' => 400 ]
				);
			}
		}

		try {
			// Create order
			$order = wc_create_order( [
				'customer_id' => $user->ID,
			] );

			// Add items
			foreach ( $order_data['items'] as $item ) {
				$product_id = absint( $item['product_id'] );
				$quantity = absint( $item['quantity'] ?: 1 );
				
				$product = wc_get_product( $product_id );
				if ( ! $product ) {
					$order->delete( true );
					return new WP_Error(
						'invalid_product',
						sprintf( __( 'Invalid product ID: %d', 'oba-apis-integration' ), $product_id ),
						[ 'status' => 400 ]
					);
				}

				$order->add_product( $product, $quantity );
			}

			// Set billing address
			$billing_address = $order_data['billing_address'];
			$order->set_address( $billing_address, 'billing' );

			// Set shipping address if provided
			if ( ! empty( $order_data['shipping_address'] ) ) {
				$order->set_address( $order_data['shipping_address'], 'shipping' );
			}

			// Set payment method if provided
			if ( ! empty( $order_data['payment_method'] ) ) {
				$order->set_payment_method( $order_data['payment_method'] );
			}

			// Set order status
			$status = $order_data['status'] ?? 'pending';
			$order->set_status( $status );

			// Calculate totals
			$order->calculate_totals();

			// Save order
			$order->save();

			$formatted_order = $this->format_order( $order, true );

			return new WP_REST_Response( [
				'success' => true,
				'message' => __( 'Order created successfully.', 'oba-apis-integration' ),
				'data' => $formatted_order,
			], 201 );

		} catch ( \Exception $e ) {
			return new WP_Error(
				'order_creation_failed',
				$e->getMessage(),
				[ 'status' => 500 ]
			);
		}
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
				$product = $item->get_product();
				$items[] = [
					'id' => $item->get_id(),
					'product_id' => $item->get_product_id(),
					'product_name' => $item->get_name(),
					'quantity' => $item->get_quantity(),
					'subtotal' => $item->get_subtotal(),
					'total' => $item->get_total(),
					'tax' => $item->get_total_tax(),
					'product_image' => $product ? wp_get_attachment_image_url( $product->get_image_id(), 'thumbnail' ) : '',
					'product_url' => $product ? get_permalink( $product->get_id() ) : '',
				];
			}
			$order_data['items'] = $items;

			// Add order notes
			$notes = [];
			foreach ( $order->get_customer_order_notes() as $note ) {
				$notes[] = [
					'id' => $note->id,
					'content' => $note->comment_content,
					'date_created' => $note->comment_date,
				];
			}
			$order_data['notes'] = $notes;
		}

		return $order_data;
	}
} 