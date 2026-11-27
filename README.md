# Super Store POS Backend

Laravel 12 and MySQL for the POS catalog and management pages. Accounts are created from the command line, not by public sign-up. A separate opt-in seeder provides a local demo account. Authenticated users can use the register and all four management sections in this MVP; there is not yet a separate cashier role. Orders can be saved to MySQL and viewed in Orders. Saving a draft does not change inventory. Finalizing a sale records cash tender and deducts stock in the same transaction; the app does not charge customers. Finalized sales cannot be edited.

## Setup or upgrade

Requirements: PHP 8.2+ with PDO MySQL and Laravel's standard extensions, Composer, and MySQL 8.x.

1. Run `composer install`. For a new setup, copy `.env.example` to `.env` and fill in the MySQL settings. On Windows: `Copy-Item .env.example .env`. If you want a local MySQL container, set a development password and run `docker compose up -d --wait mysql`.
2. For a new setup only, run `php artisan key:generate`. Do not rotate an existing app key to apply this update.
3. Run `php artisan migrate`. This preserves existing data and backfills unique random four-digit internal codes. On an empty demo database you can additionally run `php artisan db:seed` for the sample catalog. Do not run demo seeds against real inventory.
4. Create an account using `php artisan pos:create-user you@example.com` and enter the display name and password when prompted.
5. Run `php artisan serve --host=127.0.0.1 --port=8000`. In the matching frontend folder run `npm ci` and `npm run dev`. Open `http://127.0.0.1:4173` and sign in.

### Optional local demo login

On a local demo database only, set `APP_ENV=local` and `POS_DEMO_PASSWORD` (at least eight characters) in your private `.env`. Run `php artisan config:clear`, `php artisan migrate`, then `php artisan db:seed --class=PosDemoUserSeeder`. Log in with `admin@demo.com` and the password you set. The seeder skips non-local environments, is not part of the default `db:seed`, and does not reset an existing account's password. Do not deploy a database containing this demo account.

A four-digit string allows `0000` through `9999`, including leading zeros. The code is unique and editable, and is generated when omitted on creation. Barcodes are separate, optional, unique strings. The original SKU remains stable. Fresh demo products receive `DEMO-0001` through `DEMO-0016` for manual lookup tests; replace those with the actual printed barcodes before scanning. Reseeding will not overwrite edited product codes or other product changes.

Products can be archived and restored via **Available in POS**. Categories, units, and suppliers can be deleted only when no product references them, including archived products. This protects existing references; reassign the product before deleting its old category, unit, or supplier. Supplier details are not exposed by the cashier catalog endpoint.

**Product prices:** `retail_price_minor` is the regular shelf price and `price_minor` is the default POS sale price. Both are integer paisa. The price migration copies existing sale prices into the new retail field without changing sales or order snapshots. Older API clients can still send just `price_minor`: the server preserves an existing retail price or initializes it from the sale price for a new item.

**Product images:** Optional JPG, PNG or WebP, up to 2 MB and 4096 × 4096 px. Upload as multipart `image` with the normal product create request; for an edit with a file, send multipart POST with `_method=PUT`. To remove the image, send `remove_image: true` with the normal edit fields. Files live under `storage/app/private/pos-product-images`; Laravel must be able to write there. Back up this folder along with MySQL. Images are served through the authenticated POS API; no `storage:link` is needed. PHP must have Fileinfo enabled, with `upload_max_filesize` at least 2 MB and `post_max_size` larger than that.

## API and deployment

- `GET /api/pos/auth/session` returns the current user (or null) and a CSRF token.
- `POST /api/pos/auth/login` and `POST /api/pos/auth/logout` require that token in `X-CSRF-TOKEN` and cookies.
- `GET /api/pos/management/{resource}` lists records. It and `GET /api/pos/orders` accept `page`, `per_page` (1–100, default 15), and `q`. They return `data` and `meta` with the current/last page, row range and total. `GET /api/pos/management/options` supplies lightweight product form lookups.
- The catalog, management and orders endpoints require an authenticated session. JSON write requests also require the CSRF token.
- `POST /api/pos/orders` saves the register order. `GET /api/pos/orders` lists orders with server-side pagination and search, and `GET /api/pos/orders/{id}` shows the saved line details and an edit revision. `PUT /api/pos/orders/{id}` updates that same order if the revision still matches. It does not change the original cashier or create a second order.
- The dev server proxies `/api/pos/*` to Laravel. For deployment use HTTPS, serve Vue and proxy POS API requests on the same origin, and set `SESSION_SECURE_COOKIE=true`. Keep the Laravel `.env` private. Treat this as a single-store MVP until separate cashier/manager roles and account recovery are in place.

