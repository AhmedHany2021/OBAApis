<?php

namespace OBA\APIsIntegration\Core;

use Firebase\JWT\JWT as FirebaseJWT;
use Firebase\JWT\Key;
use WP_Error;
use WP_User;

/**
 * JWT utility class
 *
 * @package OBA\APIsIntegration\Core
 */
class JWT {

	/**
	 * Algorithm for JWT signing
	 *
	 * @var string
	 */
	const ALGORITHM = 'HS256';

	/**
	 * Generate JWT token for user
	 *
	 * @param WP_User $user User object.
	 * @param string  $type Token type (access or refresh).
	 * @return string|WP_Error
	 */
	public static function generate_token( $user, $type = 'access' ) {
		if ( ! $user instanceof WP_User ) {
			return new WP_Error( 'invalid_user', __( 'Invalid user object.', 'oba-apis-integration' ) );
		}

		$secret = Options::get_jwt_secret();
		if ( empty( $secret ) ) {
			return new WP_Error( 'no_secret', __( 'JWT secret not configured.', 'oba-apis-integration' ) );
		}

		$expiration = ( 'refresh' === $type ) 
			? Options::get_jwt_refresh_expiration() 
			: Options::get_jwt_expiration();

		$payload = [
			'iss' => get_site_url(), // Issuer
			'aud' => 'oba-mobile-app', // Audience
			'iat' => time(), // Issued at
			'nbf' => time(), // Not before
			'exp' => time() + $expiration, // Expiration
			'user_id' => $user->ID,
			'user_email' => $user->user_email,
			'user_login' => $user->user_login,
			'user_roles' => $user->roles,
			'token_type' => $type,
		];

		try {
			return FirebaseJWT::encode( $payload, $secret, self::ALGORITHM );
		} catch ( \Exception $e ) {
			return new WP_Error( 'token_generation_failed', $e->getMessage() );
		}
	}

	/**
	 * Validate JWT token
	 *
	 * @param string $token JWT token.
	 * @return array|WP_Error
	 */
	public static function validate_token( $token ) {
		if ( empty( $token ) ) {
			return new WP_Error( 'empty_token', __( 'Token is empty.', 'oba-apis-integration' ) );
		}

		$secret = Options::get_jwt_secret();
		if ( empty( $secret ) ) {
			return new WP_Error( 'no_secret', __( 'JWT secret not configured.', 'oba-apis-integration' ) );
		}

		try {
			$decoded = FirebaseJWT::decode( $token, new Key( $secret, self::ALGORITHM ) );
			$payload = (array) $decoded;

			// Validate required fields
			if ( ! isset( $payload['user_id'] ) || ! isset( $payload['exp'] ) ) {
				return new WP_Error( 'invalid_token_payload', __( 'Invalid token payload.', 'oba-apis-integration' ) );
			}

			// Check if token is expired
			if ( $payload['exp'] < time() ) {
				return new WP_Error( 'token_expired', __( 'Token has expired.', 'oba-apis-integration' ) );
			}

			// Check if user still exists
			$user = get_user_by( 'ID', $payload['user_id'] );
			if ( ! $user ) {
				return new WP_Error( 'user_not_found', __( 'User not found.', 'oba-apis-integration' ) );
			}

			// Check if user is active
			if ( ! $user->has_cap( 'read' ) ) {
				return new WP_Error( 'user_inactive', __( 'User account is inactive.', 'oba-apis-integration' ) );
			}

			return $payload;

		} catch ( \Firebase\JWT\ExpiredException $e ) {
			return new WP_Error( 'token_expired', __( 'Token has expired.', 'oba-apis-integration' ) );
		} catch ( \Firebase\JWT\SignatureInvalidException $e ) {
			return new WP_Error( 'invalid_signature', __( 'Invalid token signature.', 'oba-apis-integration' ) );
		} catch ( \Firebase\JWT\BeforeValidException $e ) {
			return new WP_Error( 'token_not_yet_valid', __( 'Token is not yet valid.', 'oba-apis-integration' ) );
		} catch ( \Exception $e ) {
			return new WP_Error( 'token_validation_failed', $e->getMessage() );
		}
	}

