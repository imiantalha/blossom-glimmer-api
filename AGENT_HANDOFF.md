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
| Categories | Tree/list/detail | Initial implementation; verify relationships |
| Brands | List/detail/products | Initial implementation; verify |
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
| Shipping | Methods/rates/quotes | Not implemented; no fabricated rates |
| Payments | Methods/provider lifecycle/webhooks | Not implemented beyond initial method listing |
| Orders | List/detail/status/cancel | Initial list/detail/create; remaining lifecycle pending |
| Shipments | Carrier/tracking events | Not implemented |
| Admin/vendor | Role-scoped management | Existing admin APIs; vendor authorization pending |
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
