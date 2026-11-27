# Blossom Glimmer — Marketplace Completion Tracker

## Architecture decisions

- The Next.js repository (`imiantalha/blossom-glimmer`) is the canonical public website and the single production entry point for all marketplace roles: Customers use the storefront/account/checkout routes, Admin and Super Admin use `/administrator`, and Vendors use `/vendor`.
- The Laravel repository (`imiantalha/blossom-glimmer-api`) owns the API, database, business rules, server-side authorization, and the preserved React staff dashboard.
- The Laravel React dashboard remains available as a legacy/internal staff portal at the Laravel application origin. It is not the canonical public website and must not be deleted or silently replaced; retirement requires separate approval.
- Production DNS should route the public website domain to Next.js and configure `NEXT_PUBLIC_API_URL` to the Laravel API origin. Keep the Laravel dashboard origin separate from the public website domain.
- Next.js Admin/Vendor routes are active canonical role workspaces, not redirects to the Laravel dashboard. Keep their role guards aligned with Laravel API authorization.
- Payment, payout, shipping and notification providers may be simulated locally. All mock operations must be clearly labelled as simulated and must never claim that real money was charged or transferred.

## Completion standard

A module is implementation-complete only when its UI, API, validation, authorization, persistence, loading/empty/error states, and action-to-API contract are implemented. A route existing or a page rendering is not sufficient. A separate release-verification status is granted only after the authorized test/build/browser and database compatibility phase passes.

**Tracker chronology note:** This document preserves historical progress snapshots. Any earlier heading or bullet labelled “Remaining work” describes the state at that timestamp; use the latest dated implementation entries and the current release-verification gate as the source of truth.

## Customer storefront — Next.js

- Authentication: registration, login/logout, vendor application submission, forgot/reset password, session expiry and protected routes.
- Account: profile, password, address CRUD/default address, order history/details, shipment timeline.
- Discovery: homepage, categories, brands, stores, collections, product listing/details, search/filter/sort/pagination, variants.
- Shopping: cart merge, quantity/stock validation, wishlist, compare, recently viewed.
- Checkout: server-calculated totals, address validation, shipping quote, discounts, payment selection, idempotent order placement and confirmation.
- Post-purchase: order tracking, cancellation/return request eligibility, reviews, notifications and support contact/tickets.

## Vendor dashboard — canonical Next.js portal; Laravel React legacy surface

- Vendor application and approval state; store creation/profile/media/settings.
- Product CRUD, variants/media/pricing, approval feedback and product status.
- Inventory quantities, stock adjustments, low-stock alerts and stock movement history.
- Vendor-scoped order listing/details, fulfilment, carrier/tracking and delivery status.
- Earnings, commissions, deductions, refunds, ledger, payout requests/history/status.
- Sales/product analytics, review responses, notifications and support tickets.
- Every query and mutation must be scoped to the authenticated vendor and its store.

## Admin dashboard — canonical Next.js portal; Laravel React legacy surface

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

- The Next.js app is the canonical Customer, Vendor, Admin and Super Admin portal. The Laravel React dashboard is preserved as a legacy/internal staff portal; do not delete it or require it to duplicate every Next.js workflow.
- Seed local demo data: multiple customers, vendors/stores, products of different types, variants, inventory, addresses, orders, reviews, coupons, payment outcomes, refunds and payouts. Use safe demo images.
- API feature tests for role access, ownership isolation, validation, state transitions, idempotency, malformed payloads and simulated provider outcomes.
- Frontend tests for loading/empty/error states, invalid response shapes, retry, auth/login/logout, cart/checkout, vendor workflows and admin workflows.
- Browser tests for customer, vendor and admin scenarios against local services only.
- Verify SQLite and SQL Server compatibility where supported.
- Do not access staging/production or third-party databases during this work.
- Automatic GitHub Actions triggers are manual-dispatch only on the current feature branches. Do not dispatch workflows or claim tests passed unless actually executed locally and recorded.
- Keep work on the existing feature branches/PRs. Do not merge or deploy until implementation is complete, the user authorizes the test phase, and the user separately approves merge/deployment.

