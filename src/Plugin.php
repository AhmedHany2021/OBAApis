<?php

namespace OBA\APIsIntegration;

use OBA\APIsIntegration\Core\Options;
use OBA\APIsIntegration\API\Router;
use OBA\APIsIntegration\API\Middleware\AuthMiddleware;
use OBA\APIsIntegration\Middleware\WooCommerceAuthMiddleware;
use OBA\APIsIntegration\Services\AppointmentService;
use OBA\APIsIntegration\Services\AuthService;
use OBA\APIsIntegration\Services\CallService;
use OBA\APIsIntegration\Services\CreditSystemService;
use OBA\APIsIntegration\Services\CreditCardService;
use OBA\APIsIntegration\Services\DoctorService;
use OBA\APIsIntegration\Services\MedicationRequestService;
use OBA\APIsIntegration\Services\SurveyService;
use OBA\APIsIntegration\Services\UserService;
use OBA\APIsIntegration\Services\OrderService;
use OBA\APIsIntegration\Services\ProductService;
use OBA\APIsIntegration\Services\VendorService;
use OBA\APIsIntegration\Services\MembershipService;
use OBA\APIsIntegration\Services\CartService;
use OBA\APIsIntegration\Services\CheckoutService;
use OBA\APIsIntegration\Services\BlogService;
use OBA\APIsIntegration\Services\ForgetPasswordService;
use OBA\APIsIntegration\API\Controllers\AppleNotificationsController;

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
        
        // Initialize Apple Notifications
        $this->init_apple_notifications();

        // Add admin hooks
        $this->add_admin_hooks();

        // Add security headers
        $this->add_security_headers();

        // Handle one-time token login
        $this->handle_token_login();
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
        $this->services['medication'] = new MedicationRequestService();
        $this->services['doctor'] = new DoctorService();
        $this->services['blog'] = new BlogService();
        $this->services['forget_password'] = new ForgetPasswordService();
        $this->services['credit'] = new CreditSystemService();
        $this->services['creditcard'] = new CreditCardService();
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

        // Forget Password routes
        $this->router->register_route('auth/forgot-password', 'POST', [$this->services['forget_password'], 'request_password_reset']);
        $this->router->register_route('auth/verify-reset-token', 'POST', [$this->services['forget_password'], 'verify_reset_token']);
        $this->router->register_route('auth/reset-password', 'POST', [$this->services['forget_password'], 'reset_password']);

        // User routes
        $this->router->register_route('user/me', 'GET', [$this->services['user'], 'get_current_user'], [AuthMiddleware::class]);
        $this->router->register_route('user/profile', 'POST', [$this->services['user'], 'update_profile'], [AuthMiddleware::class]);
        $this->router->register_route('user/recommendations-products', 'POST', [$this->services['user'], 'get_recommended_medications'], [AuthMiddleware::class]);
        $this->router->register_route('user/recommendations-doctors', 'POST', [$this->services['user'], 'get_recommended_doctors'], [AuthMiddleware::class]);
        $this->router->register_route('user/generate-one-time-token', 'POST', [$this->services['user'], 'generate_one_time_token'], [AuthMiddleware::class]);

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

        // Membership routes - Complete PMPro Integration
        $this->router->register_route('membership/checkout/fields', 'GET', [$this->services['membership'], 'get_checkout_fields']);
        $this->router->register_route('membership/signup', 'POST', [$this->services['membership'], 'process_signup']);
        $this->router->register_route('membership/status', 'GET', [$this->services['membership'], 'get_status'], [AuthMiddleware::class]);
        $this->router->register_route('membership/plans', 'GET', [$this->services['membership'], 'get_plans']);
        $this->router->register_route('membership/cancel', 'POST', [$this->services['membership'], 'cancel_membership'], [AuthMiddleware::class]);
        $this->router->register_route('membership/change', 'POST', [$this->services['membership'], 'change_membership'], [AuthMiddleware::class]);
        $this->router->register_route('membership/profile', 'GET', [$this->services['membership'], 'get_user_profile'], [AuthMiddleware::class]);
        $this->router->register_route('membership/profile/update', 'PUT', [$this->services['membership'], 'update_profile_fields'], [AuthMiddleware::class]);

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
        $this->router->register_route('checkout/shipping', 'POST', [$this->services['checkout'], 'get_shipping_rates'], [AuthMiddleware::class]);
        $this->router->register_route('checkout/shipping/update', 'POST', [$this->services['checkout'], 'set_shipping_method'], [AuthMiddleware::class]);
        $this->router->register_route('checkout/process', 'POST', [$this->services['checkout'], 'process_checkout'], [AuthMiddleware::class]);
        $this->router->register_route('checkout/validate', 'POST', [$this->services['checkout'], 'validate_checkout'], [AuthMiddleware::class]);
        $this->router->register_route('checkout/coupon/check', 'POST', [$this->services['checkout'], 'check_coupon'], [AuthMiddleware::class]);
        $this->router->register_route('checkout/coupon/apply', 'POST', [$this->services['checkout'], 'apply_coupon'], [AuthMiddleware::class]);
        $this->router->register_route('checkout/coupon/remove', 'POST', [$this->services['checkout'], 'remove_coupon'], [AuthMiddleware::class]);

        //Appointment
        $this->router->register_route('appointments','POST',[$this->services['appointment'] , 'create'] , [AuthMiddleware::class]);
        $this->router->register_route('appointments','GET',[$this->services['appointment'] , 'get_appointments'] , [AuthMiddleware::class]);
        $this->router->register_route('appointments/{id}','GET',[$this->services['appointment'] , 'get_appointment'] , [AuthMiddleware::class]);
        $this->router->register_route('call-requests','GET',[$this->services['appointment'] , 'call_requests'] , [AuthMiddleware::class]);

        //Call
        $this->router->register_route('call/pending','GET',[$this->services['call'] , 'check_call_status'] , [AuthMiddleware::class]);
        $this->router->register_route('call/end/{id}','GET',[$this->services['call'] , 'check_call_end_status'] , [AuthMiddleware::class]);
        $this->router->register_route('call/update/{id}','POST',[$this->services['call'] , 'update_appointment_call_id'] , [AuthMiddleware::class]);
        $this->router->register_route('call/feedback/submit','POST',[$this->services['call'] , 'submit_feedback'] , [AuthMiddleware::class]);

        //Medication Requests
        $this->router->register_route('medication-requests','GET',[$this->services['medication'] , 'get_requests'] , [AuthMiddleware::class]);

        //Doctor
        $this->router->register_route('doctors/rating' , 'GET' , [$this->services['doctor'] , 'get_doctor_rating']);
        $this->router->register_route('doctors/emergency-clinics' , 'GET' , [$this->services['doctor'] , 'get_emergency_clinics']);

        //Blog routes
        $this->router->register_route('blog/posts', 'GET', [$this->services['blog'], 'get_blog_posts']);
        $this->router->register_route('blog/posts/{id}', 'GET', [$this->services['blog'], 'get_blog_post']);

        //Credit System
        $this->router->register_route('credits', 'GET', [$this->services['credit'] , 'get_user_credit'] , [AuthMiddleware::class]);
        $this->router->register_route('credits/add', 'POST', [$this->services['credit'] , 'add_user_credit'] , [AuthMiddleware::class]);

        //Payment Methods (Credit Cards)
        $this->router->register_route('payment-methods', 'POST', [$this->services['creditcard'], 'create_payment_method'], [AuthMiddleware::class]);
        $this->router->register_route('payment-methods', 'GET', [$this->services['creditcard'], 'get_user_payment_methods'], [AuthMiddleware::class]);
        $this->router->register_route('payment-methods/{id}', 'GET', [$this->services['creditcard'], 'get_payment_method'], [AuthMiddleware::class]);
        $this->router->register_route('payment-methods/{id}', 'PUT', [$this->services['creditcard'], 'update_payment_method'], [AuthMiddleware::class]);
        $this->router->register_route('payment-methods/{id}', 'DELETE', [$this->services['creditcard'], 'delete_payment_method'], [AuthMiddleware::class]);
        $this->router->register_route('payment-methods/{id}/set-default', 'POST', [$this->services['creditcard'], 'set_default_payment_method'], [AuthMiddleware::class]);
    }

    /**
     * Initialize Apple Notifications
     *
     * @return void
     */
    private function init_apple_notifications() {
        new \OBA\APIsIntegration\API\Controllers\AppleNotificationsController();
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

    /**
     * Handle one-time token login on WordPress init
     */
    private function handle_token_login() {
        // Check if token parameter exists in URL
        if ( ! isset( $_GET['oba_token'] ) || empty( $_GET['oba_token'] ) ) {
            return;
        }

        $token = sanitize_text_field( $_GET['oba_token'] );
        $token_hash = hash( 'sha256', $token );
        
        // Get token data from transients
        $token_data = get_transient( "oba_one_time_token_{$token_hash}" );
        
        if ( ! $token_data ) {
            // Token not found or expired
            wp_die( __( 'Invalid or expired token.', 'oba-apis-integration' ), __( 'Token Error', 'oba-apis-integration' ), [ 'response' => 401 ] );
        }

        // Check if token is already used
        if ( $token_data['used'] ) {
            return;
        }

        // Check if token is expired
        if ( time() > $token_data['expires_at'] ) {
            delete_transient( "oba_one_time_token_{$token_hash}" );
            wp_die( __( 'Token has expired.', 'oba-apis-integration' ), __( 'Token Error', 'oba-apis-integration' ), [ 'response' => 401 ] );
        }

        // Get user
        $user = get_user_by( 'ID', $token_data['user_id'] );
        if ( ! $user ) {
            delete_transient( "oba_one_time_token_{$token_hash}" );
            wp_die( __( 'User not found.', 'oba-apis-integration' ), __( 'Token Error', 'oba-apis-integration' ), [ 'response' => 404 ] );
        }

        // Check if user is active
        if ( ! $user->has_cap( 'read' ) ) {
            delete_transient( "oba_one_time_token_{$token_hash}" );
            wp_die( __( 'User account is inactive.', 'oba-apis-integration' ), __( 'Token Error', 'oba-apis-integration' ), [ 'response' => 403 ] );
        }

        // Mark token as used
        $token_data['used'] = true;
        $token_data['used_at'] = time();
        
        set_transient( "oba_one_time_token_{$token_hash}", $token_data, $token_data['expires_at'] - time() );

        // Log the token usage
        $this->log_token_activity( $user->ID, 'used', $token_hash, $token_data['call_id'] );

        // Login the user
        wp_set_current_user( $user->ID );
        wp_set_auth_cookie( $user->ID, true );

        // Update last login
        update_user_meta( $user->ID, 'last_login', current_time( 'mysql' ) );

        // Redirect to dashboard with call_id parameter
        $redirect_url = home_url( '/video-call' );
        if ( ! empty( $token_data['call_id'] ) ) {
            $redirect_url .= '?appointment=' . urlencode( $token_data['call_id'] );
        }

        // Redirect user
        wp_redirect( $redirect_url );
        exit;
    }

    /**
     * Log token activity
     *
     * @param int    $user_id User ID.
     * @param string $action Action performed.
     * @param string $token_hash Token hash.
     * @param string $call_id Call ID (optional).
     * @return void
     */
    private function log_token_activity( $user_id, $action, $token_hash, $call_id = null ) {
        $log_data = [
            'user_id' => $user_id,
            'action' => $action,
            'token_hash' => $token_hash,
            'call_id' => $call_id,
            'timestamp' => current_time( 'mysql' ),
        ];

        // Store in user meta for recent activity
        $recent_activity = get_user_meta( $user_id, 'oba_token_activity', true ) ?: [];
        $recent_activity[] = $log_data;
        
        // Keep only last 20 activities
        if ( count( $recent_activity ) > 20 ) {
            $recent_activity = array_slice( $recent_activity, -20 );
        }
        
        update_user_meta( $user_id, 'oba_token_activity', $recent_activity );
    }
} 