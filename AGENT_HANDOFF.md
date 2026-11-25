# Blossom Glimmer Backend — Agent Handoff & Integration Tracker

> **Purpose:** This file is the shared source of truth for any human or coding agent continuing the frontend-driven backend integration. Read this file before changing code, then update it in the same branch whenever scope, status, decisions, or validation results change.

## Mission

Complete the Laravel backend required by the existing Blossom Glimmer frontend. The frontend is currently functional with mock/placeholder data. The backend must provide the real APIs and persistent data needed to replace those mocks.

**Source of truth for requirements:** `imiantalha/blossom-glimmer` frontend repository — inspect actual services, endpoint constants, hooks, page/component usage, request payloads, response types and mock fallbacks. Do not invent contracts where frontend contracts already exist.

**Backend repository:** `imiantalha/blossom-glimmer-api`

**Scope:** Backend-first. Preserve existing frontend behavior and UI. Do not redesign frontend. Do not merge directly to `main` without review/approval.

## Current execution state

- [x] Confirmed GitHub read/write/admin access to frontend and backend repositories.
- [x] Reviewed the frontend API client, endpoint definitions and a selection of domain service files.
- [x] Reviewed backend API routes, product/catalog models, migrations and selected controllers/resources.
- [x] Created working branch: `feat/storefront-api-integration` (based on backend `main`).
- [x] Added initial commerce persistence migration.
- [x] Added initial storefront controller for catalog, customer address, cart, wishlist, reviews, profile and order workflows.
- [x] Added versioned `/api/v1` route group and exposed initial storefront routes.
- [x] Added product vendor ownership migration and started model relationship.
- [x] Added admin brand/category CRUD endpoints and authorization coverage.
- [x] Added storefront category hierarchy details and aligned frontend brand/category adapters.
- [x] Added SQL Server-safe order/address FK handling and explicit unlink-on-address-delete behavior; re-running SQL Server CI.
- [x] Resolved duplicate `/admin/products` GET route by moving the lower-level ProductController resource to `/catalog/products`.
- [ ] Complete and validate the current backend implementation.
- [ ] Finish full frontend-to-backend endpoint inventory.
- [ ] Implement remaining backend domains and align every response exactly with frontend types.
- [ ] Run migrations, automated tests, static checks and API smoke tests in a real Laravel environment.
- [ ] Review, open PR and merge only after validation and approval.
- [ ] Integrate frontend by disabling mock mode only after the corresponding real endpoints pass tests.

## Work already committed on the feature branch

Latest known branch work includes:
- `9dcc0c7` — initial storefront commerce persistence schema.
- `11ddac2` — initial storefront catalog and customer API handlers.
- `ed22a65` — checkout and order routes.
- `43ae06c`, `a89df3f` — vendor handler corrections.
- `c1ffe47` — profile update route adjustment.
- `620c426` — product vendor ownership migration.
- `d302abd` — product vendor relationship.
- Latest follow-up commits: catalog management controller/routes/tests, public category hierarchy, SQL Server order/address FK correction, and admin product route collision fix. Check the branch history for exact SHAs.

These are incremental commits, **not a validated or production-ready feature**. Inspect the branch's actual latest tree before relying on any description here.

## Critical handoff warning

The current implementation is incomplete and has not been run against a Laravel environment or database. Before adding more features, perform a code review of every changed file and fix syntax, schema, relationship, authorization, route, and response-contract issues.

Known areas requiring immediate verification:
1. `StorefrontController.php` may contain incomplete or malformed vendor-handler edits from the interrupted implementation. Inspect the actual file first; do not assume vendor products are complete.
2. Confirm all referenced Eloquent relationships/columns exist (`Product`, `Category`, `Brand`, variants, media, inventory, tags, attributes).
3. Confirm `ApiResponse` method signatures match all calls.
4. Confirm `AuthController` methods and request/response contracts match frontend expectations.
5. Verify route ordering (static routes before wildcard routes where needed) and the frontend's expected `/api/v1` base URL.
6. The commerce schema is only an initial baseline. Confirm nullable unique constraints, DB-driver compatibility, inventory schema, foreign keys and migration rollback behavior.
7. Do not represent unavailable features as functional by returning hardcoded success or empty data. A placeholder fallback belongs in the frontend; backend should return truthful empty results or explicit validation/configuration errors.
8. Do not process real payments until a provider integration, webhook verification, idempotency and payment state machine are implemented.
9. Do not assume shipping methods exist; build configuration-backed methods/rates and clearly report when none are configured.
10. Product ownership must be applied consistently in admin/vendor authorization and product queries.

