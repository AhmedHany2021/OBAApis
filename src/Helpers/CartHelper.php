<?php

namespace OBA\APIsIntegration\Helpers;

class CartHelper {
    /**
     * Rebuild WooCommerce cart from custom cart table
     */
    public static function rebuild_cart($user_id) {
        if (!class_exists('WC_Cart')) {
            return false;
        }

        $cart_table = new \OBA\APIsIntegration\Database\CartTable();
        $cart_items = $cart_table->get_cart_items($user_id);

        // Clear existing cart
        WC()->cart->empty_cart();

        foreach ($cart_items as $item) {
            WC()->cart->add_to_cart(
                $item->product_id,
                $item->quantity,
                $item->variation_id,
                maybe_unserialize($item->options)
            );
        }

        return true;
    }

    /**
     * Get cart item key for a specific product/variation
     */
    public static function get_cart_item_key($product_id, $variation_id = null) {
        if (!class_exists('WC_Cart')) {
            return null;
        }

        $cart = WC()->cart;
        $cart_items = $cart->get_cart();

        foreach ($cart_items as $key => $item) {
            if ($item['product_id'] === $product_id && $item['variation_id'] === $variation_id) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Sync cart with WooCommerce session
     */
    public static function sync_with_woocommerce($user_id) {
        if (!class_exists('WC_Cart')) {
            return false;
        }

        // Rebuild cart from our custom table
        self::rebuild_cart($user_id);

        // Save to WooCommerce session
        WC()->session->set('cart', WC()->cart->get_cart());
        
        return true;
    }

    /**
     * Get product price
     */
    public static function get_product_price($product_id, $variation_id = null) {
        $product = wc_get_product($product_id);
        if (!$product) {
            return 0;
        }

        if ($variation_id) {
            $variation = wc_get_product($variation_id);
            if ($variation) {
                return $variation->get_price();
            }
        }

        return $product->get_price();
    }
}
