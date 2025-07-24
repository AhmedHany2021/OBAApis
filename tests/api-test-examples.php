<?php
/**
 * API Test Examples
 * 
 * This file contains examples of how to test the OBA APIs Integration endpoints.
 * Use these examples to test your API implementation.
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Example API test functions
 */
class OBA_API_Test_Examples {

	/**
	 * Test login endpoint
	 */
	public static function test_login() {
		$url = rest_url( 'oba/v1/auth/login' );
		
		$response = wp_remote_post( $url, [
			'headers' => [
				'Content-Type' => 'application/json',
			],
			'body' => json_encode( [
				'email' => 'test@example.com',
				'password' => 'testpassword',
			] ),
		] );

		if ( is_wp_error( $response ) ) {
			echo "Login Error: " . $response->get_error_message() . "\n";
			return false;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( $data['success'] ) {
			echo "Login successful!\n";
			echo "Access Token: " . substr( $data['data']['access_token'], 0, 50 ) . "...\n";
			return $data['data']['access_token'];
		} else {
			echo "Login failed: " . $data['message'] . "\n";
			return false;
		}
	}

	/**
	 * Test get current user endpoint
	 */
	public static function test_get_user( $access_token ) {
		$url = rest_url( 'oba/v1/user/me' );
		
		$response = wp_remote_get( $url, [
			'headers' => [
				'Authorization' => 'Bearer ' . $access_token,
				'Content-Type' => 'application/json',
			],
		] );

		if ( is_wp_error( $response ) ) {
			echo "Get User Error: " . $response->get_error_message() . "\n";
			return false;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( $data['success'] ) {
			echo "User data retrieved successfully!\n";
			echo "User ID: " . $data['data']['id'] . "\n";
			echo "Email: " . $data['data']['email'] . "\n";
			return $data['data'];
		} else {
			echo "Get user failed: " . $data['message'] . "\n";
			return false;
		}
	}

	/**
	 * Test get products endpoint
	 */
	public static function test_get_products() {
		$url = rest_url( 'oba/v1/products' );
		
		$response = wp_remote_get( $url, [
			'headers' => [
				'Content-Type' => 'application/json',
			],
		] );

		if ( is_wp_error( $response ) ) {
			echo "Get Products Error: " . $response->get_error_message() . "\n";
			return false;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( $data['success'] ) {
			echo "Products retrieved successfully!\n";
			echo "Total products: " . count( $data['data'] ) . "\n";
			return $data['data'];
		} else {
			echo "Get products failed: " . $data['message'] . "\n";
			return false;
		}
	}

	/**
	 * Test get orders endpoint (requires authentication)
	 */
	public static function test_get_orders( $access_token ) {
		$url = rest_url( 'oba/v1/orders' );
		
		$response = wp_remote_get( $url, [
			'headers' => [
				'Authorization' => 'Bearer ' . $access_token,
				'Content-Type' => 'application/json',
			],
		] );

		if ( is_wp_error( $response ) ) {
			echo "Get Orders Error: " . $response->get_error_message() . "\n";
			return false;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( $data['success'] ) {
			echo "Orders retrieved successfully!\n";
			echo "Total orders: " . count( $data['data'] ) . "\n";
			return $data['data'];
		} else {
			echo "Get orders failed: " . $data['message'] . "\n";
			return false;
		}
	}

	/**
	 * Test logout endpoint
	 */
	public static function test_logout( $access_token ) {
		$url = rest_url( 'oba/v1/auth/logout' );
		
		$response = wp_remote_post( $url, [
			'headers' => [
				'Authorization' => 'Bearer ' . $access_token,
				'Content-Type' => 'application/json',
			],
		] );

		if ( is_wp_error( $response ) ) {
			echo "Logout Error: " . $response->get_error_message() . "\n";
			return false;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( $data['success'] ) {
			echo "Logout successful!\n";
			return true;
		} else {
			echo "Logout failed: " . $data['message'] . "\n";
			return false;
		}
	}

	/**
	 * Run all tests
	 */
	public static function run_all_tests() {
		echo "=== OBA APIs Integration Test Suite ===\n\n";

		// Test 1: Login
		echo "1. Testing Login...\n";
		$access_token = self::test_login();
		if ( ! $access_token ) {
			echo "Login test failed. Stopping tests.\n";
			return;
		}
		echo "\n";

		// Test 2: Get User
		echo "2. Testing Get User...\n";
		$user_data = self::test_get_user( $access_token );
		echo "\n";

		// Test 3: Get Products
		echo "3. Testing Get Products...\n";
		$products = self::test_get_products();
		echo "\n";

		// Test 4: Get Orders
		echo "4. Testing Get Orders...\n";
		$orders = self::test_get_orders( $access_token );
		echo "\n";

		// Test 5: Logout
		echo "5. Testing Logout...\n";
		self::test_logout( $access_token );
		echo "\n";

		echo "=== Test Suite Complete ===\n";
	}
}

// Example usage (uncomment to run tests):
// OBA_API_Test_Examples::run_all_tests();

/**
 * cURL Examples for testing from command line or external applications
 */

/**
 * Login with cURL
 */
function curl_login_example() {
	$url = 'https://your-site.com/wp-json/oba/v1/auth/login';
	
	$data = [
		'email' => 'test@example.com',
		'password' => 'testpassword',
	];

	$ch = curl_init();
	curl_setopt( $ch, CURLOPT_URL, $url );
	curl_setopt( $ch, CURLOPT_POST, true );
	curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode( $data ) );
	curl_setopt( $ch, CURLOPT_HTTPHEADER, [
		'Content-Type: application/json',
	] );
	curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );

	$response = curl_exec( $ch );
	$http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
	curl_close( $ch );

	echo "HTTP Code: " . $http_code . "\n";
	echo "Response: " . $response . "\n";
}

/**
 * Get user data with cURL
 */
function curl_get_user_example( $access_token ) {
	$url = 'https://your-site.com/wp-json/oba/v1/user/me';
	
	$ch = curl_init();
	curl_setopt( $ch, CURLOPT_URL, $url );
	curl_setopt( $ch, CURLOPT_HTTPGET, true );
	curl_setopt( $ch, CURLOPT_HTTPHEADER, [
		'Authorization: Bearer ' . $access_token,
		'Content-Type: application/json',
	] );
	curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );

	$response = curl_exec( $ch );
	$http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
	curl_close( $ch );

	echo "HTTP Code: " . $http_code . "\n";
	echo "Response: " . $response . "\n";
}

/**
 * JavaScript/Fetch Examples
 */

/**
 * Login with JavaScript Fetch
 */
function javascript_login_example() {
	?>
	<script>
	async function login() {
		const response = await fetch('/wp-json/oba/v1/auth/login', {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
			},
			body: JSON.stringify({
				email: 'test@example.com',
				password: 'testpassword'
			})
		});

		const data = await response.json();
		console.log('Login response:', data);

		if (data.success) {
			// Store the access token
			localStorage.setItem('access_token', data.data.access_token);
			return data.data.access_token;
		}
	}

	// Usage
	login().then(token => {
		if (token) {
			console.log('Login successful, token:', token);
		}
	});
	</script>
	<?php
}

/**
 * Get user data with JavaScript Fetch
 */
function javascript_get_user_example() {
	?>
	<script>
	async function getUser() {
		const token = localStorage.getItem('access_token');
		
		if (!token) {
			console.log('No access token found');
			return;
		}

		const response = await fetch('/wp-json/oba/v1/user/me', {
			method: 'GET',
			headers: {
				'Authorization': 'Bearer ' + token,
				'Content-Type': 'application/json',
			}
		});

		const data = await response.json();
		console.log('User data:', data);
	}

	// Usage
	getUser();
	</script>
	<?php
}

/**
 * PHP cURL command line examples
 */

/**
 * Login command line example
 */
function curl_command_login() {
	return 'curl -X POST https://your-site.com/wp-json/oba/v1/auth/login \
		-H "Content-Type: application/json" \
		-d \'{"email":"test@example.com","password":"testpassword"}\'';
}

/**
 * Get user command line example
 */
function curl_command_get_user( $token ) {
	return 'curl -X GET https://your-site.com/wp-json/oba/v1/user/me \
		-H "Authorization: Bearer ' . $token . '" \
		-H "Content-Type: application/json"';
}

/**
 * Get products command line example
 */
function curl_command_get_products() {
	return 'curl -X GET https://your-site.com/wp-json/oba/v1/products \
		-H "Content-Type: application/json"';
}

// Example usage:
echo "=== cURL Command Examples ===\n";
echo "Login:\n" . curl_command_login() . "\n\n";
echo "Get Products:\n" . curl_command_get_products() . "\n\n"; 