## Initial gaps identified in the 2026-11-26 baseline audit

The bullets in this historical baseline were progressively addressed by the implementation entries below; they are not a current declaration that the modules are still absent.

- Payment simulation, refunds, vendor ledger/payouts, coupons, notifications and support-ticket APIs/UI have since been added.
- Admin and Vendor dashboard pages and multiple marketplace-management workspaces have since been added to the Next.js feature branch.
- Remaining work is tracked in the current implementation checklist at the end of this document. Runtime correctness, migration execution and end-to-end completion remain unverified until the planned local test phase.


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

### Historical outstanding list from 2026-11-26 — superseded by later implementation entries

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
- At this point in the 2026-11-26 history, payment simulation, refunds/returns, vendor ledger/payouts, notifications, support tickets, remaining vendor/admin screens and demo fixtures were still outstanding. The implementation entries below supersede those module statuses; the local browser/security test and release gate remains deferred.

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

- Extended the lifecycle demo seed data with a pending mock-card order (pending payment + pending vendor fulfilment) for cancellation/payment-retry scenarios, and a high-priority vendor support ticket so both Customer and Vendor support queues are populated.

- Added a seeded pending customer review on the second customer account so Admin review moderation and Vendor review inboxes have realistic pending content alongside the approved review.

- Audited notification destinations and corrected support replies to open the correct Customer or Vendor support workspace, store approval notifications to open vendor store settings, and vendor review replies to open the reviewed product.

- Demo orders now decrement managed inventory when first seeded, so the pending cancellation scenario can restore stock without inflating inventory; shipped and delivered scenarios also reserve their units.

### Current review branches (not merged)

- Backend implementation: PR #47, current branch head b3db22e544b8f32eda3307e24181b607dee82d80 — open and mergeable.
- Next.js portal implementation: PR #57, current branch head 9b6304420337cf1b2c23588deccd8bbaa5b4bf55 — open; currently not mergeable because the corrective default-branch cleanup PR has not been merged.
- Corrective default-branch cleanup: PR #58, current head d5106dbf788d7154e2b2186dc67137edb896b311 — draft and unmerged. It must be reviewed/merged before PR #57 if the user approves that workflow.
- No PRs have been merged, no GitHub Actions have been run, and no staging or production deployments have been made.

### Implementation progress — returns, payment events and financial accuracy

- Added a separate return-request workflow with item-level quantity reservations, delivered-order eligibility, a 30-day post-delivery window, Admin approve/reject, received and completed states, and Customer/Admin notifications. Returns are distinct from monetary refund processing.
- Added an order-level `delivered_at` timestamp and set it when all vendor fulfilments are delivered; seeded lifecycle orders include delivery timestamps.
- Added an idempotent simulated payment-webhook event ledger, Admin transaction/event history endpoints and a Finance workspace for simulating success/failure events. Event IDs are rechecked after transaction locking to protect concurrent retries.
- Vendor finance now calculates earnings, reservations, paid-out totals and available payout balances independently per currency. Vendor payout requests must specify the currency, preventing cross-currency balance aggregation.
- Added Customer Returns and Admin Returns workspaces, item-specific return links from delivered order details, and a pending-return KPI on the Admin dashboard.
- Restricted support-ticket assignment to Admin and Super Admin accounts; internal support notes remain hidden from requesters.

### Additional implementation progress — returns, reviews and reporting

- Completed the separate Customer/Admin return workspace and API routes. Return requests reserve item quantities, require delivery within the 30-day return window, support Admin approval/rejection and received/completed transitions, and create a linked pending refund request when a return is completed.
- Added order-level delivery timestamps, backfilled existing delivered orders, and set the timestamp when all vendor fulfilments are delivered.
- Added a customer My Reviews workspace and API for listing, editing and deleting owned reviews. New and edited reviews require a delivered purchase and return to moderation.
- Added Admin marketplace reports with date filters, paid sales grouped by currency, daily revenue, order status, top products, processed refunds and simulated payouts.
- Added Vendor analytics with vendor-scoped paid sales, daily revenue, top products and fulfilment status reporting; all financial values remain separated by currency.
- Added Admin payment transaction listing, simulated webhook event application and event history, with duplicate event IDs protected by transaction locking.
- Corrected vendor finance summaries and payout requests to calculate and reserve balances per currency rather than mixing different currencies.

