# Blossom Glimmer API

Laravel 12 backend for the Blossom Glimmer marketplace. Provides a versioned JSON API, Sanctum authentication, role-based administration, product catalogue, customer commerce and AI product assistance.

## Stack

- PHP 8.2+ / Laravel 12
- Laravel Sanctum and Spatie Laravel Permission
- MySQL or SQLite (local/testing)
- PHPUnit
- Existing Laravel AI product assistant

## Local setup

\`\`\`bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan db:seed
php artisan serve
\`\`\`

The API base URL is \`http://localhost:8000/api/v1\`. Configure \`NEXT_PUBLIC_API_URL\` in the frontend to point to this base URL.

### Development catalogue data

The frontend's existing mock catalogue is copied into backend demo fixtures. It contains 132 products, 9 root categories plus child categories, 6 brands, 4 vendors, 4 collections and 192 reviews. These are **development/demo records**, not verified live stock or approved production vendor data.

Demo seeding is enabled by default outside production. To control it explicitly:

\`\`\`env
STOREFRONT_SEED_DEMO_CATALOG=true
STOREFRONT_COD_ENABLED=true
SANCTUM_EXPIRATION=10080
\`\`\`

Do not enable demo seeding in production. Production catalogues must be populated with approved products, vendors, images, prices and inventory.

## API conventions

- Prefix: \`/api/v1\`
- Success envelope: \`success\`, \`message\`, \`data\`, optional \`pagination\`
- Error envelope: \`success=false\`, \`message\`, \`errors\`
- Sanctum bearer tokens for authenticated customer and admin endpoints
- Admin management routes require the \`Admin\` role
- Customer resources are scoped to the authenticated user

## API surface

### Public catalogue
- \`GET /products\` — search, filters, sorting and pagination
- \`GET /products/{slug}\`
- \`GET /categories\`, \`GET /categories/{slug}\`
- \`GET /brands\`, \`GET /brands/{slug}\`, \`GET /brands/{slug}/products\`
- \`GET /vendors\`, \`GET /vendors/{slug}\`, \`GET /vendors/{slug}/products\`
- \`GET /collections\`, \`GET /collections/{slug}\`, \`GET /collections/{slug}/products\`
- \`GET /reviews?product_id={id}\`
- \`GET /shipping/methods\`, \`GET /payment/methods\`

### Authentication and customer commerce
- \`POST /auth/register\`, \`POST /auth/login\`, \`POST /auth/logout\`, \`GET /auth/me\`
- \`POST /auth/forgot-password\`, \`POST /auth/reset-password\`
- \`PATCH /users/me\`, \`PATCH /users/me/password\`
- \`GET|POST /users/me/addresses\`, \`PATCH|DELETE /users/me/addresses/{id}\`
- \`GET|POST /cart\`, \`PATCH|DELETE /cart/{id}\`, \`DELETE /cart\`
- \`GET|POST /wishlist\`, \`DELETE /wishlist/{id}\`
- \`POST /reviews\`
- \`GET|POST /orders\`, \`GET /orders/{id}\`
- \`POST /shipping/quote\`

Order creation recalculates prices and validates stock on the server. Send an \`Idempotency-Key\` header for safe checkout retries. Cart endpoints currently require authentication; guest-cart merge is not implemented.

### Administration and tracking
- Admin user, product, variant, role, permission, dashboard and email-log routes require the \`Admin\` role.
- \`POST /admin/orders/{id}/shipment\` updates carrier/tracking data and order fulfillment status.

### AI
- \`POST /ai/product-assistant\`
- \`POST /ai/product-assistant/stream\`

The AI assistant foundation is preserved; recommendations should be verified against live catalogue and inventory data.

## Configuration notes

- Shipping methods are configured in the \`shipping_methods\` table. The API returns a service-unavailable response when no methods are active; it does not invent rates.
- Cash on delivery is controlled by \`STOREFRONT_COD_ENABLED\`.
- Card payment processing, provider webhooks, refunds, carrier integrations and guest-cart merge are not implemented yet.
- Password reset requires working mail configuration.
- Product images can be stored as external URLs in catalogue metadata or uploaded media records.

## Testing

\`\`\`bash
php artisan test
php artisan route:list --path=api/v1
\`\`\`

A GitHub Actions workflow runs the Laravel test suite on pushes and pull requests.

## Agent handoff

Read [AGENT_HANDOFF.md](AGENT_HANDOFF.md) before continuing implementation. The endpoint matrix and frontend integration gaps are tracked in [API_REQUIREMENTS.md](API_REQUIREMENTS.md).

## License

MIT, unless otherwise stated by the repository owner.
