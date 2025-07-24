<?php

namespace OBA\APIsIntegration\Services;

use OBA\APIsIntegration\Core\JWT;
use OBA\APIsIntegration\Core\Options;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use WP_User;

/**
 * Authentication service
 *
 * @package OBA\APIsIntegration\Services
 */
class AuthService {

	/**
	 * Handle user login
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function login( $request ) {
		$email = sanitize_email( $request->get_param( 'email' ) );
		$password = $request->get_param( 'password' );

		// Validate required fields
		if ( empty( $email ) || empty( $password ) ) {
			return new WP_Error(
				'invalid_credentials',
				__( 'Email and password are required.', 'oba-apis-integration' ),
				[ 'status' => 400 ]
			);
		}

		// Validate email format
		if ( ! is_email( $email ) ) {
			return new WP_Error(
				'invalid_email',
				__( 'Invalid email format.', 'oba-apis-integration' ),
				[ 'status' => 400 ]
			);
		}

		// Get user by email
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return new WP_Error(
				'invalid_credentials',
				__( 'Invalid email or password.', 'oba-apis-integration' ),
				[ 'status' => 401 ]
			);
		}

		// Verify password
		if ( ! wp_check_password( $password, $user->user_pass, $user->ID ) ) {
			return new WP_Error(
				'invalid_credentials',
				__( 'Invalid email or password.', 'oba-apis-integration' ),
				[ 'status' => 401 ]
			);
		}

		// Check if user is active
		if ( ! $user->has_cap( 'read' ) ) {
			return new WP_Error(
				'user_inactive',
				__( 'User account is inactive.', 'oba-apis-integration' ),
				[ 'status' => 403 ]
			);
		}

		// Generate tokens
		$access_token = JWT::generate_token( $user, 'access' );
		$refresh_token = JWT::generate_token( $user, 'refresh' );

		if ( is_wp_error( $access_token ) ) {
			return $access_token;
		}

		if ( is_wp_error( $refresh_token ) ) {
			return $refresh_token;
		}

		// Log successful login
		$this->log_login_attempt( $user->ID, true );

		// Return response
		return new WP_REST_Response( [
			'success' => true,
			'message' => __( 'Login successful.', 'oba-apis-integration' ),
			'data' => [
				'access_token' => $access_token,
				'refresh_token' => $refresh_token,
				'expires_in' => Options::get_jwt_expiration(),
				'token_type' => 'Bearer',
				'user' => $this->format_user_data( $user ),
			],
		], 200 );
	}

	/**
	 * Handle user logout
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function logout( $request ) {
		$user = $request->get_param( 'current_user' );
		$token = JWT::extract_token_from_header( $request->get_header( 'Authorization' ) );

		if ( ! $user || ! $token ) {
			return new WP_Error(
				'authentication_required',
				__( 'Authentication required.', 'oba-apis-integration' ),
				[ 'status' => 401 ]
			);
		}

		// Blacklist the token
		JWT::blacklist_token( $token );

		// Log logout
		$this->log_logout( $user->ID );

		return new WP_REST_Response( [
			'success' => true,
			'message' => __( 'Logout successful.', 'oba-apis-integration' ),
		], 200 );
	}

	/**
	 * Handle token refresh
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function refresh_token( $request ) {
		$refresh_token = $request->get_param( 'refresh_token' );

		if ( empty( $refresh_token ) ) {
			return new WP_Error(
				'missing_refresh_token',
				__( 'Refresh token is required.', 'oba-apis-integration' ),
				[ 'status' => 400 ]
			);
		}

		// Refresh the token
		$result = JWT::refresh_access_token( $refresh_token );
		
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( [
			'success' => true,
			'message' => __( 'Token refreshed successfully.', 'oba-apis-integration' ),
			'data' => $result,
		], 200 );
	}

	/**
	 * Format user data for API response
	 *
	 * @param WP_User $user User object.
	 * @return array
	 */
	private function format_user_data( $user ) {
		$user_data = [
			'id' => $user->ID,
			'email' => $user->user_email,
			'username' => $user->user_login,
			'display_name' => $user->display_name,
			'first_name' => $user->first_name,
			'last_name' => $user->last_name,
			'roles' => $user->roles,
			'capabilities' => array_keys( $user->allcaps ),
			'registered_date' => $user->user_registered,
			'last_login' => get_user_meta( $user->ID, 'last_login', true ),
		];

		// Add WooCommerce customer data if available
		if ( class_exists( 'WC_Customer' ) ) {
			$customer = new \WC_Customer( $user->ID );
			if ( $customer->get_id() ) {
				$user_data['woocommerce'] = [
					'customer_id' => $customer->get_id(),
					'billing_address' => [
						'first_name' => $customer->get_billing_first_name(),
						'last_name' => $customer->get_billing_last_name(),
						'company' => $customer->get_billing_company(),
						'address_1' => $customer->get_billing_address_1(),
						'address_2' => $customer->get_billing_address_2(),
						'city' => $customer->get_billing_city(),
						'state' => $customer->get_billing_state(),
						'postcode' => $customer->get_billing_postcode(),
						'country' => $customer->get_billing_country(),
						'email' => $customer->get_billing_email(),
						'phone' => $customer->get_billing_phone(),
					],
					'shipping_address' => [
						'first_name' => $customer->get_shipping_first_name(),
						'last_name' => $customer->get_shipping_last_name(),
						'company' => $customer->get_shipping_company(),
						'address_1' => $customer->get_shipping_address_1(),
						'address_2' => $customer->get_shipping_address_2(),
						'city' => $customer->get_shipping_city(),
						'state' => $customer->get_shipping_state(),
						'postcode' => $customer->get_shipping_postcode(),
						'country' => $customer->get_shipping_country(),
					],
				];
			}
		}

		// Add Dokan vendor data if available
		if ( class_exists( 'WeDevs_Dokan' ) && in_array( 'seller', $user->roles, true ) ) {
			$vendor_data = get_user_meta( $user->ID, 'dokan_profile_settings', true );
			if ( $vendor_data ) {
				$user_data['dokan'] = [
					'store_name' => $vendor_data['store_name'] ?? '',
					'store_url' => $vendor_data['store_url'] ?? '',
					'store_description' => $vendor_data['store_description'] ?? '',
					'store_address' => $vendor_data['address'] ?? [],
					'store_banner' => $vendor_data['banner'] ?? '',
					'store_logo' => $vendor_data['logo'] ?? '',
					'store_phone' => $vendor_data['phone'] ?? '',
					'store_email' => $vendor_data['email'] ?? '',
				];
			}
		}

		// Add Paid Memberships Pro data if available
		if ( class_exists( 'PMPro_Member' ) ) {
			$member = new \PMPro_Member( $user->ID );
			if ( $member->membership_level ) {
				$user_data['membership'] = [
					'level_id' => $member->membership_level->id,
					'level_name' => $member->membership_level->name,
					'level_description' => $member->membership_level->description,
					'start_date' => $member->membership_level->startdate,
					'end_date' => $member->membership_level->enddate,
					'status' => $member->status,
				];
			}
		}

		return $user_data;
	}

