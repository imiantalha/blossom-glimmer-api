# Storefront API Requirements

**Frontend source:** `imiantalha/blossom-glimmer`  
**Backend target:** `/api/v1`  
**Contract:** JSON envelope `{ success, message, data, pagination? }`; errors use `{ success:false, message, errors }`.

## Frontend service contract

| Domain | Method + endpoint | Auth | Backend status |
|---|---|---:|---|
| Auth | POST `/auth/register`, POST `/auth/register/vendor` | No | Implemented; customer/vendor Sanctum token responses |
| Auth | POST `/auth/login` | No | Implemented; stateless token response |
| Auth | POST `/auth/logout` | Yes | Implemented |
| Auth | GET `/auth/me` | Yes | Implemented |
| Auth | POST `/auth/forgot-password` | No | Implemented; requires mail configuration |
| Auth | POST `/auth/reset-password` | No | Implemented; requires mail configuration |
| Account | PATCH `/users/me` | Yes | Implemented |
| Account | PATCH `/users/me/password` | Yes | Implemented |
| Addresses | GET/POST `/users/me/addresses` | Yes | Implemented |
| Addresses | PATCH/DELETE `/users/me/addresses/{id}` | Yes | Implemented |
| Products | GET `/products` | No | Implemented: search, page, filters, sorting |
| Products | GET `/products/{slug}` | No | Implemented |
| Categories | GET `/categories` | No | Implemented: hierarchy + pagination |
| Categories | GET `/categories/{slug}` | No | Implemented |
| Brands | GET `/brands` | No | Implemented: pagination/search |
| Brands | GET `/brands/{slug}` | No | Implemented |
| Brands | GET `/brands/{slug}/products` | No | Implemented |
| Vendors | GET `/vendors` | No | Implemented: profile + pagination/search |
| Vendors | GET `/vendors/{slug}` | No | Implemented |
| Vendors | GET `/vendors/{slug}/products` | No | Implemented |
| Search | GET `/products?search=...` | No | Uses product listing endpoint |
| Cart | GET/POST `/cart` | Yes | Implemented; authenticated carts only |
| Cart | PATCH/DELETE `/cart/{id}` | Yes | Implemented |
| Cart | DELETE `/cart` | Yes | Implemented |
| Wishlist | GET/POST `/wishlist` | Yes | Implemented |
| Wishlist | DELETE `/wishlist/{id}` | Yes | Implemented |
| Reviews | GET `/reviews?product_id=...` | No | Implemented |
| Reviews | POST `/reviews` | Yes | Implemented; pending moderation |
| Orders | GET/POST `/orders` | Yes | Implemented; server-side price/stock validation, optional idempotency key |
| Orders | GET `/orders/{id}` | Yes | Implemented; owner-scoped |
| Collections | GET `/collections` | No | Implemented; frontend service still reads local JSON |
| Collections | GET `/collections/{slug}` | No | Implemented |
| Collections | GET `/collections/{slug}/products` | No | Implemented |
| Shipping | GET `/shipping/methods` | No | Implemented; requires configured active methods |
| Shipping | POST `/shipping/quote` | Yes | Implemented; requires configured active methods |
| Payments | GET `/payment/methods` | No | Implemented; COD controlled by config; no card provider |
| Shipments | POST `/admin/orders/{id}/shipment` | Admin | Implemented; order response includes tracking snapshot |
| AI assistant | POST `/ai/product-assistant`, POST `/ai/product-assistant/stream` | Yes | Existing implementation preserved |

## Additional vendor/admin contracts

- Vendor registration: POST `/auth/register/vendor`.
- Vendor store: GET/POST/PATCH `/vendor/store/me`, `/vendor/store`.
- Vendor products: GET/POST `/vendor/products`, GET/PATCH/DELETE `/vendor/products/{id}`, PATCH `/vendor/products/{id}/inventory`, GET `/vendor/products/options`.
- Admin stores: GET `/admin/stores`, GET `/admin/stores/{id}`, PATCH status/commission.
- Admin products: GET `/admin/products`, GET `/admin/products/{id}`, GET `/admin/products/options`, PATCH approval.
- Guest cart/wishlist merge endpoints: POST `/cart/merge`, POST `/wishlist/merge`.

## Frontend behavior that still needs integration

- `authService` still uses temporary in-memory credentials; connect it to Sanctum and persist the bearer token through `setApiAuthToken`.
- Catalog services have a mock-mode switch but do not yet consistently fall back to mock data after API errors.
- `collectionService`, `paymentService`, `shippingService`, `inventoryService`, and `shipmentService` currently contain local/static logic; connect them to the backend contracts where applicable.
- `orderService.create` currently does not send a shipping method or idempotency key; update the frontend payload before requiring those values.
- Confirm whether country/state/city selectors require an API or remain client-side reference data. The current API endpoint registry has no location endpoints.

## Data and persistence

- The backend now includes tables for addresses, carts/items, wishlists, product reviews, orders/items, collections, vendor ownership/profiles, shipping methods, shipments and shipment events.
- Storefront catalog demo fixtures were copied from frontend mock JSON into `database/seeders/data`: 132 products, 9 root categories plus child categories, 6 brands, 4 vendors, 4 collections and 120 matching reviews (72 orphan review fixtures are skipped).
- These are **development/demo fixtures copied from the frontend mock catalogue**, not verified live inventory or real vendor business data. Production catalogue records must be supplied/approved by the business.
- Run `php artisan db:seed` in local/dev with `STOREFRONT_SEED_DEMO_CATALOG=true`. Demo seeding is disabled by default in production.
- Shipping methods must be configured in `shipping_methods`; no rates are fabricated. Card payments require a provider integration and verified webhooks.

## Validation still required

- Run `php artisan migrate:fresh --seed` against SQLite and the intended production database engine.
- Run `php artisan test`, `php artisan route:list --path=api/v1`, and PHP lint on changed files.
- Smoke-test public catalog, auth, ownership boundaries, cart stock checks, checkout idempotency, shipping quote and shipment tracking.
- Verify CORS, mail delivery, Sanctum expiration, role guards, media URL behavior and SQL portability.
- GitHub Actions now runs SQLite and SQL Server 2022 suites. The first run exposed duplicate catalog migration, test logging's MongoDB dependency and a missing ODBC driver; fixes were committed and the next CI run must be checked.
