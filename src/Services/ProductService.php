<?php

namespace OBA\APIsIntegration\Services;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * Product service
 *
 * @package OBA\APIsIntegration\Services
 */
class ProductService {
    /**
	 * Get products
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_products( $request ) {
		if ( ! class_exists( 'WC_Product' ) ) {
			return new WP_Error(
				'woocommerce_required',
				__( 'WooCommerce is required for product operations.', 'oba-apis-integration' ),
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
		$vendor_id = $request->get_param( 'vendor_id' );

		// Build query args
		$args = [
			'post_type' => 'product',
			'post_status' => 'publish',
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

		// Add vendor filter (Dokan)
		if ( ! empty( $vendor_id ) && class_exists( 'WeDevs_Dokan' ) ) {
			$args['author'] = $vendor_id;
		}

		// Get products
		$query = new \WP_Query( $args );
		$products = [];

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$product = wc_get_product( get_the_ID() );
				if ( $product ) {
					$products[] = $this->format_product( $product);
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
	 * Get specific product
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_product( $request ) {
		if ( ! class_exists( 'WC_Product' ) ) {
			return new WP_Error(
				'woocommerce_required',
				__( 'WooCommerce is required for product operations.', 'oba-apis-integration' ),
				[ 'status' => 400 ]
			);
		}
		$product_id = absint( $request->get_param( 'id' ) );
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return new WP_Error(
				'product_not_found',
				__( 'Product not found.', 'oba-apis-integration' ),
				[ 'status' => 404 ]
			);
		}
		$formatted_product = $this->format_product( $product, true );
		return new WP_REST_Response( [
			'success' => true,
			'data' => $formatted_product,
		], 200 );
	}

	/**
	 * Get product categories
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_categories( $request ) {
		if ( ! class_exists( 'WC_Product' ) ) {
			return new WP_Error(
				'woocommerce_required',
				__( 'WooCommerce is required for product operations.', 'oba-apis-integration' ),
				[ 'status' => 400 ]
			);
		}

		$parent_id = $request->get_param( 'parent' );
		$hide_empty = $request->get_param( 'hide_empty' ) !== 'false';

		$args = [
			'taxonomy' => 'product_cat',
			'hide_empty' => $hide_empty,
		];

		if ( ! empty( $parent_id ) ) {
			$args['parent'] = absint( $parent_id );
		}

		$categories = get_terms( $args );
		$formatted_categories = [];

		foreach ( $categories as $category ) {
			$formatted_categories[] = [
				'id' => $category->term_id,
				'name' => $category->name,
				'slug' => $category->slug,
				'description' => $category->description,
				'count' => $category->count,
				'parent_id' => $category->parent,
				'image' => $this->get_category_image( $category->term_id ),
			];
		}

		return new WP_REST_Response( [
			'success' => true,
			'data' => $formatted_categories,
		], 200 );
	}

	/**
	 * Format product for API response
	 *
	 * @param \WC_Product $product Product object.
	 * @param bool        $detailed Whether to include detailed information.
	 * @return array
	 */
	private function format_product( $product ,  $detailed = false ) {
		$product_data = [
			'id' => $product->get_id(),
			'name' => $product->get_name(),
			'slug' => $product->get_slug(),
			'description' => $product->get_description(),
			'short_description' => $product->get_short_description(),
			'regular_price' => $product->get_regular_price(),
			'sale_price' => $product->get_sale_price(),
            'membership_price' => get_post_meta($product->get_id(), '_membership_price', true) ? get_post_meta($product->get_id(), '_membership_price', true) : false,
			'stock_status' => $product->get_stock_status(),
            'required_survey' => get_post_meta($product->get_id(), '_required_survey_id', true) ? get_post_meta($product->get_id(), '_required_survey_id', true) : false,
		];
        $images = [];
		$product_images = $product->get_gallery_image_ids();
		array_unshift( $product_images, $product->get_image_id() );
		foreach ( $product_images as $image_id ) {
			if ( $image_id ) {
				$image_url = wp_get_attachment_image_url( $image_id, 'full' );
				if ( $image_url ) {
					$images[] = [
						'id' => $image_id,
						'url' => $image_url,
					];
				}
			}
		}
		$product_data['images'] = $images;
        $categories = [];
		$product_categories = get_the_terms( $product->get_id(), 'product_cat' );
		if ( $product_categories && ! is_wp_error( $product_categories ) ) {
			foreach ( $product_categories as $category ) {
				$categories[] = [
					'id' => $category->term_id,
					'name' => $category->name,
				];
			}
		}
		$product_data['categories'] = $categories;
        if ( $detailed ) {
            $subscription_plans = get_post_meta($product->get_id(), '_wcsatt_schemes', true) ? get_post_meta($product->get_id(), '_wcsatt_schemes', true) : false;
            $allow_one_time_purchase = get_post_meta($product->get_id(), '_wcsatt_force_subscription', true) == 'no' ? true : false;
            $one_time_purchase_promp = get_post_meta($product->get_id(), '_wcsatt_subscription_prompt', true) ? get_post_meta($product->get_id(), '_wcsatt_subscription_prompt', true) : false;
            $subscription_plans_enabled = $subscription_plans ? true : false;
            $product_data['subscription_plans'] = [
                'enabled' => $subscription_plans_enabled,
                'subscription_plans' => $subscription_plans,
                'allow_one_time_purchase' => $allow_one_time_purchase,
                'one_time_purchase_promp' => $one_time_purchase_promp,
            ];
        }
		return $product_data;
	}

	/**
	 * Get category image
	 *
	 * @param int $category_id Category ID.
	 * @return array|null
	 */
	private function get_category_image( $category_id ) {
		$thumbnail_id = get_term_meta( $category_id, 'thumbnail_id', true );
		if ( $thumbnail_id ) {
			$image_url = wp_get_attachment_image_url( $thumbnail_id, 'full' );
			$image_thumb = wp_get_attachment_image_url( $thumbnail_id, 'thumbnail' );
			
			return [
				'id' => $thumbnail_id,
				'url' => $image_url,
				'thumbnail' => $image_thumb,
			];
		}
		return null;
	}
} 