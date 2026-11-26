# Blossom Glimmer — Marketplace Completion Tracker

## Architecture decisions

- The Next.js repository (`imiantalha/blossom-glimmer`) owns the customer-facing storefront and customer account/checkout experience.
- The Laravel repository (`imiantalha/blossom-glimmer-api`) owns the API, database, business rules, and the React admin/vendor dashboard.
- The Laravel React dashboard must not duplicate customer storefront pages or customer shopping flows.
- Existing Next.js `/administrator` and `/vendor` routes are legacy/overlapping dashboard surfaces. Audit their imports, auth and navigation before redirecting/removing them; do not delete blindly.
- Payment, payout, shipping and notification providers may be simulated locally. All mock operations must be clearly labelled as simulated and must never claim that real money was charged or transferred.

## Completion standard

A module is complete only when its UI, API, validation, authorization, persistence, loading/empty/error states, and tests are implemented. A route existing or a page rendering is not sufficient.

## Customer storefront — Next.js

- Authentication: registration, login/logout, vendor application submission, forgot/reset password, session expiry and protected routes.
- Account: profile, password, address CRUD/default address, order history/details, shipment timeline.
- Discovery: homepage, categories, brands, stores, collections, product listing/details, search/filter/sort/pagination, variants.
- Shopping: cart merge, quantity/stock validation, wishlist, compare, recently viewed.
- Checkout: server-calculated totals, address validation, shipping quote, discounts, payment selection, idempotent order placement and confirmation.
- Post-purchase: order tracking, cancellation/return request eligibility, reviews, notifications and support contact/tickets.

## Vendor dashboard — Laravel React app

- Vendor application and approval state; store creation/profile/media/settings.
- Product CRUD, variants/media/pricing, approval feedback and product status.
- Inventory quantities, stock adjustments, low-stock alerts and stock movement history.
- Vendor-scoped order listing/details, fulfilment, carrier/tracking and delivery status.
- Earnings, commissions, deductions, refunds, ledger, payout requests/history/status.
- Sales/product analytics, review responses, notifications and support tickets.
- Every query and mutation must be scoped to the authenticated vendor and its store.

## Admin dashboard — Laravel React app

- Dashboard KPIs and reports.
- Vendor onboarding, store approval/suspension/reactivation and commission configuration.
- Customer directory, account activation, roles and customer purchase history.
- Product moderation, catalog, categories, brands, tags, attributes and collections.
- Marketplace-wide orders and per-vendor fulfilment oversight.
- Simulated payments, payment failures, refunds, returns and reconciliation.
- Commission rules, vendor ledger, payout approval/rejection/mark-paid simulation.
- Inventory oversight, promotions/coupons, shipping methods/zones.
- Review moderation, support tickets/disputes, notifications.
- Marketplace settings, roles/permissions, audit trail and sales/commission/refund/payout reports.

## Provider-independent local simulations

- Payment adapter: deterministic success/failure/pending outcomes, transaction reference, status history, retry and webhook-event simulation. Never store card PAN/CVV or represent the simulation as a real charge.
- Refund adapter: full/partial refund requests with admin review and order/payment status reconciliation.
- Payout adapter: vendor payout request, admin approve/reject, simulated disbursement, reference and ledger entries. No real bank transfer.
- Keep provider integration behind interfaces/configuration so a real gateway can replace the simulator later without changing checkout/order business logic.

## Resilience and API contract requirements

- All pages: loading, empty, recoverable error and retry states.
- Add route-level and root-level React/Next error boundaries; log technical details, show users a friendly message.
- Validate API envelope and endpoint data shapes before rendering. Treat missing/wrongly typed fields as recoverable errors or safe empty values; do not blindly dereference nested data.
- Normalize server errors to a stable `success/message/errors/data` contract. Do not expose stack traces or secrets in production.
- Handle non-JSON responses, invalid JSON, timeouts, offline/network errors, 401/403/404/422/429/5xx and malformed success payloads.
- Mutations must not silently fall back to mock success. Read-only fallback is allowed only where explicitly intended and must be visibly identified as demo data.
- Preserve authorization and ownership checks on the server; frontend route guards are not security boundaries.

