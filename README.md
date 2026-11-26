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
- These endpoints are for the single-store MVP. Till reconciliation, returns/refunds, reports, and separate manager-only permissions are not in this release.

## Daily Sales Report

`GET /api/pos/reports/sales` is authenticated. Use `period=today` (default), `period=yesterday`, or `period=custom&from=YYYY-MM-DD&to=YYYY-MM-DD` (inclusive, up to 366 days), optionally with `cashier_id`, `page` and `per_page`. Dates use Pakistan time; sales are matched by finalization time and assigned to the finalizing cashier, not the draft creator.

Totals cover the full selection, not just the current detail page. Gross uses the stored line quantities and pre-discount unit prices; discounts are gross less finalized order totals. Amounts are strings in PKR minor units to preserve integer precision. The payment breakdown retains historical payment methods. Drafts are excluded. Refunds and net sales after refunds are returned as `null` because this release does not record refunds. The report does not require a new migration.
