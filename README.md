# OBA APIs Integration

A comprehensive WordPress plugin providing backend API services for mobile applications with JWT authentication, WooCommerce integration, and extensive e-commerce and healthcare functionality.

![Version](https://img.shields.io/badge/version-1.0.3-blue.svg)
![WordPress](https://img.shields.io/badge/wordpress-5.0%2B-brightgreen.svg)
![PHP](https://img.shields.io/badge/php-7.4%2B-purple.svg)
![WooCommerce](https://img.shields.io/badge/woocommerce-required-orange.svg)

## 📋 Table of Contents

- [Overview](#overview)
- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [API Documentation](#api-documentation)
- [Authentication](#authentication)
- [Testing](#testing)
- [Development](#development)
- [Security](#security)
- [Support](#support)

## 🌟 Overview

OBA APIs Integration is a powerful WordPress plugin designed to provide a complete backend API solution for mobile applications. It features JWT-based authentication, seamless WooCommerce integration, and a comprehensive set of REST API endpoints for e-commerce, healthcare appointments, membership management, and more.

The plugin is built with modern PHP practices, including PSR-4 autoloading, namespacing, and a modular service-oriented architecture.

## ✨ Features

### Core Features
- **JWT Authentication** - Secure token-based authentication with access and refresh tokens
- **Social Login** - Google and Apple Sign-In integration
- **WooCommerce Integration** - Complete e-commerce functionality
- **Membership Management** - Paid Memberships Pro integration
- **Custom Cart System** - Enhanced cart management for mobile apps
- **Apple App Store Notifications** - ASSN v2 integration for subscription management

### API Endpoints
- **Authentication** - Login, logout, token refresh, social login
- **User Management** - Profile management, recommendations
- **Products** - Product catalog, categories, search, filtering
- **Orders** - Order history, creation, tracking
- **Cart & Checkout** - Cart management, shipping, coupons, payment
- **Appointments** - Healthcare appointment scheduling and management
- **Video Calls** - Appointment-based video call integration
- **Doctors** - Doctor profiles, ratings, emergency clinics
- **Vendors** - Multi-vendor support with product listings
- **Membership** - Subscription plans, signup, cancellation
- **Blog** - Blog post integration
- **Surveys** - User surveys for personalized recommendations
- **Medication Requests** - Prescription and medication management
- **Credit System** - User credit wallet functionality
- **Payment Methods** - Credit card management

### Security Features
- JWT token blacklisting
- Rate limiting
- CORS configuration
- Security headers (X-Frame-Options, X-Content-Type-Options, etc.)
- Request validation and sanitization
- One-time token login for secure redirects

## 📦 Requirements

- **WordPress**: 5.0 or higher
- **PHP**: 7.4 or higher
- **WooCommerce**: Latest version
- **Paid Memberships Pro**: Latest version (optional, for membership features)
- **PHP Extensions**: 
  - OpenSSL
  - JSON
  - cURL

## 🚀 Installation

### Method 1: Manual Installation

1. **Download or Clone the Repository**
   ```bash
   cd wp-content/plugins/
   git clone https://github.com/AhmedHany2021/OBAApis.git oba-apis-integration
   cd oba-apis-integration
   ```

2. **Install Dependencies**
   ```bash
   composer install --no-dev
   ```

3. **Activate the Plugin**
   - Go to WordPress Admin → Plugins
   - Find "OBA APIs Integration"
   - Click "Activate"

### Method 2: Upload via WordPress Admin

1. Download the plugin as a ZIP file
2. Go to WordPress Admin → Plugins → Add New
3. Click "Upload Plugin"
4. Choose the ZIP file and click "Install Now"
5. Click "Activate Plugin"

### Post-Installation

After activation, the plugin will:
- Create necessary database tables
- Set up default configuration options
- Generate a secure JWT secret key
- Flush rewrite rules for REST API endpoints

## ⚙️ Configuration

### Access Settings

Go to **WooCommerce → OBA APIs** in the WordPress admin panel to configure:

### JWT Settings
- **JWT Secret**: Auto-generated secure key (can be regenerated)
- **Access Token Expiration**: Default 3600 seconds (1 hour)
- **Refresh Token Expiration**: Default 604800 seconds (7 days)

### Rate Limiting
- **Enable Rate Limiting**: ON/OFF
- **Requests Per Minute**: Default 60 requests

### CORS Settings
- **Enable CORS**: ON/OFF
- **Allowed Origins**: Comma-separated list or `*` for all origins

### API Logging
- **Enable Logging**: ON/OFF
- **Log Retention**: Default 30 days

### Constants

You can define these constants in `wp-config.php` for additional configuration:

```php
// Instance name for MDClara integration
define('MDCLARA_INSTANCE_NAME', 'OBA');

// MDClara API key
define('MDCLARA_KEY', 'your-mdclara-api-key');
```

## 📚 API Documentation

### Base URL

All API endpoints are available under:
```
https://your-site.com/wp-json/oba/v1/
```

### API Namespace

```
oba/v1
```

### Endpoints Overview

#### Authentication
```
POST   /auth/login                    - Login with email/password
POST   /auth/google-login             - Login with Google
POST   /auth/apple-login              - Login with Apple
POST   /auth/logout                   - Logout (requires auth)
POST   /auth/refresh                  - Refresh access token
POST   /auth/forgot-password          - Request password reset
POST   /auth/verify-reset-token       - Verify reset token
POST   /auth/reset-password           - Reset password
```

#### User Management
```
GET    /user/me                       - Get current user (requires auth)
POST   /user/profile                  - Update profile (requires auth)
POST   /user/recommendations-products - Get recommended products (requires auth)
POST   /user/recommendations-doctors  - Get recommended doctors (requires auth)
POST   /user/generate-one-time-token  - Generate one-time login token (requires auth)
```

#### Products
```
GET    /products                      - Get all products
GET    /products/{id}                 - Get single product
GET    /products/categories           - Get product categories
GET    /products/check-survey         - Check user survey status (requires auth)
```

#### Orders
```
GET    /orders                        - Get user orders (requires auth)
GET    /orders/{id}                   - Get single order (requires auth)
POST   /orders                        - Create order (requires auth)
```

#### Cart
```
GET    /cart                          - Get cart (requires auth)
GET    /cart/summary                  - Get cart summary (requires auth)
POST   /cart/add                      - Add item to cart (requires auth)
POST   /cart/remove                   - Remove item from cart (requires auth)
POST   /cart/update                   - Update cart item (requires auth)
POST   /cart/clear                    - Clear cart (requires auth)
```

#### Checkout
```
GET    /checkout                      - Get checkout data (requires auth)
POST   /checkout/shipping             - Get shipping rates (requires auth)
POST   /checkout/shipping/update      - Update shipping method (requires auth)
POST   /checkout/process              - Process checkout (requires auth)
POST   /checkout/validate             - Validate checkout (requires auth)
POST   /checkout/coupon/check         - Check coupon validity (requires auth)
POST   /checkout/coupon/apply         - Apply coupon (requires auth)
POST   /checkout/coupon/remove        - Remove coupon (requires auth)
```

#### Vendors
```
GET    /vendors                       - Get all vendors
GET    /vendors/{id}                  - Get single vendor
GET    /vendors/{id}/products         - Get vendor products
```

#### Membership
```
GET    /membership/checkout/fields    - Get checkout fields
POST   /membership/send-otp           - Send OTP for verification
POST   /membership/verify-otp         - Verify OTP code
POST   /membership/signup             - Complete signup
GET    /membership/status             - Get membership status (requires auth)
GET    /membership/plans              - Get membership plans
POST   /membership/cancel             - Cancel membership (requires auth)
POST   /membership/change             - Change membership (requires auth)
GET    /membership/profile            - Get user profile (requires auth)
PUT    /membership/profile/update     - Update profile (requires auth)
```

#### Appointments
```
POST   /appointments                  - Create appointment (requires auth)
GET    /appointments                  - Get appointments (requires auth)
GET    /appointments/{id}             - Get single appointment (requires auth)
GET    /call-requests                 - Get call requests (requires auth)
```

#### Video Calls
```
GET    /call/pending                  - Check call status (requires auth)
GET    /call/end/{id}                 - Check call end status (requires auth)
POST   /call/update/{id}              - Update appointment call ID (requires auth)
POST   /call/feedback/submit          - Submit call feedback (requires auth)
```

#### Doctors
```
GET    /doctors/rating                - Get doctor ratings
GET    /doctors/emergency-clinics     - Get emergency clinics
```

#### Blog
```
GET    /blog/posts                    - Get all blog posts
GET    /blog/posts/{id}               - Get single blog post
```

#### Surveys
```
GET    /survey/{id}                   - Get survey by ID
POST   /survey/submit                 - Submit survey (requires auth)
POST   /survey/retake                 - Retake survey (requires auth)
```

#### Medication Requests
```
GET    /medication-requests           - Get medication requests (requires auth)
```

#### Credit System
```
GET    /credits                       - Get user credit balance (requires auth)
POST   /credits/add                   - Add user credit (requires auth)
```

#### Payment Methods
```
POST   /payment-methods               - Create payment method (requires auth)
GET    /payment-methods               - Get all payment methods (requires auth)
GET    /payment-methods/{id}          - Get payment method (requires auth)
PUT    /payment-methods/{id}          - Update payment method (requires auth)
DELETE /payment-methods/{id}          - Delete payment method (requires auth)
POST   /payment-methods/{id}/set-default - Set default payment method (requires auth)
```

#### Apple Notifications
```
POST   /apple/notifications           - Apple App Store Server Notifications webhook
```

### Response Format

All API responses follow a consistent format:

**Success Response:**
```json
{
  "success": true,
  "data": {
    // Response data
  },
  "message": "Success message"
}
```

**Error Response:**
```json
{
  "success": false,
  "message": "Error message",
  "code": "error_code",
  "status": 400
}
```

## 🔐 Authentication

### JWT Token Flow

1. **Login**: Send credentials to `/auth/login` to receive access and refresh tokens
2. **Authenticated Requests**: Include access token in the `Authorization` header
3. **Token Refresh**: Use refresh token at `/auth/refresh` when access token expires
4. **Logout**: Call `/auth/logout` to blacklist tokens

### Request Headers

For authenticated endpoints, include:

```
Authorization: Bearer {access_token}
Content-Type: application/json
```

### Example Login Request

```bash
curl -X POST https://your-site.com/wp-json/oba/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "user@example.com",
    "password": "password123"
  }'
```

### Example Authenticated Request

```bash
curl -X GET https://your-site.com/wp-json/oba/v1/user/me \
  -H "Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGc..."
```

## 🧪 Testing

### Postman Collection

A complete Postman collection is available in the `/postman` directory:

1. **Import Collection**
   - Open Postman
   - Import `OBA-APIs-Integration.postman_collection.json`
   - Import `OBA-APIs-Integration.postman_environment.json`

2. **Configure Environment**
   - Select "OBA APIs Integration Environment"
   - Update `base_url` with your WordPress site URL
   - Update test credentials and IDs

3. **Run Tests**
   - Start with Authentication → Login
   - Tokens are automatically saved for subsequent requests
   - Test endpoints in logical order

For detailed testing instructions, see [postman/README.md](postman/README.md)

### Newman CLI

Run automated tests with Newman:

```bash
npm install -g newman
newman run postman/OBA-APIs-Integration.postman_collection.json \
  -e postman/OBA-APIs-Integration.postman_environment.json
```

## 👨‍💻 Development

### Project Structure

```
oba-apis-integration/
├── src/
│   ├── API/
│   │   ├── Controllers/       # API controllers
│   │   ├── Middleware/        # Auth and request middleware
│   │   └── Router.php         # REST API router
│   ├── Core/
│   │   ├── JWT.php            # JWT token handling
│   │   └── Options.php        # Plugin options management
│   ├── Database/
│   │   ├── Migration.php      # Database migrations
│   │   └── CartTable.php      # Custom cart table
│   ├── Helpers/               # Helper classes
│   ├── Middleware/            # Additional middleware
│   ├── Services/              # Business logic services
│   ├── Traits/                # Reusable traits
│   └── Plugin.php             # Main plugin class
├── templates/
│   └── admin/
│       └── settings.php       # Admin settings page
├── postman/                   # Postman collection and docs
├── composer.json              # Composer dependencies
├── oba-apis-integration.php   # Plugin entry point
└── README.md                  # This file
```

### Composer Scripts

```bash
# Run PHPUnit tests
composer test

# Run PHP CodeSniffer
composer phpcs

# Auto-fix coding standards
composer phpcbf
```

### Adding New Endpoints

1. **Create Service Class** in `src/Services/`
2. **Register Service** in `Plugin::init_services()`
3. **Register Route** in `Plugin::register_routes()`
4. **Add Middleware** if authentication required

Example:

```php
// In Plugin::init_services()
$this->services['my_service'] = new MyService();

// In Plugin::register_routes()
$this->router->register_route(
    'my-endpoint',
    'GET',
    [$this->services['my_service'], 'my_method'],
    [AuthMiddleware::class] // Optional
);
```

### Custom Middleware

Create middleware in `src/API/Middleware/`:

```php
<?php
namespace OBA\APIsIntegration\API\Middleware;

use WP_REST_Request;

class MyMiddleware {
    public function handle(WP_REST_Request $request) {
        // Validation logic
        if (!$this->is_valid($request)) {
            return new \WP_Error('invalid_request', 'Invalid request');
        }
        return null; // Continue to next middleware
    }
}
```

## 🔒 Security

### Best Practices

1. **Keep WordPress and Dependencies Updated**
   - Regularly update WordPress core
   - Update WooCommerce and other plugins
   - Run `composer update` periodically

2. **Secure JWT Secret**
   - Never commit the JWT secret to version control
   - Regenerate secret if compromised
   - Use strong, random secrets (auto-generated on activation)

3. **HTTPS Required**
   - Always use HTTPS in production
   - JWT tokens are transmitted in headers

4. **Rate Limiting**
   - Enable rate limiting in plugin settings
   - Adjust limits based on your application needs

5. **Input Validation**
   - All user inputs are sanitized and validated
   - Use WordPress sanitization functions

6. **Token Management**
   - Access tokens expire after 1 hour by default
   - Refresh tokens expire after 7 days by default
   - Tokens are blacklisted on logout

### Security Headers

The plugin automatically adds:
- `X-Content-Type-Options: nosniff`
- `X-Frame-Options: DENY`
- `X-XSS-Protection: 1; mode=block`
- `Referrer-Policy: strict-origin-when-cross-origin`

### Apple App Store Notifications

For Apple subscription management, see [APPLE_NOTIFICATIONS_SETUP.md](APPLE_NOTIFICATIONS_SETUP.md)

## 📝 Support

### Getting Help

1. **Documentation**: Check this README and the Postman documentation
2. **Debug Logs**: Enable WordPress debug mode to see detailed logs
3. **Issue Tracker**: Submit issues on GitHub
4. **Contact**: Reach out to the development team

### Debugging

Enable debug mode in `wp-config.php`:

```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
```

Logs are written to `/wp-content/debug.log`

### Common Issues

**REST API Not Working**
- Ensure permalinks are not set to "Plain"
- Check that WooCommerce is activated
- Verify .htaccess file is writable

**Authentication Failing**
- Verify JWT secret is set in settings
- Check token expiration settings
- Ensure Authorization header is being sent

**CORS Errors**
- Enable CORS in plugin settings
- Add your app's origin to allowed origins
- Check browser console for specific errors

## 📄 License

GPL v2 or later - https://www.gnu.org/licenses/gpl-2.0.html

## 👥 Credits

**Author**: OBA Team  
**Contributors**: Development team members  
**Version**: 1.0.3  
**Repository**: https://github.com/AhmedHany2021/OBAApis

---

Made with ❤️ for mobile app development