## Test and release requirements

- Seed local demo data: multiple customers, vendors/stores, products of different types, variants, inventory, addresses, orders, reviews, coupons, payment outcomes, refunds and payouts. Use safe demo images.
- API feature tests for role access, ownership isolation, validation, state transitions, idempotency, malformed payloads and simulated provider outcomes.
- Frontend tests for loading/empty/error states, invalid response shapes, retry, auth/login/logout, cart/checkout, vendor workflows and admin workflows.
- Browser tests for customer, vendor and admin scenarios against local services only.
- Verify SQLite and SQL Server compatibility where supported.
- Do not access staging/production or third-party databases during this work.
- Avoid GitHub Actions runs because the monthly minutes budget is nearly exhausted. Do not claim tests passed unless actually executed locally and recorded.
- Keep work on the existing feature branches/PRs. Do not merge or deploy until implementation and local verification are complete and the user explicitly approves.

## Known gaps identified in the 2026-11-26 audit

- Backend payment methods currently expose COD only; order creation accepts only `cod`.
- No payment transaction lifecycle, refund/return workflow, vendor ledger/payout workflow, coupon/promotion module, in-app notification center, or support-ticket module was found in the Laravel API tree.
- The Laravel React dashboard currently has a small set of routes (dashboard, users, customers, login); marketplace administration and vendor workspace screens remain to be implemented there.
- Next.js still contains legacy `/administrator` and `/vendor` routes despite the agreed architecture. Audit and consolidate these without removing customer functionality.
- Both applications need page-level resilience beyond API envelope validation. Root/route error boundaries have been started on the respective branches; continue validating every module's data contracts and error states.


## Implementation progress — 2026-11-26 continuation

### Implemented on the feature branch (not yet runtime-verified)

- Added SQL-backed `audit_logs` storage and an API middleware audit trail for versioned API activity. Records actor ID/role, action, HTTP method/path, resource type/identifier, response status, IP, user agent, timing, outcome and safe metadata key names.
- Request audit metadata deliberately excludes credentials and sensitive payment/banking values; it does not persist raw request bodies.
- Product detail API reads are recorded as product views (including guest views); order placement also writes explicit order and per-product purchase audit records.
- Successful/failed login, logout and registration events are explicitly audited.
- Added an admin audit-log API and searchable React audit activity page.
- Added Super Admin role provisioning through the role seeder and optional environment-configured first-account bootstrap (`SUPER_ADMIN_EMAIL`, `SUPER_ADMIN_PASSWORD`, optional `SUPER_ADMIN_NAME`). No default credentials are created.
- Restricted role and permission management API routes to Super Admin. Added basic React role/permission management pages.
- Added user-request authorization rules preventing Admin users from creating/promoting/deleting privileged Admin or Super Admin accounts.
- Added active-account middleware and applied it to authenticated API groups; deactivated users' current access token is revoked on access.
- Separated customer shopping/checkout APIs from Vendor and Admin route groups; admin shipment update is in the admin route group.
- Corrected Laravel React dashboard API base URL to `/api/v1`, auth route paths to `/auth/*`, and admin user service paths to `/admin/users`.
- Corrected the dashboard login response parsing for Laravel's `success/message/data` envelope and role-aware login redirects.
- Added role-aware React dashboard routing/navigation, vendor dashboard, vendor order fulfilment page, vendor store profile page, and vendor product/inventory list.
- Added defensive Next.js API envelope/pagination parsing and invalid-JSON handling, plus route/root error recovery screens. Added a React dashboard error boundary.

### Still outstanding — do not mark complete