Run `php artisan test` for the automated feature tests (in-memory SQLite by default). For MySQL tests, prepare a separate empty database ending in `_test`, and run the suite with `DB_CONNECTION=mysql`, `DB_DATABASE=super_store_test` and your test credentials. The test suite refuses to run migrations on a MySQL database without this suffix.


## Inventory and finalized checkout

Run `php artisan migrate` before using the matching frontend. The checkout migration preserves existing orders and stock. Older cash receipts have no recorded cash tender or change. For a fresh installation, enter real opening quantities before finalizing sales; demo seeders do not add stock balances.

- `GET /api/pos/inventory` lists active and archived products and balances, with normal paging/search, optional `status=active|archived`, and `low_stock=1`.
- `POST /api/pos/inventory/{product}/movements` takes a UUID `request_id`, movement type, quantity and reason; supplier receipts also require a supplier. Stock history is paginated at the same path with GET.
- `PUT /api/pos/inventory/{product}/threshold` sets a per-product low-stock quantity.
- `POST /api/pos/checkout` finalizes a new cart using its draft fields and `payment_method`. `POST /api/pos/orders/{id}/checkout` also requires the draft edit revision and a UUID `checkout_id`. New sales require `payment_method: cash` and integer `cash_received_minor` in paisa, at least the server-calculated order total. Older non-cash receipts remain readable; only exact replays of an already-finalized non-cash checkout are supported.
- `GET /api/pos/orders/{id}` has the saved line, payment, finalization, cashier, and tender details used by the receipt. `change_minor` is computed from recorded tender and the immutable finalized total.
- Replay the exact submission on a timeout, with the same `request_id`, `checkout_id`, or draft PUT `update_id`. Replaying a successful checkout does not deduct stock again. Reusing a key with different contents returns a conflict; check Orders before creating a new sale.
- These endpoints are for the single-store MVP. Separate manager permissions are not enabled. Only trusted staff should have accounts.

## Daily Sales Report

`GET /api/pos/reports/sales` is authenticated. Use `period=today` (default), `period=yesterday`, or `period=custom&from=YYYY-MM-DD&to=YYYY-MM-DD` (inclusive, up to 366 days), optionally with `cashier_id`, `page` and `per_page`. Dates use Pakistan time; sales are matched by finalization time and assigned to the finalizing cashier, not the draft creator.

Totals cover the full selection, not just the current detail page. Gross uses the stored line quantities and pre-discount unit prices; discounts are gross less finalized order totals. Amounts are strings in PKR minor units to preserve integer precision. The payment breakdown retains historical payment methods. Drafts are excluded. Posted refunds are assigned to the Pakistan date and the cashier who posted them, even for a sale from an earlier day or a different cashier. Net sales are finalized sales after discounts minus posted refunds. With `kind=refunds`, the detail pages show refund receipts; the summary still covers both event types. A refund-only date can have negative net sales.


## Cash refunds and drawer reconciliation

Run `php artisan migrate` for migration `2026_11_27_000008`, then use the matching frontend. The migration is additive. Do not reseed or run `migrate:fresh` on a live store. Existing sales and inventory are preserved.

- A finalized cash sale can have several partial refunds, up to its original sold quantities. Archived, missing, or incompatible stock may be refunded without a restock. Refund rounding allocates the original paid line total across returns; the original sale is unchanged. Historical card, bank transfer, and unrecorded payments are not converted to cash refunds.
- Use GET `/api/pos/orders/{id}/return-options` for remaining quantities and allowed increments, POST `/api/pos/orders/{id}/refund-preview` for the amount and its quote, and POST `/api/pos/orders/{id}/refunds` to submit the same lines with the quote, a reason, and a UUID `request_id`. Each line has its original zero-based `line`, integer `qty_milli`, and boolean `restock`. GET on that order's refunds lists the receipts; `/api/pos/refunds/{id}` reads one.
- The drawer is a single shared register. Tracking starts when the first shift is opened; earlier sales/refunds stay in reports and are not backfilled into a drawer. After activation, new finalized cash sales and refunds must carry the active `cash_session_id`. A parked order joins the shift where it is finalized. A shift can span dates and staff logins.
- Under `/api/pos/cash-drawer`, GET status and `/history` show current/closed balances, POST `/open` creates a shift, GET/POST `/{session}/movements` list/record manual cash in or out, and POST `/{session}/close` takes counted cash and the latest displayed `revision`. Write requests use UUID `request_id` and amounts in paisa. Cash sales contribute their final total, not tender. The close saves expected cash, counted cash and variance. A stale close requires recounting against the refreshed balance.
- For uncertain writes, retry the **exact** request with the original ID. Confirm the receipt and check whether physical cash already changed hands before paying out again. The application records cash; it does not move money.

