<?php

namespace OBA\APIsIntegration\Middleware;

use WP_REST_Request;
use WP_Error;

class WooCommerceAuthMiddleware
{
    /**
     * Handle the request and convert JWT to WooCommerce authentication
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Request|WP_Error
     */
    public function handle(WP_REST_Request $request)
    {
        // Get JWT token from Authorization header
        $auth_header = $request->get_header('Authorization');
        if (empty($auth_header) || strpos($auth_header, 'Bearer ') !== 0) {
            return new WP_Error(
                'invalid_token',
                __('Invalid authorization token.', 'oba-apis-integration'),
                ['status' => 401]
            );
        }

        $jwt_token = str_replace('Bearer ', '', $auth_header);
        
        // Get WooCommerce API credentials from WordPress options
        $consumer_key = get_option('woocommerce_api_consumer_key');
        $consumer_secret = get_option('woocommerce_api_consumer_secret');

        if (empty($consumer_key) || empty($consumer_secret)) {
            return new WP_Error(
                'woocommerce_config_missing',
                __('WooCommerce API credentials not configured.', 'oba-apis-integration'),
                ['status' => 500]
            );
        }

        // Add WooCommerce API credentials to request
        $request->set_header('Authorization', 'Basic ' . base64_encode($consumer_key . ':' . $consumer_secret));
        
        // Add WooCommerce API version
        $request->set_header('X-WC-API-Version', 'wc/v3');
        
        return $request;
    }
}