- Full vendor product create/edit experience in the Laravel React dashboard; vendor analytics, earnings, commission ledger, payout requests, notifications and support.
- Admin React pages and workflows for marketplace products, categories, brands, stores, orders, inventory, reviews, shipping, settings, reports, refunds, returns, promotions/coupons, support and financial operations.
- Customer-facing payment transaction lifecycle and mock payment adapter; refund/return state machine; payout simulation and vendor ledger.
- Promotions/coupons, notification center/preferences and support ticket workflows.
- End-to-end role/permission and ownership-isolation test matrix, API contract tests, malformed-payload UI tests, browser scenarios and seeded multi-role demo data.
- Verify all route/service contracts in both repositories, including old Next.js `/administrator` and `/vendor` route consolidation.
- Validate SQL Server and SQLite migrations/queries. No tests, CI, staging or production checks have been run during this continuation.

## Follow-up after Actions were disabled — 2026-11-26

- Corrected the doubled namespace/import separators in `VendorDashboardController.php` that caused the earlier PHP parse failure.
- Corrected the legacy admin shipment controller guard to allow both Admin and Super Admin, matching the route group's role policy.
- Re-inspected the newly added PHP controllers and the main new migrations for the same doubled-backslash syntax issue; no further occurrences were found in the inspected files.
- GitHub Actions remain disabled per the repository owner's confirmation. No workflow was dispatched. The latest backend commit has no status checks because CI is disabled; this is not a passing test result.
- The feature branches are still not release-ready: payment transaction simulation, refunds/returns, vendor ledger/payouts, notifications, support tickets, remaining vendor/admin screens, demo fixtures and the full local browser/security test matrix remain outstanding. Do not merge or deploy on the basis of these syntax/RBAC corrections alone.

### Implementation progress — payment/refund backend slice

- Added `payment_transactions` and `refund_requests` persistence with order/customer ownership, unique payment references, status fields, audit timestamps and query indexes.
- Added customer endpoints to create and inspect local simulated payment attempts, record deterministic success/failure/pending outcomes, and submit/list refund requests.
- Added Admin/Super Admin endpoints to review and process approved refunds. Processing is explicitly simulated and returns a reference; it does not transfer money.
- Checkout now accepts `mock_card` and `mock_wallet` as simulation-only methods alongside COD. The API never accepts card PAN/CVV or banking credentials.
- This slice is committed to the existing backend feature branch. It has not been run against Laravel/PHP or a database; it is not runtime-verified. Frontend checkout wiring, refund UI, test coverage and remaining modules are still outstanding.

### Implementation progress — finance, support and notifications

- Added vendor ledger and payout persistence, with idempotent sale-credit entries for eligible delivered order items, commission deduction, available-balance calculation, payout reservation, admin review and simulated processing.
- Added Vendor finance summary/ledger/payout APIs and Admin/Super Admin payout queue/review/process APIs.
- Added Vendor Earnings & Payouts and Support screens, Admin Payout and Refund management screens, and Admin support-ticket management.
- Added support-ticket APIs for customer/vendor ticket creation, owner-scoped listing/details/replies, Admin queue/replies/status/priority/assignment, and private internal notes. Customer responses cannot read internal notes.
- Added in-app notification persistence and owner-scoped list/read/read-all APIs, plus notification screens for dashboard users and customers.
- Added customer account pages for support tickets, refund requests/history, and notifications; linked them in account navigation.
- Added notification/support/refund endpoint contracts to the Next.js API endpoint registry.
- All payout/refund operations remain explicitly simulated; no real bank or payment transfer is performed.
- These changes are committed to the existing feature branches. They have not been run against Laravel, a database, Next.js, or a browser; no tests are claimed as passed.

### Remaining implementation work

- Vendor product create/edit/variant/media flows and vendor analytics/review responses.
- Admin category/brand/store/product/order/inventory/shipping/promotion/settings/report screens and complete lifecycle controls.
- Coupons/discount rules and checkout application with server-side totals and usage limits.
- Order cancellation/return eligibility and state machine; shipment tracking and notification event automation.
- Seeded multi-role demo dataset and automated tests for RBAC, ownership, financial state transitions, malformed API responses, browser login/logout and complete purchase/fulfilment/refund/payout journeys.
- Audit all endpoint contracts and old Next.js dashboard routes; resolve any discovered mismatches.

