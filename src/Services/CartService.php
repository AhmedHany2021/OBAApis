<?php

namespace OBA\APIsIntegration\Services;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use OBA\APIsIntegration\Services\WooCommerceAPIWrapper;

class CartService {
    private $woocommerce_api;

    public function __construct() {
        $this->woocommerce_api = new WooCommerceAPIWrapper();
    }

    /**
     * Helper method to get authenticated user
     */
    private function get_authenticated_user(WP_REST_Request $request) {
        $user = $request->get_param('current_user');
        if (!$user || !isset($user->ID)) {
            return new WP_Error(
                'authentication_required',
                __('Authentication required.', 'oba-apis-integration'),
                ['status' => 401]
            );
        }
        return $user->ID;
    }

    /**
     * Add product to cart using WooCommerce API
     */
    public function add_to_cart(WP_REST_Request $request)
    {
        $user_id = $this->get_authenticated_user($request);
        if (is_wp_error($user_id)) {
            return $user_id;
        }

        $response = $this->woocommerce_api->add_to_cart($request);
        
        if (is_wp_error($response)) {
            return $response;
        }

        $cart_data = $this->woocommerce_api->get_cart_summary();
        
        return new WP_REST_Response([
            'success' => true,
            'message' => __('Product added to cart successfully.', 'oba-apis-integration'),
            'data' => $cart_data->get_data()
        ]);
    }

    /**
     * Remove product from cart using WooCommerce API
     */
    public function remove_from_cart(WP_REST_Request $request)
    {
        $user_id = $this->get_authenticated_user($request);
        if (is_wp_error($user_id)) {
            return $user_id;
        }

        $response = $this->woocommerce_api->remove_from_cart($request);
        
        if (is_wp_error($response)) {
            return $response;
        }

        $cart_data = $this->woocommerce_api->get_cart_summary();
        
        return new WP_REST_Response([
            'success' => true,
            'message' => __('Product removed from cart successfully.', 'oba-apis-integration'),
            'data' => $cart_data->get_data()
        ]);
    }

    /**
     * Get cart summary using WooCommerce API
     */
    public function get_cart_summary(WP_REST_Request $request)
    {
        $user_id = $this->get_authenticated_user($request);
        if (is_wp_error($user_id)) {
            return $user_id;
        }

        $response = $this->woocommerce_api->get_cart_summary();
        
        if (is_wp_error($response)) {
            return $response;
        }

        return new WP_REST_Response([
            'success' => true,
            'data' => $response->get_data()
        ]);
    }
}