### Implementation progress — customer reviews and notification preferences

- Customer review submission now requires a delivered purchase; customers can list, edit and delete their own reviews, and edited reviews return to moderation.
- Added per-user in-app notification preferences for orders, payments, shipping, refunds, returns, reviews, support, finance, catalog and marketing. Customer, Vendor and Admin notification centres expose the preference controls, and disabled categories are filtered from the in-app feed and unread count.

- Extended the lifecycle demo seeder with a second independent delivered order and pending item-level return request, so return approval/completion can be exercised without colliding with the separate pending refund scenario.

- Refund processing now creates idempotent vendor-ledger reversal entries allocated across order items after discount allocation; affected vendors are notified, and refunded amounts reduce currency-specific available payout balances. Full/partial-refunded orders remain eligible for ledger reconciliation.

- Payment intent creation now locks the customer order and has a database unique index for per-user idempotency keys, preventing concurrent duplicate payment attempts.
- COD orders are marked paid when all vendor fulfilments reach delivered, aligning vendor earnings, customer refunds and revenue reports.

- Refund and payout operations now serialize on the vendor account row. Admin payout processing rechecks the vendor's currency-specific available balance after refunds and other reservations, and blocks a stale approved payout if the balance no longer covers it.
- Refund totals are calculated only after locking the parent order, preventing concurrent partial refunds from overwriting the cumulative order payment status.

- Vendor sale credits now use discounted line-item values, and refund reversals use the original recorded commission where available. This keeps vendor net earnings aligned with coupon discounts and subsequent refunds.

- The Vendor dashboard KPI now groups gross sales by currency instead of labelling a mixed-currency sum as USD.

- Vendor dashboard and analytics revenue figures now reflect proportional coupon discounts and are filtered to paid orders; dashboard sales remain grouped by currency.

- Vendor finance UI now exposes refund adjustments and any outstanding balance due, alongside available funds and payout reservations.

- Return-linked refund ledger reversals are scoped to the returned order item and its owning vendor; general order refunds continue to allocate product-value adjustments proportionally across all vendors.

- Customer payment confirmation and Admin webhook simulation now lock the order before the payment transaction, matching cancellation's lock order and preventing a late simulated success from resurrecting a cancelled order.

### Current implementation closeout — authoritative status

The older "Remaining implementation work" bullets above are historical snapshots and are superseded by this closeout list.

**Implemented in code on the feature branches (not runtime-verified):**
- Customer storefront/account, cart, checkout, payment simulation, order history/details/cancellation, item-level returns, refunds, reviews, support, notifications and notification preferences.
- Vendor store/product/catalog requests, variants/inventory, vendor orders/fulfilment, reviews/replies, support, currency-specific earnings/payouts, refund ledger adjustments, analytics and notifications.
- Admin marketplace dashboards, customer/vendor/store/product/order/catalog/shipping/coupon/review/support/return/refund/payout/finance/payment-event/report/notification workflows.
- Super Admin-only role/permission administration and privileged-account safeguards.
- API role separation, ownership checks, account-active middleware, audit logging, idempotency and transactional financial state changes.
- Demo lifecycle data for shipped/delivered/pending orders, payment attempts, refunds, returns, payouts, reviews, support and notifications.

**Still required before the test phase can be the only remaining work:**
- Finish the final page-by-page API contract and fallback audit across all Next.js customer/vendor/Admin/Super Admin screens, and resolve any remaining mismatches.
- Resolve the overlapping Next.js portal and Laravel React dashboard ownership so there is one clear production website entry point for all roles; do not delete existing screens until their use is confirmed.
- Confirm all customer/vendor/Admin navigation entries point to the intended route and that role guards match the API.
- Review demo seeder idempotency and fixture completeness, then execute migrations and seeders only in the local test environment.
- Confirm local environment configuration for the supported database drivers. Real payment and payout gateways remain intentionally simulated until provider credentials and an approved integration scope exist; shipment tracking currently supports manually managed carrier/tracking data.

