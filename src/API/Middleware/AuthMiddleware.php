<?php

namespace OBA\APIsIntegration\API\Middleware;

use OBA\APIsIntegration\Core\JWT;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * Authentication middleware
 *
 * @package OBA\APIsIntegration\API\Middleware
 */
class AuthMiddleware {

	/**
	 * Handle middleware
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error|null
	 */
	public function handle( $request ) {
		// Get Authorization header
		$authorization_header = $request->get_header( 'Authorization' );
		
		if ( empty( $authorization_header ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Authentication required.', 'oba-apis-integration' ),
				[ 'status' => 401 ]
			);
		}

		// Extract token from header
		$token = JWT::extract_token_from_header( $authorization_header );
		if ( ! $token ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Invalid authorization header format.', 'oba-apis-integration' ),
				[ 'status' => 401 ]
			);
		}

		// Check if token is blacklisted
		if ( JWT::is_token_blacklisted( $token ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Token has been invalidated.', 'oba-apis-integration' ),
				[ 'status' => 401 ]
			);
		}

		// Validate token
		$payload = JWT::validate_token( $token );
		if ( is_wp_error( $payload ) ) {
			return new WP_Error(
				'rest_forbidden',
				$payload->get_error_message(),
				[ 'status' => 401 ]
			);
		}

		// Get user from token
		$user = JWT::get_user_from_token( $token );
		if ( is_wp_error( $user ) ) {
			return new WP_Error(
				'rest_forbidden',
				$user->get_error_message(),
				[ 'status' => 401 ]
			);
		}

		// Set current user
		wp_set_current_user( $user->ID );
        wp_set_auth_cookie($user->ID);

		// Add user data to request for use in controllers
		$request->set_param( 'current_user', $user );
		$request->set_param( 'jwt_payload', $payload );

		// Check rate limiting
		$rate_limit_result = $this->check_rate_limit( $user->ID );
		if ( is_wp_error( $rate_limit_result ) ) {
			return $rate_limit_result;
		}

		return null; // Continue to next middleware/controller
	}

	/**
	 * Check rate limiting for user
	 *
	 * @param int $user_id User ID.
	 * @return bool|WP_Error
	 */
	private function check_rate_limit( $user_id ) {
		$rate_limit = \OBA\APIsIntegration\Core\Options::get_rate_limit();
		if ( $rate_limit <= 0 ) {
			return true; // No rate limiting
		}

		$current_time = time();
		$window_start = $current_time - 60; // 1 minute window
		
		$rate_limit_key = "oba_rate_limit_{$user_id}";
		$requests = get_transient( $rate_limit_key ) ?: [];

		// Remove old requests outside the window
		$requests = array_filter( $requests, function ( $timestamp ) use ( $window_start ) {
			return $timestamp >= $window_start;
		} );

		// Check if user has exceeded rate limit
		if ( count( $requests ) >= $rate_limit ) {
			return new WP_Error(
				'rest_too_many_requests',
				__( 'Rate limit exceeded. Please try again later.', 'oba-apis-integration' ),
				[ 'status' => 429 ]
			);
		}

		// Add current request
		$requests[] = $current_time;
		set_transient( $rate_limit_key, $requests, 60 );

		return true;
	}

	/**
	 * Check if user has required capability
	 *
	 * @param WP_REST_Request $request Request object.
	 * @param string          $capability Required capability.
	 * @return bool|WP_Error
	 */
	public static function check_capability( $request, $capability ) {
		$user = $request->get_param( 'current_user' );
		
		if ( ! $user || ! $user->has_cap( $capability ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Insufficient permissions.', 'oba-apis-integration' ),
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	/**
	 * Check if user has required role
	 *
	 * @param WP_REST_Request $request Request object.
	 * @param string          $role Required role.
	 * @return bool|WP_Error
	 */
	public static function check_role( $request, $role ) {
		$user = $request->get_param( 'current_user' );
		
		if ( ! $user || ! in_array( $role, $user->roles, true ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Insufficient permissions.', 'oba-apis-integration' ),
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	/**
	 * Check if user is the owner of the resource
	 *
	 * @param WP_REST_Request $request Request object.
	 * @param int             $resource_user_id Resource owner user ID.
	 * @return bool|WP_Error
	 */
	public static function check_ownership( $request, $resource_user_id ) {
		$user = $request->get_param( 'current_user' );
		
		if ( ! $user ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Authentication required.', 'oba-apis-integration' ),
				[ 'status' => 401 ]
			);
		}

		// Allow if user is the owner or has admin capabilities
		if ( $user->ID === $resource_user_id || $user->has_cap( 'manage_options' ) ) {
			return true;
		}

		return new WP_Error(
			'rest_forbidden',
			__( 'Access denied. You can only access your own resources.', 'oba-apis-integration' ),
			[ 'status' => 403 ]
		);
	}
} 