# Super Store POS Backend

A Laravel 12 API for the POS sale screen. MySQL is the default database. This version serves the product catalog only: orders, discounts, notes, and the payment preview still live in the Vue demo. It does not process payments or save sales.

## Run locally

Prerequisites: PHP 8.2+ with Laravel's required extensions and PDO MySQL, Composer, and MySQL 8.x. On Windows, copy `.env.example` to `.env` in File Explorer or with `Copy-Item .env.example .env` in PowerShell.

1. Run `composer install`, then copy `.env.example` to `.env`.
2. In `.env`, fill in `DB_DATABASE`, `DB_USERNAME` and `DB_PASSWORD` for your local MySQL. Use a database and user you created for this project. Alternatively set a development password and run `docker compose up -d --wait mysql`. This is an optional local MySQL instance, not a production deployment.
3. From the backend directory, run:

   ```sh
   php artisan key:generate
   php artisan migrate --seed
   php artisan serve --host=127.0.0.1 --port=8000
   ```

4. Open `http://127.0.0.1:8000/api/pos/catalog`. Without a seeder run the arrays will be empty.

The seeders create 4 categories, 6 units, and 16 products. Re-running `php artisan db:seed` updates those sample records by slug, unit code, and SKU rather than duplicating them; it also restores original sample prices and flags. Do not run the demo seeder against a real inventory.

## Connect Vue

Start the paired Vue frontend on `http://127.0.0.1:4173` or `http://localhost:4173`. The default local API URL is `http://127.0.0.1:8000`. To change the frontend origin, edit comma-separated `POS_FRONTEND_ORIGINS` in the backend `.env`. To change the frontend's API URL, set its `POS_API_BASE_URL` before building it. For a same-origin deployment, serve the compiled frontend and route `/api/pos/*` to Laravel. For separate hosts, use an HTTPS API and allow only the intended frontend's origin.

CORS controls browser cross-origin requests; it is not authentication. The read-only catalog route is intended for local demo data. Put production data and future write endpoints behind appropriate authentication.

## API

`GET /api/pos/catalog` returns a currency code (`PKR`), ordered category and unit records, and active products. Product records contain a SKU, category slug, unit code, artwork metadata, and integer `price_minor` (paisa). A unit's `quantity_precision` controls whether the frontend accepts decimals (e.g. 3 for kg and 0 for pieces).

## Tests

`php artisan test` defaults to an in-memory SQLite test database for convenience. To test on MySQL, export `DB_CONNECTION=mysql` and `DB_DATABASE=super_store_test` (plus the test connection's host, port, username, and password) before running it. Create a **dedicated empty test database** first: the tests run migrations and may drop tables in the test database.