### Daily order references

Migration `2026_11_27_000009` adds the counter. A new order receives its number on its first successful Park, draft save or direct checkout. All cashiers share the sequence for the Pakistan calendar date, starting with `#0001`. Reopening, checkout of an older draft, and retries keep the original number and business date. Existing numbered receipts from before this migration keep their old ID-based labels. API routes and foreign keys still use the permanent order ID, not the displayed number.

Orders can be searched by number across dates, or by date and number together, for example `2026-11-27 #0001`. The sales report still groups sales by finalization date. The order business date displayed alongside the reference is the date it was first saved.

## Purchase Receiving

Run the additive `2026_11_27_000010` migration with `php artisan migrate`. Existing product supplier links, receipts, and stock movements are unchanged. Do not reseed an existing store.

- A product's existing optional `supplier_id` is its **preferred supplier**, not an exclusive relationship. Product listings include its supplier name and accept `supplier_id` as a filter. Supplier listings include a count of linked products (including archived products).
- `POST /api/pos/purchases` posts a supplier delivery. It accepts a UUID `request_id`, a `supplier_id`, optional `invoice_reference` and `note`, and 1–50 distinct product lines. Each line specifies `product_id`, the expected `unit_id`, positive `quantity_milli` (thousandths of the stock unit), and nonnegative `unit_cost_minor` (PKR paisa per stock unit). Quantities must match unit precision. Each line cost is rounded half up to the nearest paisa before totaling.
- Posting adds all stock and corresponding inventory ledger entries in one transaction. A rejected receipt changes nothing. Retry an uncertain post with the original request ID and identical values; the same clerk gets the original receipt without adding stock again. Posted receipts are immutable and capture supplier, product, unit and staff at posting time.
- `GET /api/pos/purchases` accepts `supplier_id`, `q` (receipt number, supplier name, or invoice), `page` and `per_page`. `GET /api/pos/purchases/{id}` returns saved lines and costs. `GET /api/pos/purchases/products` feeds the active product picker. Inventory history links new stock movements to the purchase receipt. The original single-product receiving API and its historical movements remain available.
- Receiving records purchase costs and stock only. It does not change POS prices, update a cash drawer, pay a supplier, or calculate cost of goods sold.

## Customers

Run `php artisan migrate` to add the optional customer directory and link. Authenticated `/api/pos/management/customers` supports creation, updates, server paging, search by name/email/phone, and archiving. Name is required to create a customer; email and phone are optional. Archived customers are hidden from new selections. No customer is required for a sale.

Order writes accept an optional `customer_id`. When a customer is selected, the server saves their name on the order so historical receipts do not change when the directory is edited or archived. Drafts can keep, change, or clear the selection. Existing order names and retry references remain valid. No customer email or phone is printed.

## Store settings

Run `php artisan migrate` for the store profile and receipt branding snapshots. Authenticated `GET /api/pos/settings` returns the current profile, revision, fixed currency, and fixed timezone. `PUT /api/pos/settings` requires the revision, store name, and the optional fields `address`, `phone`, and `receipt_footer` (send null to clear). Stale edits conflict rather than overwriting another session. Repeating identical values leaves the revision unchanged.

New finalized sales and posted refunds carry the store details that were in effect when issued. Reprints use that saved snapshot. Pre-migration receipts retain the original “Super Store” presentation. PKR and Pakistan time remain fixed; settings do not alter transaction totals or business dates.

Store Settings also returns `receipt_width_mm` (58 or 80; default 80). PUT accepts this optional field; callers that omit it keep the saved width. The width applies to current printing and is separate from the immutable brand details on issued receipts.
