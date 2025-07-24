<?php

namespace OBA\APIsIntegration\Services;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * Vendor service
 *
 * @package OBA\APIsIntegration\Services
 */
class VendorService {

	/**
	 * Get vendors
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_vendors( $request ) {
		if ( ! class_exists( 'WeDevs_Dokan' ) ) {
			return new WP_Error(
				'dokan_required',
				__( 'Dokan is required for vendor operations.', 'oba-apis-integration' ),
				[ 'status' => 400 ]
			);
		}

		// Get query parameters
		$page = max( 1, absint( $request->get_param( 'page' ) ?: 1 ) );
		$per_page = min( 50, max( 1, absint( $request->get_param( 'per_page' ) ?: 10 ) ) );
		$search = $request->get_param( 'search' );
		$orderby = $request->get_param( 'orderby' ) ?: 'registered';
		$order = $request->get_param( 'order' ) ?: 'DESC';

		// Build query args
		$args = [
			'role' => 'seller',
			'number' => $per_page,
			'paged' => $page,
			'orderby' => $orderby,
			'order' => $order,
		];

		// Add search filter
		if ( ! empty( $search ) ) {
			$args['search'] = "*{$search}*";
			$args['search_columns'] = [ 'user_login', 'user_email', 'display_name' ];
		}

		// Get vendors
		$vendor_query = new \WP_User_Query( $args );
		$vendors = [];

		if ( $vendor_query->get_results() ) {
			foreach ( $vendor_query->get_results() as $vendor ) {
				$vendors[] = $this->format_vendor( $vendor );
			}
		}

		return new WP_REST_Response( [
			'success' => true,
			'data' => $vendors,
			'pagination' => [
				'page' => $page,
				'per_page' => $per_page,
				'total' => $vendor_query->get_total(),
				'total_pages' => ceil( $vendor_query->get_total() / $per_page ),
			],
		], 200 );
	}

	/**
	 * Get specific vendor
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_vendor( $request ) {
		if ( ! class_exists( 'WeDevs_Dokan' ) ) {
			return new WP_Error(
				'dokan_required',
				__( 'Dokan is required for vendor operations.', 'oba-apis-integration' ),
				[ 'status' => 400 ]
			);
		}

		$vendor_id = absint( $request->get_param( 'id' ) );
		$vendor = get_user_by( 'ID', $vendor_id );

		if ( ! $vendor ) {
			return new WP_Error(
				'vendor_not_found',
				__( 'Vendor not found.', 'oba-apis-integration' ),
				[ 'status' => 404 ]
			);
		}

		// Check if user is a vendor
		if ( ! in_array( 'seller', $vendor->roles, true ) ) {
			return new WP_Error(
				'not_a_vendor',
				__( 'User is not a vendor.', 'oba-apis-integration' ),
				[ 'status' => 400 ]
			);
		}

		$formatted_vendor = $this->format_vendor( $vendor, true );

		return new WP_REST_Response( [
			'success' => true,
			'data' => $formatted_vendor,
		], 200 );
	}

	/**
	 * Get vendor products
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_vendor_products( $request ) {
		if ( ! class_exists( 'WeDevs_Dokan' ) ) {
			return new WP_Error(
				'dokan_required',
				__( 'Dokan is required for vendor operations.', 'oba-apis-integration' ),
				[ 'status' => 400 ]
			);
		}

		if ( ! class_exists( 'WC_Product' ) ) {
			return new WP_Error(
				'woocommerce_required',
				__( 'WooCommerce is required for product operations.', 'oba-apis-integration' ),
				[ 'status' => 400 ]
			);
		}

		$vendor_id = absint( $request->get_param( 'id' ) );
		$vendor = get_user_by( 'ID', $vendor_id );

		if ( ! $vendor ) {
			return new WP_Error(
				'vendor_not_found',
				__( 'Vendor not found.', 'oba-apis-integration' ),
				[ 'status' => 404 ]
			);
		}

		// Check if user is a vendor
		if ( ! in_array( 'seller', $vendor->roles, true ) ) {
			return new WP_Error(
				'not_a_vendor',
				__( 'User is not a vendor.', 'oba-apis-integration' ),
				[ 'status' => 400 ]
			);
		}

		// Get query parameters
		$page = max( 1, absint( $request->get_param( 'page' ) ?: 1 ) );
		$per_page = min( 50, max( 1, absint( $request->get_param( 'per_page' ) ?: 10 ) ) );
		$category = $request->get_param( 'category' );
		$search = $request->get_param( 'search' );
		$orderby = $request->get_param( 'orderby' ) ?: 'date';
		$order = $request->get_param( 'order' ) ?: 'DESC';

		// Build query args
		$args = [
			'post_type' => 'product',
			'post_status' => 'publish',
			'author' => $vendor_id,
			'posts_per_page' => $per_page,
			'paged' => $page,
			'orderby' => $orderby,
			'order' => $order,
		];

		// Add category filter
		if ( ! empty( $category ) ) {
			$args['tax_query'][] = [
				'taxonomy' => 'product_cat',
				'field' => 'slug',
				'terms' => $category,
			];
		}

		// Add search filter
		if ( ! empty( $search ) ) {
			$args['s'] = $search;
		}

		// Get products
		$query = new \WP_Query( $args );
		$products = [];

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$product = wc_get_product( get_the_ID() );
				if ( $product ) {
					$products[] = $this->format_product( $product );
				}
			}
		}

		wp_reset_postdata();

		return new WP_REST_Response( [
			'success' => true,
			'data' => $products,
			'pagination' => [
				'page' => $page,
				'per_page' => $per_page,
				'total' => $query->found_posts,
				'total_pages' => $query->max_num_pages,
			],
		], 200 );
	}

	/**
	 * Format vendor for API response
	 *
	 * @param \WP_User $vendor Vendor user object.
	 * @param bool     $detailed Whether to include detailed information.
	 * @return array
	 */
	private function format_vendor( $vendor, $detailed = false ) {
		$vendor_data = [
			'id' => $vendor->ID,
			'username' => $vendor->user_login,
			'email' => $vendor->user_email,
			'display_name' => $vendor->display_name,
			'first_name' => $vendor->first_name,
			'last_name' => $vendor->last_name,
			'registered_date' => $vendor->user_registered,
			'last_login' => get_user_meta( $vendor->ID, 'last_login', true ),
		];

		// Get Dokan vendor data
		$dokan_data = get_user_meta( $vendor->ID, 'dokan_profile_settings', true );
		if ( $dokan_data ) {
			$vendor_data['store'] = [
				'name' => $dokan_data['store_name'] ?? '',
				'url' => $dokan_data['store_url'] ?? '',
				'description' => $dokan_data['store_description'] ?? '',
				'phone' => $dokan_data['phone'] ?? '',
				'email' => $dokan_data['email'] ?? '',
				'address' => $dokan_data['address'] ?? [],
				'banner' => $dokan_data['banner'] ?? '',
				'logo' => $dokan_data['logo'] ?? '',
				'featured' => isset( $dokan_data['featured'] ) ? (bool) $dokan_data['featured'] : false,
				'store_ppp' => $dokan_data['store_ppp'] ?? 10,
				'store_seo' => $dokan_data['store_seo'] ?? [],
			];
		}

		if ( $detailed ) {
			// Get vendor statistics
			$vendor_data['statistics'] = [
				'products_count' => $this->get_vendor_products_count( $vendor->ID ),
				'orders_count' => $this->get_vendor_orders_count( $vendor->ID ),
				'total_sales' => $this->get_vendor_total_sales( $vendor->ID ),
			];

			// Get vendor categories
			$vendor_data['categories'] = $this->get_vendor_categories( $vendor->ID );
		}

		return $vendor_data;
	}