## Ordered implementation plan

### Phase 0 — Stabilize and discover (first priority)
- Read this tracker.
- Fetch latest `main` and feature-branch trees for both repositories.
- Inspect every frontend service, API endpoint constant, API client, hook, data adapter, mock provider, relevant page/component and TypeScript API type.
- Produce a complete endpoint matrix: method, path, auth, query params, request body, response envelope, consumer, mock fallback and status.
- Compare with Laravel routes/controllers/models/migrations/seeders/policies.
- Fix syntax and structural issues already introduced before expanding scope.

### Phase 1 — API foundation and catalog
- Standardize API prefix, response envelope, pagination, validation errors, authentication and CORS.
- Public catalog: products, product details, categories/tree, brands, vendors, collections, search, filters, sorting, pagination.
- Ensure product images/media, variants/options, prices, stock, ratings and metadata map to frontend response types.
- Add vendor ownership and authorization properly.
- Seed only development/demo data through explicit seeders; never silently fabricate live records.

### Phase 2 — Customer identity and profile
- Register, login, logout, current user, profile update, password change/reset and token lifecycle.
- Align Sanctum bearer-token behavior with frontend client.
- Addresses CRUD and country/region/city data source/contract. Inspect frontend's actual location selectors before selecting a package or schema.

### Phase 3 — Shopping
- Persistent cart, guest/session cart if frontend supports it, merge guest cart on login, item CRUD, variant validation and stock checks.
- Wishlist list/add/remove.
- Product reviews list/create, moderation and aggregate rating/count.
- Ensure ownership checks and prevent cross-user access.

### Phase 4 — Checkout and fulfillment
- Checkout validation and order creation with DB transactions, price recalculation server-side, inventory reservation/decrement, snapshots of address/product/price and idempotency.
- Configurable shipping methods and rates; do not hardcode fabricated rates.
- Payment method discovery and provider integration only when credentials/configuration are available. COD can be enabled only if business rules permit it.
- Orders list/detail, cancellation/refund rules, shipment records and tracking integration.

### Phase 5 — Quality and integration
- Feature tests for public and authenticated endpoints, validation, authorization, pagination, empty states, concurrency, stock and checkout.
- API smoke tests using seeded database records.
- Update API docs / OpenAPI if repository uses it.
- Only after endpoint tests pass, update frontend configuration to use backend and keep mock fallback for API failures as requested.
- Test all storefront flows end-to-end with real API data.

### Phase 6 — AI/ML readiness
- Preserve the existing AI product assistant.
- Ground search/recommendation tools in real catalog, availability, prices and customer permissions.
- Add comparison, recommendation/personalization and semantic retrieval only after catalog data contracts and quality are stable.

## API domain checklist

| Domain | Required backend capability | Status |
|---|---|---|
| Auth | Register/login/logout/me/token handling | Existing auth; contract verification pending |
| Products | List/detail/search/filter/sort/pagination | Partial implementation on feature branch; verify |
| Categories | Tree/list/detail + admin CRUD | Implemented on feature branch; SQL Server CI pending |
| Brands | List/detail/products + admin CRUD | Implemented on feature branch; SQL Server CI pending |
| Vendors | List/detail/products and ownership | Incomplete; verify/fix |
| Collections | List/detail and collection products | Schema/list/detail started; product resolution pending |
| Locations | Countries/regions/cities | Not audited fully |
| Profile | Read/update/password | Partial; verify |
| Addresses | CRUD/default address | Initial implementation; verify |
| Cart | Guest/auth cart, CRUD, merge, pricing | Initial implementation; guest support pending |
| Wishlist | List/add/remove | Initial implementation; verify |
| Reviews | List/create/moderation/aggregates | Initial implementation; verify |
| Inventory | Availability and reservation | Existing schema; checkout integration needs review |
| Checkout | Server-side totals/order creation | Initial COD workflow only; not validated |
| Shipping | Methods/rates/quotes | Config-backed methods and address/currency quote implemented; validate end-to-end |
| Payments | Methods/provider lifecycle/webhooks | Not implemented beyond initial method listing |
| Orders | List/detail/status/cancel | Initial list/detail/create; remaining lifecycle pending |
| Shipments | Carrier/tracking events | Demo shipment seed and order-embedded tracking; lifecycle/admin integration still needs audit |
| Admin/vendor | Role-scoped management | Store/product handlers and brand/category management implemented; continue route/authorization audit |
| AI assistant | Search/recommendation grounded in live data | Existing foundation; verify after catalog completion |

