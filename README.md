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

### Appointments

#### POST /wp-json/oba/v1/appointments
Create a new medical appointment.

**Required Parameters:**
- doctor_id: ID of the doctor
- clinic_id: ID of the clinic
- patient_id: ID of the patient
- appointment_date: Date of appointment (YYYY-MM-DD)
- appointment_time: Time of appointment (HH:MM)
- appointment_id: Unique appointment identifier

**Request Body:**
```json
{
    "doctor_id": "{{doctor_id}}",
    "clinic_id": "{{clinic_id}}",
    "patient_id": "{{patient_id}}",
    "appointment_date": "{{appointment_date}}",
    "appointment_time": "{{appointment_time}}",
    "appointment_id": "{{appointment_id}}"
}
```

#### GET /wp-json/oba/v1/appointments
Get all appointments for the current user.

#### GET /wp-json/oba/v1/appointments/{id}
Get details of a specific appointment.

### Calls

#### GET /wp-json/oba/v1/call/pending
Check if there's a pending call for the current user.

#### GET /wp-json/oba/v1/call/end/{appointment_id}
Check the end status of a call for a specific appointment.

**Note:** The 'appointment_id' parameter refers to the appointment ID.

#### POST /wp-json/oba/v1/call/update/{appointment_id}
Update the call ID for a specific appointment.

**Request Body:**
```json
{
    "call_id": "{{call_id}}"
}
```

**Note:** The 'appointment_id' parameter in the URL refers to the appointment ID.

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

### Cart

#### GET /wp-json/oba/v1/cart
Get user cart contents with detailed item information (requires authentication).

**Headers:**
```
Authorization: Bearer <access_token>
```

**Response:**
```json
{
  "success": true,
  "data": {
    "items": [
      {
        "id": 1,
        "product_id": 123,
        "variation_id": 456,
        "quantity": 2,
        "name": "Product Name",
        "price": 29.99,
        "subtotal": 59.98,
        "image": "https://example.com/image.jpg",
        "options": {},
        "stock_status": "instock",
        "stock_quantity": 10,
        "created_at": "2024-01-01 10:00:00",
        "updated_at": "2024-01-01 10:00:00"
      }
    ],
    "subtotal": 59.98,
    "total": 59.98,
    "currency": "USD",
    "currency_symbol": "$",
    "item_count": 1,
    "total_quantity": 2
  }
}
```

#### GET /wp-json/oba/v1/cart/summary
Get cart summary (item count and total quantity) (requires authentication).

**Headers:**
```
Authorization: Bearer <access_token>
```

**Response:**
```json
{
  "success": true,
  "data": {
    "item_count": 3,
    "total_quantity": 5
  }
}
```

#### POST /wp-json/oba/v1/cart/add
Add product to cart or update quantity if already exists (requires authentication).

**Headers:**
```
Authorization: Bearer <access_token>
Content-Type: application/json
```

**Request Body:**
```json
{
  "product_id": 123,
  "quantity": 2,
  "variation_id": 456,
  "options": {}
}
```

**Response:**
```json
{
  "success": true,
  "message": "Product added to cart successfully.",
  "data": {
    "product_id": 123,
    "variation_id": 456,
    "quantity": 2,
    "cart": {
      "items": [...],
      "subtotal": 59.98,
      "total": 59.98,
      "currency": "USD",
      "currency_symbol": "$",
      "item_count": 1,
      "total_quantity": 2
    }
  }
}
```

#### POST /wp-json/oba/v1/cart/remove
Remove product from cart (requires authentication). Supports both cart item ID and product ID methods.

**Headers:**
```
Authorization: Bearer <access_token>
Content-Type: application/json
```

**Method 1 - Using Cart Item ID:**
```json
{
  "cart_item_id": 1
}
```

**Method 2 - Using Product ID:**
```json
{
  "product_id": 123,
  "variation_id": 456
}
```

**Response:**
```json
{
  "success": true,
  "message": "Product removed from cart successfully.",
  "data": {
    "cart": {
      "items": [...],
      "subtotal": 0,
      "total": 0,
      "currency": "USD",
      "currency_symbol": "$",
      "item_count": 0,
      "total_quantity": 0
    }
  }
}
```