	/**
	 * Format product for API response
	 *
	 * @param \WC_Product $product Product object.
	 * @return array
	 */
	private function format_product( $product ) {
		return [
			'id' => $product->get_id(),
			'name' => $product->get_name(),
			'slug' => $product->get_slug(),
			'description' => $product->get_description(),
			'short_description' => $product->get_short_description(),
			'price' => $product->get_price(),
			'regular_price' => $product->get_regular_price(),
			'sale_price' => $product->get_sale_price(),
			'price_html' => $product->get_price_html(),
			'type' => $product->get_type(),
			'status' => $product->get_status(),
			'featured' => $product->get_featured(),
			'stock_status' => $product->get_stock_status(),
			'manage_stock' => $product->get_manage_stock(),
			'stock_quantity' => $product->get_stock_quantity(),
			'average_rating' => $product->get_average_rating(),
			'review_count' => $product->get_review_count(),
			'date_created' => $product->get_date_created()->format( 'c' ),
			'date_modified' => $product->get_date_modified()->format( 'c' ),
			'permalink' => get_permalink( $product->get_id() ),
			'image' => $this->get_product_image( $product ),
		];
	}

	/**
	 * Get vendor products count
	 *
	 * @param int $vendor_id Vendor ID.
	 * @return int
	 */
	private function get_vendor_products_count( $vendor_id ) {
		$args = [
			'post_type' => 'product',
			'post_status' => 'publish',
			'author' => $vendor_id,
			'posts_per_page' => -1,
			'fields' => 'ids',
		];

		$query = new \WP_Query( $args );
		return $query->found_posts;
	}