## Engineering rules for all agents

- Work only in `feat/storefront-api-integration` unless the user explicitly changes scope.
- Never push unreviewed work to `main`.
- Inspect existing architecture and reuse existing models/services/resources; avoid duplicate abstractions.
- Match frontend contracts exactly; document any necessary frontend contract changes before implementing them.
- Use Form Requests, API Resources, policies/permissions, transactions and service classes consistent with repository conventions.
- Validate ownership and authorization on every customer/vendor resource.
- Recalculate price, discounts, tax, shipping and stock on the server. Never trust client totals.
- Use idempotency for order/payment operations and safe retry behavior.
- Never expose secrets, internal stack traces or sensitive user data.
- Do not claim tests passed unless actually run and results are available.
- Keep commits focused and messages descriptive.
- After each work session, update this tracker with files changed, commits, tests run, failures, outstanding risks and the exact next action.

## Agent session log

### 2026-11-25
- Started frontend-driven backend integration on `feat/storefront-api-integration`.
- Added initial migration/controller/routes and vendor ownership foundation (see commit list above).
- No local Laravel runtime/database test results have been recorded yet.
- **Next action:** inspect current feature branch files for interrupted edits and syntax/schema correctness, then complete the endpoint inventory from the frontend before proceeding.


## Progress update — 2026-11-25 (continued)

### Backend work added on `feat/storefront-api-integration`
- Expanded public catalog APIs: product filtering/sorting/pagination, category hierarchy, brand/vendor listings and product listings, collection details/products.
- Added account profile/password, password reset, address CRUD, cart, wishlist, review and order APIs.
- Added checkout stock/variant validation, shipping method configuration/quotes, order idempotency support and shipment tracking snapshots/admin updates.
- Added vendor ownership/profile schema and admin-role protection for administration routes.
- Added phone, catalog image URLs, collection metadata, vendor profile metadata and order-item product snapshots.
- Imported the frontend's existing demo JSON fixtures into backend seeder data: 132 products, 9 root categories plus children, 6 brands, 4 vendors, 4 collections and 120 matching reviews (72 review fixtures reference products absent from the current catalogue and are skipped).
- Added `StorefrontDemoSeeder`; `DatabaseSeeder` now seeds roles then the development catalog. Demo catalog seeding is disabled by default in production.
- Added `API_REQUIREMENTS.md` with the endpoint matrix and frontend integration gaps.

### Current known limitations / risks
- The catalog fixtures are copied from frontend mock data and are development/demo data, not verified live business inventory.
- Frontend integration is not yet complete: auth still uses temporary credentials; service-level API failure fallback, collections, shipping/payment, inventory, shipment and idempotency wiring still need frontend changes.
- Country/state/city selectors still need a complete component-level audit before deciding whether to add location endpoints.
- Shipping methods are database-configured and intentionally return a service-unavailable response when none are configured; no rates are invented.
- Cash on delivery is configurable; card payment is not implemented.
- Vendor product ownership is available, but vendor dashboard CRUD/permissions still need a frontend-driven audit.
- Guest carts are not implemented; cart endpoints currently require Sanctum authentication.
- No PHP runtime, migration, seeder or automated test execution has been performed. The current environment could not reach GitHub from a local runtime, so do not mark validation complete.

### Immediate next actions
1. Review the latest changed PHP and migrations for syntax/schema compatibility; run local PHP lint and migrations.
2. Add API feature tests for auth, public catalog, customer ownership, stock validation, checkout and tracking.
3. Verify the seeded demo catalogue against frontend mock shapes and run the seed idempotently.
4. Complete frontend page/component audit for locations and any hidden API dependencies.
5. Integrate frontend API clients with real endpoints and mock fallback on failures.
6. Run end-to-end checks and update the existing draft PR #46. Do not merge without user approval.