#### POST /wp-json/oba/v1/cart/update
Update cart item quantity (requires authentication). Supports both cart item ID and product ID methods.

**Headers:**
```
Authorization: Bearer <access_token>
Content-Type: application/json
```

**Method 1 - Using Cart Item ID:**
```json
{
  "cart_item_id": 1,
  "quantity": 3
}
```

**Method 2 - Using Product ID:**
```json
{
  "product_id": 123,
  "variation_id": 456,
  "quantity": 3
}
```

**Response:**
```json
{
  "success": true,
  "message": "Cart item quantity updated successfully.",
  "data": {
    "quantity": 3,
    "cart": {
      "items": [...],
      "subtotal": 89.97,
      "total": 89.97,
      "currency": "USD",
      "currency_symbol": "$",
      "item_count": 1,
      "total_quantity": 3
    }
  }
}
```

#### POST /wp-json/oba/v1/cart/clear
Clear entire cart (requires authentication).

**Headers:**
```
Authorization: Bearer <access_token>
```

**Response:**
```json
{
  "success": true,
  "message": "Cart cleared successfully.",
  "data": {
    "cart": {
      "items": [],
      "subtotal": 0,
      "total": 0,
      "currency": "USD",
      "currency_symbol": "$",
      "item_count": 0
    }
  }
}
```

### Checkout

#### GET /wp-json/oba/v1/checkout
Get checkout data including cart summary, addresses, payment methods, and shipping methods (requires authentication).

**Headers:**
```
Authorization: Bearer <access_token>
```

**Response:**
```json
{
  "success": true,
  "data": {
    "cart": {
      "items": [...],
      "subtotal": 99.99,
      "shipping_total": 5.99,
      "tax_total": 8.99,
      "total": 114.97,
      "currency": "USD",
      "currency_symbol": "$"
    },
    "payment_methods": {
      "stripe": {
        "id": "stripe",
        "title": "Credit Card (Stripe)",
        "description": "Pay securely with your credit card."
      }
    },
    "shipping_methods": {
      "flat_rate": {
        "id": "flat_rate",
        "title": "Flat Rate",
        "description": "Fixed shipping cost."
      }
    },
    "addresses": {
      "billing": {...},
      "shipping": {...}
    }
  }
}
```

#### POST /wp-json/oba/v1/checkout/validate
Validate checkout data before processing (requires authentication).

**Headers:**
```
Authorization: Bearer <access_token>
Content-Type: application/json
```

**Request Body:**
```json
{
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
  "shipping_method": "flat_rate",
  "order_notes": "Please deliver in the morning"
}
```

#### POST /wp-json/oba/v1/checkout/process
Process checkout and create order (requires authentication).

**Headers:**
```
Authorization: Bearer <access_token>
Content-Type: application/json
```

**Request Body:**
```json
{
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
  "shipping_method": "flat_rate",
  "order_notes": "Please deliver in the morning"
}
```

**Response:**
```json
{
  "success": true,
  "message": "Order created successfully.",
  "data": {
    "id": 123,
    "number": "123",
    "status": "pending",
    "total": 114.97,
    "currency": "USD",
    "billing_address": {...},
    "shipping_address": {...},
    "payment_method": "stripe",
    "items": [...]
  }
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
Get user's current membership status and details (requires authentication).

**Headers:**
```
Authorization: Bearer <access_token>
```

**Response:**
```json
{
  "success": true,
  "data": {
    "has_membership": true,
    "level_id": 1,
    "level_name": "Premium",
    "level_description": "Premium membership with full access",
    "level_cost": 99.00,
    "level_billing_amount": 9.99,
    "level_billing_limit": 12,
    "level_cycle_number": 1,
    "level_cycle_period": "Month",
    "level_trial_amount": 0.00,
    "level_trial_limit": 0,
    "start_date": "2024-01-01 00:00:00",
    "end_date": "2024-12-31 23:59:59",
    "status": "active",
    "is_active": true,
    "days_remaining": 365
  }
}
```

#### GET /wp-json/oba/v1/membership/plans
Get list of available membership plans with pagination.

**Query Parameters:**
- `page` (optional): Page number (default: 1)
- `per_page` (optional): Items per page (default: 10, max: 50)
- `active_only` (optional): Show only active plans (default: true)

**Response:**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "name": "Basic",
      "description": "Basic membership",
      "initial_payment": 29.99,
      "billing_amount": 9.99,
      "billing_limit": 12,
      "cycle_number": 1,
      "cycle_period": "Month",
      "trial_amount": 0.00,
      "trial_limit": 0,
      "allow_signups": true,
      "expiration_number": 12,
      "expiration_period": "Month"
    }
  ],
  "pagination": {
    "page": 1,
    "per_page": 10,
    "total": 5,
    "total_pages": 1
  }
}
```

