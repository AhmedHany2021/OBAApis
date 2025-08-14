<?php

namespace OBA\APIsIntegration\Services;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

class WooCommerceAPIWrapper
{
    private $base_url;
    private $consumer_key;
    private $consumer_secret;

    public function __construct()
    {
        $this->base_url = get_home_url() . '/wp-json';
        $this->consumer_key = get_option('woocommerce_api_consumer_key');
        $this->consumer_secret = get_option('woocommerce_api_consumer_secret');
    }

    /**
     * Make a request to WooCommerce API
     *
     * @param string $method HTTP method
     * @param string $endpoint API endpoint
     * @param array $data Request data
     * @return WP_REST_Response|WP_Error
     */
    private function make_request($method, $endpoint, $data = [])
    {
        $url = $this->base_url . $endpoint;
        $args = [
            'method' => $method,
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($this->consumer_key . ':' . $this->consumer_secret),
                'Content-Type' => 'application/json',
            ],
        ];

        if (!empty($data)) {
            $args['body'] = json_encode($data);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        $status = wp_remote_retrieve_response_code($response);

        return new WP_REST_Response(json_decode($body, true), $status);
    }

    /**
     * Add item to cart
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function add_to_cart(WP_REST_Request $request)
    {
        $product_id = absint($request->get_param('product_id'));
        $quantity = absint($request->get_param('quantity', 1));
        $variation_id = absint($request->get_param('variation_id', 0));
        $options = $request->get_param('options', []);

        $data = [
            'product_id' => $product_id,
            'quantity' => $quantity,
        ];

        if ($variation_id > 0) {
            $data['variation_id'] = $variation_id;
        }

        if (!empty($options)) {
            $data['options'] = $options;
        }

        return $this->make_request('POST', '/wc/v3/cart/add-item', $data);
    }

    /**
     * Remove item from cart
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function remove_from_cart(WP_REST_Request $request)
    {
        $cart_item_id = absint($request->get_param('cart_item_id'));
        $product_id = absint($request->get_param('product_id'));
        $variation_id = absint($request->get_param('variation_id', 0));

        if ($cart_item_id > 0) {
            return $this->make_request('POST', '/wc/v3/cart/remove-item', [
                'cart_item_id' => $cart_item_id
            ]);
        } elseif ($product_id > 0) {
            $data = [
                'product_id' => $product_id
            ];

            if ($variation_id > 0) {
                $data['variation_id'] = $variation_id;
            }

            return $this->make_request('POST', '/wc/v3/cart/remove-item', $data);
        }

        return new WP_Error(
            'invalid_parameters',
            __('Either cart_item_id or product_id is required.', 'oba-apis-integration'),
            ['status' => 400]
        );
    }

    /**
     * Update cart item
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function update_cart_item(WP_REST_Request $request)
    {
        $cart_item_id = absint($request->get_param('cart_item_id'));
        $quantity = absint($request->get_param('quantity'));

        if ($cart_item_id <= 0 || $quantity <= 0) {
            return new WP_Error(
                'invalid_parameters',
                __('cart_item_id and quantity are required.', 'oba-apis-integration'),
                ['status' => 400]
            );
        }

        return $this->make_request('POST', '/wc/v3/cart/update-item', [
            'cart_item_id' => $cart_item_id,
            'quantity' => $quantity
        ]);
    }

    /**
     * Get cart contents
     *
     * @return WP_REST_Response|WP_Error
     */
    public function get_cart()
    {
        return $this->make_request('GET', '/wc/v3/cart');
    }

    /**
     * Get cart summary
     *
     * @return WP_REST_Response|WP_Error
     */
    public function get_cart_summary()
    {
        $response = $this->get_cart();
        if (is_wp_error($response)) {
            return $response;
        }

        $cart = $response->get_data();
        $summary = [
            'total' => $cart['total'],
            'subtotal' => $cart['subtotal'],
            'total_items' => count($cart['cart_contents']),
            'items' => array_map(function($item) {
                return [
                    'product_id' => $item['product_id'],
                    'variation_id' => $item['variation_id'] ?? null,
                    'quantity' => $item['quantity'],
                    'price' => $item['line_total'],
                    'name' => $item['name'],
                    'options' => $item['options'] ?? [],
                ];
            }, $cart['cart_contents'])
        ];

        return new WP_REST_Response($summary);
    }
}
