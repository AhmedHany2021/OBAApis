<?php

namespace OBA\APIsIntegration\Core;

/**
 * Options management class
 *
 * @package OBA\APIsIntegration\Core
 */
class Options {

	/**
	 * Option name prefix
	 *
	 * @var string
	 */
	const OPTION_PREFIX = 'oba_apis_integration_';

	/**
	 * Default settings
	 *
	 * @var array
	 */
	private static $defaults = [
		'jwt_secret' => '',
		'jwt_expiration' => 3600, // 1 hour
		'jwt_refresh_expiration' => 604800, // 7 days
		'api_rate_limit' => 100, // requests per minute
		'enable_cors' => true,
		'allowed_origins' => '*',
		'debug_mode' => false,
	];

	/**
	 * Set default options
	 *
	 * @return void
	 */
	public static function set_defaults() {
		foreach ( self::$defaults as $key => $value ) {
			$option_name = self::OPTION_PREFIX . $key;
			if ( false === get_option( $option_name ) ) {
				update_option( $option_name, $value );
			}
		}
	}

	/**
	 * Get option
	 *
	 * @param string $key Option key.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$option_name = self::OPTION_PREFIX . $key;
		$value = get_option( $option_name, $default );

		// If no value found and default is not provided, check our defaults
		if ( null === $value && null === $default && isset( self::$defaults[ $key ] ) ) {
			$value = self::$defaults[ $key ];
		}

		return $value;
	}

	/**
	 * Set option
	 *
	 * @param string $key Option key.
	 * @param mixed  $value Option value.
	 * @return bool
	 */
	public static function set( $key, $value ) {
		$option_name = self::OPTION_PREFIX . $key;
		return update_option( $option_name, $value );
	}

	/**
	 * Delete option
	 *
	 * @param string $key Option key.
	 * @return bool
	 */
	public static function delete( $key ) {
		$option_name = self::OPTION_PREFIX . $key;
		return delete_option( $option_name );
	}

	/**
	 * Get all options
	 *
	 * @return array
	 */
	public static function get_all() {
		$options = [];
		foreach ( array_keys( self::$defaults ) as $key ) {
			$options[ $key ] = self::get( $key );
		}
		return $options;
	}

	/**
	 * Save settings from form
	 *
	 * @param array $data Form data.
	 * @return void
	 */
	public static function save_settings( $data ) {
		// Sanitize and save each setting
		foreach ( self::$defaults as $key => $default_value ) {
			if ( isset( $data[ $key ] ) ) {
				$value = self::sanitize_setting( $key, $data[ $key ] );
				self::set( $key, $value );
			}
		}
	}

	/**
	 * Sanitize setting value
	 *
	 * @param string $key Setting key.
	 * @param mixed  $value Setting value.
	 * @return mixed
	 */
	private static function sanitize_setting( $key, $value ) {
		switch ( $key ) {
			case 'jwt_secret':
				return sanitize_text_field( $value );
			case 'jwt_expiration':
			case 'jwt_refresh_expiration':
			case 'api_rate_limit':
				return absint( $value );
			case 'enable_cors':
				return (bool) $value;
			case 'allowed_origins':
				return sanitize_textarea_field( $value );
			case 'debug_mode':
				return (bool) $value;
			default:
				return sanitize_text_field( $value );
		}
	}

	/**
	 * Delete all options
	 *
	 * @return void
	 */
	public static function delete_all() {
		foreach ( array_keys( self::$defaults ) as $key ) {
			self::delete( $key );
		}
	}

	/**
	 * Get JWT secret
	 *
	 * @return string
	 */
	public static function get_jwt_secret() {
		$secret = self::get( 'jwt_secret' );
		if ( empty( $secret ) ) {
			// Generate a new secret if none exists
			$secret = wp_generate_password( 64, false );
			self::set( 'jwt_secret', $secret );
		}
		return $secret;
	}

	/**
	 * Get JWT expiration time
	 *
	 * @return int
	 */
	public static function get_jwt_expiration() {
		return self::get( 'jwt_expiration' );
	}

	/**
	 * Get JWT refresh expiration time
	 *
	 * @return int
	 */
	public static function get_jwt_refresh_expiration() {
		return self::get( 'jwt_refresh_expiration' );
	}

	/**
	 * Get API rate limit
	 *
	 * @return int
	 */
	public static function get_rate_limit() {
		return self::get( 'api_rate_limit' );
	}

	/**
	 * Check if CORS is enabled
	 *
	 * @return bool
	 */
	public static function is_cors_enabled() {
		return self::get( 'enable_cors' );
	}

	/**
	 * Get allowed origins
	 *
	 * @return string
	 */
	public static function get_allowed_origins() {
		return self::get( 'allowed_origins' );
	}

	/**
	 * Check if debug mode is enabled
	 *
	 * @return bool
	 */
	public static function is_debug_mode() {
		return self::get( 'debug_mode' );
	}
} 