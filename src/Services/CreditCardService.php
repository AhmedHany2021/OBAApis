<?php

namespace OBA\APIsIntegration\Services;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

class CreditCardService
{
    /**
     * Create a new payment method from Stripe payment method ID
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error
     */
    public function create_payment_method(WP_REST_Request $request)
    {
        $user = $request->get_param('current_user');
        
        if (!$user) {
            return new WP_Error(
                'authentication_required',
                __('Authentication required.', 'oba-apis-integration'),
                ['status' => 401]
            );
        }

        $user_id = $user->ID;
        $payment_method_id = sanitize_text_field($request->get_param('payment_method_id'));

        // Validate payment_method_id is provided
        if (empty($payment_method_id)) {
            return new WP_Error(
                'missing_payment_method',
                __('Payment method ID is required.', 'oba-apis-integration'),
                ['status' => 400]
            );
        }

        try {
            // Load Stripe SDK
            if (!class_exists(\Stripe\Stripe::class)) {
                require_once WP_PLUGIN_DIR . '/woocommerce-gateway-stripe/vendor/autoload.php';
            }

            // Get Stripe settings
            $stripe_settings = get_option('woocommerce_stripe_settings', []);
            $is_test_mode = isset($stripe_settings['testmode']) && $stripe_settings['testmode'] === 'yes';

            // Pick the right secret key based on mode
            if ($is_test_mode) {
                $secret_key = $stripe_settings['test_secret_key'] ?? '';
            } else {
                $secret_key = $stripe_settings['secret_key'] ?? '';
            }

            if (empty($secret_key)) {
                return new WP_Error(
                    'stripe_config_error',
                    __('Stripe secret key is missing! Check WooCommerce Stripe settings.', 'oba-apis-integration'),
                    ['status' => 500]
                );
            }

            // Set the Stripe API key
            \Stripe\Stripe::setApiKey($secret_key);

            // Retrieve payment method from Stripe
            $payment_method = \Stripe\PaymentMethod::retrieve($payment_method_id);

            // Validate it's a card
            if ($payment_method->type !== 'card') {
                return new WP_Error(
                    'invalid_payment_type',
                    __('Only card payment methods are supported.', 'oba-apis-integration'),
                    ['status' => 400]
                );
            }

            // Extract card details
            $card = $payment_method->card;
            $card_data = [
                'last4' => $card->last4,
                'card_type' => $card->brand,
                'expiry_month' => str_pad($card->exp_month, 2, '0', STR_PAD_LEFT),
                'expiry_year' => $card->exp_year,
                'fingerprint' => $card->fingerprint
            ];

            global $wpdb;

            // Check for duplicate cards using fingerprint
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT t.token_id 
                FROM {$wpdb->prefix}woocommerce_payment_tokens t
                INNER JOIN {$wpdb->prefix}woocommerce_payment_tokenmeta m 
                    ON t.token_id = m.payment_token_id
                WHERE t.user_id = %d 
                    AND t.gateway_id = 'stripe' 
                    AND m.meta_key = 'fingerprint' 
                    AND m.meta_value = %s",
                $user_id,
                $card_data['fingerprint']
            ));

            if ($existing) {
                return new WP_Error(
                    'duplicate_card',
                    __('This card is already saved.', 'oba-apis-integration'),
                    ['status' => 409]
                );
            }

            // Get is_default parameter from request (optional, defaults to false)
            $is_default_requested = filter_var($request->get_param('is_default'), FILTER_VALIDATE_BOOLEAN);