	/**
	 * Get vendor orders count
	 *
	 * @param int $vendor_id Vendor ID.
	 * @return int
	 */
	private function get_vendor_orders_count( $vendor_id ) {
		if ( ! class_exists( 'WC_Order' ) ) {
			return 0;
		}

		// This is a simplified version. In a real implementation,
		// you would need to query orders that contain products from this vendor
		$args = [
			'limit' => -1,
			'return' => 'ids',
		];

		$orders = wc_get_orders( $args );
		$vendor_orders = 0;

		foreach ( $orders as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( $order ) {
				foreach ( $order->get_items() as $item ) {
					$product_id = $item->get_product_id();
					$product_author = get_post_field( 'post_author', $product_id );
					if ( $product_author == $vendor_id ) {
						$vendor_orders++;
						break;
					}
				}
			}
		}

		return $vendor_orders;
	}

	/**
	 * Get vendor total sales
	 *
	 * @param int $vendor_id Vendor ID.
	 * @return float
	 */
	private function get_vendor_total_sales( $vendor_id ) {
		if ( ! class_exists( 'WC_Order' ) ) {
			return 0;
		}

		$args = [
			'limit' => -1,
			'status' => [ 'completed', 'processing' ],
			'return' => 'objects',
		];

		$orders = wc_get_orders( $args );
		$total_sales = 0;

		foreach ( $orders as $order ) {
			foreach ( $order->get_items() as $item ) {
				$product_id = $item->get_product_id();
				$product_author = get_post_field( 'post_author', $product_id );
				if ( $product_author == $vendor_id ) {
					$total_sales += $item->get_total();
				}
			}
		}

		return $total_sales;
	}

	/**
	 * Get vendor categories
	 *
	 * @param int $vendor_id Vendor ID.
	 * @return array
	 */
	private function get_vendor_categories( $vendor_id ) {
		$args = [
			'post_type' => 'product',
			'post_status' => 'publish',
			'author' => $vendor_id,
			'posts_per_page' => -1,
			'fields' => 'ids',
		];

		$query = new \WP_Query( $args );
		$product_ids = $query->posts;

		$categories = [];
		$category_counts = [];

		foreach ( $product_ids as $product_id ) {
			$product_categories = get_the_terms( $product_id, 'product_cat' );
			if ( $product_categories && ! is_wp_error( $product_categories ) ) {
				foreach ( $product_categories as $category ) {
					if ( ! isset( $category_counts[ $category->term_id ] ) ) {
						$category_counts[ $category->term_id ] = 0;
					}
					$category_counts[ $category->term_id ]++;
				}
			}
		}

		foreach ( $category_counts as $category_id => $count ) {
			$category = get_term( $category_id, 'product_cat' );
			if ( $category && ! is_wp_error( $category ) ) {
				$categories[] = [
					'id' => $category->term_id,
					'name' => $category->name,
					'slug' => $category->slug,
					'count' => $count,
				];
			}
		}

		return $categories;
	}

	/**
	 * Get product image
	 *
	 * @param \WC_Product $product Product object.
	 * @return array|null
	 */
	private function get_product_image( $product ) {
		$image_id = $product->get_image_id();
		if ( $image_id ) {
			$image_url = wp_get_attachment_image_url( $image_id, 'full' );
			$image_thumb = wp_get_attachment_image_url( $image_id, 'thumbnail' );
			$image_medium = wp_get_attachment_image_url( $image_id, 'medium' );

			return [
				'id' => $image_id,
				'url' => $image_url,
				'thumbnail' => $image_thumb,
				'medium' => $image_medium,
			];
		}
		return null;
	}
} 