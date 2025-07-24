# OBA APIs Integration

A comprehensive WordPress plugin that provides a secure, scalable backend API service for mobile applications. Built with modern PHP architecture, JWT authentication, and full integration with WooCommerce, Dokan multi-vendor, and Paid Memberships Pro.

## Features

- **Secure JWT Authentication**: Token-based authentication with refresh tokens
- **Modern Architecture**: PSR-4 autoloading, clean separation of concerns
- **RESTful API**: Full REST API with proper HTTP status codes and responses
- **Rate Limiting**: Configurable rate limiting per user
- **CORS Support**: Cross-origin resource sharing for mobile apps
- **Database Logging**: Comprehensive API request logging
- **Admin Interface**: WordPress admin settings page with API documentation
- **Multi-Platform Integration**: WooCommerce, Dokan, Paid Memberships Pro

## Requirements

- WordPress 5.0+
- PHP 7.4+
- WooCommerce (required)
- Dokan Multi-vendor (optional)
- Paid Memberships Pro (optional)
- Composer (for dependency management)

## Installation

1. **Clone or download the plugin** to your WordPress plugins directory:
   ```bash
   cd wp-content/plugins/
   git clone <repository-url> oba-apis-integration
   ```

2. **Install dependencies**:
   ```bash
   cd oba-apis-integration
   composer install
   ```

3. **Activate the plugin** through WordPress admin or via WP-CLI:
   ```bash
   wp plugin activate oba-apis-integration
   ```

4. **Configure the plugin** by going to WooCommerce → OBA APIs in the WordPress admin

## Configuration

### JWT Settings
- **JWT Secret**: Secret key for token signing (auto-generated if empty)
- **Access Token Expiration**: How long access tokens are valid (300-86400 seconds)
- **Refresh Token Expiration**: How long refresh tokens are valid (3600-2592000 seconds)

### Rate Limiting
- **API Rate Limit**: Maximum requests per minute per user (0 to disable)

### CORS Settings
- **Enable CORS**: Enable Cross-Origin Resource Sharing
- **Allowed Origins**: Comma-separated list of allowed origins (use * for all)

## API Endpoints

### Authentication

#### POST /wp-json/oba/v1/auth/login
User login with email and password.

**Request Body:**
```json
{
  "email": "user@example.com",
  "password": "userpassword"
}
```

**Response:**
```json
{
  "success": true,
  "message": "Login successful.",
  "data": {
    "access_token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
    "refresh_token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
    "expires_in": 3600,
    "token_type": "Bearer",
    "user": {
      "id": 1,
      "email": "user@example.com",
      "username": "username",
      "display_name": "User Name",
      "roles": ["customer"],
      "woocommerce": {
        "customer_id": 1,
        "billing_address": {...},
        "shipping_address": {...}
      }
    }
  }
}
```

#### POST /wp-json/oba/v1/auth/logout
User logout (requires authentication).

**Headers:**
```
Authorization: Bearer <access_token>
```

#### POST /wp-json/oba/v1/auth/refresh
Refresh access token using refresh token.

**Request Body:**
```json
{
  "refresh_token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9..."
}
```

### User Management

#### GET /wp-json/oba/v1/user/me
Get current user information (requires authentication).

**Headers:**
```
Authorization: Bearer <access_token>
```

#### PUT /wp-json/oba/v1/user/profile
Update user profile (requires authentication).

**Headers:**
```
Authorization: Bearer <access_token>
```

**Request Body:**
```json
{
  "first_name": "John",
  "last_name": "Doe",
  "display_name": "John Doe",
  "billing_first_name": "John",
  "billing_last_name": "Doe",
  "billing_address_1": "123 Main St",
  "billing_city": "New York",
  "billing_state": "NY",
  "billing_postcode": "10001",
  "billing_country": "US"
}
```

### Products

#### GET /wp-json/oba/v1/products
Get products list with filtering and pagination.

**Query Parameters:**
- `page` (optional): Page number (default: 1)
- `per_page` (optional): Items per page (default: 10, max: 50)
- `category` (optional): Product category slug
- `search` (optional): Search term
- `orderby` (optional): Sort field (date, title, price, etc.)
- `order` (optional): Sort order (ASC, DESC)
- `vendor_id` (optional): Filter by vendor ID

#### GET /wp-json/oba/v1/products/{id}
Get specific product details.

#### GET /wp-json/oba/v1/products/categories
Get product categories.

**Query Parameters:**
- `parent` (optional): Parent category ID
- `hide_empty` (optional): Hide empty categories (default: true)

### Orders

#### GET /wp-json/oba/v1/orders
Get user orders (requires authentication).

**Headers:**
```
Authorization: Bearer <access_token>
```

**Query Parameters:**
- `page` (optional): Page number
- `per_page` (optional): Items per page
- `status` (optional): Order status filter
- `orderby` (optional): Sort field
- `order` (optional): Sort order

