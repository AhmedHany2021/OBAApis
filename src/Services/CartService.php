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
        // Authenticate user
        $user_id = $this->get_authenticated_user($request);
        if (is_wp_error($user_id)) {
            return $user_id;
        }

        // Request params
        $product_id           = (int) $request->get_param('product_id');
        $quantity             = (int) $request->get_param('quantity') ?: 1;
        $variation_id         = (int) $request->get_param('variation_id');
        $variations           = (array) $request->get_param('variations');
        $purchase_type        = $request->get_param('purchase_type') ? sanitize_text_field($request->get_param('purchase_type')) : 'one-time';
        $subscription_plan_id = sanitize_text_field($request->get_param('subscription_plan_id')); // Example: "1_month_5"

        if (!$product_id) {
            return new \WP_Error(
                'invalid_product',
                __('Product ID is required.', 'oba-apis-integration'),
                ['status' => 400]
            );
        }

        // Get product
        $product = wc_get_product($product_id);
        if (!$product) {
            return new \WP_Error(
                'invalid_product',
                __('Product not found.', 'oba-apis-integration'),
                ['status' => 400]
            );
        }

        // Prepare cart item data
        $cart_item_data = [];

        if ($purchase_type === 'subscription') {
            if ($subscription_plan_id > -1) {
                // Get available subscription schemes for this product
                $schemes = \WCS_ATT_Product_Schemes::get_subscription_schemes($product);

                if (!empty($schemes) && array_key_exists($subscription_plan_id, $schemes)) {
                    // Use the plugin's proper way to set subscription scheme
                    $cart_item_data['wcsatt_data'] = [
                        'active_subscription_scheme' => $subscription_plan_id
                    ];
                } else {
                    return new \WP_Error(
                        'invalid_scheme',
                        __('Invalid subscription plan for this product.', 'oba-apis-integration'),
                        ['status' => 400]
                    );
                }
            }
        }

        // Add product to cart
        $added = WC()->cart->add_to_cart(
            $product_id,
            $quantity,
            $variation_id,
            $variations,
            $cart_item_data
        );

        if (!$added) {
            return new \WP_Error(
                'add_to_cart_failed',
                __('Unable to add product to cart.', 'oba-apis-integration'),
                ['status' => 500]
            );
        }

        // Recalculate totals
        WC()->cart->calculate_totals();

        // Get the cart item key
        $cart_item_key = WC()->cart->generate_cart_id($product_id, $variation_id, $variations, $cart_item_data);

        // Ensure the cart item has the subscription data
        if (isset(WC()->cart->cart_contents[$cart_item_key])) {
            $item = &WC()->cart->cart_contents[$cart_item_key];
            if ($purchase_type === 'subscription' && !empty($subscription_plan_id)) {
                $item['wcsatt_data'] = [
                    'active_subscription_scheme' => $subscription_plan_id
                ];
            }
        }

        return new WP_REST_Response([
            'success' => true,
            'message' => __('Product added to cart successfully.', 'oba-apis-integration'),
            'data'    => $this->get_cart_data_array()
        ]);
    }    /**
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
     * Clear the entire cart
     */
    public function clear_cart(WP_REST_Request $request)
    {
        $user_id = $this->get_authenticated_user($request);
        if (is_wp_error($user_id)) {
            return $user_id;
        }

        WC()->cart->empty_cart();
        WC()->cart->calculate_totals();

        return new WP_REST_Response([
            'success' => true,
            'message' => __('Cart cleared successfully.', 'oba-apis-integration'),
            'data'    => $this->get_cart_data_array()
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

        $cart_item_key = $request->get_param('cart_item_key');
        $quantity = (int) $request->get_param('quantity');

        if (!$cart_item_key || $quantity < 0) {
            return new WP_Error(
                'invalid_parameters',
                __('Cart item key and valid quantity are required.', 'oba-apis-integration'),
                ['status' => 400]
            );
        }

        $updated = WC()->cart->set_quantity($cart_item_key, $quantity, true);

        if (!$updated && $quantity > 0) {
            return new WP_Error(
                'update_quantity_failed',
                __('Unable to update quantity for this item.', 'oba-apis-integration'),
                ['status' => 500]
            );
        }

        WC()->cart->calculate_totals();

        return new WP_REST_Response([
            'success' => true,
            'message' => __('Cart item quantity updated successfully.', 'oba-apis-integration'),
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
            /** @var WC_Product $product */
            $product = $item['data'];

            // Get variation ID if exists
            $variation_id = $item['variation_id'] ?? 0;

            // Get _variation_membership_price
            $membership_price = '';
            if ($variation_id) {
                $membership_price = get_post_meta($variation_id, '_variation_membership_price', true);
            } else {
                $membership_price = get_post_meta($product->get_id(), '_variation_membership_price', true);
            }

            if (is_array($membership_price)) {
                $membership_price = $membership_price[0] ?? '';
            }

            $cart_items[] = [
                'cart_item_key'              => $cart_item_key,
                'product_id'                 => $product->get_id(),
                'name'                       => $product->get_name(),
                'quantity'                   => (int) $item['quantity'],
                'price'                      => (float) $product->get_price(),
                'subtotal'                   => (float) $item['line_subtotal'],
                'total'                      => (float) $item['line_total'],
                'thumbnail'                  => wp_get_attachment_image_url($product->get_image_id(), 'thumbnail'),
                'is_virtual'                 => $product->is_virtual(),
                '_variation_membership_price'=> $membership_price,
            ];
        }

        // Collect applied fees
        $fees = [];
        foreach (WC()->cart->get_fees() as $fee) {
            $fees[] = [
                'name'   => $fee->name,
                'amount' => wc_format_decimal($fee->amount, wc_get_price_decimals()),
                'tax'    => wc_format_decimal($fee->tax, wc_get_price_decimals()),
            ];
        }

        // Collect applied taxes
        $taxes = [];
        foreach (WC()->cart->get_taxes() as $tax_rate_id => $tax_amount) {
            $taxes[] = [
                'rate_id' => $tax_rate_id,
                'amount'  => wc_format_decimal($tax_amount, wc_get_price_decimals()),
            ];
        }

        return [
            'items'      => $cart_items,
            'subtotal'   => WC()->cart->get_subtotal(),
            'total'      => WC()->cart->get_total('edit'),
            'currency'   => get_woocommerce_currency(),
            'item_count' => WC()->cart->get_cart_contents_count(),
            'fees'       => $fees,
            'taxes'      => $taxes,
        ];
    }

}