	/**
	 * Log login attempt
	 *
	 * @param int  $user_id User ID.
	 * @param bool $success Whether login was successful.
	 * @return void
	 */
	private function log_login_attempt( $user_id, $success ) {
		$log_data = [
			'user_id' => $user_id,
			'success' => $success,
			'timestamp' => current_time( 'mysql' ),
			'ip_address' => $this->get_client_ip(),
			'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
		];

		// Store in user meta for recent attempts
		$recent_attempts = get_user_meta( $user_id, 'oba_login_attempts', true ) ?: [];
		$recent_attempts[] = $log_data;
		
		// Keep only last 10 attempts
		if ( count( $recent_attempts ) > 10 ) {
			$recent_attempts = array_slice( $recent_attempts, -10 );
		}
		
		update_user_meta( $user_id, 'oba_login_attempts', $recent_attempts );

		// Update last login time
		if ( $success ) {
			update_user_meta( $user_id, 'last_login', current_time( 'mysql' ) );
		}
	}

	/**
	 * Log logout
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	private function log_logout( $user_id ) {
		$log_data = [
			'user_id' => $user_id,
			'timestamp' => current_time( 'mysql' ),
			'ip_address' => $this->get_client_ip(),
			'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
		];

		// Store in user meta
		$recent_logouts = get_user_meta( $user_id, 'oba_logout_logs', true ) ?: [];
		$recent_logouts[] = $log_data;
		
		// Keep only last 10 logouts
		if ( count( $recent_logouts ) > 10 ) {
			$recent_logouts = array_slice( $recent_logouts, -10 );
		}
		
		update_user_meta( $user_id, 'oba_logout_logs', $recent_logouts );
	}

	/**
	 * Get client IP address
	 *
	 * @return string
	 */
	private function get_client_ip() {
		$ip_keys = [ 'HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ];
		
		foreach ( $ip_keys as $key ) {
			if ( array_key_exists( $key, $_SERVER ) === true ) {
				foreach ( explode( ',', $_SERVER[ $key ] ) as $ip ) {
					$ip = trim( $ip );
					if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) !== false ) {
						return $ip;
					}
				}
			}
		}

		return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
	}
} 