#### GET /wp-json/oba/v1/membership/signup-form
Get signup form fields and configuration for a specific membership level.

**Query Parameters:**
- `level_id` (required): Membership level ID

**Response:**
```json
{
  "success": true,
  "data": {
    "level": {
      "id": 1,
      "name": "Premium",
      "description": "Premium membership",
      "initial_payment": 99.00,
      "billing_amount": 9.99,
      "billing_limit": 12,
      "cycle_number": 1,
      "cycle_period": "Month",
      "trial_amount": 0.00,
      "trial_limit": 0,
      "expiration_number": 12,
      "expiration_period": "Month"
    },
    "gateways": [
      {
        "id": "stripe",
        "name": "Stripe",
        "is_active": true
      }
    ],
    "custom_fields": [],
    "required_fields": [
      "username",
      "email",
      "password",
      "confirm_password",
      "first_name",
      "last_name",
      "billing_address_1",
      "billing_city",
      "billing_state",
      "billing_postcode",
      "billing_country"
    ]
  }
}
```

#### POST /wp-json/oba/v1/membership/signup
Process membership signup with user creation and payment data.

**Request Body:**
```json
{
  "level_id": 1,
  "username": "john_doe",
  "email": "john@example.com",
  "password": "securepassword123",
  "first_name": "John",
  "last_name": "Doe",
  "billing_address": {
    "address_1": "123 Main St",
    "city": "New York",
    "state": "NY",
    "postcode": "10001",
    "country": "US",
    "phone": "+1234567890"
  },
  "custom_fields": {
    "company": "ACME Corp",
    "phone": "+1234567890"
  }
}
```

**Response:**
```json
{
  "success": true,
  "message": "Membership signup completed successfully.",
  "data": {
    "id": 123,
    "username": "john_doe",
    "email": "john@example.com",
    "first_name": "John",
    "last_name": "Doe",
    "membership": {
      "user_id": 123,
      "level_id": 1,
      "status": "active",
      "start_date": "2024-01-01 10:00:00",
      "end_date": "2024-12-31 23:59:59"
    }
  }
}
```

#### POST /wp-json/oba/v1/membership/change
Upgrade or downgrade existing membership level (requires authentication).

**Headers:**
```
Authorization: Bearer <access_token>
Content-Type: application/json
```

**Request Body:**
```json
{
  "new_level_id": 2
}
```

**Response:**
```json
{
  "success": true,
  "message": "Membership level changed successfully.",
  "data": {
    "level_id": 2,
    "level_name": "Premium Plus",
    "status": "active",
    "start_date": "2024-01-01 10:00:00",
    "end_date": "2024-12-31 23:59:59"
  }
}
```

#### POST /wp-json/oba/v1/membership/cancel
Cancel current membership (immediately or at period end) (requires authentication).

**Headers:**
```
Authorization: Bearer <access_token>
Content-Type: application/json
```

**Request Body:**
```json
{
  "cancel_at_period_end": true
}
```

**Response:**
```json
{
  "success": true,
  "message": "Membership cancelled successfully.",
  "data": {
    "cancelled": true,
    "cancel_at_period_end": true
  }
}
```

#### GET /wp-json/oba/v1/membership/gateways
Get available payment gateways and their capabilities.

