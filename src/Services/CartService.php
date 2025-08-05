<?php

namespace OBA\APIsIntegration\Services;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use OBA\APIsIntegration\Database\CartTable;

class CartService {
    private $cart_table;

    public function __construct() {
        $this->cart_table = new CartTable();
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
     * Add product to cart
     */
    public function add_to_cart(WP_REST_Request $request)
    {
        $user_id = $this->get_authenticated_user($request);
        if (is_wp_error($user_id)) {
            return $user_id;
        }

        $product_id = absint($request->get_param('product_id'));
        $quantity = absint($request->get_param('quantity', 1));
        $variation_id = absint($request->get_param('variation_id', 0));
        $options = $request->get_param('options', []);

        if (!$product_id) {
            return new WP_Error(
                'product_required',
                __('Product ID is required.', 'oba-apis-integration'),
                ['status' => 400]
            );
        }

        // Check if item already exists
        $existing_item = $this->cart_table->get_cart_items($user_id);
        $item_exists = false;
        foreach ($existing_item as $item) {
            if ($item->product_id === $product_id && $item->variation_id === $variation_id) {
                $item_exists = true;
                $result = $this->cart_table->update_item(
                    $user_id,
                    $product_id,
                    $quantity + $item->quantity,
                    $variation_id,
                    $options
                );
                break;
            }
        }

        // If item doesn't exist, add new
        if (!$item_exists) {
            $result = $this->cart_table->add_item(
                $user_id,
                $product_id,
                $quantity,
                $variation_id,
                $options
            );
        }

        if ($result === false) {
            return new WP_Error(
                'add_to_cart_failed',
                __('Failed to add product to cart.', 'oba-apis-integration'),
                ['status' => 500]
            );
        }

        return new WP_REST_Response([
            'success' => true,
            'message' => __('Product added to cart successfully.', 'oba-apis-integration'),
            'product_id' => $product_id,
            'quantity' => $quantity
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

        $product_id = absint($request->get_param('product_id'));
        $variation_id = absint($request->get_param('variation_id', 0));

        if (!$product_id) {
            return new WP_Error(
                'product_required',
                __('Product ID is required.', 'oba-apis-integration'),
                ['status' => 400]
            );
        }

        $result = $this->cart_table->delete_item(
            $user_id,
            $product_id,
            $variation_id
        );

        if ($result === false) {
            return new WP_Error(
                'remove_from_cart_failed',
                __('Failed to remove product from cart.', 'oba-apis-integration'),
                ['status' => 500]
            );
        }

        return new WP_REST_Response([
            'success' => true,
            'message' => __('Product removed from cart successfully.', 'oba-apis-integration'),
            'product_id' => $product_id
        ]);
    }

    /**
     * Update cart item quantity
     */
    public function update_cart_item(WP_REST_Request $request)
    {
        $user_id = $this->get_authenticated_user($request);
        if (is_wp_error($user_id)) {
            return $user_id;
        }

        $product_id = absint($request->get_param('product_id'));
        $quantity = absint($request->get_param('quantity'));
        $variation_id = absint($request->get_param('variation_id', 0));

        if (!$product_id || $quantity <= 0) {
            return new WP_Error(
                'invalid_parameters',
                __('Product ID and quantity are required.', 'oba-apis-integration'),
                ['status' => 400]
            );
        }

        $result = $this->cart_table->update_item(
            $user_id,
            $product_id,
            $quantity,
            $variation_id
        );

        if ($result === false) {
            return new WP_Error(
                'update_cart_failed',
                __('Failed to update cart item.', 'oba-apis-integration'),
                ['status' => 500]
            );
        }

        return new WP_REST_Response([
            'success' => true,
            'message' => __('Cart item quantity updated successfully.', 'oba-apis-integration'),
            'product_id' => $product_id,
            'quantity' => $quantity
        ]);
    }

    /**
     * Get cart contents
     */
    public function get_cart(WP_REST_Request $request)
    {
        $user_id = $this->get_authenticated_user($request);
        if (is_wp_error($user_id)) {
            return $user_id;
        }

        $cart_items = $this->cart_table->get_cart_items($user_id);
        $formatted_items = [];
        $subtotal = 0;
        $total = 0;

        foreach ($cart_items as $item) {
            $product = wc_get_product($item->product_id);
            $variation = $item->variation_id ? wc_get_product($item->variation_id) : null;
            
            $formatted_items[] = [
                'product_id' => $item->product_id,
                'variation_id' => $item->variation_id,
                'quantity' => $item->quantity,
                'name' => $product ? $product->get_name() : __('Product not found'),
                'price' => $product ? $product->get_price() : 0,
                'subtotal' => $product ? $product->get_price() * $item->quantity : 0,
                'image' => $product ? wp_get_attachment_image_src($product->get_image_id())[0] : '',
                'options' => maybe_unserialize($item->options)
            ];

            if ($product) {
                $subtotal += $product->get_price() * $item->quantity;
                $total += $product->get_price() * $item->quantity;
            }
        }

        return new WP_REST_Response([
            'success' => true,
            'cart' => [
                'items' => $formatted_items,
                'subtotal' => $subtotal,
                'total' => $total,
                'currency' => get_woocommerce_currency(),
                'currency_symbol' => get_woocommerce_currency_symbol()
            ]
        ]);
    }

    /**
     * Clear cart
     */
    public function clear_cart(WP_REST_Request $request)
    {
        $user_id = $this->get_authenticated_user($request);
        if (is_wp_error($user_id)) {
            return $user_id;
        }

        $result = $this->cart_table->clear_cart($user_id);

        if ($result === false) {
            return new WP_Error(
                'clear_cart_failed',
                __('Failed to clear cart.', 'oba-apis-integration'),
                ['status' => 500]
            );
        }

        return new WP_REST_Response([
            'success' => true,
            'message' => __('Cart cleared successfully.', 'oba-apis-integration')
        ]);
    }
}