#### GET /wp-json/oba/v1/orders/{id}
Get specific order details (requires authentication).

#### POST /wp-json/oba/v1/orders
Create new order (requires authentication).

**Request Body:**
```json
{
  "items": [
    {
      "product_id": 123,
      "quantity": 2
    }
  ],
  "billing_address": {
    "first_name": "John",
    "last_name": "Doe",
    "address_1": "123 Main St",
    "city": "New York",
    "state": "NY",
    "postcode": "10001",
    "country": "US",
    "email": "john@example.com",
    "phone": "+1234567890"
  },
  "shipping_address": {
    "first_name": "John",
    "last_name": "Doe",
    "address_1": "123 Main St",
    "city": "New York",
    "state": "NY",
    "postcode": "10001",
    "country": "US"
  },
  "payment_method": "stripe",
  "status": "pending"
}
```

### Vendors (Dokan)

#### GET /wp-json/oba/v1/vendors
Get vendors list.

**Query Parameters:**
- `page` (optional): Page number
- `per_page` (optional): Items per page
- `search` (optional): Search term
- `orderby` (optional): Sort field
- `order` (optional): Sort order

#### GET /wp-json/oba/v1/vendors/{id}
Get specific vendor details.

#### GET /wp-json/oba/v1/vendors/{id}/products
Get vendor products.

**Query Parameters:**
- `page` (optional): Page number
- `per_page` (optional): Items per page
- `category` (optional): Product category
- `search` (optional): Search term

### Membership (Paid Memberships Pro)

#### GET /wp-json/oba/v1/membership/status
Get user membership status (requires authentication).

**Headers:**
```
Authorization: Bearer <access_token>
```

#### GET /wp-json/oba/v1/membership/plans
Get membership plans.

**Query Parameters:**
- `page` (optional): Page number
- `per_page` (optional): Items per page
- `active_only` (optional): Show only active plans (default: true)

## Error Handling

The API returns proper HTTP status codes and error messages:

```json
{
  "code": "authentication_required",
  "message": "Authentication required.",
  "data": {
    "status": 401
  }
}
```

Common HTTP status codes:
- `200` - Success
- `201` - Created
- `400` - Bad Request
- `401` - Unauthorized
- `403` - Forbidden
- `404` - Not Found
- `429` - Too Many Requests
- `500` - Internal Server Error

## Security Features

- **JWT Token Authentication**: Secure token-based authentication
- **Token Blacklisting**: Secure logout with token invalidation
- **Rate Limiting**: Configurable rate limiting per user
- **CORS Protection**: Configurable cross-origin access
- **Input Validation**: Comprehensive input sanitization and validation
- **SQL Injection Protection**: Prepared statements and proper escaping
- **XSS Protection**: Output escaping and content security headers

## Database Tables

The plugin creates the following database tables:

- `wp_oba_api_logs` - API request logging
- `wp_oba_rate_limits` - Rate limiting data
- `wp_oba_jwt_blacklist` - Blacklisted JWT tokens
- `wp_oba_api_settings` - API settings storage

## Development

### Adding New Endpoints

1. Create a new service class in `src/Services/`
2. Add the route registration in `src/Plugin.php`
3. Implement the endpoint logic in your service class

Example:
```php
// In src/Plugin.php
$this->router->register_route( 'custom/endpoint', 'GET', [ $this->services['custom'], 'get_data' ], [ AuthMiddleware::class ] );

// In src/Services/CustomService.php
public function get_data( $request ) {
    // Your endpoint logic here
    return new WP_REST_Response( [ 'data' => $result ], 200 );
}
```

### Custom Middleware

Create custom middleware by extending the base middleware pattern:

```php
// In src/API/Middleware/CustomMiddleware.php
class CustomMiddleware {
    public function handle( $request ) {
        // Your middleware logic here
        return null; // Continue to next middleware/controller
    }
}
```

## Troubleshooting

### Common Issues

1. **JWT Secret Not Set**: The plugin will auto-generate a JWT secret on first use
2. **CORS Issues**: Ensure CORS is enabled and allowed origins are configured
3. **Rate Limiting**: Check rate limit settings if requests are being blocked
4. **Dependencies**: Ensure WooCommerce is installed and activated

### Debug Mode

Enable debug mode in the admin settings to get detailed error messages. Only use in development environments.

### Logs

API requests are logged to the database. You can view logs through the admin interface or directly query the `wp_oba_api_logs` table.

## Support

For support and questions:
- Check the WordPress admin settings page for API documentation
- Review the error logs for debugging information
- Ensure all dependencies are properly installed and configured

## License

This plugin is licensed under the GPL v2 or later.

## Changelog

### Version 1.0.0
- Initial release
- JWT authentication system
- Complete REST API implementation
- WooCommerce integration
- Dokan multi-vendor support
- Paid Memberships Pro integration
- Rate limiting and CORS support
- Admin interface with documentation 