**Test phase remains deferred by explicit instruction:** no migration execution, seeder execution, API tests, frontend build/lint, browser tests, CI runs, staging or production verification have been performed in this implementation session.

- Added the Next.js Admin audit activity workspace with actor/role, action, path, resource, HTTP status and date/search filters. Added missing Admin Inventory/Audit and Vendor Inventory/Analytics navigation links.


### Follow-up implementation pass — listing pagination

- Added explicit server-side pagination controls and page metadata to the Admin returns workspace (20 per page), retaining status filtering and adding search across order number, product name and customer email. The Laravel Admin returns endpoint already supplies paginator metadata and now receives the matching UI parameters.
- Replaced the Admin brands listing's oversized single-page fetch with 25-item server-side pages and Previous/Next controls driven by the API's `pagination` metadata.
- Updated the Admin stores query/page to use 25-item server-side pages and actual API pagination metadata instead of inferring a next page from a hard-coded 100-row response.
- Updated Admin and Vendor order listings to request 20 records per page and render Previous/Next controls from the backend pagination metadata. Search/status filters are retained when paging Admin orders.
- Added dedicated paginated query helpers for Admin and Vendor product listings (25 records per page) while retaining the full-list vendor product helper for slug lookup compatibility. Connected the Admin products listing to the new paginated helper and backend metadata.

**Pagination audit — implementation pass closed in source (not runtime-verified):**
- Vendor products: connected the 25-item server-paginated query helper to the page; page links preserve all active product filters.
- Customer orders, returns, refunds and reviews: confirmed order history was already paginated; added 20-item paging to returns, refunds and review history.
- Admin users, returns, catalog requests, review moderation, coupons, finance payouts/refunds, support tickets and payment webhook events: connected 20/25-item server pagination and metadata-driven controls. Admin audit logs already used server pagination.
- Vendor inventory, catalog request history, product reviews, support tickets and payout history: connected server pagination and Previous/Next controls. Vendor orders and product listings are paginated.
- Admin inventory: connected the marketplace-wide inventory table to paginated product results.
- Public store, brand and category index pages now request 24 items per page and render API-backed pagination. Public products already use API pagination; store, brand and category detail pages already paginate their product grids. Collection pages are curated/finite content rather than an unbounded product listing; collection product previews remain deliberately capped.
- Admin category hierarchy is loaded as a complete tree because parent/child relationships must be assembled together; it is not treated as a flat paged table. Address book entries are a per-customer finite set.
- Customer, Vendor and Admin notification histories now use paginated API reads. Empty API results remain empty instead of incorrectly falling back to sample records on public index pages.

**API/source audit:** Relevant Laravel return, review, support, vendor finance, payment-event and audit endpoints expose paginator metadata. No database migrations or seeders were executed.

**Remaining non-pagination implementation decisions:** Portal ownership is resolved: Next.js is canonical and Laravel React is preserved as a legacy/internal staff portal. Real payment/payout gateways and carrier integrations remain intentionally simulated/manual pending credentials and an approved integration scope.

**Test phase remains deferred by explicit instruction:** no migration execution, seeder execution, API tests, frontend build/lint, browser tests, CI runs, staging or production verification have been performed. The listing pagination changes are committed to the existing frontend feature branch; PRs remain open and unmerged.


### Further RBAC and workspace implementation pass — 27 Nov 2026

