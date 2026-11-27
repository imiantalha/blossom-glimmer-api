# Blossom Glimmer API

Laravel 12 backend for the Blossom Glimmer marketplace. The canonical public website and role-aware production entry point is the separate Next.js application (`imiantalha/blossom-glimmer`): Customers use the storefront/account routes, Admin and Super Admin use `/administrator`, and Vendors use `/vendor`. This Laravel application provides the REST API, persistence, authorization and business rules. Its existing React dashboard is preserved as a legacy/internal staff portal at the Laravel application origin; do not delete or silently replace it. Production DNS should route the public website domain to Next.js and configure `NEXT_PUBLIC_API_URL` to this Laravel API origin. It provides a versioned JSON API, Sanctum authentication, role-based administration, catalog and commerce workflows, vendor operations, shipment tracking, and the existing AI product assistant.

## Stack

- PHP 8.2+ / Laravel 12
- Laravel Sanctum and Spatie Laravel Permission
- SQLite, MySQL/MariaDB, or Microsoft SQL Server 2022
- PHPUnit / Laravel feature tests
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

API base URL: `http://localhost:8000/api/v1`. Set the frontend `NEXT_PUBLIC_API_URL` to this URL.

### Environment configuration

The demo catalog is enabled by default outside production. Configure these values explicitly for local development:

```env
STOREFRONT_SEED_DEMO_CATALOG=true
STOREFRONT_COD_ENABLED=true
SANCTUM_EXPIRATION=10080
```

Never enable demo seeders in production. The seeders use dedicated demo accounts and fixture data; they do not deactivate unrelated catalog records or reset unrelated vendor credentials.

## Development catalogue and test accounts

The curated storefront fixtures contain 132 products (simple and variable), 9 root categories plus children, 6 brands, 4 image-backed vendor stores, 4 collections, and 120 valid product reviews. These are demo records, not verified live inventory or production-approved vendor listings.

The scenario seeder creates customer/admin/vendor accounts, addresses, a populated cart, wishlist, review, sample order, shipment, tracking event, and two configured shipping methods.

Development-only accounts use `Password123!`:

- Customer: `customer@demo.blossomglimmer.local`
- Second customer: `customer2@demo.blossomglimmer.local`
- Admin: `admin@demo.blossomglimmer.local`
- Vendors: `vendor+atelier-lane@demo.blossomglimmer.local`, `vendor+glow-collective@demo.blossomglimmer.local`, `vendor+nord-living-co@demo.blossomglimmer.local`, `vendor+arc-marketplace@demo.blossomglimmer.local`

Do not use these accounts or fixtures in production.

## API conventions

- Prefix: `/api/v1`
- Success envelope: `success`, `message`, `data`, optional `pagination`
- Error envelope: `success=false`, `message`, `errors`
- Sanctum bearer tokens for authenticated operations
- Admin management routes require the `Admin` role
- Vendor operations are scoped to the authenticated vendor
- Customer resources are scoped to the authenticated user

## API surface

### Public catalogue

- `GET /products` — search, filters, sorting, pagination
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
- `GET|POST|DELETE /cart`, `PATCH /cart/{id}`, `POST /cart/merge`
- `GET|POST /wishlist`, `DELETE /wishlist/{id}`, `POST /wishlist/merge`
- `POST /reviews`
- `POST /inventory/validate`
- `GET|POST /orders`, `GET /orders/{id}`
- `POST /shipping/quote`

Guest cart and wishlist changes are retained locally by the frontend and merged after sign-in using product slugs and variant SKUs. Cart endpoints require authentication.

Order creation recalculates prices and validates inventory on the server in a transaction. Send an `Idempotency-Key` header for safe checkout retries. COD is the only enabled payment method until a payment provider and credentials are selected. Card processing, payment webhooks, and refunds are not fabricated.

### Vendor and administration

- Vendor store setup and profile: `GET /vendor/store/me`, `POST|PATCH /vendor/store`
- Vendor product management: `GET|POST /vendor/products`, `GET|PATCH|DELETE /vendor/products/{id}`
- Vendor inventory: `PATCH /vendor/products/{id}/inventory`
- Admin store listing, review, approval/suspension, and commission management
- Admin product listing/detail/options and product approval/rejection
- Existing admin user, role, permission, dashboard, and email-log routes remain available

### Shipment tracking

- `POST /admin/orders/{id}/shipment` updates carrier/tracking details, shipment events, and order fulfillment status.
- Shipment snapshots are included in customer order details.
- External carrier synchronization is pending provider contracts and credentials.

### AI assistant

- `POST /ai/product-assistant`
- `POST /ai/product-assistant/stream`

The existing AI assistant foundation is preserved. Grounding and evaluating recommendations against live catalog and inventory data remains a follow-up after staging integration.

## Microsoft SQL Server 2022

A Docker-based SQL Server instance is available for local testing without XAMPP:

```bash
export MSSQL_SA_PASSWORD='LocalOnly_StrongPassword_2026!'
docker compose -f docker-compose.sqlserver.yml up -d
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

Then run `php artisan migrate:fresh --seed` only against this disposable development database. Windows users can use an installed SQL Server instance with the same Laravel settings.

## Configuration notes

- Shipping methods and rates are stored in `shipping_methods`. If none are active, the API reports that shipping is not configured instead of inventing live rates.
- Cash on delivery is controlled by `STOREFRONT_COD_ENABLED`.
- Password reset requires a configured mail transport. Public responses remain generic to avoid account enumeration.
- Product images can be external URLs in catalog metadata or uploaded media records.
- Production catalogues must be populated with approved products, vendors, images, prices, and inventory.

## Testing

```bash
php artisan test
php artisan route:list --path=api/v1
```

GitHub Actions runs the Laravel feature suite against SQLite and SQL Server 2022.

## Agent handoff

Read [AGENT_HANDOFF.md](AGENT_HANDOFF.md) before continuing implementation. The endpoint matrix and frontend integration gaps are tracked in [API_REQUIREMENTS.md](API_REQUIREMENTS.md).

## License

MIT, unless otherwise stated by the repository owner.
