<?php

namespace OBA\APIsIntegration;

use OBA\APIsIntegration\Core\Options;
use OBA\APIsIntegration\API\Router;
use OBA\APIsIntegration\API\Middleware\AuthMiddleware;
use OBA\APIsIntegration\Middleware\WooCommerceAuthMiddleware;
use OBA\APIsIntegration\Services\AppointmentService;
use OBA\APIsIntegration\Services\AuthService;
use OBA\APIsIntegration\Services\CallService;
use OBA\APIsIntegration\Services\SurveyService;
use OBA\APIsIntegration\Services\UserService;
use OBA\APIsIntegration\Services\OrderService;
use OBA\APIsIntegration\Services\ProductService;
use OBA\APIsIntegration\Services\VendorService;
use OBA\APIsIntegration\Services\MembershipService;
use OBA\APIsIntegration\Services\CartService;
use OBA\APIsIntegration\Services\CheckoutService;

/**
 * Main plugin class
 *
 * @package OBA\APIsIntegration
 */
class Plugin
{
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
    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Initialize the plugin
     *
     * @return void
     */
    public function init()
    {
        // Load text domain
        load_plugin_textdomain('oba-apis-integration', false, dirname(plugin_basename(OBA_APIS_INTEGRATION_PLUGIN_FILE)) . '/languages');

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
    private function init_services()
    {
        $this->services['auth'] = new AuthService();
        $this->services['user'] = new UserService();
        $this->services['order'] = new OrderService();
        $this->services['product'] = new ProductService();
        $this->services['vendor'] = new VendorService();
        $this->services['membership'] = new MembershipService();
        $this->services['survey'] = new SurveyService();
        $this->services['cart'] = new CartService();
        $this->services['checkout'] = new CheckoutService();
        $this->services['appointment'] = new AppointmentService();
        $this->services['call'] = new CallService();
    }

    /**
     * Initialize router
     *
     * @return void
     */
    private function init_router()
    {
        $this->router = new Router();
    }

    /**
     * Register REST API routes
     *
     * @return void
     */
    public function register_routes()
    {
        // Auth routes
        $this->router->register_route('auth/login', 'POST', [$this->services['auth'], 'login']);
        $this->router->register_route('auth/logout', 'POST', [$this->services['auth'], 'logout'], [AuthMiddleware::class]);
        $this->router->register_route('auth/refresh', 'POST', [$this->services['auth'], 'refresh_token']);

        // User routes
        $this->router->register_route('user/me', 'GET', [$this->services['user'], 'get_current_user'], [AuthMiddleware::class]);
        $this->router->register_route('user/profile', 'PUT', [$this->services['user'], 'update_profile'], [AuthMiddleware::class]);

        // Order routes
        $this->router->register_route('orders', 'GET', [$this->services['order'], 'get_orders'], [AuthMiddleware::class]);
        $this->router->register_route('orders/{id}', 'GET', [$this->services['order'], 'get_order'], [AuthMiddleware::class]);
        $this->router->register_route('orders', 'POST', [$this->services['order'], 'create_order'], [AuthMiddleware::class]);

        // Product routes
        $this->router->register_route('products', 'GET', [$this->services['product'], 'get_products']);
        $this->router->register_route('products/{id}', 'GET', [$this->services['product'], 'get_product']);
        $this->router->register_route('products/categories', 'GET', [$this->services['product'], 'get_categories']);
        $this->router->register_route('products/check-survey', 'GET', [$this->services['survey'], 'get_user_survey_product'], [AuthMiddleware::class]);

        // Vendor routes
        $this->router->register_route('vendors', 'GET', [$this->services['vendor'], 'get_vendors']);
        $this->router->register_route('vendors/{id}', 'GET', [$this->services['vendor'], 'get_vendor']);
        $this->router->register_route('vendors/{id}/products', 'GET', [$this->services['vendor'], 'get_vendor_products']);

        // Membership routes
        $this->router->register_route('membership/status', 'GET', [$this->services['membership'], 'get_status'], [AuthMiddleware::class]);
        $this->router->register_route('membership/plans', 'GET', [$this->services['membership'], 'get_plans']);
        $this->router->register_route('membership/signup-form', 'GET', [$this->services['membership'], 'get_signup_form']);
        $this->router->register_route('membership/signup', 'POST', [$this->services['membership'], 'process_signup']);
        $this->router->register_route('membership/change', 'POST', [$this->services['membership'], 'change_membership'], [AuthMiddleware::class]);
        $this->router->register_route('membership/cancel', 'POST', [$this->services['membership'], 'cancel_membership'], [AuthMiddleware::class]);
        $this->router->register_route('membership/gateways', 'GET', [$this->services['membership'], 'get_payment_gateways']);
        $this->router->register_route('membership/analytics', 'GET', [$this->services['membership'], 'get_analytics']);
        $this->router->register_route('membership/history', 'GET', [$this->services['membership'], 'get_membership_history'], [AuthMiddleware::class]);
        $this->router->register_route('membership/invoices', 'GET', [$this->services['membership'], 'get_invoices'], [AuthMiddleware::class]);
        $this->router->register_route('membership/profile', 'GET', [$this->services['membership'], 'get_user_profile'], [AuthMiddleware::class]);

        //survey routes
        $this->router->register_route('survey/{id}', 'GET', [$this->services['survey'], 'get_survey']);
        $this->router->register_route('survey/submit', 'POST', [$this->services['survey'], 'submit_survey'], [AuthMiddleware::class]);
        $this->router->register_route('survey/retake', 'POST', [$this->services['survey'], 'retake_survey'], [AuthMiddleware::class]);


        //cart routes
        $this->router->register_route('cart', 'GET', [$this->services['cart'], 'get_cart'], [AuthMiddleware::class]);
        $this->router->register_route('cart/summary', 'GET', [$this->services['cart'], 'get_cart_summary'], [AuthMiddleware::class]);
        $this->router->register_route('cart/add', 'POST', [$this->services['cart'], 'add_to_cart'], [AuthMiddleware::class]);
        $this->router->register_route('cart/remove', 'POST', [$this->services['cart'], 'remove_from_cart'], [AuthMiddleware::class]);
        $this->router->register_route('cart/update', 'POST', [$this->services['cart'], 'update_cart_item'], [AuthMiddleware::class]);
        $this->router->register_route('cart/clear', 'POST', [$this->services['cart'], 'clear_cart'], [AuthMiddleware::class]);

        //checkout routes
        $this->router->register_route('checkout', 'GET', [$this->services['checkout'], 'get_checkout_data'], [AuthMiddleware::class]);
        $this->router->register_route('checkout/shipping', 'GET', [$this->services['checkout'], 'get_shipping_rates'], [AuthMiddleware::class]);
        $this->router->register_route('checkout/shipping/update', 'POST', [$this->services['checkout'], 'set_shipping_method'], [AuthMiddleware::class]);
        $this->router->register_route('checkout/process', 'POST', [$this->services['checkout'], 'process_checkout'], [AuthMiddleware::class]);
        $this->router->register_route('checkout/validate', 'POST', [$this->services['checkout'], 'validate_checkout'], [AuthMiddleware::class]);

        //Appointment
        $this->router->register_route('appointments','POST',[$this->services['appointment'] , 'create'] , [AuthMiddleware::class]);
        $this->router->register_route('appointments','GET',[$this->services['appointment'] , 'get_appointments'] , [AuthMiddleware::class]);
        $this->router->register_route('appointments/{id}','GET',[$this->services['appointment'] , 'get_appointment'] , [AuthMiddleware::class]);

        //Call
        $this->router->register_route('call/pending','GET',[$this->services['call'] , 'check_call_status'] , [AuthMiddleware::class]);
        $this->router->register_route('call/end/{id}','GET',[$this->services['call'] , 'check_call_end_status'] , [AuthMiddleware::class]);
        $this->router->register_route('call/update/{id}','POST',[$this->services['call'] , 'update_appointment_call_id'] , [AuthMiddleware::class]);
        $this->router->register_route('call/feedback/submit','POST',[$this->services['call'] , 'submit_feedback'] , [AuthMiddleware::class]);
    }

    /**
     * Add admin hooks
     *
     * @return void
     */
    private function add_admin_hooks()
    {
        if (is_admin()) {
            add_action('admin_menu', [$this, 'add_admin_menu']);
            add_action('admin_init', [$this, 'admin_init']);
        }
    }

    public function __construct()
    {
        $this->register_hooks();
    }

    public function register_hooks()
    {
        add_action('init', [$this, 'init']);
        add_action('rest_api_init', [$this, 'register_routes']);

        // Add activation hook
        register_activation_hook(ABSPATH . 'wp-content/plugins/oba-apis-integration/oba-apis-integration.php',
            [$this, 'activate_plugin']);
            
        // Add deactivation hook
        register_deactivation_hook(ABSPATH . 'wp-content/plugins/oba-apis-integration/oba-apis-integration.php',
            [$this, 'deactivate_plugin']);
    }

    /**
     * Plugin activation hook
     */
    public function activate_plugin()
    {
        // Initialize database tables
        \OBA\APIsIntegration\Database\Migration::create_tables();
        \OBA\APIsIntegration\Database\CartTable::init();
        
        // Set default options
        $this->set_default_options();
        
        // Flush rewrite rules for REST API
        flush_rewrite_rules();
    }

    /**
     * Plugin deactivation hook
     */
    public function deactivate_plugin()
    {
        // Flush rewrite rules
        flush_rewrite_rules();
        
        // Note: We don't drop tables on deactivation to preserve data
        // Tables will be dropped only on uninstall if needed
    }

    /**
     * Set default plugin options
     */
    private function set_default_options()
    {
        // JWT settings
        if ( ! get_option( 'oba_jwt_secret' ) ) {
            update_option( 'oba_jwt_secret', wp_generate_password( 64, false ) );
        }
        
        if ( ! get_option( 'oba_jwt_access_expiration' ) ) {
            update_option( 'oba_jwt_access_expiration', 3600 ); // 1 hour
        }
        
        if ( ! get_option( 'oba_jwt_refresh_expiration' ) ) {
            update_option( 'oba_jwt_refresh_expiration', 604800 ); // 7 days
        }
        
        // Rate limiting settings
        if ( ! get_option( 'oba_rate_limit_enabled' ) ) {
            update_option( 'oba_rate_limit_enabled', true );
        }
        
        if ( ! get_option( 'oba_rate_limit_requests' ) ) {
            update_option( 'oba_rate_limit_requests', 60 ); // 60 requests per minute
        }
        
        // CORS settings
        if ( ! get_option( 'oba_cors_enabled' ) ) {
            update_option( 'oba_cors_enabled', true );
        }
        
        if ( ! get_option( 'oba_cors_allowed_origins' ) ) {
            update_option( 'oba_cors_allowed_origins', '*' );
        }
        
        // API logging settings
        if ( ! get_option( 'oba_api_logging_enabled' ) ) {
            update_option( 'oba_api_logging_enabled', true );
        }
        
        if ( ! get_option( 'oba_api_log_retention_days' ) ) {
            update_option( 'oba_api_log_retention_days', 30 );
        }
    }

    /**
     * Add admin menu
     *
     * @return void
     */
    public function add_admin_menu()
    {
        add_submenu_page(
            'woocommerce',
            __('OBA APIs', 'oba-apis-integration'),
            __('OBA APIs', 'oba-apis-integration'),
            'manage_woocommerce',
            'oba-apis-integration',
            [$this, 'admin_page']
        );
    }

    /**
     * Admin init
     *
     * @return void
     */
    public function admin_init()
    {
        // Handle settings form submission
        if (isset($_POST['oba_apis_integration_settings']) && wp_verify_nonce($_POST['oba_apis_integration_nonce'], 'oba_apis_integration_settings')) {
            Options::save_settings($_POST);
            add_action('admin_notices', function () {
                echo '<div class="notice notice-success"><p>' . esc_html__('Settings saved successfully.', 'oba-apis-integration') . '</p></div>';
            });
        }
    }

    /**
     * Admin page
     *
     * @return void
     */
    public function admin_page()
    {
        include OBA_APIS_INTEGRATION_PLUGIN_DIR . 'templates/admin/settings.php';
    }

    /**
     * Add security headers
     *
     * @return void
     */
    private function add_security_headers()
    {
        add_action('send_headers', function () {
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: DENY');
            header('X-XSS-Protection: 1; mode=block');
            header('Referrer-Policy: strict-origin-when-cross-origin');
        });
    }

    /**
     * Get service
     *
     * @param string $service_name Service name.
     * @return mixed|null
     */
    public function get_service($service_name)
    {
        return $this->services[$service_name] ?? null;
    }

    /**
     * Get router
     *
     * @return Router
     */
    public function get_router()
    {
        return $this->router;
    }
} 