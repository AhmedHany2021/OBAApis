<?php

namespace OBA\APIsIntegration\Database;

/**
 * Database migration class
 *
 * @package OBA\APIsIntegration\Database
 */
class Migration {

	/**
	 * Create database tables
	 *
	 * @return void
	 */
	public static function create_tables() {
		// Check if we're in WordPress context
		if ( ! defined( 'ABSPATH' ) || ! function_exists( 'dbDelta' ) ) {
			return;
		}

		global $wpdb;

		// Check if $wpdb is available
		if ( ! $wpdb ) {
			return;
		}

		$charset_collate = $wpdb->get_charset_collate();

		// API logs table
		$table_name = $wpdb->prefix . 'oba_api_logs';
		$sql = "CREATE TABLE $table_name (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			user_id bigint(20) DEFAULT NULL,
			endpoint varchar(255) NOT NULL,
			method varchar(10) NOT NULL,
			ip_address varchar(45) NOT NULL,
			user_agent text,
			request_data longtext,
			response_code int(3) DEFAULT NULL,
			response_time float DEFAULT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY user_id (user_id),
			KEY endpoint (endpoint),
			KEY created_at (created_at)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		// API rate limiting table
		$table_name = $wpdb->prefix . 'oba_rate_limits';
		$sql = "CREATE TABLE $table_name (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			user_id bigint(20) DEFAULT NULL,
			ip_address varchar(45) NOT NULL,
			endpoint varchar(255) NOT NULL,
			request_count int(11) DEFAULT 1,
			window_start datetime NOT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY unique_rate_limit (user_id, ip_address, endpoint, window_start),
			KEY user_id (user_id),
			KEY ip_address (ip_address),
			KEY window_start (window_start)
		) $charset_collate;";

		dbDelta( $sql );