**Response:**
```json
{
  "success": true,
  "data": [
    {
      "id": "stripe",
      "name": "Stripe",
      "is_active": true,
      "description": "Credit card payments via Stripe",
      "supports_recurring": true,
      "supports_trial": true
    },
    {
      "id": "paypal",
      "name": "PayPal",
      "is_active": true,
      "description": "PayPal payments",
      "supports_recurring": true,
      "supports_trial": true
    }
  ]
}
```

#### GET /wp-json/oba/v1/membership/analytics
Get membership analytics and statistics for date range.

**Query Parameters:**
- `start_date` (optional): Start date (default: 30 days ago)
- `end_date` (optional): End date (default: today)

**Response:**
```json
{
  "success": true,
  "data": {
    "total_members": 1500,
    "active_members": 1200,
    "expired_members": 200,
    "cancelled_members": 80,
    "pending_members": 20,
    "new_members_today": 5,
    "revenue_today": 299.95,
    "level_distribution": []
  }
}
```

#### GET /wp-json/oba/v1/membership/history
Get user's membership history and level changes (requires authentication).

**Headers:**
```
Authorization: Bearer <access_token>
```

**Response:**
```json
{
  "success": true,
  "data": [
    {
      "level_id": 1,
      "level_name": "Basic",
      "start_date": "2023-01-01 00:00:00",
      "end_date": "2023-12-31 23:59:59",
      "status": "expired",
      "is_current": false
    },
    {
      "level_id": 2,
      "level_name": "Premium",
      "start_date": "2024-01-01 00:00:00",
      "end_date": "2024-12-31 23:59:59",
      "status": "active",
      "is_current": true
    }
  ]
}
```

#### GET /wp-json/oba/v1/membership/invoices
Get user's membership invoices and payment history (requires authentication).

**Headers:**
```
Authorization: Bearer <access_token>
```

**Query Parameters:**
- `page` (optional): Page number (default: 1)
- `per_page` (optional): Items per page (default: 10, max: 50)

**Response:**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "code": "INV-001",
      "user_id": 123,
      "membership_id": 1,
      "gateway": "stripe",
      "gateway_environment": "live",
      "amount": 99.00,
      "subtotal": 99.00,
      "tax": 0.00,
      "status": "completed",
      "date": "2024-01-01 10:00:00",
      "notes": "Initial payment"
    }
  ],
  "pagination": {
    "page": 1,
    "per_page": 10,
    "total": 5,
    "total_pages": 1
  }
}
```

#### GET /wp-json/oba/v1/membership/profile
Get comprehensive user profile including custom fields, membership data, and addresses (requires authentication).

**Headers:**
```
Authorization: Bearer <access_token>
```

**Response:**
```json
{
  "success": true,
  "data": {
    "id": 123,
    "username": "john_doe",
    "email": "john@example.com",
    "first_name": "John",
    "last_name": "Doe",
    "display_name": "John Doe",
    "website": "https://example.com",
    "date_registered": "2024-01-01 10:00:00",
    "last_login": "2024-01-15 14:30:00",
    "membership": {
      "level_id": 1,
      "level_name": "Premium",
      "status": "active",
      "start_date": "2024-01-01 10:00:00",
      "end_date": "2024-12-31 23:59:59",
      "is_active": true
    },
    "billing_address": {
      "first_name": "John",
      "last_name": "Doe",
      "company": "ACME Corp",
      "address_1": "123 Main St",
      "city": "New York",
      "state": "NY",
      "postcode": "10001",
      "country": "US",
      "phone": "+1234567890",
      "email": "john@example.com"
    },
    "shipping_address": {
      "first_name": "John",
      "last_name": "Doe",
      "company": "ACME Corp",
      "address_1": "123 Main St",
      "city": "New York",
      "state": "NY",
      "postcode": "10001",
      "country": "US"
    },
    "custom_fields": {
      "phone": {
        "value": "+1234567890",
        "type": "tel"
      },
      "company": {
        "value": "ACME Corp",
        "type": "text"
      },
      "website": {
        "value": "https://example.com",
        "type": "url"
      }
    }
  }
}
```

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