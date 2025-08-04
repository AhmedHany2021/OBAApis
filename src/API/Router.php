<?php

namespace OBA\APIsIntegration\API;

use OBA\APIsIntegration\Core\Options;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * API Router class
 *
 * @package OBA\APIsIntegration\API
 */
class Router {

	/**
	 * API namespace
	 *
	 * @var string
	 */
	const API_NAMESPACE = 'oba/v1';

	/**
	 * Registered routes
	 *
	 * @var array
	 */
	private $routes = [];

	/**
	 * Constructor
	 */
	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
		add_action( 'rest_api_init', [ $this, 'add_cors_headers' ] );
	}

	/**
	 * Register a route
	 *
	 * @param string   $route Route path.
	 * @param string   $method HTTP method.
	 * @param callable $callback Route callback.
	 * @param array    $middleware Middleware classes.
	 * @return void
	 */
	public function register_route( $route, $method, $callback, $middleware = [] ) {
		$this->routes[] = [
			'route' => $route,
			'method' => $method,
			'callback' => $callback,
			'middleware' => $middleware,
		];
	}

	/**
	 * Register all routes with WordPress REST API
	 *
	 * @return void
	 */
	public function register_routes() {
		foreach ( $this->routes as $route_data ) {
			register_rest_route(
				self::API_NAMESPACE,
				'/' . $route_data['route'],
				[
					[
						'methods' => $route_data['method'],
						'callback' => [ $this, 'handle_request' ],
						'permission_callback' => '__return_true',
						'args' => $this->get_route_args( $route_data['route'] ),
					],
				]
			);
		}
	}

	/**
	 * Handle API request
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_request( $request ) {
		$route = $this->get_route_from_request( $request );
		$method = $request->get_method();
		
		// Find matching route
		$route_data = $this->find_route( $route, $method );
		if ( ! $route_data ) {
			return new WP_Error( 'route_not_found', __( 'Route not found.', 'oba-apis-integration' ), [ 'status' => 404 ] );
		}

		// Apply middleware
		$middleware_result = $this->apply_middleware( $route_data['middleware'], $request );
		if ( is_wp_error( $middleware_result ) ) {
			return $middleware_result;
		}

		// Execute callback
		try {
			$response = call_user_func( $route_data['callback'], $request );
			
			// Ensure response is properly formatted
			if ( is_array( $response ) ) {
				$response = new WP_REST_Response( $response );
			} elseif ( ! $response instanceof WP_REST_Response && ! is_wp_error( $response ) ) {
				$response = new WP_REST_Response( [ 'data' => $response ] );
			}

			return $response;

		} catch ( \Exception $e ) {
			if ( Options::is_debug_mode() ) {
				return new WP_Error( 'internal_error', $e->getMessage(), [ 'status' => 500 ] );
			} else {
				return new WP_Error( 'internal_error', __( 'Internal server error.', 'oba-apis-integration' ), [ 'status' => 500 ] );
			}
		}
	}

	/**
	 * Apply middleware to request
	 *
	 * @param array         $middleware_classes Middleware classes.
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error|null
	 */
	private function apply_middleware( $middleware_classes, $request ) {
		foreach ( $middleware_classes as $middleware_class ) {
			if ( class_exists( $middleware_class ) ) {
				$middleware = new $middleware_class();
				if ( method_exists( $middleware, 'handle' ) ) {
					$result = $middleware->handle( $request );
					if ( $result instanceof WP_REST_Response || is_wp_error( $result ) ) {
						return $result;
					}
				}
			}
		}
		return null;
	}

	/**
	 * Get route from request
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return string
	 */
	private function get_route_from_request( $request ) {
		$route = $request->get_route();
		
		// Remove the namespace prefix
		$namespace = '/' . self::API_NAMESPACE . '/';
		if ( strpos( $route, $namespace ) === 0 ) {
			$route = substr( $route, strlen( $namespace ) );
		}
		
		return trim( $route, '/' );
	}

	/**
	 * Find route by path and method
	 *
	 * @param string $route Route path.
	 * @param string $method HTTP method.
	 * @return array|null
	 */
	private function find_route( $route, $method ) {
		foreach ( $this->routes as $route_data ) {
			if ( $this->route_matches( $route_data['route'], $route ) && $route_data['method'] === $method ) {
				return $route_data;
			}
		}
		return null;
	}

	/**
	 * Check if route pattern matches actual route
	 *
	 * @param string $pattern Route pattern.
	 * @param string $route Actual route.
	 * @return bool
	 */
	private function route_matches( $pattern, $route ) {
		// Convert pattern to regex
		// Handle {id} syntax by converting it to .+ pattern to match any characters
		$regex = preg_replace( '/\{([^}]+)\}/', '([^/]+)', $pattern );
		$regex = '#^' . $regex . '$#';
		
		return preg_match( $regex, $route );
	}

	/**
	 * Get route arguments for validation
	 *
	 * @param string $route Route path.
	 * @return array
	 */
	private function get_route_args( $route ) {
		$args = [];

		// Extract parameters from route pattern
		if ( preg_match_all( '/\{([^}]+)\}/', $route, $matches ) ) {
			foreach ( $matches[1] as $param ) {
				$args[ $param ] = [
					'required' => true,
					'validate_callback' => function ( $value, $request, $param ) {
						// Validate that the value is a number
						return ! empty( $value ) && is_numeric( $value );
					},
				];
			}
		}

		return $args;
	}

	/**
	 * Add CORS headers
	 *
	 * @return void
	 */
	public function add_cors_headers() {
		if ( ! Options::is_cors_enabled() ) {
			return;
		}

		add_action( 'rest_api_init', function () {
			remove_filter( 'rest_pre_serve_request', 'rest_send_cors_headers' );
			add_filter( 'rest_pre_serve_request', [ $this, 'serve_cors_headers' ] );
		} );
	}

	/**
	 * Serve CORS headers
	 *
	 * @param bool $served Whether the request was served.
	 * @return bool
	 */
	public function serve_cors_headers( $served ) {
		$origin = get_http_origin();
		$allowed_origins = Options::get_allowed_origins();

		if ( $origin ) {
			if ( '*' === $allowed_origins || in_array( $origin, explode( ',', $allowed_origins ), true ) ) {
				header( 'Access-Control-Allow-Origin: ' . esc_url_raw( $origin ) );
			}
		}

		header( 'Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS' );
		header( 'Access-Control-Allow-Credentials: true' );
		header( 'Access-Control-Allow-Headers: Authorization, Content-Type, X-Requested-With' );
		header( 'Access-Control-Max-Age: 86400' );

		if ( 'OPTIONS' === $_SERVER['REQUEST_METHOD'] ) {
			status_header( 200 );
			exit();
		}

		return $served;
	}

	/**
	 * Get all registered routes
	 *
	 * @return array
	 */
	public function get_routes() {
		return $this->routes;
	}

	/**
	 * Get API base URL
	 *
	 * @return string
	 */
	public static function get_api_base_url() {
		return rest_url( self::API_NAMESPACE );
	}
} 