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

        if ($quantity <= 0) {
            return new WP_Error(
                'invalid_quantity',
                __('Quantity must be greater than 0.', 'oba-apis-integration'),
                ['status' => 400]
            );
        }

        // Check if product exists
        $product = wc_get_product($product_id);
        if (!$product) {
            return new WP_Error(
                'product_not_found',
                __('Product not found.', 'oba-apis-integration'),
                ['status' => 404]
            );
        }

        // Check if variation exists if specified
        if ($variation_id > 0) {
            $variation = wc_get_product($variation_id);
            if (!$variation || $variation->get_parent_id() != $product_id) {
                return new WP_Error(
                    'invalid_variation',
                    __('Invalid product variation.', 'oba-apis-integration'),
                    ['status' => 400]
                );
            }
        }

        // Check if item already exists
        $existing_item = $this->cart_table->get_cart_item($user_id, $product_id, $variation_id);
        
        if ($existing_item) {
            // Update existing item quantity
            $new_quantity = $existing_item->quantity + $quantity;
            $result = $this->cart_table->update_item(
                $user_id,
                $product_id,
                $new_quantity,
                $variation_id,
                $options
            );
            $message = __('Product quantity updated in cart.', 'oba-apis-integration');
        } else {
            // Add new item
            $result = $this->cart_table->add_item(
                $user_id,
                $product_id,
                $quantity,
                $variation_id,
                $options
            );
            $message = __('Product added to cart successfully.', 'oba-apis-integration');
        }

        if ($result === false) {
            return new WP_Error(
                'add_to_cart_failed',
                __('Failed to add product to cart.', 'oba-apis-integration'),
                ['status' => 500]
            );
        }

        // Get updated cart data
        $cart_data = $this->get_cart_data($user_id);

        return new WP_REST_Response([
            'success' => true,
            'message' => $message,
            'data' => [
                'product_id' => $product_id,
                'variation_id' => $variation_id,
                'quantity' => $quantity,
                'cart' => $cart_data
            ]
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

        // Support both cart_item_id and product_id + variation_id
        $cart_item_id = absint($request->get_param('cart_item_id'));
        $product_id = absint($request->get_param('product_id'));
        $variation_id = absint($request->get_param('variation_id', 0));

        if ($cart_item_id > 0) {
            // Remove by cart item ID
            $cart_item = $this->cart_table->get_cart_item_by_id($cart_item_id);
            if (!$cart_item || $cart_item->user_id != $user_id) {
                return new WP_Error(
                    'cart_item_not_found',
                    __('Cart item not found.', 'oba-apis-integration'),
                    ['status' => 404]
                );
            }
            $result = $this->cart_table->delete_item_by_id($cart_item_id);
        } elseif ($product_id > 0) {
            // Remove by product ID and variation ID
            $result = $this->cart_table->delete_item($user_id, $product_id, $variation_id);
        } else {
            return new WP_Error(
                'invalid_parameters',
                __('Either cart_item_id or product_id is required.', 'oba-apis-integration'),
                ['status' => 400]
            );
        }

        if ($result === false) {
            return new WP_Error(
                'remove_from_cart_failed',
                __('Failed to remove product from cart.', 'oba-apis-integration'),
                ['status' => 500]
            );
        }

        // Get updated cart data
        $cart_data = $this->get_cart_data($user_id);

        return new WP_REST_Response([
            'success' => true,
            'message' => __('Product removed from cart successfully.', 'oba-apis-integration'),
            'data' => [
                'cart' => $cart_data
            ]
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

        $quantity = absint($request->get_param('quantity'));
        $cart_item_id = absint($request->get_param('cart_item_id'));
        $product_id = absint($request->get_param('product_id'));
        $variation_id = absint($request->get_param('variation_id', 0));

        if ($quantity <= 0) {
            return new WP_Error(
                'invalid_quantity',
                __('Quantity must be greater than 0.', 'oba-apis-integration'),
                ['status' => 400]
            );
        }

        if ($cart_item_id > 0) {
            // Update by cart item ID
            $cart_item = $this->cart_table->get_cart_item_by_id($cart_item_id);
            if (!$cart_item || $cart_item->user_id != $user_id) {
                return new WP_Error(
                    'cart_item_not_found',
                    __('Cart item not found.', 'oba-apis-integration'),
                    ['status' => 404]
                );
            }
            $result = $this->cart_table->update_item_by_id($cart_item_id, $quantity);
        } elseif ($product_id > 0) {
            // Update by product ID and variation ID
            $result = $this->cart_table->update_item($user_id, $product_id, $quantity, $variation_id);
        } else {
            return new WP_Error(
                'invalid_parameters',
                __('Either cart_item_id or product_id is required.', 'oba-apis-integration'),
                ['status' => 400]
            );
        }

        if ($result === false) {
            return new WP_Error(
                'update_cart_failed',
                __('Failed to update cart item.', 'oba-apis-integration'),
                ['status' => 500]
            );
        }

        // Get updated cart data
        $cart_data = $this->get_cart_data($user_id);

        return new WP_REST_Response([
            'success' => true,
            'message' => __('Cart item quantity updated successfully.', 'oba-apis-integration'),
            'data' => [
                'quantity' => $quantity,
                'cart' => $cart_data
            ]
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

        $cart_data = $this->get_cart_data($user_id);

        return new WP_REST_Response([
            'success' => true,
            'data' => $cart_data
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
            'message' => __('Cart cleared successfully.'),
            'data' => [
                'cart' => [
                    'items' => [],
                    'subtotal' => 0,
                    'total' => 0,
                    'currency' => get_woocommerce_currency(),
                    'currency_symbol' => get_woocommerce_currency_symbol(),
                    'item_count' => 0
                ]
            ]
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

        $cart_count = $this->cart_table->get_cart_count($user_id);
        $cart_total = $this->cart_table->get_cart_total($user_id);

        return new WP_REST_Response([
            'success' => true,
            'data' => [
                'item_count' => (int) $cart_count,
                'total_quantity' => (int) $cart_total
            ]
        ]);
    }

    /**
     * Helper method to get formatted cart data
     */
    private function get_cart_data($user_id) {
        $cart_items = $this->cart_table->get_cart_items($user_id);
        $formatted_items = [];
        $subtotal = 0;
        $total = 0;

        foreach ($cart_items as $item) {
            $product = wc_get_product($item->product_id);
            $variation = $item->variation_id ? wc_get_product($item->variation_id) : null;
            
            if ($product) {
                $item_price = $variation ? $variation->get_price() : $product->get_price();
                $item_total = $item_price * $item->quantity;
                $subtotal += $item_total;
                $total += $item_total;

                $formatted_items[] = [
                    'id' => $item->id,
                    'product_id' => $item->product_id,
                    'variation_id' => $item->variation_id,
                    'quantity' => $item->quantity,
                    'name' => $product->get_name(),
                    'price' => $item_price,
                    'subtotal' => $item_total,
                    'image' => wp_get_attachment_image_src($product->get_image_id())[0] ?? '',
                    'options' => maybe_unserialize($item->options),
                    'stock_status' => $product->get_stock_status(),
                    'stock_quantity' => $product->get_stock_quantity(),
                    'created_at' => $item->created_at,
                    'updated_at' => $item->updated_at
                ];
            }
        }

        return [
            'items' => $formatted_items,
            'subtotal' => $subtotal,
            'total' => $total,
            'currency' => get_woocommerce_currency(),
            'currency_symbol' => get_woocommerce_currency_symbol(),
            'item_count' => count($formatted_items),
            'total_quantity' => array_sum(array_column($formatted_items, 'quantity'))
        ];
    }
}