	/**
	 * Get user from token
	 *
	 * @param string $token JWT token.
	 * @return WP_User|WP_Error
	 */
	public static function get_user_from_token( $token ) {
		$payload = self::validate_token( $token );
		
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$user = get_user_by( 'ID', $payload['user_id'] );
		if ( ! $user ) {
			return new WP_Error( 'user_not_found', __( 'User not found.', 'oba-apis-integration' ) );
		}

		return $user;
	}

	/**
	 * Refresh access token using refresh token
	 *
	 * @param string $refresh_token Refresh token.
	 * @return array|WP_Error
	 */
	public static function refresh_access_token( $refresh_token ) {
		$payload = self::validate_token( $refresh_token );
		
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		// Check if this is a refresh token
		if ( ! isset( $payload['token_type'] ) || 'refresh' !== $payload['token_type'] ) {
			return new WP_Error( 'invalid_token_type', __( 'Invalid token type for refresh.', 'oba-apis-integration' ) );
		}

		$user = get_user_by( 'ID', $payload['user_id'] );
		if ( ! $user ) {
			return new WP_Error( 'user_not_found', __( 'User not found.', 'oba-apis-integration' ) );
		}

		// Generate new access token
		$access_token = self::generate_token( $user, 'access' );
		if ( is_wp_error( $access_token ) ) {
			return $access_token;
		}

		// Generate new refresh token
		$new_refresh_token = self::generate_token( $user, 'refresh' );
		if ( is_wp_error( $new_refresh_token ) ) {
			return $new_refresh_token;
		}

		return [
			'access_token' => $access_token,
			'refresh_token' => $new_refresh_token,
			'expires_in' => Options::get_jwt_expiration(),
			'token_type' => 'Bearer',
		];
	}

	/**
	 * Extract token from Authorization header
	 *
	 * @param string $authorization_header Authorization header.
	 * @return string|false
	 */
	public static function extract_token_from_header( $authorization_header ) {
		if ( empty( $authorization_header ) ) {
			return false;
		}

		// Check if it's a Bearer token
		if ( 0 !== strpos( $authorization_header, 'Bearer ' ) ) {
			return false;
		}

		$token = substr( $authorization_header, 7 ); // Remove 'Bearer ' prefix
		
		if ( empty( $token ) ) {
			return false;
		}

		return $token;
	}

	/**
	 * Blacklist a token (for logout)
	 *
	 * @param string $token JWT token.
	 * @return bool
	 */
	public static function blacklist_token( $token ) {
		$payload = self::validate_token( $token );
		
		if ( is_wp_error( $payload ) ) {
			return false;
		}

		// Store token in blacklist with expiration
		$blacklist_key = 'oba_jwt_blacklist_' . md5( $token );
		$expiration = $payload['exp'] - time();
		
		if ( $expiration > 0 ) {
			set_transient( $blacklist_key, true, $expiration );
			return true;
		}

		return false;
	}

	/**
	 * Check if token is blacklisted
	 *
	 * @param string $token JWT token.
	 * @return bool
	 */
	public static function is_token_blacklisted( $token ) {
		$blacklist_key = 'oba_jwt_blacklist_' . md5( $token );
		return (bool) get_transient( $blacklist_key );
	}

	/**
	 * Get token payload without validation
	 *
	 * @param string $token JWT token.
	 * @return array|WP_Error
	 */
	public static function get_token_payload( $token ) {
		$secret = Options::get_jwt_secret();
		if ( empty( $secret ) ) {
			return new WP_Error( 'no_secret', __( 'JWT secret not configured.', 'oba-apis-integration' ) );
		}

		try {
			$decoded = FirebaseJWT::decode( $token, new Key( $secret, self::ALGORITHM ) );
			return (array) $decoded;
		} catch ( \Exception $e ) {
			return new WP_Error( 'token_decode_failed', $e->getMessage() );
		}
	}
} 