### Implementation progress — vendor catalogue, reviews and coupons

- Replaced the vendor products read-only table with a catalogue workflow for creating and editing simple/variable products, approved brand/category/shipping selection, image URL, SKU/pricing/stock/dimensions, draft saving, approval submission, inventory adjustments and archive actions.
- Added vendor review inbox API and UI. Vendors can view reviews belonging only to their own products and publish a public reply to approved reviews; the customer receives an in-app notification. Added vendor review permissions and schema fields.
- Added coupon rule storage, admin CRUD/status management UI, global/per-customer usage limits, active date windows, minimum subtotal, percentage/fixed discounts and maximum discount caps.
- Added customer cart coupon validation and applied coupon discounts atomically during order creation, with redemption history and usage-count updates. Customer checkout now previews the discount and sends the coupon code for server-side revalidation.
- Added customer/admin coupon endpoint contracts and Admin/Super Admin coupon navigation.
- These changes are committed on the existing feature branches. Runtime validation, migrations, build checks and browser journeys are still pending and have not been claimed as passed.

### Remaining implementation work (updated)

- Vendor analytics/reports, store profile completeness, vendor order lifecycle and customer order cancellation/return eligibility/state machine.
- Customer-facing review reply rendering and notification event coverage for all order/payment/refund/shipping transitions.
- Admin shipping/tracking, promotions reporting, marketplace settings, order/fulfillment, inventory and moderation workflows: audit all screens against the backend route contracts and complete missing actions.
- Seeded multi-role demo dataset, schema compatibility verification (MySQL and SQL Server), API ownership/RBAC tests, frontend type/lint/build checks and full browser login/logout + purchase-to-delivery/refund/payout scenarios.
- Review idempotency and coupon lifecycle edge cases (failed/cancelled payment redemptions, coupon edits after usage, and order retry semantics) during the comprehensive verification phase.

### Implementation progress — admin marketplace operations

- Added admin order search/filter/detail UI and per-vendor fulfilment advancement, including carrier/tracking capture before shipment.
- Added admin product moderation UI for pending approval/rejection, product activation/deactivation and featured status.
- Added admin vendor-store queue with approve/activate, suspend, pending status and commission controls.
- Added admin brand/category CRUD screens, including root/child category selection and active/inactive status.
- Registered all new admin routes in the role-protected React dashboard and updated sidebar navigation.
- Corrected the vendor sidebar conditional to use a fragment around its multiple navigation items; the earlier incremental navigation insertions had produced invalid JSX.
- No local build, PHP syntax run, migrations, automated tests or browser verification have been performed because no non-billable runtime is connected. GitHub Actions remain disabled as requested.

### Remaining work — focus areas

- Complete remaining marketplace settings (collections, shipping methods, attributes/tags) UI and admin review moderation screens.
- Customer order cancellation and return eligibility/state transitions, plus shipment tracking display and notifications on payment/refund/order changes.
- Vendor store profile editing and order detail/fulfilment UX; customer order details and order history consistency.
- Verify admin/vendor/customer navigation and role isolation, especially separate route groups and permission seeding.
- Finish seeded data and comprehensive tests only after implementation scope is complete.

### Implementation progress — admin catalogue, orders and cancellation

- Added Admin/Super Admin marketplace order search, filtering, order details, vendor-specific fulfilment progression and tracking capture.
- Added Admin/Super Admin product moderation controls (approve/reject, activate/deactivate, feature/unfeature), store approval/suspension and commission controls, and brand/category CRUD.
- Added customer order cancellation for unpaid/pending or COD orders before any vendor begins fulfilment. Cancellation restores managed inventory, cancels pending simulated payment attempts, releases coupon redemption usage and records a customer notification.
- Added a customer order-detail cancellation action with server-authoritative eligibility checks and clear handling for paid orders (refund flow instead).
- Hardened the cancellation guard to check vendor fulfilment rows directly, not only the parent order status.
- Normalized uppercase product statuses in the admin product controls so activation/deactivation actions work against the backend payload.
- These are implementation commits only; full tests remain intentionally deferred until the requested implementation phase is complete.