### Pull request checkpoint
- Draft PR: https://github.com/imiantalha/blossom-glimmer-api/pull/46
- Head branch: `feat/storefront-api-integration`
- Latest confirmed branch head at this checkpoint: `133de708dbea4f8b004a1450f5c6d4d0cad56e58`
- PR is open, draft, and GitHub reports it mergeable. It is **not merged**.
- GitHub Actions status is not visible yet; no passing CI result has been confirmed.
- The latest migration review caught and corrected a malformed block in migration `000005`; re-review all migration and PHP files before marking validation complete.


## Progress update — backend completion and frontend integration (2026-11-25)

### Newly confirmed frontend integration branch
- Frontend branch: `feat/laravel-api-integration` (66+ commits ahead of main at inspection).
- It contains centralized API clients, NextAuth bearer-token bridging, read-only mock fallback, guest cart/wishlist persistence, vendor store and product actions, admin store/product queries and checkout/order service integration.
- Further work is required: cart/wishlist mutations need robust offline reconciliation; address/profile/review/collection/shipping/payment/inventory/shipment flows need end-to-end checks; vendor registration has now been moved to the Laravel endpoint.

### Backend additions in this continuation
- Added `POST /auth/register/vendor` and frontend server action now uses it instead of frontend Prisma for vendor account creation.
- Added admin product creation-options endpoint; frontend admin page now calls the admin-scoped endpoint.
- Reordered admin product GET routes so the ProductRecord-compatible vendor controller responses are not shadowed by generic resource GET routes.
- Added vendor registration and vendor store onboarding feature tests.
- Fixed duplicate legacy product catalog migration, skipped MongoDB-backed request logging during automated tests, and installed Microsoft ODBC Driver 18 in SQL Server CI.
- Added a local SQL Server 2022 Docker Compose service and documented SQL Server configuration and demo accounts.

### CI findings
- The first CI run failed for three concrete reasons: duplicate `combination_key` migration, MongoDB request logging during tests, and missing Microsoft ODBC Driver 18 in the SQL Server job.
- Fixes are committed. Verify the newest CI run after these commits; do not treat the earlier failed run as validation.

### Demo dataset
- Existing development seeders load the frontend's image-backed catalogue: 132 products (simple and variable), categories with hierarchy, 6 brands, 4 vendor stores, 4 collections, and 120 reviews that map to seeded products.
- Scenario seeder creates customer/admin/vendor test accounts, shipping methods, customer address, cart, wishlist, review, sample order, shipment and tracking event.
- Development test password: `Password123!`. Never enable demo seeders in production.

### Immediate next actions
1. Verify current CI results on the latest backend PR head and fix every migration/runtime failure, including SQL Server-specific issues.
2. Complete remaining backend lifecycle APIs (vendor/admin product/store, review moderation, order cancellation/status, shipment event reads, address and profile validation) only where the frontend requires them.
3. Complete frontend service integration and API-unavailable fallback behavior, ensuring mutations never claim remote success and local guest data is reconciled safely.
4. Add frontend tests for fallback and API contract behavior; run TypeScript, ESLint, build, Laravel tests on SQLite and SQL Server.
5. Update PRs and report exact results; do not merge without user approval.


### Latest implementation checkpoint
- Added vendor registration, store profile management and admin store moderation/commission endpoints.
- Added vendor product options/list/create/detail/update/archive and stock adjustment endpoints; admin product list/detail/options and approval/rejection endpoints.
- Frontend now uses Laravel product/store queries and mutations; guest cart and wishlist can merge by product slug and variant SKU.
- Added demo scenarios and expanded feature tests for product listing, auth, vendor isolation, inventory, checkout idempotency and seeded data.
- Added SQLite and SQL Server CI jobs. Latest SQL Server run is being rechecked after changing foreign-key delete behavior to SQL Server-compatible `NO ACTION`.
- Frontend integration remains on `feat/laravel-api-integration`; run its lint/typecheck/build before opening its PR.