- The Next.js customer account layout now requires the Customer role, not merely any authenticated session. Vendor, Admin and Super Admin sessions are redirected to their own workspace instead of being allowed to open customer-only account screens and then encountering API authorization failures.
- The shared protected-route component supports an optional required role and routes authenticated users who lack that role to the correct role home. Existing login redirects and loading states remain intact.
- Vendor navigation was decoupled so finance, support and notifications are not accidentally hidden behind the `orders.view` permission. Reviews now follow `reviews.view`; orders and analytics remain gated by their relevant permission.
- Customer notifications now request 20 records for the selected server page and consume API pagination metadata; read/unread actions reload the current page.
- Reviewed the Admin marketplace settings contract: collections and shipping methods are read together through the settings endpoint, with create/update/activate/deactivate actions backed by their dedicated API routes. Category hierarchy is intentionally loaded as a tree rather than paged flat.
- Reconfirmed that Super Admin role/permission routes are isolated behind the Super Admin API middleware, and the Next.js role-management page checks the session role before rendering controls.

**Historical closeout checklist:** the route-family and portal-ownership bullets above were a partial snapshot on 27 Nov and have been superseded by the later implementation entries below, which record the customer commerce boundary, vendor variants/inventory/store/fulfilment, Admin catalog/user/finance/support, public catalogue, settings, pagination and API response-alignment passes. Do not interpret this historical block as a current list of unimplemented modules.

**Test phase remains deferred by explicit instruction:** no builds, lint, automated tests, browser checks, migrations, seeders, CI dispatches, merges or deployments have been run for the latest implementation commits.

- Extended the Customer-role boundary to checkout: authenticated Vendor/Admin/Super Admin users are redirected to their own workspace and do not trigger customer checkout-summary API reads. Guest carts remain preserved while unauthenticated visitors are sent to sign in.


### Public catalogue pagination hardening — 27 Nov 2026

- Replaced collection-wide in-memory slicing for public category, brand and store indexes with database-backed Laravel pagination.
- Category index pagination keeps each root category's active child tree intact, so parent/child navigation is not split across unrelated flat pages.
- Brand active-product counts are loaded with aggregate counts; vendor active-product counts use a correlated aggregate instead of per-vendor count queries.
- Added deterministic secondary ID ordering to prevent duplicate/omitted rows when names or sort positions tie.
- The API continues returning the same `success/message/data/pagination` envelope and honours the existing `page` and `per_page` query contract.
- Source change committed to the existing backend feature branch. Runtime and SQL Server compatibility remain unverified because the user has deferred the test phase.


### Follow-up Customer role isolation and storefront catalogue query pass — 27 Nov 2026

- Restricted the standalone `/orders` route group to Customer accounts, matching the existing Customer-only account layout and Customer-only Laravel order APIs.
- Updated the shared Next.js commerce provider so Vendor, Admin and Super Admin sessions do not call Customer-only cart or wishlist APIs. Their storefront cart/wishlist state remains local; Customer sessions continue using the authenticated Laravel cart/wishlist and guest-item merge flow.
- Confirmed cart, wishlist, address, checkout, order, payment, refund and return endpoint paths are represented in the Laravel v1 route group; the role boundary now also matches those Customer-only commerce endpoints.
- This is a source-level implementation correction only. No build, lint, test, browser, migration, seeder, CI, merge or deployment was run.


### Production portal decision and additional source correction — 27 Nov 2026

- Resolved portal ownership: Next.js is the canonical public website and unified role-aware entry point (`/`, `/administrator`, `/vendor`). Laravel remains the API/business-rule authority. The existing Laravel React dashboard is preserved as an internal/legacy staff portal on the Laravel application origin and is not deleted or silently replaced.
- Updated both repositories' README/architecture documentation to make the domain split explicit: public website domain -> Next.js; `NEXT_PUBLIC_API_URL` -> Laravel API origin; Laravel React dashboard remains separately reachable on its own application origin.
- Corrected the Customer address book so a successful empty Laravel response remains empty. It no longer replaces an empty server address list with demo fixture addresses. Explicit preview fallback remains available through the service's unavailable-API behavior.
- The frontend `/orders` route now has the same Customer role restriction as the account and checkout routes; shared commerce state avoids Customer-only cart/wishlist API calls for other roles.
- Public category, brand and vendor index endpoints now use database-backed pagination, with stable ordering and aggregate product counts.
- These are source/documentation changes only. All runtime checks, builds, migrations, seeders, CI, merges and deployments remain deferred.