### Remaining implementation work (next)

- Complete marketplace settings UI (collections, shipping methods, attributes, tags) and admin review moderation.
- Add customer order tracking/return journey and automatic notifications for payment, shipment, refund and fulfilment state changes.
- Complete vendor store profile editing and richer order details/fulfilment workflow.
- Final audit of all route/permission contracts, migrations and seed data, followed by one comprehensive test pass.

### Implementation progress — remaining admin catalogue screens

- Added Admin/Super Admin management screens for storefront collections and shipping methods, including create/edit and enable/disable actions.
- Added catalog attribute and tag management, including attribute type, variant flag, approved values and active/inactive controls.
- Added customer review moderation UI with pending/approved/rejected filters and approve/reject actions with rejection reasons.
- Registered all screens in the Admin/Super Admin protected router and navigation.
- Existing APIs were reused; no duplicate endpoint families were introduced.

### Remaining implementation work

- Improve vendor store profile editor and customer-facing review reply rendering.
- Complete customer order tracking and return/cancellation/refund lifecycle UX, including notifications from payment, shipment, refund and fulfilment changes.
- Inspect the frontend for other incomplete flows and reconcile all API contracts and fallback behavior.
- Complete demo data seeding and then run the single comprehensive test phase after implementation is complete.

### Implementation progress — customer tracking and lifecycle notifications

- Customer order payloads now include vendor-specific fulfilment records, carrier, tracking number/URL, estimated delivery and the items in each store shipment.
- Customer order detail shipping UI now displays tracking separately for each vendor shipment, while retaining support for legacy single-shipment orders.
- Public approved product reviews now expose vendor replies; storefront review cards render the store response and reply date.
- Added customer notifications for simulated payment success/failure, refund request submission, refund approval/rejection/processing, and vendor/admin fulfilment status changes.
- Fixed a refund accounting defect discovered during implementation: the processing code previously counted the current refund twice when calculating cumulative refunded amount. It now sums processed requests once.
- Customer order cancellation now releases coupon usage and is blocked once any vendor fulfilment has moved beyond pending.
- These changes still require runtime/database verification; no tests are claimed as passed.

### Remaining implementation work

- Vendor store profile editor, customer return workflow for delivered orders, order lifecycle consistency and support follow-up.
- Complete notifications for store/product approvals and other administrative actions; check duplicate/noisy notification behavior.
- Seed demo data, reconcile all front-end API contracts, and then run full role, lifecycle, API and browser verification.

### Implementation progress — local lifecycle demo data

- Existing catalog seed data already supplies multiple categories/subcategories, brands, vendors, products, images and both simple/variable product structures. Demo seeding remains gated to local/testing environments and the storefront seed flag.
- Added a lifecycle scenario seeder with a paid/delivered marketplace order, simulated payment transaction, vendor fulfilment tracking, pending refund request, WELCOME10 coupon, customer support ticket/message, notification and pending simulated vendor payout request.
- Added the lifecycle seeder to DatabaseSeeder after catalog and baseline scenario data.
- Corrected the baseline shipped demo order's vendor fulfilment records so their status and tracking details agree with the parent order; customer payloads suppress the legacy parent shipment when vendor-specific shipments exist, avoiding duplicate tracking cards.
- Demo credentials in local/testing only: customer and customer2 plus seeded admin use Password123!; vendor accounts use the email pattern vendor+STORE-SLUG@demo.blossomglimmer.local with Password123!. A Super Admin is only bootstrapped when explicit SUPER_ADMIN_EMAIL and SUPER_ADMIN_PASSWORD environment variables are set.
- Seeders have not been executed against a local database in this session. Treat these as code changes pending actual migration/seed verification.