            // Check if this is the user's first card
            $existing_tokens_count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_payment_tokens 
                WHERE user_id = %d AND gateway_id = 'stripe'",
                $user_id
            ));

            // If it's the first card, always set as default
            // Otherwise, use the requested value
            if ($existing_tokens_count == 0) {
                $is_default = 1;
            } else {
                $is_default = $is_default_requested ? 1 : 0;
            }

            // If setting this card as default, unset all other cards
            if ($is_default == 1 && $existing_tokens_count > 0) {
                $wpdb->update(
                    $wpdb->prefix . 'woocommerce_payment_tokens',
                    ['is_default' => 0],
                    ['user_id' => $user_id, 'gateway_id' => 'stripe'],
                    ['%d'],
                    ['%d', '%s']
                );
            }

            // Insert into woocommerce_payment_tokens table
            $result = $wpdb->insert(
                $wpdb->prefix . 'woocommerce_payment_tokens',
                [
                    'gateway_id' => 'stripe',
                    'token' => $payment_method_id,
                    'user_id' => $user_id,
                    'type' => 'CC',
                    'is_default' => $is_default
                ],
                ['%s', '%s', '%d', '%s', '%d']
            );

            if ($result === false) {
                return new WP_Error(
                    'database_error',
                    __('Failed to save payment method.', 'oba-apis-integration'),
                    ['status' => 500]
                );
            }

            $token_id = $wpdb->insert_id;

            // Insert card metadata
            $metadata = [
                'last4' => $card_data['last4'],
                'card_type' => $card_data['card_type'],
                'expiry_month' => $card_data['expiry_month'],
                'expiry_year' => $card_data['expiry_year'],
                'fingerprint' => $card_data['fingerprint']
            ];

            foreach ($metadata as $meta_key => $meta_value) {
                $wpdb->insert(
                    $wpdb->prefix . 'woocommerce_payment_tokenmeta',
                    [
                        'payment_token_id' => $token_id,
                        'meta_key' => $meta_key,
                        'meta_value' => $meta_value
                    ],
                    ['%d', '%s', '%s']
                );
            }

            return new WP_REST_Response([
                'success' => true,
                'message' => __('Payment method saved successfully.', 'oba-apis-integration'),
                'data' => [
                    'token_id' => $token_id,
                    'payment_method_id' => $payment_method_id,
                    'last4' => $card_data['last4'],
                    'card_type' => $card_data['card_type'],
                    'expiry_month' => $card_data['expiry_month'],
                    'expiry_year' => $card_data['expiry_year'],
                    'is_default' => (bool)$is_default
                ]
            ], 201);

        } catch (\Stripe\Exception\InvalidRequestException $e) {
            return new WP_Error(
                'stripe_error',
                __('Invalid payment method ID.', 'oba-apis-integration') . ' ' . $e->getMessage(),
                ['status' => 400]
            );
        } catch (\Exception $e) {
            return new WP_Error(
                'stripe_error',
                __('Stripe error: ', 'oba-apis-integration') . $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Get all payment methods for the current user
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error
     */
    public function get_user_payment_methods(WP_REST_Request $request)
    {
        $user = $request->get_param('current_user');
        
        if (!$user) {
            return new WP_Error(
                'authentication_required',
                __('Authentication required.', 'oba-apis-integration'),
                ['status' => 401]
            );
        }

        $user_id = $user->ID;
        global $wpdb;

        // Query payment tokens
        $tokens = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}woocommerce_payment_tokens 
            WHERE user_id = %d AND gateway_id = 'stripe' 
            ORDER BY is_default DESC, token_id DESC",
            $user_id
        ), ARRAY_A);

        $payment_methods = [];

        foreach ($tokens as $token) {
            // Get metadata for this token
            $metadata = $wpdb->get_results($wpdb->prepare(
                "SELECT meta_key, meta_value 
                FROM {$wpdb->prefix}woocommerce_payment_tokenmeta 
                WHERE payment_token_id = %d",
                $token['token_id']
            ), ARRAY_A);

            // Format metadata
            $meta = [];
            foreach ($metadata as $meta_row) {
                $meta[$meta_row['meta_key']] = $meta_row['meta_value'];
            }

            $payment_methods[] = [
                'token_id' => $token['token_id'],
                'payment_method_id' => $token['token'],
                'last4' => $meta['last4'] ?? '',
                'card_type' => $meta['card_type'] ?? '',
                'expiry_month' => $meta['expiry_month'] ?? '',
                'expiry_year' => $meta['expiry_year'] ?? '',
                'is_default' => (bool)$token['is_default']
            ];
        }

        return new WP_REST_Response([
            'success' => true,
            'data' => $payment_methods
        ], 200);
    }

    /**
     * Get a specific payment method
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error
     */
    public function get_payment_method(WP_REST_Request $request)
    {
        $user = $request->get_param('current_user');
        
        if (!$user) {
            return new WP_Error(
                'authentication_required',
                __('Authentication required.', 'oba-apis-integration'),
                ['status' => 401]
            );
        }

        $user_id = $user->ID;
        $token_id = absint($request->get_param('id'));

        if (empty($token_id)) {
            return new WP_Error(
                'invalid_token_id',
                __('Invalid payment method ID.', 'oba-apis-integration'),
                ['status' => 400]
            );
        }

        global $wpdb;

        // Get token and verify ownership
        $token = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}woocommerce_payment_tokens 
            WHERE token_id = %d AND gateway_id = 'stripe'",
            $token_id
        ), ARRAY_A);

        if (!$token) {
            return new WP_Error(
                'payment_method_not_found',
                __('Payment method not found.', 'oba-apis-integration'),
                ['status' => 404]
            );
        }

        // Verify ownership
        if ($token['user_id'] != $user_id) {
            return new WP_Error(
                'unauthorized',
                __('You do not have permission to access this payment method.', 'oba-apis-integration'),
                ['status' => 403]
            );
        }

        // Get metadata
        $metadata = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_key, meta_value 
            FROM {$wpdb->prefix}woocommerce_payment_tokenmeta 
            WHERE payment_token_id = %d",
            $token_id
        ), ARRAY_A);

        // Format metadata
        $meta = [];
        foreach ($metadata as $meta_row) {
            $meta[$meta_row['meta_key']] = $meta_row['meta_value'];
        }

        return new WP_REST_Response([
            'success' => true,
            'data' => [
                'token_id' => $token['token_id'],
                'payment_method_id' => $token['token'],
                'last4' => $meta['last4'] ?? '',
                'card_type' => $meta['card_type'] ?? '',
                'expiry_month' => $meta['expiry_month'] ?? '',
                'expiry_year' => $meta['expiry_year'] ?? '',
                'is_default' => (bool)$token['is_default']
            ]
        ], 200);
    }

    /**
     * Update a payment method
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error
     */
    public function update_payment_method(WP_REST_Request $request)
    {
        $user = $request->get_param('current_user');
        
        if (!$user) {
            return new WP_Error(
                'authentication_required',
                __('Authentication required.', 'oba-apis-integration'),
                ['status' => 401]
            );
        }

        $user_id = $user->ID;
        $token_id = absint($request->get_param('id'));

        if (empty($token_id)) {
            return new WP_Error(
                'invalid_token_id',
                __('Invalid payment method ID.', 'oba-apis-integration'),
                ['status' => 400]
            );
        }

        global $wpdb;

        // Get token and verify ownership
        $token = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}woocommerce_payment_tokens 
            WHERE token_id = %d AND gateway_id = 'stripe'",
            $token_id
        ), ARRAY_A);

        if (!$token) {
            return new WP_Error(
                'payment_method_not_found',
                __('Payment method not found.', 'oba-apis-integration'),
                ['status' => 404]
            );
        }

        // Verify ownership
        if ($token['user_id'] != $user_id) {
            return new WP_Error(
                'unauthorized',
                __('You do not have permission to update this payment method.', 'oba-apis-integration'),
                ['status' => 403]
            );
        }

        // Update expiry if provided
        $expiry_month = $request->get_param('expiry_month');
        $expiry_year = $request->get_param('expiry_year');

        if (!empty($expiry_month)) {
            $expiry_month = str_pad($expiry_month, 2, '0', STR_PAD_LEFT);
            $wpdb->update(
                $wpdb->prefix . 'woocommerce_payment_tokenmeta',
                ['meta_value' => $expiry_month],
                ['payment_token_id' => $token_id, 'meta_key' => 'expiry_month'],
                ['%s'],
                ['%d', '%s']
            );
        }

        if (!empty($expiry_year)) {
            $wpdb->update(
                $wpdb->prefix . 'woocommerce_payment_tokenmeta',
                ['meta_value' => $expiry_year],
                ['payment_token_id' => $token_id, 'meta_key' => 'expiry_year'],
                ['%s'],
                ['%d', '%s']
            );
        }

        return new WP_REST_Response([
            'success' => true,
            'message' => __('Payment method updated successfully.', 'oba-apis-integration')
        ], 200);
    }

    /**
     * Delete a payment method
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error
     */
    public function delete_payment_method(WP_REST_Request $request)
    {
        $user = $request->get_param('current_user');
        
        if (!$user) {
            return new WP_Error(
                'authentication_required',
                __('Authentication required.', 'oba-apis-integration'),
                ['status' => 401]
            );
        }

        $user_id = $user->ID;
        $token_id = absint($request->get_param('id'));

        if (empty($token_id)) {
            return new WP_Error(
                'invalid_token_id',
                __('Invalid payment method ID.', 'oba-apis-integration'),
                ['status' => 400]
            );
        }

        global $wpdb;

        // Get token and verify ownership
        $token = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}woocommerce_payment_tokens 
            WHERE token_id = %d AND gateway_id = 'stripe'",
            $token_id
        ), ARRAY_A);

        if (!$token) {
            return new WP_Error(
                'payment_method_not_found',
                __('Payment method not found.', 'oba-apis-integration'),
                ['status' => 404]
            );
        }

        // Verify ownership
        if ($token['user_id'] != $user_id) {
            return new WP_Error(
                'unauthorized',
                __('You do not have permission to delete this payment method.', 'oba-apis-integration'),
                ['status' => 403]
            );
        }

        // Delete metadata first
        $wpdb->delete(
            $wpdb->prefix . 'woocommerce_payment_tokenmeta',
            ['payment_token_id' => $token_id],
            ['%d']
        );

        // Delete token
        $result = $wpdb->delete(
            $wpdb->prefix . 'woocommerce_payment_tokens',
            ['token_id' => $token_id],
            ['%d']
        );

        if ($result === false) {
            return new WP_Error(
                'database_error',
                __('Failed to delete payment method.', 'oba-apis-integration'),
                ['status' => 500]
            );
        }

        // If this was default, set another card as default
        if ($token['is_default'] == 1) {
            $new_default = $wpdb->get_var($wpdb->prepare(
                "SELECT token_id FROM {$wpdb->prefix}woocommerce_payment_tokens 
                WHERE user_id = %d AND gateway_id = 'stripe' 
                ORDER BY token_id DESC LIMIT 1",
                $user_id
            ));

            if ($new_default) {
                $wpdb->update(
                    $wpdb->prefix . 'woocommerce_payment_tokens',
                    ['is_default' => 1],
                    ['token_id' => $new_default],
                    ['%d'],
                    ['%d']
                );
            }
        }

        return new WP_REST_Response([
            'success' => true,
            'message' => __('Payment method deleted successfully.', 'oba-apis-integration')
        ], 200);
    }

    /**
     * Set a payment method as default
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error
     */
    public function set_default_payment_method(WP_REST_Request $request)
    {
        $user = $request->get_param('current_user');
        
        if (!$user) {
            return new WP_Error(
                'authentication_required',
                __('Authentication required.', 'oba-apis-integration'),
                ['status' => 401]
            );
        }

        $user_id = $user->ID;
        $token_id = absint($request->get_param('id'));

        if (empty($token_id)) {
            return new WP_Error(
                'invalid_token_id',
                __('Invalid payment method ID.', 'oba-apis-integration'),
                ['status' => 400]
            );
        }

        global $wpdb;

        // Get token and verify ownership
        $token = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}woocommerce_payment_tokens 
            WHERE token_id = %d AND gateway_id = 'stripe'",
            $token_id
        ), ARRAY_A);

        if (!$token) {
            return new WP_Error(
                'payment_method_not_found',
                __('Payment method not found.', 'oba-apis-integration'),
                ['status' => 404]
            );
        }

        // Verify ownership
        if ($token['user_id'] != $user_id) {
            return new WP_Error(
                'unauthorized',
                __('You do not have permission to modify this payment method.', 'oba-apis-integration'),
                ['status' => 403]
            );
        }

        // Unset all other cards as default for this user
        $wpdb->update(
            $wpdb->prefix . 'woocommerce_payment_tokens',
            ['is_default' => 0],
            ['user_id' => $user_id, 'gateway_id' => 'stripe'],
            ['%d'],
            ['%d', '%s']
        );

        // Set this card as default
        $result = $wpdb->update(
            $wpdb->prefix . 'woocommerce_payment_tokens',
            ['is_default' => 1],
            ['token_id' => $token_id],
            ['%d'],
            ['%d']
        );

        if ($result === false) {
            return new WP_Error(
                'database_error',
                __('Failed to set default payment method.', 'oba-apis-integration'),
                ['status' => 500]
            );
        }

        return new WP_REST_Response([
            'success' => true,
            'message' => __('Default payment method updated successfully.', 'oba-apis-integration')
        ], 200);
    }
}

