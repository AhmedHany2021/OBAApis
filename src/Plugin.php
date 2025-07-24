<?php

namespace OBA\APIsIntegration;

use OBA\APIsIntegration\Core\Options;
use OBA\APIsIntegration\API\Router;
use OBA\APIsIntegration\API\Middleware\AuthMiddleware;
use OBA\APIsIntegration\Services\AuthService;
use OBA\APIsIntegration\Services\UserService;
use OBA\APIsIntegration\Services\OrderService;
use OBA\APIsIntegration\Services\ProductService;
use OBA\APIsIntegration\Services\VendorService;
use OBA\APIsIntegration\Services\MembershipService;

/**
 * Main plugin class
 *
 * @package OBA\APIsIntegration
 */
class Plugin {

	/**
	 * Plugin instance
	 *
	 * @var Plugin
	 */
	private static $instance = null;

	/**
	 * Router instance
	 *
	 * @var Router
	 */
	private $router;

	/**
	 * Services container
	 *
	 * @var array
	 */
	private $services = [];

	/**
	 * Get plugin instance
	 *
	 * @return Plugin
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Initialize the plugin
	 *
	 * @return void
	 */
	public function init() {
		// Load text domain
		load_plugin_textdomain( 'oba-apis-integration', false, dirname( plugin_basename( OBA_APIS_INTEGRATION_PLUGIN_FILE ) ) . '/languages' );

		// Initialize services
		$this->init_services();

		// Initialize API router
		$this->init_router();

		// Register REST API routes
		$this->register_routes();

		// Add admin hooks
		$this->add_admin_hooks();

		// Add security headers
		$this->add_security_headers();
	}

	/**
	 * Initialize services
	 *
	 * @return void
	 */
	private function init_services() {
		$this->services['auth'] = new AuthService();
		$this->services['user'] = new UserService();
		$this->services['order'] = new OrderService();
		$this->services['product'] = new ProductService();
		$this->services['vendor'] = new VendorService();
		$this->services['membership'] = new MembershipService();
	}

	/**
	 * Initialize router
	 *
	 * @return void
	 */
	private function init_router() {
		$this->router = new Router();
	}

	/**
	 * Register REST API routes
	 *
	 * @return void
	 */
	private function register_routes() {
		// Auth routes
		$this->router->register_route( 'auth/login', 'POST', [ $this->services['auth'], 'login' ] );
		$this->router->register_route( 'auth/logout', 'POST', [ $this->services['auth'], 'logout' ], [ AuthMiddleware::class ] );
		$this->router->register_route( 'auth/refresh', 'POST', [ $this->services['auth'], 'refresh_token' ] );

		// User routes
		$this->router->register_route( 'user/me', 'GET', [ $this->services['user'], 'get_current_user' ], [ AuthMiddleware::class ] );
		$this->router->register_route( 'user/profile', 'PUT', [ $this->services['user'], 'update_profile' ], [ AuthMiddleware::class ] );

		// Order routes
		$this->router->register_route( 'orders', 'GET', [ $this->services['order'], 'get_orders' ], [ AuthMiddleware::class ] );
		$this->router->register_route( 'orders/(?P<id>\d+)', 'GET', [ $this->services['order'], 'get_order' ], [ AuthMiddleware::class ] );
		$this->router->register_route( 'orders', 'POST', [ $this->services['order'], 'create_order' ], [ AuthMiddleware::class ] );

		// Product routes
		$this->router->register_route( 'products', 'GET', [ $this->services['product'], 'get_products' ] );
		$this->router->register_route( 'products/(?P<id>\d+)', 'GET', [ $this->services['product'], 'get_product' ] );
		$this->router->register_route( 'products/categories', 'GET', [ $this->services['product'], 'get_categories' ] );

		// Vendor routes
		$this->router->register_route( 'vendors', 'GET', [ $this->services['vendor'], 'get_vendors' ] );
		$this->router->register_route( 'vendors/(?P<id>\d+)', 'GET', [ $this->services['vendor'], 'get_vendor' ] );
		$this->router->register_route( 'vendors/(?P<id>\d+)/products', 'GET', [ $this->services['vendor'], 'get_vendor_products' ] );

		// Membership routes
		$this->router->register_route( 'membership/status', 'GET', [ $this->services['membership'], 'get_status' ], [ AuthMiddleware::class ] );
		$this->router->register_route( 'membership/plans', 'GET', [ $this->services['membership'], 'get_plans' ] );
	}

	/**
	 * Add admin hooks
	 *
	 * @return void
	 */
	private function add_admin_hooks() {
		if ( is_admin() ) {
			add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
			add_action( 'admin_init', [ $this, 'admin_init' ] );
		}
	}

	/**
	 * Add admin menu
	 *
	 * @return void
	 */
	public function add_admin_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'OBA APIs', 'oba-apis-integration' ),
			__( 'OBA APIs', 'oba-apis-integration' ),
			'manage_woocommerce',
			'oba-apis-integration',
			[ $this, 'admin_page' ]
		);
	}

	/**
	 * Admin init
	 *
	 * @return void
	 */
	public function admin_init() {
		// Handle settings form submission
		if ( isset( $_POST['oba_apis_integration_settings'] ) && wp_verify_nonce( $_POST['oba_apis_integration_nonce'], 'oba_apis_integration_settings' ) ) {
			Options::save_settings( $_POST );
			add_action( 'admin_notices', function () {
				echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved successfully.', 'oba-apis-integration' ) . '</p></div>';
			} );
		}
	}

	/**
	 * Admin page
	 *
	 * @return void
	 */
	public function admin_page() {
		include OBA_APIS_INTEGRATION_PLUGIN_DIR . 'templates/admin/settings.php';
	}

	/**
	 * Add security headers
	 *
	 * @return void
	 */
	private function add_security_headers() {
		add_action( 'send_headers', function () {
			header( 'X-Content-Type-Options: nosniff' );
			header( 'X-Frame-Options: DENY' );
			header( 'X-XSS-Protection: 1; mode=block' );
			header( 'Referrer-Policy: strict-origin-when-cross-origin' );
		} );
	}

	/**
	 * Get service
	 *
	 * @param string $service_name Service name.
	 * @return mixed|null
	 */
	public function get_service( $service_name ) {
		return $this->services[ $service_name ] ?? null;
	}

	/**
	 * Get router
	 *
	 * @return Router
	 */
	public function get_router() {
		return $this->router;
	}
} 