### Remaining implementation work

- Verify and refine the seeded lifecycle data on MySQL/SQL Server; add more order states and customer/vendor role scenarios if gaps remain.
- Add notifications for admin product/store decisions and make support ticket assignment/priority handling more complete.
- Audit the remaining Next.js customer/vendor/admin screens and API fallback paths; finish outstanding order/refund/support actions.
- Then run the complete test phase once implementation is frozen.

### Implementation progress — vendor support and operational notifications

- Completed the vendor support ticket conversation UI: vendors can create tickets, open their own ticket threads, read support responses and send follow-up messages until resolved/closed.
- Added notifications to vendors when product moderation decisions are made and when their store is approved, set pending or suspended.
- Added lifecycle demo records for delivered/paid order, pending refund, support ticket, coupon and payout review to support later integrated verification.

### Implementation progress — variable product correctness and financial concurrency

- Expanded the vendor product form to select approved variant attributes and choose a valid value for each variant; saved metadata now carries the full approved value set.
- Added frontend and backend checks for unique variant SKUs, unique/complete variant combinations and sale prices below regular prices. This prevents database uniqueness failures for variable products and rejects duplicate SKU use across products.
- Serialized vendor payout requests on the vendor row and made admin payout review atomic; vendor notifications now cover payout approval/rejection/processing.
- Enforced a 100% upper bound for percentage coupons.
- Made refund review atomic to prevent concurrent duplicate decisions.

### Repository workflow correction (2026-11-26)

- A group of Next.js portal files was initially created on the frontend repository's default branch because the branch argument was omitted from some create-file calls. This was identified during the commit audit.
- All affected portal files have now been copied to the existing frontend feature branch for PR #57.
- A draft corrective revert PR was opened: https://github.com/imiantalha/blossom-glimmer/pull/58. It is intentionally unmerged. If approved, merge the revert PR before the feature PR so the portal implementation returns through review rather than remaining as direct default-branch commits.
- No PRs have been merged and no deployments have been made.

### Next.js portal consolidation progress

- Copied customer support/refund/notification pages and vendor finance/reviews/support/notifications workspaces into the frontend feature branch.
- Added Next.js Admin/Super Admin finance (payout/refund), coupons, support and notification workspaces and registered them in the portal navigation.
- The Next.js application already contains Admin/Super Admin store, product, order, category, brand, catalog-settings, marketplace-settings, roles and review pages; the new modules fill gaps in that same portal rather than relying only on the Laravel React dashboard.
- Frontend feature branch includes the new routes/components; see PR #57. The corrective default-branch revert is tracked separately in draft PR #58.

### RBAC UI alignment

- The Next.js admin portal now hides the Roles & Permissions navigation entry from ordinary Admin users and guards the role-management page itself to Super Admin. This matches the Laravel API's Super Admin-only role/permission routes.
- Admin and Super Admin retain access to normal marketplace operations; Vendor and Customer workspaces remain role-scoped by their respective layouts and API middleware.

### Implementation progress — portal dashboards

- Added a role-scoped Admin/Super Admin dashboard metrics endpoint covering user/store/product/order counts, paid revenue grouped by currency, pending refunds/payouts, support workload, reviews and catalog requests.
- Connected the Next.js Admin dashboard to live API metrics with a safe unavailable state when the API cannot be reached.
- Connected the Next.js Vendor dashboard to vendor-scoped live product/order/sales metrics, low-stock alerts and recent vendor orders.
- Added Next.js portal workspaces and routes for vendor finance, reviews, support and notifications; Admin/Super Admin finance, coupons, support and notifications; Customer support, refunds and notifications.
- Added action links in notification workspaces so users can navigate to the related order, refund, finance or support area.
- Role-management navigation and page access are now Super Admin-only, matching API route middleware.
