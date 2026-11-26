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