		// JWT blacklist table
		$table_name = $wpdb->prefix . 'oba_jwt_blacklist';
		$sql = "CREATE TABLE $table_name (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			token_hash varchar(64) NOT NULL,
			user_id bigint(20) DEFAULT NULL,
			expires_at datetime NOT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY token_hash (token_hash),
			KEY user_id (user_id),
			KEY expires_at (expires_at)
		) $charset_collate;";

		dbDelta( $sql );

		// API settings table
		$table_name = $wpdb->prefix . 'oba_api_settings';
		$sql = "CREATE TABLE $table_name (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			setting_key varchar(255) NOT NULL,
			setting_value longtext,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY setting_key (setting_key)
		) $charset_collate;";

		dbDelta( $sql );
	}

	/**
	 * Drop database tables
	 *
	 * @return void
	 */
	public static function drop_tables() {
		// Check if we're in WordPress context
		if ( ! defined( 'ABSPATH' ) ) {
			return;
		}

		global $wpdb;

		// Check if $wpdb is available
		if ( ! $wpdb ) {
			return;
		}

		$tables = [
			$wpdb->prefix . 'oba_api_logs',
			$wpdb->prefix . 'oba_rate_limits',
			$wpdb->prefix . 'oba_jwt_blacklist',
			$wpdb->prefix . 'oba_api_settings',
		];

		foreach ( $tables as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS $table" );
		}
	}

	/**
	 * Log API request
	 *
	 * @param array $data Log data.
	 * @return bool
	 */
	public static function log_api_request( $data ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'oba_api_logs';

		$result = $wpdb->insert(
			$table_name,
			[
				'user_id' => $data['user_id'] ?? null,
				'endpoint' => $data['endpoint'],
				'method' => $data['method'],
				'ip_address' => $data['ip_address'],
				'user_agent' => $data['user_agent'] ?? '',
				'request_data' => $data['request_data'] ?? '',
				'response_code' => $data['response_code'] ?? null,
				'response_time' => $data['response_time'] ?? null,
			],
			[
				'%d',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%d',
				'%f',
			]
		);

		return $result !== false;
	}

	/**
	 * Get API logs
	 *
	 * @param array $args Query arguments.
	 * @return array
	 */
	public static function get_api_logs( $args = [] ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'oba_api_logs';

		$defaults = [
			'user_id' => null,
			'endpoint' => null,
			'limit' => 100,
			'offset' => 0,
			'orderby' => 'created_at',
			'order' => 'DESC',
		];

		$args = wp_parse_args( $args, $defaults );

		$where = [];
		$where_values = [];

		if ( $args['user_id'] ) {
			$where[] = 'user_id = %d';
			$where_values[] = $args['user_id'];
		}

		if ( $args['endpoint'] ) {
			$where[] = 'endpoint LIKE %s';
			$where_values[] = '%' . $wpdb->esc_like( $args['endpoint'] ) . '%';
		}

		$where_clause = '';
		if ( ! empty( $where ) ) {
			$where_clause = 'WHERE ' . implode( ' AND ', $where );
		}

		$orderby = sanitize_sql_orderby( $args['orderby'] . ' ' . $args['order'] );

		$sql = $wpdb->prepare(
			"SELECT * FROM $table_name $where_clause ORDER BY $orderby LIMIT %d OFFSET %d",
			array_merge( $where_values, [ $args['limit'], $args['offset'] ] )
		);

		return $wpdb->get_results( $sql );
	}

	/**
	 * Clean old API logs
	 *
	 * @param int $days Number of days to keep.
	 * @return int Number of deleted records.
	 */
	public static function clean_old_logs( $days = 30 ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'oba_api_logs';
		$cutoff_date = date( 'Y-m-d H:i:s', strtotime( "-$days days" ) );

		$result = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM $table_name WHERE created_at < %s",
				$cutoff_date
			)
		);

		return $result;
	}

	/**
	 * Get rate limit data
	 *
	 * @param int    $user_id User ID.
	 * @param string $ip_address IP address.
	 * @param string $endpoint Endpoint.
	 * @param int    $window_minutes Window in minutes.
	 * @return array
	 */
	public static function get_rate_limit_data( $user_id, $ip_address, $endpoint, $window_minutes = 1 ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'oba_rate_limits';
		$window_start = date( 'Y-m-d H:i:s', strtotime( "-$window_minutes minutes" ) );

		$sql = $wpdb->prepare(
			"SELECT * FROM $table_name WHERE user_id = %d AND ip_address = %s AND endpoint = %s AND window_start > %s",
			$user_id,
			$ip_address,
			$endpoint,
			$window_start
		);

		return $wpdb->get_row( $sql );
	}

	/**
	 * Update rate limit data
	 *
	 * @param int    $user_id User ID.
	 * @param string $ip_address IP address.
	 * @param string $endpoint Endpoint.
	 * @param int    $window_minutes Window in minutes.
	 * @return bool
	 */
	public static function update_rate_limit_data( $user_id, $ip_address, $endpoint, $window_minutes = 1 ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'oba_rate_limits';
		$window_start = date( 'Y-m-d H:i:s', strtotime( "-$window_minutes minutes" ) );

		// Try to update existing record
		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE $table_name SET request_count = request_count + 1, updated_at = NOW() 
				WHERE user_id = %d AND ip_address = %s AND endpoint = %s AND window_start > %s",
				$user_id,
				$ip_address,
				$endpoint,
				$window_start
			)
		);

		// If no rows were updated, insert new record
		if ( $result === 0 ) {
			$result = $wpdb->insert(
				$table_name,
				[
					'user_id' => $user_id,
					'ip_address' => $ip_address,
					'endpoint' => $endpoint,
					'request_count' => 1,
					'window_start' => $window_start,
				],
				[
					'%d',
					'%s',
					'%s',
					'%d',
					'%s',
				]
			);
		}

		return $result !== false;
	}

	/**
	 * Clean old rate limit data
	 *
	 * @param int $hours Number of hours to keep.
	 * @return int Number of deleted records.
	 */
	public static function clean_old_rate_limits( $hours = 24 ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'oba_rate_limits';
		$cutoff_date = date( 'Y-m-d H:i:s', strtotime( "-$hours hours" ) );

		$result = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM $table_name WHERE window_start < %s",
				$cutoff_date
			)
		);

		return $result;
	}

	/**
	 * Add token to blacklist
	 *
	 * @param string $token_hash Token hash.
	 * @param int    $user_id User ID.
	 * @param string $expires_at Expiration date.
	 * @return bool
	 */
	public static function blacklist_token( $token_hash, $user_id, $expires_at ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'oba_jwt_blacklist';

		$result = $wpdb->insert(
			$table_name,
			[
				'token_hash' => $token_hash,
				'user_id' => $user_id,
				'expires_at' => $expires_at,
			],
			[
				'%s',
				'%d',
				'%s',
			]
		);

		return $result !== false;
	}

	/**
	 * Check if token is blacklisted
	 *
	 * @param string $token_hash Token hash.
	 * @return bool
	 */
	public static function is_token_blacklisted( $token_hash ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'oba_jwt_blacklist';

		$result = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM $table_name WHERE token_hash = %s AND expires_at > NOW()",
				$token_hash
			)
		);

		return (bool) $result;
	}

	/**
	 * Clean expired blacklisted tokens
	 *
	 * @return int Number of deleted records.
	 */
	public static function clean_expired_blacklist() {
		global $wpdb;

		$table_name = $wpdb->prefix . 'oba_jwt_blacklist';

		$result = $wpdb->query(
			"DELETE FROM $table_name WHERE expires_at < NOW()"
		);

		return $result;
	}
} 