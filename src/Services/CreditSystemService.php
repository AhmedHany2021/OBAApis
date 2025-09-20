<?php

namespace OBA\APIsIntegration\Services;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

class CreditSystemService
{
    public function get_user_credit (WP_REST_Request $request)
    {
        $user_id = $request->get_param( 'current_user' )->ID;
        $wps_keys = get_option('wps_wsfw_wallet_rest_api_keys');
        if (empty($wps_keys['consumer_key']) || empty($wps_keys['consumer_secret'])) {
            return new WP_Error('no_keys', 'Wallet API keys not set');
        }


        $url = add_query_arg(
            [
                'consumer_key'    => $wps_keys['consumer_key'],
                'consumer_secret' => $wps_keys['consumer_secret'],
            ],
            home_url('/wp-json/wsfw-route/v1/wallet/' . $user_id)
        );

        $response = wp_remote_get($url, ['timeout' => 15]);
        $body = wp_remote_retrieve_body($response);
        $wallet_balance = json_decode($body, true);
        $credit_rate = get_option('wps_wsfw_money_ratio' ,0);
        return new WP_REST_Response( [
            'success' => true,
            'data' => [
                'balance' => $wallet_balance,
                'credit_rate' => $credit_rate,
            ],
        ], 200 );

    }

    public function add_user_credit (WP_REST_Request $request)
    {
        $user_id = $request->get_param( 'current_user' )->ID;
        $recharge_amount = $request->get_param('amount');

        // Validate the recharge amount
        if (empty($recharge_amount) || $recharge_amount <= 0) {
            wp_send_json_error(['message' => 'Invalid recharge amount']);
            return;
        }

        // Get the rechargeable product ID (created by the plugin)
        $rechargeable_product_id = get_option('wps_wsfw_rechargeable_product_id');

        if (!$rechargeable_product_id) {
            wp_send_json_error(['message' => 'Recharge product not found']);
            return;
        }

        // Set session data for wallet recharge
        WC()->session->set(
            'wallet_recharge',
            array(
                'userid'         => $user_id,
                'rechargeamount' => $recharge_amount,
            )
        );
        WC()->session->set('recharge_amount', $recharge_amount);

        // Clear any existing wallet recharge items from cart
        foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
            if (isset($cart_item['wallet_recharge_amount']) || true) {
                WC()->cart->remove_cart_item($cart_item_key);
            }
        }

        // Add the recharge product to cart with custom data
        $cart_item_data = array(
            'wallet_recharge_amount' => $recharge_amount,
            'wallet_recharge_user' => $user_id
        );

        $cart_item_key = WC()->cart->add_to_cart(
            $rechargeable_product_id,
            1, // quantity
            0, // variation_id
            array(), // variation attributes
            $cart_item_data
        );

        if ($cart_item_key) {
            return new WP_REST_Response( [
                'success' => true,
            ], 200 );
        } else {
            return new WP_REST_Response( [
                'success' => false,
            ], 200 );
        }
    }
}