### Cross-portal pagination and API contract alignment — 27 Nov 2026

- Added pagination to the Laravel React Admin product, store, order, review, support, coupon, payout, and role/permission listing workspaces; Vendor product, order, review, support, and payout-history lists now expose pagination controls.
- Corrected the Laravel React Admin review moderation page to consume the direct data + pagination response emitted by ReviewManagementController. Vendor review, support, coupon, payout and notification consumers retain the nested ApiResponse envelope where their controllers use ApiResponse::successResponse.
- Role assignment now fetches the full permission option set from the dedicated /permissions/options endpoint while the role and permission management lists use their paginated index endpoints.
- Vendor finance UI now selects among the currencies returned by the finance summary and uses the selected currency for available-balance limits and payout requests.
- Customer wishlist now consumes server-side paginated wishlist data and exposes page navigation. Local guest/non-customer wishlists remain local and are not sent to Customer-only APIs.
- Public collections directory and collection product pages now use server-side pagination. Existing category hierarchy/tree and small account address/cart views remain unpaginated by design; account order, return, refund, review, notification, public product/brand/store/category and vendor/admin order lists use paginated data.
- Confirmed the backend wishlist endpoint already returns data and pagination metadata and the collection products endpoint already returns the same direct pagination contract; added pagination to the public collection directory endpoint.
- No builds, lint, automated tests, browser tests, migrations, seeders, CI runs, merges or deployments were performed.


### Vendor API route and page-level permission alignment — 27 Nov 2026

- Corrected both Next.js and Laravel React Vendor support clients to use the existing vendor-scoped /vendor/support/tickets API family. Customer support continues to use /support/tickets; Admin support continues to use /admin/support/tickets.
- Added server-rendered page guards for Vendor product view/create/edit, inventory, catalog requests, order list/detail, analytics, reviews, and store management routes. Added shared products.view and reviews.view permission constants to match the seeded backend permission names.
- Added the matching orders.view permission middleware to the Vendor analytics API endpoint. Vendor review API already requires reviews.view; Vendor products, inventory, orders, store and catalog request endpoints already enforce their granular permission middleware.
- Both repositories' automated workflows are now manual-dispatch only on these feature branches, preserving the requested pause on automatic CI triggers. No workflow was dispatched and no tests/builds/lint/migrations/seeders/merges/deployments were run.


### Finance ledger and address fallback hardening — 27 Nov 2026

- Added a Vendor-owned paginated earnings ledger API and connected both the Next.js Vendor finance workspace and preserved Laravel React Vendor finance page to its page controls. Payout history and ledger pages now maintain separate pagination state.
- Corrected the Next.js Vendor finance workspace's missing selected-currency balance derivation; the selected currency now drives the available-balance and payout amount validation.
- Tightened address-book fallback behavior: saved sample/local addresses are used only when the API is unavailable, not for authorization or other API errors. Checkout now respects a successful empty server address book instead of reintroducing stale local/demo addresses.
- No tests, builds, lint, migrations, seeders, CI dispatches, merges or deployments were run.


### Catalog and marketplace settings list pagination — 27 Nov 2026

- Catalog attributes and tags now have independent backend pagination parameters and metadata. Both Next.js Admin and the preserved Laravel React Admin dashboard expose separate page controls for each list.
- Marketplace collections and shipping methods now have independent backend pagination parameters and metadata. Both Admin portals expose separate page controls while preserving the active tab and the other list's current page.
- The legacy Admin brand management list now uses 25-record server pagination; the category hierarchy remains a tree response because flattening/paginating it would break parent-child selection and display semantics.
- No tests, builds, lint, migrations, seeders, CI dispatches, merges or deployments were run.


### Server query error handling and settings contract reconciliation — 27 Nov 2026

