<?php
/**
 * Plugin Name: OBA APIs Integration
 * Plugin URI: https://oba.com
 * Description: Backend API-only service for mobile applications with JWT authentication
 * Version: 1.0.0
 * Author: OBA Team
 * Author URI: https://oba.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: oba-apis-integration
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires PHP: 7.4
 * Network: false
 *GitHub Plugin URI: https://github.com/AhmedHany2021/OBAApis
 *
 * @package OBA\APIsIntegration
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants
define( 'OBA_APIS_INTEGRATION_VERSION', '1.0.0' );
define( 'OBA_APIS_INTEGRATION_PLUGIN_FILE', __FILE__ );
define( 'OBA_APIS_INTEGRATION_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'OBA_APIS_INTEGRATION_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'OBA_APIS_INTEGRATION_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Autoloader
if ( file_exists( OBA_APIS_INTEGRATION_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
	require_once OBA_APIS_INTEGRATION_PLUGIN_DIR . 'vendor/autoload.php';
} else {
	// Fallback autoloader for development
	spl_autoload_register( function ( $class ) {
		// Only handle our namespace
		if ( strpos( $class, 'OBA\\APIsIntegration\\' ) !== 0 ) {
			return;
		}

		$class = str_replace( 'OBA\\APIsIntegration\\', '', $class );
		$class = str_replace( '\\', '/', $class );
		$file  = OBA_APIS_INTEGRATION_PLUGIN_DIR . 'src/' . $class . '.php';

		if ( file_exists( $file ) ) {
			require_once $file;
		}
	} );
}

// Initialize the plugin
add_action( 'plugins_loaded', function () {
	// Check if required dependencies are available
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p>' . 
				 esc_html__( 'OBA APIs Integration requires WooCommerce to be installed and activated.', 'oba-apis-integration' ) . 
				 '</p></div>';
		} );
		return;
	}

	// Initialize the main plugin class
	$plugin = new \OBA\APIsIntegration\Plugin();
	$plugin->init();
} );

// Activation hook
register_activation_hook( __FILE__, function () {
	// Create necessary database tables
	\OBA\APIsIntegration\Database\Migration::create_tables();
	
	// Set default options
	\OBA\APIsIntegration\Core\Options::set_defaults();
	
	// Flush rewrite rules
	flush_rewrite_rules();
} );

// Deactivation hook
register_deactivation_hook( __FILE__, function () {
	// Flush rewrite rules
	flush_rewrite_rules();
} );

// Uninstall hook
register_uninstall_hook( __FILE__, function () {
	// Clean up database tables and options
	\OBA\APIsIntegration\Database\Migration::drop_tables();
	\OBA\APIsIntegration\Core\Options::delete_all();
} ); 