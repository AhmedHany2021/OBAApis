<?php

namespace OBA\APIsIntegration\Services;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

class CartService
{

    public function __construct()
    {
        $this->init_cart();
    }

    private function init_cart()
    {
        if (null === WC()->cart) {
            wc_load_cart();
        }
    }

    /**
     * Helper method to get authenticated user
     */
    private function get_authenticated_user(WP_REST_Request $request)
    {
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
     * Add product to cart
     */
    public function add_to_cart(WP_REST_Request $request)
    {
        $user_id = $this->get_authenticated_user($request);
        if (is_wp_error($user_id)) {
            return $user_id;
        }

        $product_id = (int) $request->get_param('product_id');
        $quantity   = (int) $request->get_param('quantity') ?: 1;
        $variation_id = (int) $request->get_param('variation_id');
        $variations   = (array) $request->get_param('variations');

        if (!$product_id) {
            return new WP_Error(
                'invalid_product',
                __('Product ID is required.', 'oba-apis-integration'),
                ['status' => 400]
            );
        }

        $added = WC()->cart->add_to_cart($product_id, $quantity, $variation_id, $variations);

        if (!$added) {
            return new WP_Error(
                'add_to_cart_failed',
                __('Unable to add product to cart.', 'oba-apis-integration'),
                ['status' => 500]
            );
        }

        WC()->cart->calculate_totals();

        return new WP_REST_Response([
            'success' => true,
            'message' => __('Product added to cart successfully.', 'oba-apis-integration'),
            'data'    => $this->get_cart_data_array()
        ]);
    }

    /**
     * Remove product from cart
     */
    public function remove_from_cart(WP_REST_Request $request)
    {
        $user_id = $this->get_authenticated_user($request);
        if (is_wp_error($user_id)) {
            return $user_id;
        }

        $cart_item_key = $request->get_param('cart_item_key');

        if (!$cart_item_key) {
            return new WP_Error(
                'invalid_cart_item',
                __('Cart item key is required.', 'oba-apis-integration'),
                ['status' => 400]
            );
        }

        $removed = WC()->cart->remove_cart_item($cart_item_key);

        if (!$removed) {
            return new WP_Error(
                'remove_from_cart_failed',
                __('Unable to remove item from cart.', 'oba-apis-integration'),
                ['status' => 500]
            );
        }

        WC()->cart->calculate_totals();

        return new WP_REST_Response([
            'success' => true,
            'message' => __('Product removed from cart successfully.', 'oba-apis-integration'),
            'data'    => $this->get_cart_data_array()
        ]);
    }

    /**
     * Get cart summary
     */
    public function get_cart_summary(WP_REST_Request $request)
    {
        $user_id = $this->get_authenticated_user($request);
        if (is_wp_error($user_id)) {
            return $user_id;
        }

        return new WP_REST_Response([
            'success' => true,
            'data'    => $this->get_cart_data_array()
        ]);
    }

    /**
     * Helper: Format cart data
     */
    private function get_cart_data_array()
    {
        $cart_items = [];

        foreach (WC()->cart->get_cart() as $cart_item_key => $item) {
            $product = $item['data'];
            $cart_items[] = [
                'cart_item_key' => $cart_item_key,
                'product_id'    => $product->get_id(),
                'name'          => $product->get_name(),
                'quantity'      => $item['quantity'],
                'price'         => $product->get_price(),
                'subtotal'      => $item['line_subtotal'],
                'total'         => $item['line_total'],
                'thumbnail'     => wp_get_attachment_image_url($product->get_image_id(), 'thumbnail'),
            ];
        }

        return [
            'items'     => $cart_items,
            'subtotal'  => WC()->cart->get_subtotal(),
            'total'     => WC()->cart->get_total('edit'),
            'currency'  => get_woocommerce_currency(),
            'item_count'=> WC()->cart->get_cart_contents_count(),
        ];
    }
}
