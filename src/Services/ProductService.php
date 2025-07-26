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
	private function format_product( $product, $detailed = false ) {
		$product_data = [
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
			'catalog_visibility' => $product->get_catalog_visibility(),
			'stock_status' => $product->get_stock_status(),
			'manage_stock' => $product->get_manage_stock(),
			'stock_quantity' => $product->get_stock_quantity(),
			'backorders' => $product->get_backorders(),
			'sold_individually' => $product->get_sold_individually(),
			'weight' => $product->get_weight(),
			'length' => $product->get_length(),
			'width' => $product->get_width(),
			'height' => $product->get_height(),
			'dimensions' => $product->get_dimensions( false ),
			'average_rating' => $product->get_average_rating(),
			'review_count' => $product->get_review_count(),
			'rating_count' => $product->get_rating_count(),
			'date_created' => $product->get_date_created()->format( 'c' ),
			'date_modified' => $product->get_date_modified()->format( 'c' ),
			'permalink' => get_permalink( $product->get_id() ),
		];

		// Add images
		$images = [];
		$product_images = $product->get_gallery_image_ids();
		array_unshift( $product_images, $product->get_image_id() );

		foreach ( $product_images as $image_id ) {
			if ( $image_id ) {
				$image_url = wp_get_attachment_image_url( $image_id, 'full' );
				$image_thumb = wp_get_attachment_image_url( $image_id, 'thumbnail' );
				$image_medium = wp_get_attachment_image_url( $image_id, 'medium' );

				if ( $image_url ) {
					$images[] = [
						'id' => $image_id,
						'url' => $image_url,
						'thumbnail' => $image_thumb,
						'medium' => $image_medium,
					];
				}
			}
		}
		$product_data['images'] = $images;

		// Add categories
		$categories = [];
		$product_categories = get_the_terms( $product->get_id(), 'product_cat' );
		if ( $product_categories && ! is_wp_error( $product_categories ) ) {
			foreach ( $product_categories as $category ) {
				$categories[] = [
					'id' => $category->term_id,
					'name' => $category->name,
					'slug' => $category->slug,
				];
			}
		}
		$product_data['categories'] = $categories;

		// Add tags
		$tags = [];
		$product_tags = get_the_terms( $product->get_id(), 'product_tag' );
		if ( $product_tags && ! is_wp_error( $product_tags ) ) {
			foreach ( $product_tags as $tag ) {
				$tags[] = [
					'id' => $tag->term_id,
					'name' => $tag->name,
					'slug' => $tag->slug,
				];
			}
		}
		$product_data['tags'] = $tags;

		// Add vendor information (Dokan)
		if ( class_exists( 'WeDevs_Dokan' ) ) {
			$vendor_id = get_post_field( 'post_author', $product->get_id() );
			$vendor = get_user_by( 'ID', $vendor_id );
			if ( $vendor ) {
				$vendor_data = get_user_meta( $vendor_id, 'dokan_profile_settings', true );
				$product_data['vendor'] = [
					'id' => $vendor_id,
					'name' => $vendor->display_name,
					'store_name' => $vendor_data['store_name'] ?? '',
					'store_url' => $vendor_data['store_url'] ?? '',
				];
			}
		}

		if ( $detailed ) {
			// Add variations for variable products
			if ( $product->is_type( 'variable' ) ) {
				$variations = [];
				foreach ( $product->get_children() as $variation_id ) {
					$variation = wc_get_product( $variation_id );
					if ( $variation ) {
						$variations[] = [
							'id' => $variation->get_id(),
							'attributes' => $variation->get_variation_attributes(),
							'price' => $variation->get_price(),
							'regular_price' => $variation->get_regular_price(),
							'sale_price' => $variation->get_sale_price(),
							'stock_status' => $variation->get_stock_status(),
							'stock_quantity' => $variation->get_stock_quantity(),
							'manage_stock' => $variation->get_manage_stock(),
							'backorders' => $variation->get_backorders(),
							'weight' => $variation->get_weight(),
							'length' => $variation->get_length(),
							'width' => $variation->get_width(),
							'height' => $variation->get_height(),
						];
					}
				}
				$product_data['variations'] = $variations;
			}

			// Add attributes
			$attributes = [];
			foreach ( $product->get_attributes() as $attribute ) {
				$attributes[] = [
					'id' => $attribute->get_id(),
					'name' => $attribute->get_name(),
					'position' => $attribute->get_position(),
					'visible' => $attribute->get_visible(),
					'variation' => $attribute->get_variation(),
					'options' => $attribute->get_options(),
				];
			}
			$product_data['attributes'] = $attributes;

			// Add related products
//			$related_products = wc_get_related_product_ids( $product->get_id() );
//			$product_data['related_products'] = $related_products;

			// Add upsell products
			$upsell_products = $product->get_upsell_ids();
			$product_data['upsell_products'] = $upsell_products;

			// Add cross-sell products
			$cross_sell_products = $product->get_cross_sell_ids();
			$product_data['cross_sell_products'] = $cross_sell_products;
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