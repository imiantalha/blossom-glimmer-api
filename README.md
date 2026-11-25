# Blossom Glimmer API

Laravel 12 backend for the Blossom Glimmer marketplace. Provides a versioned JSON API, Sanctum authentication, role-based administration, product catalogue, customer commerce and AI product assistance.

## Stack

- PHP 8.2+ / Laravel 12
- Laravel Sanctum and Spatie Laravel Permission
- SQLite, MySQL, or Microsoft SQL Server 2022 (local/CI testing)
- PHPUnit
- Existing Laravel AI product assistant

## Local setup

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan db:seed
php artisan serve
```

The API base URL is `http://localhost:8000/api/v1`. Configure `NEXT_PUBLIC_API_URL` in the frontend to point to this base URL.

### Development catalogue data

The frontend's existing mock catalogue is copied into backend demo fixtures. It contains 132 products, 9 root categories plus child categories, 6 brands, 4 vendors, 4 collections and 120 matching reviews (72 orphan review fixtures are skipped). These are **development/demo records**, not verified live stock or approved production vendor data.

Demo seeding is enabled by default outside production. To control it explicitly:

```env
STOREFRONT_SEED_DEMO_CATALOG=true
STOREFRONT_COD_ENABLED=true
SANCTUM_EXPIRATION=10080
```

Do not enable demo seeding in production. Production catalogues must be populated with approved products, vendors, images, prices and inventory.

## API conventions

- Prefix: `/api/v1`
- Success envelope: `success`, `message`, `data`, optional `pagination`
- Error envelope: `success=false`, `message`, `errors`
- Sanctum bearer tokens for authenticated customer and admin endpoints
- Admin management routes require the `Admin` role
- Customer resources are scoped to the authenticated user

## API surface

### Public catalogue
- `GET /products` — search, filters, sorting and pagination
- `GET /products/{slug}`
- `GET /categories`, `GET /categories/{slug}`
- `GET /brands`, `GET /brands/{slug}`, `GET /brands/{slug}/products`
- `GET /vendors`, `GET /vendors/{slug}`, `GET /vendors/{slug}/products`
- `GET /collections`, `GET /collections/{slug}`, `GET /collections/{slug}/products`
- `GET /reviews?product_id={id}`
- `GET /shipping/methods`, `GET /payment/methods`

### Authentication and customer commerce
- `POST /auth/register`, `POST /auth/register/vendor`, `POST /auth/login`, `POST /auth/logout`, `GET /auth/me`
- `POST /auth/forgot-password`, `POST /auth/reset-password`
- `PATCH /users/me`, `PATCH /users/me/password`
- `GET|POST /users/me/addresses`, `PATCH|DELETE /users/me/addresses/{id}`
- `GET|POST /cart`, `PATCH|DELETE /cart/{id}`, `DELETE /cart`
- `GET|POST /wishlist`, `DELETE /wishlist/{id}`
- `POST /reviews`
- `GET|POST /orders`, `GET /orders/{id}`
- `POST /shipping/quote`

Order creation recalculates prices and validates stock on the server. Send an `Idempotency-Key` header for safe checkout retries. Cart endpoints require authentication; the guest cart and wishlist can be merged after sign-in.

### Vendor and administration
- `POST /auth/register/vendor` creates a vendor-role account.
- `GET|POST|PATCH /vendor/store` supports store setup and profile updates.
- `GET|POST|PATCH|DELETE /vendor/products` and `/vendor/products/{id}` support vendor product management.
- `PATCH /vendor/products/{id}/inventory` updates vendor-owned stock.
- Admin store review, approval/suspension, commission and product approval endpoints are role-protected.

### Vendor and administration\n- Vendor store profile CRUD, vendor product CRUD, inventory adjustments, admin store approval/suspension and commission settings.\n- Admin product listing/detail, product approval/rejection and existing user/role/permission management.\n- Guest carts and wishlists merge into an authenticated account by product slug and variant SKU.\n\n### Administration and tracking
- Admin user, product, variant, role, permission, dashboard and email-log routes require the `Admin` role.
- `POST /admin/orders/{id}/shipment` updates carrier/tracking data and order fulfillment status.

### AI
- `POST /ai/product-assistant`
- `POST /ai/product-assistant/stream`

The AI assistant foundation is preserved; recommendations should be verified against live catalogue and inventory data.

## Demo data and test accounts

`php artisan db:seed` creates 132 catalogue products (simple and variable), 9 root categories with child categories, 6 brands, 4 image-backed vendor stores, 4 collections, and 120 valid product reviews. Product, category, brand, vendor and collection images use the curated image URLs already included in the frontend fixtures. The scenario seeder also creates customer addresses, a populated cart, wishlist entries, a review, a sample order, shipment and tracking event.

Development-only accounts use `Password123!`:
- Customer: `customer@demo.blossomglimmer.local`
- Second customer: `customer2@demo.blossomglimmer.local`
- Admin: `admin@demo.blossomglimmer.local`
- Vendors: `vendor+atelier-lane@demo.blossomglimmer.local`, `vendor+glow-collective@demo.blossomglimmer.local`, `vendor+nord-living-co@demo.blossomglimmer.local`, `vendor+arc-marketplace@demo.blossomglimmer.local`

Never use these accounts or fixtures in production. Demo seeding is disabled by default in production.

## Microsoft SQL Server 2022

A Docker-based SQL Server instance is available without XAMPP:

```bash
# Set a strong local-only SA password in your shell first.
export MSSQL_SA_PASSWORD='LocalOnly_StrongPassword_2026!'
docker compose -f docker-compose.sqlserver.yml up -d

# Create the development database.
docker exec blossom-glimmer-sqlserver /opt/mssql-tools18/bin/sqlcmd -C -S localhost -U sa -P "$MSSQL_SA_PASSWORD" -Q "CREATE DATABASE blossom_glimmer;"
```

Configure Laravel `.env` (PHP must have `pdo_sqlsrv` and Microsoft ODBC Driver 18):

```env
DB_CONNECTION=sqlsrv
DB_HOST=127.0.0.1
DB_PORT=1433
DB_DATABASE=blossom_glimmer
DB_USERNAME=sa
DB_PASSWORD=LocalOnly_StrongPassword_2026!
DB_ENCRYPT=yes
DB_TRUST_SERVER_CERTIFICATE=true
```

Then run `php artisan migrate:fresh --seed` only against this disposable development database. Windows users can use an installed SQL Server instance with the same Laravel settings. GitHub Actions also runs the feature suite against SQL Server 2022.

## Configuration notes

- Shipping methods are configured in the `shipping_methods` table. The API returns a service-unavailable response when no methods are active; it does not invent rates.
- Cash on delivery is controlled by `STOREFRONT_COD_ENABLED`.
- Card payment processing, payment-provider webhooks, refunds, and external carrier integrations remain pending provider credentials/contracts. Guest cart and wishlist merge endpoints are implemented.
- Password reset requires working mail configuration.
- Product images can be stored as external URLs in catalogue metadata or uploaded media records.

## Testing

```bash
php artisan test
php artisan route:list --path=api/v1
```

A GitHub Actions workflow runs the Laravel test suite on pushes and pull requests.

## Agent handoff

Read [AGENT_HANDOFF.md](AGENT_HANDOFF.md) before continuing implementation. The endpoint matrix and frontend integration gaps are tracked in [API_REQUIREMENTS.md](API_REQUIREMENTS.md).

## License

MIT, unless otherwise stated by the repository owner.
