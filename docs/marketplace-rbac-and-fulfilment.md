# Marketplace access control and vendor fulfilment

## Access boundaries

- Administrators use the administrator workspace and are protected by the Admin role.
- Vendors use the vendor workspace and are protected by the Vendor role plus endpoint-specific Spatie permissions.
- Customers can only read their own customer orders and manage their own addresses.
- Administrators can enable or disable user accounts; disabling revokes existing API tokens and blocks future login. The last active administrator cannot be disabled or demoted.
- Vendor product queries and mutations are scoped to the authenticated user's vendor_id; foreign product IDs return not found.
- Vendor order projections include only line items assigned to the authenticated vendor. They do not expose other vendors' line items or the parent order's marketplace total.

## Vendor fulfilment model

A customer order can contain products from several stores. The parent order remains the customer-facing order, while vendor_order_fulfillments stores one independent fulfilment per order_id and vendor_id. vendor_order_fulfillment_items assigns each order line to exactly one fulfilment.

Vendor transitions are sequential:
1. pending → processing
2. processing → ready_to_ship
3. ready_to_ship → shipped (carrier and tracking number required)
4. shipped → delivered (administrator controlled)

A vendor can update only the fulfilment row matching their own user ID and the requested parent order. A vendor cannot cancel, refund, or mark another store's fulfilment delivered. The parent order becomes shipped only when all vendor fulfilments are shipped or delivered, and delivered only when all are delivered.

Existing orders are backfilled by the migration. Demo order seeding also creates fulfilment rows and line-item assignments.

## Implemented API surfaces

- Vendor: list/detail own orders, update own fulfilment and tracking.
- Administrator: list/detail all customer orders, inspect every vendor fulfilment, update individual fulfilment states.
- Customer: existing list/detail endpoints remain filtered by authenticated customer ID.

## Validation still required before merge

- Run Laravel feature tests on SQLite and SQL Server, including migration rollback/re-run.
- Run the local full-stack browser suite for customer, vendor A, vendor B and administrator sessions.
- Verify cross-vendor IDOR attempts, permission-denied direct API requests, logout/token revocation, checkout idempotency and fallback behavior.
- Do not deploy to staging or production until the full local validation matrix is green.

## Further marketplace capabilities to audit separately

Payment-provider transactions, refunds/returns/disputes, vendor balances and payouts, coupons/promotions, tax rules, customer account moderation, audit trails and financial reconciliation require dedicated data models and workflows. They must not be represented as complete by the current COD/order/fulfilment implementation.