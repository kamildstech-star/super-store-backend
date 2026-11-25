# Super Store POS Backend

Laravel 12 and MySQL for the POS catalog and management pages. Accounts are created from the command line, not by public sign-up. A separate opt-in seeder provides a local demo account. Authenticated users can use the register and all four management sections in this MVP; there is not yet a separate cashier role. Cart and payment remain previews and do not save sales.

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

## API and deployment

- `GET /api/pos/auth/session` returns the current user (or null) and a CSRF token.
- `POST /api/pos/auth/login` and `POST /api/pos/auth/logout` require that token in `X-CSRF-TOKEN` and cookies.
- The catalog and management endpoints require an authenticated session. JSON write requests also require the CSRF token.
- The dev server proxies `/api/pos/*` to Laravel. For deployment use HTTPS, serve Vue and proxy POS API requests on the same origin, and set `SESSION_SECURE_COOKIE=true`. Keep the Laravel `.env` private. Treat this as a single-store MVP until separate cashier/manager roles and account recovery are in place.

Run `php artisan test` for the automated feature tests (in-memory SQLite by default). For MySQL tests, prepare a separate empty database ending in `_test`, and run the suite with `DB_CONNECTION=mysql`, `DB_DATABASE=super_store_test` and your test credentials. The test suite refuses to run migrations on a MySQL database without this suffix.
