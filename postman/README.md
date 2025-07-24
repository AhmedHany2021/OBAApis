# Postman Testing for OBA APIs Integration

This directory contains Postman collection and environment files for testing the OBA APIs Integration WordPress plugin.

## Files

- `OBA-APIs-Integration.postman_collection.json` - Complete API collection with all endpoints
- `OBA-APIs-Integration.postman_environment.json` - Environment variables for testing
- `README.md` - This documentation file

## Setup Instructions

### 1. Import Collection and Environment

1. **Open Postman**
2. **Import Collection:**
   - Click "Import" button
   - Select `OBA-APIs-Integration.postman_collection.json`
   - The collection will be imported with all endpoints organized by category

3. **Import Environment:**
   - Click "Import" button again
   - Select `OBA-APIs-Integration.postman_environment.json`
   - The environment will be imported with all necessary variables

4. **Select Environment:**
   - In the top-right corner, select "OBA APIs Integration Environment" from the dropdown

### 2. Configure Your Site URL

1. **Edit Environment Variables:**
   - Click the environment dropdown → "Edit"
   - Find the `base_url` variable
   - Replace `https://your-site.com/wp-json/oba/v1` with your actual WordPress site URL
   - Example: `https://mysite.com/wp-json/oba/v1`

2. **Update Test Credentials:**
   - Update `test_user_email` and `test_user_password` with valid WordPress user credentials
   - Update test IDs (`test_product_id`, `test_order_id`, `test_vendor_id`) with actual IDs from your site

### 3. Testing Workflow

#### Step 1: Authentication
1. **Login Request:**
   - Go to "Authentication" folder → "Login"
   - Update the request body with your test user credentials
   - Send the request
   - **Important:** The collection automatically saves tokens to environment variables

2. **Verify Tokens:**
   - Check that `access_token` and `refresh_token` are populated in the environment
   - These tokens will be automatically used for authenticated requests

#### Step 2: Test Public Endpoints
1. **Products:**
   - Test "Get Products" with various query parameters
   - Test "Get Product" with a specific product ID
   - Test "Get Categories" to browse product categories

2. **Vendors:**
   - Test "Get Vendors" to see available vendors
   - Test "Get Vendor" with a specific vendor ID
   - Test "Get Vendor Products" to see products from a specific vendor

#### Step 3: Test Authenticated Endpoints
1. **User Management:**
   - Test "Get Current User" (requires authentication)
   - Test "Update Profile" with user data

2. **Orders:**
   - Test "Get Orders" to see user's order history
   - Test "Get Order" with a specific order ID
   - Test "Create Order" to create a new order

3. **Membership:**
   - Test "Get Membership Status" to see user's membership
   - Test "Get Membership Plans" to see available plans
   - Test "Get Membership History" and "Get Invoices"

#### Step 4: Token Management
1. **Refresh Token:**
   - Use "Refresh Token" request when access token expires
   - The new tokens will be automatically saved

2. **Logout:**
   - Use "Logout" request to invalidate tokens
   - This will blacklist the tokens on the server

## Collection Features

### Automatic Token Management
- Tokens are automatically saved after successful login
- All authenticated requests use the saved access token
- Refresh tokens are handled automatically

### Pre-request Scripts
- Automatically saves tokens from login responses
- Validates request structure before sending

### Test Scripts
- Validates response status codes
- Checks response structure
- Provides helpful error messages

### Organized Structure
- **Authentication:** Login, logout, token refresh
- **User Management:** Profile operations
- **Products:** Product catalog and categories
- **Orders:** Order management and creation
- **Vendors:** Vendor browsing and products
- **Membership:** Membership status and plans

## Environment Variables

### Required Variables
- `base_url` - Your WordPress site API base URL
- `access_token` - JWT access token (auto-populated)
- `refresh_token` - JWT refresh token (auto-populated)

### Test Data Variables
- `test_user_email` - Test user email
- `test_user_password` - Test user password
- `test_product_id` - Test product ID
- `test_order_id` - Test order ID
- `test_vendor_id` - Test vendor ID

### Filtering Variables
- `page` - Page number for pagination
- `per_page` - Items per page
- `orderby` - Order by field
- `order` - Sort order (asc/desc)
- `status` - Status filter
- `min_price`/`max_price` - Price range filters
- `search` - Search terms
- `category` - Category filters

## Troubleshooting

### Common Issues

1. **401 Unauthorized:**
   - Check if access token is valid
   - Try refreshing the token
   - Verify user credentials

2. **404 Not Found:**
   - Check if the plugin is activated
   - Verify the base URL is correct
   - Check if the endpoint exists

3. **403 Forbidden:**
   - Check user permissions
   - Verify the user has required capabilities
   - Check if the resource belongs to the user

4. **429 Too Many Requests:**
   - Rate limit exceeded
   - Wait before making more requests
   - Check rate limiting settings in plugin

### Debug Tips

1. **Check Plugin Status:**
   - Verify plugin is activated in WordPress admin
   - Check plugin settings page for configuration

2. **Verify API Endpoints:**
   - Test base URL: `https://your-site.com/wp-json/oba/v1`
   - Should return a JSON response

3. **Check WordPress Permalinks:**
   - Ensure permalinks are not set to "Plain"
   - REST API requires proper URL rewriting

4. **Review Error Logs:**
   - Check WordPress debug log
   - Check server error logs
   - Enable debug mode in plugin settings

## Advanced Testing

### Custom Test Scripts
You can add custom test scripts to validate specific responses:

```javascript
// Example: Validate product response structure
pm.test("Product has required fields", function () {
    const response = pm.response.json();
    if (response.success && response.data) {
        const product = response.data;
        pm.expect(product).to.have.property('id');
        pm.expect(product).to.have.property('name');
        pm.expect(product).to.have.property('price');
    }
});
```

### Environment-Specific Testing
Create multiple environments for different testing scenarios:
- Development environment
- Staging environment
- Production environment

### Automated Testing
Use Postman's Newman CLI for automated testing:
```bash
newman run OBA-APIs-Integration.postman_collection.json -e OBA-APIs-Integration.postman_environment.json
```

## Support

For issues with the API endpoints, check:
1. Plugin documentation in the main README.md
2. WordPress admin settings page for the plugin
3. WordPress debug logs
4. Server error logs

For Postman-specific issues, refer to Postman's official documentation. 