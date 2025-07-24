<?php
/**
 * Admin settings template
 *
 * @package OBA\APIsIntegration
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$options = \OBA\APIsIntegration\Core\Options::get_all();
?>

<div class="wrap">
	<h1><?php esc_html_e( 'OBA APIs Integration Settings', 'oba-apis-integration' ); ?></h1>
	
	<div class="notice notice-info">
		<p>
			<strong><?php esc_html_e( 'API Base URL:', 'oba-apis-integration' ); ?></strong>
			<code><?php echo esc_url( \OBA\APIsIntegration\API\Router::get_api_base_url() ); ?></code>
		</p>
	</div>

	<form method="post" action="">
		<?php wp_nonce_field( 'oba_apis_integration_settings', 'oba_apis_integration_nonce' ); ?>
		<input type="hidden" name="oba_apis_integration_settings" value="1">

		<table class="form-table">
			<tbody>
				<!-- JWT Settings -->
				<tr>
					<th scope="row" colspan="2">
						<h2><?php esc_html_e( 'JWT Authentication Settings', 'oba-apis-integration' ); ?></h2>
					</th>
				</tr>
				
				<tr>
					<th scope="row">
						<label for="jwt_secret"><?php esc_html_e( 'JWT Secret', 'oba-apis-integration' ); ?></label>
					</th>
					<td>
						<input type="text" id="jwt_secret" name="jwt_secret" value="<?php echo esc_attr( $options['jwt_secret'] ); ?>" class="regular-text" />
						<p class="description">
							<?php esc_html_e( 'Secret key for JWT token signing. Leave empty to auto-generate.', 'oba-apis-integration' ); ?>
						</p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="jwt_expiration"><?php esc_html_e( 'Access Token Expiration (seconds)', 'oba-apis-integration' ); ?></label>
					</th>
					<td>
						<input type="number" id="jwt_expiration" name="jwt_expiration" value="<?php echo esc_attr( $options['jwt_expiration'] ); ?>" class="small-text" min="300" max="86400" />
						<p class="description">
							<?php esc_html_e( 'How long access tokens are valid (300-86400 seconds).', 'oba-apis-integration' ); ?>
						</p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="jwt_refresh_expiration"><?php esc_html_e( 'Refresh Token Expiration (seconds)', 'oba-apis-integration' ); ?></label>
					</th>
					<td>
						<input type="number" id="jwt_refresh_expiration" name="jwt_refresh_expiration" value="<?php echo esc_attr( $options['jwt_refresh_expiration'] ); ?>" class="small-text" min="3600" max="2592000" />
						<p class="description">
							<?php esc_html_e( 'How long refresh tokens are valid (3600-2592000 seconds).', 'oba-apis-integration' ); ?>
						</p>
					</td>
				</tr>

				<!-- Rate Limiting -->
				<tr>
					<th scope="row" colspan="2">
						<h2><?php esc_html_e( 'Rate Limiting Settings', 'oba-apis-integration' ); ?></h2>
					</th>
				</tr>

				<tr>
					<th scope="row">
						<label for="api_rate_limit"><?php esc_html_e( 'API Rate Limit (requests per minute)', 'oba-apis-integration' ); ?></label>
					</th>
					<td>
						<input type="number" id="api_rate_limit" name="api_rate_limit" value="<?php echo esc_attr( $options['api_rate_limit'] ); ?>" class="small-text" min="0" max="1000" />
						<p class="description">
							<?php esc_html_e( 'Maximum requests per minute per user. Set to 0 to disable rate limiting.', 'oba-apis-integration' ); ?>
						</p>
					</td>
				</tr>

				<!-- CORS Settings -->
				<tr>
					<th scope="row" colspan="2">
						<h2><?php esc_html_e( 'CORS Settings', 'oba-apis-integration' ); ?></h2>
					</th>
				</tr>

				<tr>
					<th scope="row">
						<label for="enable_cors"><?php esc_html_e( 'Enable CORS', 'oba-apis-integration' ); ?></label>
					</th>
					<td>
						<input type="checkbox" id="enable_cors" name="enable_cors" value="1" <?php checked( $options['enable_cors'] ); ?> />
						<label for="enable_cors"><?php esc_html_e( 'Enable Cross-Origin Resource Sharing', 'oba-apis-integration' ); ?></label>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="allowed_origins"><?php esc_html_e( 'Allowed Origins', 'oba-apis-integration' ); ?></label>
					</th>
					<td>
						<textarea id="allowed_origins" name="allowed_origins" rows="3" class="large-text"><?php echo esc_textarea( $options['allowed_origins'] ); ?></textarea>
						<p class="description">
							<?php esc_html_e( 'Comma-separated list of allowed origins. Use * for all origins.', 'oba-apis-integration' ); ?>
						</p>
					</td>
				</tr>

				<!-- Debug Settings -->
				<tr>
					<th scope="row" colspan="2">
						<h2><?php esc_html_e( 'Debug Settings', 'oba-apis-integration' ); ?></h2>
					</th>
				</tr>

				<tr>
					<th scope="row">
						<label for="debug_mode"><?php esc_html_e( 'Debug Mode', 'oba-apis-integration' ); ?></label>
					</th>
					<td>
						<input type="checkbox" id="debug_mode" name="debug_mode" value="1" <?php checked( $options['debug_mode'] ); ?> />
						<label for="debug_mode"><?php esc_html_e( 'Enable debug mode (shows detailed error messages)', 'oba-apis-integration' ); ?></label>
						<p class="description">
							<?php esc_html_e( 'Warning: This may expose sensitive information. Only enable in development.', 'oba-apis-integration' ); ?>
						</p>
					</td>
				</tr>
			</tbody>
		</table>

		<?php submit_button( __( 'Save Settings', 'oba-apis-integration' ) ); ?>
	</form>

	<!-- API Documentation -->
	<div class="card">
		<h2><?php esc_html_e( 'API Documentation', 'oba-apis-integration' ); ?></h2>
		
		<h3><?php esc_html_e( 'Authentication Endpoints', 'oba-apis-integration' ); ?></h3>
		<table class="widefat">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Endpoint', 'oba-apis-integration' ); ?></th>
					<th><?php esc_html_e( 'Method', 'oba-apis-integration' ); ?></th>
					<th><?php esc_html_e( 'Description', 'oba-apis-integration' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<td><code>/wp-json/oba/v1/auth/login</code></td>
					<td>POST</td>
					<td><?php esc_html_e( 'User login with email and password', 'oba-apis-integration' ); ?></td>
				</tr>
				<tr>
					<td><code>/wp-json/oba/v1/auth/logout</code></td>
					<td>POST</td>
					<td><?php esc_html_e( 'User logout (requires authentication)', 'oba-apis-integration' ); ?></td>
				</tr>
				<tr>
					<td><code>/wp-json/oba/v1/auth/refresh</code></td>
					<td>POST</td>
					<td><?php esc_html_e( 'Refresh access token using refresh token', 'oba-apis-integration' ); ?></td>
				</tr>
			</tbody>
		</table>

		<h3><?php esc_html_e( 'User Endpoints', 'oba-apis-integration' ); ?></h3>
		<table class="widefat">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Endpoint', 'oba-apis-integration' ); ?></th>
					<th><?php esc_html_e( 'Method', 'oba-apis-integration' ); ?></th>
					<th><?php esc_html_e( 'Description', 'oba-apis-integration' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<td><code>/wp-json/oba/v1/user/me</code></td>
					<td>GET</td>
					<td><?php esc_html_e( 'Get current user information', 'oba-apis-integration' ); ?></td>
				</tr>
				<tr>
					<td><code>/wp-json/oba/v1/user/profile</code></td>
					<td>PUT</td>
					<td><?php esc_html_e( 'Update user profile', 'oba-apis-integration' ); ?></td>
				</tr>
			</tbody>
		</table>

		<h3><?php esc_html_e( 'Product Endpoints', 'oba-apis-integration' ); ?></h3>
		<table class="widefat">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Endpoint', 'oba-apis-integration' ); ?></th>
					<th><?php esc_html_e( 'Method', 'oba-apis-integration' ); ?></th>
					<th><?php esc_html_e( 'Description', 'oba-apis-integration' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<td><code>/wp-json/oba/v1/products</code></td>
					<td>GET</td>
					<td><?php esc_html_e( 'Get products list', 'oba-apis-integration' ); ?></td>
				</tr>
				<tr>
					<td><code>/wp-json/oba/v1/products/{id}</code></td>
					<td>GET</td>
					<td><?php esc_html_e( 'Get specific product', 'oba-apis-integration' ); ?></td>
				</tr>
				<tr>
					<td><code>/wp-json/oba/v1/products/categories</code></td>
					<td>GET</td>
					<td><?php esc_html_e( 'Get product categories', 'oba-apis-integration' ); ?></td>
				</tr>
			</tbody>
		</table>

		<h3><?php esc_html_e( 'Order Endpoints', 'oba-apis-integration' ); ?></h3>
		<table class="widefat">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Endpoint', 'oba-apis-integration' ); ?></th>
					<th><?php esc_html_e( 'Method', 'oba-apis-integration' ); ?></th>
					<th><?php esc_html_e( 'Description', 'oba-apis-integration' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<td><code>/wp-json/oba/v1/orders</code></td>
					<td>GET</td>
					<td><?php esc_html_e( 'Get user orders (requires authentication)', 'oba-apis-integration' ); ?></td>
				</tr>
				<tr>
					<td><code>/wp-json/oba/v1/orders/{id}</code></td>
					<td>GET</td>
					<td><?php esc_html_e( 'Get specific order (requires authentication)', 'oba-apis-integration' ); ?></td>
				</tr>
				<tr>
					<td><code>/wp-json/oba/v1/orders</code></td>
					<td>POST</td>
					<td><?php esc_html_e( 'Create new order (requires authentication)', 'oba-apis-integration' ); ?></td>
				</tr>
			</tbody>
		</table>

		<h3><?php esc_html_e( 'Vendor Endpoints', 'oba-apis-integration' ); ?></h3>
		<table class="widefat">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Endpoint', 'oba-apis-integration' ); ?></th>
					<th><?php esc_html_e( 'Method', 'oba-apis-integration' ); ?></th>
					<th><?php esc_html_e( 'Description', 'oba-apis-integration' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<td><code>/wp-json/oba/v1/vendors</code></td>
					<td>GET</td>
					<td><?php esc_html_e( 'Get vendors list', 'oba-apis-integration' ); ?></td>
				</tr>
				<tr>
					<td><code>/wp-json/oba/v1/vendors/{id}</code></td>
					<td>GET</td>
					<td><?php esc_html_e( 'Get specific vendor', 'oba-apis-integration' ); ?></td>
				</tr>
				<tr>
					<td><code>/wp-json/oba/v1/vendors/{id}/products</code></td>
					<td>GET</td>
					<td><?php esc_html_e( 'Get vendor products', 'oba-apis-integration' ); ?></td>
				</tr>
			</tbody>
		</table>

		<h3><?php esc_html_e( 'Membership Endpoints', 'oba-apis-integration' ); ?></h3>
		<table class="widefat">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Endpoint', 'oba-apis-integration' ); ?></th>
					<th><?php esc_html_e( 'Method', 'oba-apis-integration' ); ?></th>
					<th><?php esc_html_e( 'Description', 'oba-apis-integration' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<td><code>/wp-json/oba/v1/membership/status</code></td>
					<td>GET</td>
					<td><?php esc_html_e( 'Get user membership status (requires authentication)', 'oba-apis-integration' ); ?></td>
				</tr>
				<tr>
					<td><code>/wp-json/oba/v1/membership/plans</code></td>
					<td>GET</td>
					<td><?php esc_html_e( 'Get membership plans', 'oba-apis-integration' ); ?></td>
				</tr>
			</tbody>
		</table>
	</div>
</div> 