- Corrected the Next.js marketplace-settings query/page contract to pass independent collection and shipping page numbers and consume both pagination metadata objects. Source inspection caught and corrected an earlier partial signature update before any runtime validation.
- Updated Vendor/Admin/public product and store/brand/category detail queries so only a genuine 404 is treated as a missing record. Authentication, permission, server and network errors now propagate instead of being silently rendered as empty/missing data.
- No builds, lint, tests, migrations, seeders, CI dispatches, merges or deployments were run.


### Collection merchandising query parity — 27 Nov 2026

- Corrected public collection product queries to include both configured category hierarchies and configured featured-store slugs. Collections with only featured stores now return those vendors' active products instead of an empty result; category-based and mixed collections retain both sources.
- No tests, builds, lint, migrations, seeders, CI dispatches, merges or deployments were run.


### Vendor granular action visibility — 27 Nov 2026

- Vendor catalog requests now show the submission form only to users with products.create while retaining read access for products.view.
- Vendor review replies are shown only to users with reviews.update; review viewing remains independently guarded by reviews.view.
- Vendor order fulfilment controls are shown only to users with orders.update, while order details remain available to orders.view users.
- Vendor product detail actions now respect products.update, products.create and products.delete independently; corresponding server actions and API middleware remain the enforcement boundary.
- No tests, builds, lint, migrations, seeders, CI dispatches, merges or deployments were run.

- Vendor product creation/editing and inventory pages now require the corresponding read permission as well as create/update/inventory permissions because their forms also load product and catalog option data. This aligns the page guard with the actual API calls made during page render.


### Server-side Customer route boundary — 27 Nov 2026

- Added a reusable server-side Customer role guard and applied it before rendering account, order-history and checkout route trees. Existing client-side ProtectedRoute and checkout role checks remain as defense in depth; guest cart and wishlist pages remain accessible.
- This prevents non-Customer role sessions from rendering Customer server page trees and relying only on a client redirect or API 403 response.
- No tests, builds, lint, migrations, seeders, CI dispatches, merges or deployments were run.


### Public product review pagination and aggregate integrity — 27 Nov 2026

- Public product review API now returns server-paginated review rows plus product-wide aggregate average, count and rating distribution, so pagination does not distort the displayed rating summary.
- Next.js product detail review section now loads ten reviews per page, exposes Previous/Next controls, retains sorting within the current page, and uses the product-wide aggregate summary. API errors are shown rather than silently presenting an empty review list.
- No tests, builds, lint, migrations, seeders, CI dispatches, merges or deployments were run.

- Corrected the shared product-form options query to route both Admin and Super Admin sessions to the Admin catalog-options API; previously only the literal Admin role selected that route.

- Product listing/detail rating values now derive only from approved review records, matching the paginated review API aggregate. Legacy metadata no longer creates a displayed review count or average without corresponding review rows.


### Current implementation closeout status — 27 Nov 2026

- Source-level implementation pass now covers the previously listed Customer, Vendor, Admin, Super Admin, and public storefront gaps: route/API boundaries, core workflows, granular authorization, pagination, response-envelope alignment, fallback behavior, finance ledger/payouts, catalog settings, support, reviews, returns/refunds and operational notifications.
- Next.js is the canonical role-aware website. The Laravel React dashboard is preserved as a legacy/internal staff portal and has not been removed.
- Payment and payout processing remain explicitly simulated; shipping carrier tracking is manual. Real gateway/carrier providers require credentials and a separately approved integration scope.
- Release verification remains intentionally pending: no builds, lint, tests, browser runs, migrations, seeders, CI dispatches, staging/production checks, merges or deployments have been performed on the latest commits.
- Backend PR #47 was squash-merged into `main` on 27 Nov 2026 (merge commit `5aef8ab961537fc9a7213b2b7440f5a4e01d0dfc`). Frontend PR #57 remains open because GitHub reports merge conflicts; its feature branch is 18 commits behind `main`. Resolve the overlapping account notification/refund/support and Admin/Vendor workspace files on a branch based on current `main` before merging. Do not claim release verification complete until builds, lint, automated tests, browser tests, migrations and seeders are run in an isolated environment.
