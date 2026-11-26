<?php

namespace Tests\Feature;

use App\Models\PosProduct;
use App\Models\User;
use Database\Seeders\PosDemoUserSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PosManagementTest extends TestCase
{
    use RefreshDatabase {
        refreshDatabase as private runTestMigrations;
    }

    public function refreshDatabase(): void
    {
        $connection = config('database.default');
        $database = $this->app['db']->connection()->getDatabaseName();
        if (!($connection === 'sqlite' && $database === ':memory:')
            && !($connection === 'mysql' && str_ends_with($database, '_test'))) {
            throw new \RuntimeException('Use in-memory SQLite or a dedicated MySQL database ending in _test.');
        }
        $this->runTestMigrations();
    }

    private function manager(): User
    {
        return User::create(['name' => 'POS Manager', 'email' => 'manager@example.test', 'password' => 'test-only-password']);
    }

    public function test_login_gates_catalog_and_management_and_logout_clears_session(): void
    {
        $this->manager();
        $this->getJson('/api/pos/auth/session')->assertOk()->assertJsonPath('user', null)->assertJsonStructure(['csrf_token']);
        $this->getJson('/api/pos/catalog')->assertUnauthorized();
        $this->get('/api/pos/catalog')->assertUnauthorized();
        $this->postJson('/api/pos/management/suppliers', ['name' => 'Blocked'])->assertUnauthorized();
        $this->postJson('/api/pos/auth/login', ['email' => 'manager@example.test', 'password' => 'wrong'])->assertUnprocessable();
        $login = $this->postJson('/api/pos/auth/login', ['email' => 'MANAGER@example.test', 'password' => 'test-only-password'])
            ->assertOk()->assertJsonPath('user.name', 'POS Manager')->assertJsonStructure(['csrf_token', 'cart_scope']);
        $scope = $login->json('cart_scope');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $scope);
        $this->assertNotSame($login->json('csrf_token'), $scope);
        $this->getJson('/api/pos/management/products')->assertOk();
        $this->getJson('/api/pos/auth/session')->assertJsonPath('user.email', 'manager@example.test')->assertJsonPath('cart_scope', $scope);
        $this->postJson('/api/pos/auth/logout')->assertOk()->assertJsonPath('user', null)->assertJsonPath('cart_scope', null);
        $this->getJson('/api/pos/catalog')->assertUnauthorized();
        $this->postJson('/register', [])->assertNotFound();
        $loginAgain = $this->postJson('/api/pos/auth/login', ['email' => 'manager@example.test', 'password' => 'test-only-password'])->assertOk();
        $this->assertNotSame($scope, $loginAgain->json('cart_scope'));
    }

    public function test_demo_account_is_opt_in_local_only_and_keeps_an_existing_password(): void
    {
        $demoPassword = \Illuminate\Support\Str::random(24);
        config(['pos.demo_password' => $demoPassword]);
        $this->app->instance('env', 'production');
        $this->artisan('db:seed', ['--class' => PosDemoUserSeeder::class, '--force' => true])->assertSuccessful();
        $this->assertDatabaseMissing('users', ['email' => 'admin@demo.com']);

        $this->app->instance('env', 'local');
        config(['pos.demo_password' => null]);
        $this->seed(PosDemoUserSeeder::class);
        $this->assertDatabaseMissing('users', ['email' => 'admin@demo.com']);

        config(['pos.demo_password' => $demoPassword]);
        $this->seed();
        $this->assertDatabaseMissing('users', ['email' => 'admin@demo.com']);
        $this->seed(PosDemoUserSeeder::class);
        $this->seed(PosDemoUserSeeder::class);
        $this->assertDatabaseCount('users', 1);

        $this->app->instance('env', 'testing');
        $this->postJson('/api/pos/auth/login', ['email' => 'admin@demo.com', 'password' => $demoPassword])
            ->assertOk()->assertJsonPath('user.email', 'admin@demo.com');

        $this->app->instance('env', 'local');
        $user = User::where('email', 'admin@demo.com')->firstOrFail();
        $user->update(['password' => 'changed-demo-password']);
        $this->seed(PosDemoUserSeeder::class);
        $this->assertTrue(Hash::check('changed-demo-password', $user->refresh()->password));
    }

    public function test_register_orders_are_saved_listed_and_protected(): void
    {
        $this->seed();
        $this->getJson('/api/pos/orders')->assertUnauthorized();
        $this->postJson('/api/pos/orders', [])->assertUnauthorized();
        $this->getJson('/api/pos/orders/1')->assertUnauthorized();

        $this->actingAs($this->manager());
        $this->getJson('/api/pos/orders')->assertJsonPath('data', [])->assertJsonPath('meta.total', 0);
        $body = [
            'request_id' => (string) \Illuminate\Support\Str::uuid(),
            'customer' => 'Walk-in Customer',
            'note' => 'Pack separately',
            'lines' => [['sku' => 'MIN-01', 'qty' => 2, 'price_minor' => 10001, 'discount' => 12.5]],
        ];
        $bad = $body;
        $bad['lines'][0]['qty'] = 0.5;
        $this->postJson('/api/pos/orders', $bad)->assertJsonValidationErrors('lines.0.qty');
        $bad['lines'][0]['sku'] = 'MISSING';
        $this->postJson('/api/pos/orders', $bad)->assertJsonValidationErrors('lines.0.sku');

        $id = $this->postJson('/api/pos/orders', $body)->assertCreated()
            ->assertJsonPath('total_minor', 17502)->assertJsonPath('line_count', 1)->json('id');
        $this->postJson('/api/pos/orders', $body)->assertOk()->assertJsonPath('id', $id);
        $this->assertDatabaseCount('pos_orders', 1);
        $this->getJson('/api/pos/orders')->assertOk()->assertJsonPath('data.0.id', $id)
            ->assertJsonMissingPath('data.0.lines');
        $this->getJson('/api/pos/orders/'.$id)->assertOk()->assertJsonPath('note', 'Pack separately')
            ->assertJsonPath('lines.0.sku', 'MIN-01')->assertJsonPath('cashier', 'POS Manager');
        $this->getJson('/api/pos/orders?page=2')->assertJsonCount(0, 'data');

        $body['request_id'] = (string) \Illuminate\Support\Str::uuid();
        $body['lines'][0] = ['sku' => 'MIN-01', 'qty' => 9999, 'price_minor' => 4294967295, 'discount' => 0];
        $this->postJson('/api/pos/orders', $body)->assertCreated()->assertJsonPath('total_minor', 42945377982705);
    }

    public function test_saved_order_edits_replace_items_without_creating_an_order(): void
    {
        $this->seed();
        $this->putJson('/api/pos/orders/1', [])->assertUnauthorized();
        $this->actingAs($this->manager());

        $line = ['sku' => 'MIN-01', 'qty' => 2, 'price_minor' => 10000, 'discount' => 0];
        $removed = ['sku' => 'CHP-05', 'qty' => 1, 'price_minor' => 2500, 'discount' => 0];
        $order = $this->postJson('/api/pos/orders', [
            'request_id' => (string) \Illuminate\Support\Str::uuid(),
            'note' => 'Original note',
            'lines' => [$line, $removed],
        ])->assertCreated()->json();
        $path = '/api/pos/orders/'.$order['id'];
        $revision = $this->getJson($path)->assertOk()->assertJsonPath('revision', $order['revision'])->json('revision');

        // The archived item can remain as recorded, but cannot be modified or added anew.
        PosProduct::where('sku', 'MIN-01')->update(['is_active' => false]);
        $new = ['sku' => 'APL-13', 'qty' => 0.5, 'price_minor' => 20001, 'discount' => 0];
        $payload = ['revision' => $revision, 'note' => 'Updated note', 'lines' => [$line, $new]];
        $bad = $payload;
        $bad['lines'][0]['qty'] = 3;
        $this->putJson($path, $bad)->assertJsonValidationErrors('lines.0.sku');
        $this->putJson($path, ['revision' => $revision, 'lines' => []])->assertJsonValidationErrors('lines');

        $this->putJson($path, $payload)->assertOk()->assertJsonPath('id', $order['id'])
            ->assertJsonPath('cashier', 'POS Manager')->assertJsonPath('created_at', $order['created_at'])
            ->assertJsonPath('lines.0.sku', 'MIN-01')->assertJsonPath('lines.1.sku', 'APL-13')
            ->assertJsonPath('total_minor', 30001);
        $this->assertDatabaseCount('pos_orders', 1);
        $this->putJson($path, $payload)->assertConflict();
        $this->getJson($path)->assertJsonPath('note', 'Updated note')->assertJsonCount(2, 'lines');
        $this->getJson('/api/pos/orders')->assertJsonPath('data.0.total_minor', 30001);
    }

    public function test_listings_paginate_and_search_before_slicing(): void
    {
        $this->seed();
        $this->getJson('/api/pos/management/products?page=2')->assertUnauthorized();
        $this->actingAs($this->manager());
        $prefix = '/api/pos/management/';

        $this->postJson($prefix.'suppliers', ['name' => 'A Supplier', 'email' => 'a@example.test'])->assertCreated();
        $this->postJson($prefix.'suppliers', ['name' => 'Z Supplier', 'email' => 'unique@example.test'])->assertCreated();
        foreach (['products', 'categories', 'units', 'suppliers'] as $resource) {
            $first = $this->getJson($prefix.$resource.'?page=1&per_page=1')->assertOk()
                ->assertJsonCount(1, 'data')->assertJsonPath('meta.page', 1)->assertJsonPath('meta.from', 1)
                ->assertJsonPath('meta.to', 1)->json('data.0.id');
            $second = $this->getJson($prefix.$resource.'?page=2&per_page=1')->assertOk()
                ->assertJsonCount(1, 'data')->assertJsonPath('meta.page', 2)->assertJsonPath('meta.from', 2)->json('data.0.id');
            $this->assertNotSame($first, $second);
        }
        $this->getJson($prefix.'suppliers?q=UNIQUE&per_page=1')->assertJsonPath('data.0.name', 'Z Supplier')
            ->assertJsonPath('meta.total', 1);
        $this->getJson($prefix.'products?q=DEMO-0016&per_page=1')->assertJsonPath('data.0.barcode', 'DEMO-0016')
            ->assertJsonPath('meta.total', 1);
        $this->getJson($prefix.'products?q=%25')->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0);
        $this->getJson($prefix.'units?per_page=101')->assertJsonValidationErrors('per_page');
        $this->getJson($prefix.'options')->assertJsonCount(2, 'suppliers')->assertJsonMissingPath('products');
        foreach (['First customer', 'Second customer'] as $customer) {
            $this->postJson('/api/pos/orders', [
                'request_id' => (string) \Illuminate\Support\Str::uuid(), 'customer' => $customer,
                'lines' => [['sku' => 'MIN-01', 'qty' => 1, 'price_minor' => 10000, 'discount' => 0]],
            ])->assertCreated();
        }
        $this->getJson('/api/pos/orders?per_page=1')->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.customer', 'Second customer');
        $id = $this->getJson('/api/pos/orders?page=2&per_page=1')->assertJsonPath('meta.page', 2)
            ->assertJsonPath('data.0.customer', 'First customer')->json('data.0.id');
        $this->getJson('/api/pos/orders?q=FIRST&per_page=1')->assertJsonPath('data.0.id', $id);
        $this->getJson('/api/pos/orders?q='.urlencode('#'.str_pad((string) $id, 3, '0', STR_PAD_LEFT)))
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $id);
    }

    public function test_stock_audit_tracks_receipts_adjustments_damage_and_low_stock(): void
    {
        $this->seed();
        $product = PosProduct::where('sku', 'APL-13')->firstOrFail();
        $base = '/api/pos/inventory/'.$product->id;
        $this->getJson('/api/pos/inventory')->assertUnauthorized();
        $this->postJson($base.'/movements', [])->assertUnauthorized();
        $this->actingAs($this->manager());
        $supplier = $this->postJson('/api/pos/management/suppliers', ['name' => 'Produce Vendor'])->assertCreated()->json('id');
        $move = fn (array $body) => $this->postJson($base.'/movements', $body + [
            'request_id' => (string) \Illuminate\Support\Str::uuid(), 'reason' => 'Count sheet',
        ]);
        $opening = ['request_id' => (string) \Illuminate\Support\Str::uuid(), 'type' => 'opening', 'quantity' => 2.5];
        $move($opening)->assertCreated()->assertJsonPath('balance_milli', 2500);
        $move($opening)->assertOk()->assertJsonPath('balance_milli', 2500);
        $move(['type' => 'opening', 'quantity' => 1])->assertJsonValidationErrors('type');
        $move(['type' => 'receipt', 'quantity' => 1])->assertJsonValidationErrors('supplier_id');
        $move(['type' => 'receipt', 'quantity' => 1.25, 'supplier_id' => $supplier])
            ->assertCreated()->assertJsonPath('supplier_name', 'Produce Vendor')->assertJsonPath('balance_milli', 3750);
        $move(['type' => 'adjustment', 'quantity' => -0.5])->assertCreated()->assertJsonPath('balance_milli', 3250);
        $move(['type' => 'damage', 'quantity' => 0.25])->assertCreated()->assertJsonPath('balance_milli', 3000);
        $move(['type' => 'damage', 'quantity' => 4])->assertJsonValidationErrors('quantity');
        $this->putJson($base.'/threshold', ['quantity' => 3])->assertOk()->assertJsonPath('low_stock_milli', 3000);
        $this->getJson('/api/pos/inventory?low_stock=1&q=APL-13&per_page=1')->assertJsonPath('data.0.stock_milli', 3000);
        $this->getJson($base.'/movements?per_page=2')->assertJsonPath('meta.total', 4)
            ->assertJsonPath('data.0.actor', 'POS Manager')->assertJsonPath('data.0.type', 'damage');
        $this->deleteJson('/api/pos/management/suppliers/'.$supplier)->assertConflict();
    }

    public function test_archived_inventory_is_visible_and_stock_can_only_be_reduced(): void
    {
        $this->seed();
        $this->actingAs($this->manager());
        $product = PosProduct::where('sku', 'MIN-01')->firstOrFail();
        $base = '/api/pos/inventory/'.$product->id;
        $move = fn (array $body) => $this->postJson($base.'/movements', $body + [
            'request_id' => (string) \Illuminate\Support\Str::uuid(), 'reason' => 'Stock reconciliation',
        ]);
        $move(['type' => 'opening', 'quantity' => 3])->assertCreated();
        $this->deleteJson('/api/pos/management/products/'.$product->id)->assertNoContent();

        $this->getJson('/api/pos/inventory?q=MIN-01&per_page=1')
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.stock_milli', 3000)->assertJsonPath('data.0.is_active', false);
        $this->getJson('/api/pos/inventory?q=MIN-01&status=active')->assertJsonPath('meta.total', 0);
        $this->getJson('/api/pos/inventory?q=MIN-01&status=archived')->assertJsonPath('meta.total', 1);
        $this->getJson('/api/pos/inventory?status=unknown')->assertJsonValidationErrors('status');
        $this->assertNotContains('MIN-01', array_column($this->getJson('/api/pos/catalog')->assertOk()->json('products'), 'sku'));

        $move(['type' => 'adjustment', 'quantity' => 1])->assertJsonValidationErrors('type');
        $move(['type' => 'damage', 'quantity' => 4])->assertJsonValidationErrors('quantity');
        $move(['type' => 'adjustment', 'quantity' => -2])->assertCreated()->assertJsonPath('balance_milli', 1000);
        $move(['type' => 'damage', 'quantity' => 1])->assertCreated()->assertJsonPath('balance_milli', 0);
        $this->getJson('/api/pos/inventory?q=MIN-01&status=archived&low_stock=1')->assertJsonPath('data.0.stock_milli', 0);
        $this->getJson($base.'/movements')->assertJsonPath('meta.total', 3);
    }

    public function test_cash_tender_and_retry_payloads_are_checked_against_the_original_submission(): void
    {
        $this->seed();
        $this->actingAs($this->manager());
        $sku = 'APL-13';
        PosProduct::where('sku', $sku)->update(['stock_milli' => 5000]);
        $uuid = fn () => (string) \Illuminate\Support\Str::uuid();
        $cart = ['request_id' => $uuid(), 'lines' => [['sku' => $sku, 'qty' => 1.5, 'price_minor' => 19999, 'discount' => 50]]];
        $cash = $cart + ['payment_method' => 'cash'];
        $this->postJson('/api/pos/checkout', $cash)->assertJsonValidationErrors('cash_received_minor');
        $this->postJson('/api/pos/checkout', $cash + ['cash_received_minor' => 14998])->assertJsonValidationErrors('cash_received_minor');
        $this->postJson('/api/pos/checkout', $cart + ['payment_method' => 'card', 'cash_received_minor' => 20000])->assertJsonValidationErrors('cash_received_minor');
        $this->assertDatabaseCount('pos_orders', 0);
        $this->assertDatabaseCount('pos_stock_movements', 0);

        $cash += ['cash_received_minor' => 20000];
        $id = $this->postJson('/api/pos/checkout', $cash)->assertCreated()
            ->assertJsonPath('total_minor', 14999)->assertJsonPath('cash_received_minor', 20000)
            ->assertJsonPath('change_minor', 5001)->assertJsonPath('finalized_cashier', 'POS Manager')
            ->assertJsonMissingPath('checkout_fingerprint')->json('id');
        $this->postJson('/api/pos/checkout', $cash)->assertOk()->assertJsonPath('id', $id);
        $this->postJson('/api/pos/checkout', array_replace($cash, ['cash_received_minor' => 30000]))->assertConflict();
        $this->postJson('/api/pos/checkout', array_replace($cash, ['note' => 'New note']))->assertConflict();
        $this->postJson('/api/pos/orders', $cart)->assertConflict();
        $this->getJson('/api/pos/orders/'.$id)->assertJsonPath('change_minor', 5001);
        $this->assertDatabaseCount('pos_stock_movements', 1);

        $cart['request_id'] = $uuid();
        $draft = $this->postJson('/api/pos/orders', $cart)->assertCreated()->json();
        $this->postJson('/api/pos/orders', $cart)->assertOk()->assertJsonPath('id', $draft['id']);
        $this->postJson('/api/pos/orders', array_replace($cart, ['note' => 'New note']))->assertConflict();
        $this->postJson('/api/pos/checkout', $cart + ['payment_method' => 'cash', 'cash_received_minor' => 20000])->assertConflict();
        $path = '/api/pos/orders/'.$draft['id'];
        $edit = ['update_id' => $uuid(), 'revision' => $draft['revision'], 'lines' => $cart['lines'], 'note' => 'Edited'];
        $edited = $this->putJson($path, $edit)->assertOk()->json();
        $this->putJson($path, $edit)->assertOk()->assertJsonPath('note', 'Edited');
        $this->putJson($path, array_replace($edit, ['note' => 'Different']))->assertConflict();

        $other = User::create(['name' => 'Checkout Cashier', 'email' => 'checkout@example.test', 'password' => 'test-only-password']);
        $this->actingAs($other);
        $draftCheckout = ['checkout_id' => $uuid(), 'revision' => $edited['revision'], 'lines' => $edit['lines'], 'note' => 'Edited',
            'payment_method' => 'cash', 'cash_received_minor' => 14999];
        $this->postJson($path.'/checkout', $draftCheckout)->assertOk()
            ->assertJsonPath('change_minor', 0)->assertJsonPath('finalized_cashier', 'Checkout Cashier');
        $this->postJson($path.'/checkout', $draftCheckout)->assertOk();
        $this->postJson($path.'/checkout', array_replace($draftCheckout, ['cash_received_minor' => 20000]))->assertConflict();
        $this->assertDatabaseCount('pos_stock_movements', 2);
    }

    public function test_sales_report_uses_pakistan_day_finalized_cashier_and_complete_totals(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-26 12:00:00', 'UTC'));
        $creator = $this->manager();
        $finalizer = User::create(['name' => 'Counter Two', 'email' => 'counter2@example.test', 'password' => 'test-password']);
        $path = '/api/pos/reports/sales';
        $this->getJson($path)->assertUnauthorized();
        $this->actingAs($creator);

        $record = function (?string $at, string $method, int $paid, int $price, float $qty, User $actor) use ($creator) {
            $order = \App\Models\PosOrder::create([
                'user_id' => $creator->id, 'request_id' => (string) \Illuminate\Support\Str::uuid(),
                'cashier' => $creator->name, 'line_count' => 1, 'total_minor' => $paid,
                'lines' => [['sku' => 'HISTORICAL', 'name' => 'Old product', 'unit' => 'kg', 'qty' => $qty,
                    'price_minor' => $price, 'discount' => $qty === 1.5 ? 50 : 0, 'total_minor' => $paid]],
            ]);
            $order->forceFill(['created_at' => '2026-11-01 10:00:00', 'finalized_at' => $at,
                'finalized_by' => $at ? $actor->id : null, 'finalized_cashier' => $at ? $actor->name : null,
                'payment_method' => $at ? $method : null])->save();

            return $order;
        };
        $record(null, 'cash', 1000, 1000, 1, $creator);
        $prior = $record('2026-11-25 18:59:59', 'cash', 900, 900, 1, $creator);
        $cash = $record('2026-11-25 19:00:00', 'cash', 14999, 19999, 1.5, $finalizer);
        $card = $record('2026-11-26 11:00:00', 'card', 6000, 6000, 1, $creator);
        $transfer = $record('2026-11-26 18:59:59', 'bank_transfer', 1100, 1100, 1, $creator);
        $record('2026-11-26 19:00:00', 'cash', 20000, 20000, 1, $creator);

        $this->getJson($path.'?per_page=1')->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('range', ['from' => '2026-11-26', 'to' => '2026-11-26', 'timezone' => 'Asia/Karachi'])
            ->assertJsonPath('summary.gross_minor', '37099')->assertJsonPath('summary.discount_minor', '15000')
            ->assertJsonPath('summary.sales_after_discount_minor', '22099')->assertJsonPath('summary.order_count', 3)
            ->assertJsonPath('summary.refunds_minor', null)->assertJsonPath('summary.net_sales_minor', null)
            ->assertJsonPath('data.0.id', $transfer->id)->assertJsonPath('meta.last_page', 3)
            ->assertJsonPath('payments.0.method', 'bank_transfer')->assertJsonPath('payments.0.total_minor', '1100')
            ->assertJsonPath('payments.1.method', 'card')->assertJsonPath('payments.1.total_minor', '6000')
            ->assertJsonPath('payments.2.method', 'cash')->assertJsonPath('payments.2.total_minor', '14999');
        $this->getJson($path.'?page=2&per_page=1')->assertJsonPath('data.0.id', $card->id)
            ->assertJsonPath('summary.sales_after_discount_minor', '22099');
        $this->getJson($path.'?cashier_id='.$finalizer->id)->assertJsonCount(2, 'cashiers')
            ->assertJsonPath('data.0.id', $cash->id)->assertJsonPath('data.0.cashier', 'Counter Two')
            ->assertJsonPath('summary.sales_after_discount_minor', '14999')->assertJsonPath('summary.order_count', 1);
        $this->getJson($path.'?period=yesterday')->assertJsonPath('data.0.id', $prior->id)
            ->assertJsonPath('summary.sales_after_discount_minor', '900');
        $this->getJson($path.'?period=custom&from=2026-11-25&to=2026-11-26')->assertJsonPath('summary.order_count', 4);
        $this->getJson($path.'?period=custom&from=2026-11-01&to=2026-11-01')->assertJsonPath('summary.order_count', 0);
        $this->getJson($path.'?period=custom&from=2026-11-26&to=2026-11-25')->assertJsonValidationErrors('to');
        $this->getJson($path.'?period=custom&from=2025-01-01&to=2026-11-26')->assertJsonValidationErrors('to');
    }

    public function test_non_cash_is_blocked_but_existing_receipts_can_still_be_read_and_retried(): void
    {
        $this->seed();
        $cashier = $this->manager();
        $this->actingAs($cashier);
        PosProduct::where('sku', 'MIN-01')->update(['stock_milli' => 3000]);
        $uuid = fn () => (string) \Illuminate\Support\Str::uuid();
        $cart = ['request_id' => $uuid(), 'lines' => [['sku' => 'MIN-01', 'qty' => 1, 'price_minor' => 6000, 'discount' => 0]]];
        $draft = $this->postJson('/api/pos/orders', $cart)->assertCreated()->json();
        $path = '/api/pos/orders/'.$draft['id'];
        foreach (['card', 'bank_transfer'] as $method) {
            $this->postJson('/api/pos/checkout', array_replace($cart, ['request_id' => $uuid(), 'payment_method' => $method]))
                ->assertUnprocessable()->assertJsonValidationErrors('payment_method');
            $this->postJson($path.'/checkout', [
                'checkout_id' => $uuid(), 'revision' => $draft['revision'], 'lines' => $cart['lines'], 'payment_method' => $method,
            ])->assertUnprocessable()->assertJsonValidationErrors('payment_method');
        }
        $this->getJson($path)->assertJsonPath('finalized_at', null);
        $this->assertDatabaseCount('pos_orders', 1);
        $this->assertDatabaseCount('pos_stock_movements', 0);

        $requestId = $uuid();
        $historic = \App\Models\PosOrder::create([
            'user_id' => $cashier->id, 'request_id' => $requestId, 'cashier' => $cashier->name,
            'line_count' => 1, 'total_minor' => 6000, 'lines' => $draft['lines'],
        ]);
        $historic->forceFill(['finalized_at' => now(), 'checkout_id' => $requestId, 'payment_method' => 'card'])->save();
        $this->getJson('/api/pos/orders/'.$historic->id)->assertJsonPath('payment_method', 'card')->assertJsonPath('cash_received_minor', null);
        $this->postJson('/api/pos/checkout', array_replace($cart, ['request_id' => $requestId, 'payment_method' => 'card']))
            ->assertOk()->assertJsonPath('id', $historic->id)->assertJsonPath('payment_method', 'card');
        $this->assertDatabaseCount('pos_stock_movements', 0);
    }

    public function test_checkout_is_atomic_idempotent_and_locks_finalized_orders(): void
    {
        $this->seed();
        $this->actingAs($this->manager());
        $one = PosProduct::where('sku', 'MIN-01')->firstOrFail();
        $two = PosProduct::where('sku', 'CHP-05')->firstOrFail();
        $body = ['request_id' => (string) \Illuminate\Support\Str::uuid(), 'lines' => [
            ['sku' => $one->sku, 'qty' => 2, 'price_minor' => 10000, 'discount' => 0],
            ['sku' => $two->sku, 'qty' => 1, 'price_minor' => 5000, 'discount' => 0],
        ]];
        $draft = $this->postJson('/api/pos/orders', $body)->assertCreated()->json();
        $this->assertNull($draft['finalized_at']);
        $this->assertSame(0, $one->fresh()->stock_milli);
        $openStock = fn ($item, $quantity) => $this->postJson('/api/pos/inventory/'.$item->id.'/movements', [
            'request_id' => (string) \Illuminate\Support\Str::uuid(), 'type' => 'opening', 'quantity' => $quantity, 'reason' => 'Initial count',
        ]);
        $openStock($one, 5)->assertCreated();
        $fields = ['lines' => $body['lines'], 'revision' => $draft['revision'], 'payment_method' => 'cash',
            'checkout_id' => (string) \Illuminate\Support\Str::uuid(), 'cash_received_minor' => 30000];
        $path = '/api/pos/orders/'.$draft['id'];
        $this->postJson($path.'/checkout', $fields)->assertJsonValidationErrors('quantity');
        $this->assertSame(5000, $one->fresh()->stock_milli);
        $this->assertDatabaseMissing('pos_stock_movements', ['type' => 'sale']);
        $direct = array_replace($body, ['request_id' => (string) \Illuminate\Support\Str::uuid(), 'payment_method' => 'cash', 'cash_received_minor' => 25000]);
        $this->postJson('/api/pos/checkout', $direct)->assertJsonValidationErrors('quantity');
        $this->assertDatabaseCount('pos_orders', 1);

        $openStock($two, 3)->assertCreated();
        $this->postJson($path.'/checkout', $fields)->assertOk()->assertJsonPath('payment_method', 'cash')
            ->assertJsonPath('total_minor', 25000);
        $this->postJson($path.'/checkout', $fields)->assertOk();
        $this->postJson($path.'/checkout', array_replace($fields, ['checkout_id' => (string) \Illuminate\Support\Str::uuid()]))->assertConflict();
        $this->putJson($path, $fields)->assertConflict();
        $this->assertDatabaseHas('pos_stock_movements', ['order_id' => $draft['id'], 'product_id' => $one->id, 'delta_milli' => -2000]);
        $this->assertSame(3000, $one->fresh()->stock_milli);
        $this->assertSame(2000, $two->fresh()->stock_milli);
        $this->postJson('/api/pos/checkout', $direct)->assertCreated()->assertJsonPath('payment_method', 'cash');
        $this->postJson('/api/pos/checkout', $direct)->assertOk();
        $this->assertDatabaseCount('pos_orders', 2);
        $this->assertSame(1000, $one->fresh()->stock_milli);
        $this->assertSame(1000, $two->fresh()->stock_milli);
    }

    public function test_product_image_lifecycle_is_optional_authenticated_and_validated(): void
    {
        Storage::fake('local');
        $this->seed();
        $this->actingAs($this->manager());
        $sample = PosProduct::firstOrFail();
        $url = '/api/pos/management/products';
        $body = ['name' => 'Photo Product', 'category_id' => $sample->category_id,
            'unit_id' => $sample->unit_id, 'price_minor' => 14995, 'retail_price_minor' => 20099];
        $pixels = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j1ioAAAAASUVORK5CYII=');
        $photo = fn () => UploadedFile::fake()->createWithContent('product.png', $pixels);

        $this->post($url, $body + ['image' => UploadedFile::fake()->createWithContent(
            'bad.png', '<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"></svg>'
        )], ['Accept' => 'application/json'])->assertJsonValidationErrors('image');
        $this->post($url, $body + ['image' => $photo()->size(2049)], ['Accept' => 'application/json'])
            ->assertJsonValidationErrors('image');
        $this->assertSame([], Storage::disk('local')->allFiles());

        $product = $this->post($url, $body + ['image' => $photo()], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonMissingPath('image_path')->json();
        $image = $product['image_url'];
        $imagePath = 'pos-product-images/'.basename($image);
        Storage::disk('local')->assertExists($imagePath);
        $this->get($image)->assertOk()->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson('/api/pos/catalog')->assertJsonFragment(['image_url' => $image]);
        $this->getJson($url.'?q=Photo')->assertJsonPath('data.0.image_url', $image);

        $url .= '/'.$product['id'];
        $body['internal_code'] = $product['internal_code'];
        $this->putJson($url, $body)->assertOk()->assertJsonPath('image_url', $image);
        $newImage = $this->post($url, $body + ['_method' => 'PUT', 'image' => $photo()], ['Accept' => 'application/json'])
            ->assertOk()->json('image_url');
        $this->assertNotSame($image, $newImage);
        Storage::disk('local')->assertMissing($imagePath);
        $newPath = 'pos-product-images/'.basename($newImage);
        Storage::disk('local')->assertExists($newPath);
        $this->get($image)->assertNotFound();

        $this->putJson($url, $body + ['remove_image' => true])->assertOk()->assertJsonPath('image_url', null);
        Storage::disk('local')->assertMissing($newPath);
        $this->get($newImage)->assertNotFound();
        $image = $this->post($url, $body + ['_method' => 'PUT', 'image' => $photo()], ['Accept' => 'application/json'])
            ->assertOk()->json('image_url');
        $this->postJson('/api/pos/auth/logout')->assertOk();
        $this->get($image)->assertUnauthorized();
    }

    public function test_retail_price_is_independent_of_the_pos_sale_price(): void
    {
        $this->seed();
        $this->actingAs($this->manager());
        $sample = PosProduct::firstOrFail();
        $this->assertSame($sample->price_minor, $sample->retail_price_minor);

        $url = '/api/pos/management/products';
        $body = ['name' => 'Price Test', 'category_id' => $sample->category_id,
            'unit_id' => $sample->unit_id, 'retail_price_minor' => 20099, 'price_minor' => 14995];
        $created = $this->postJson($url, $body)->assertCreated()
            ->assertJsonPath('retail_price_minor', 20099)->assertJsonPath('price_minor', 14995)->json();
        $url .= '/'.$created['id'];
        $body['internal_code'] = $created['internal_code'];

        $this->putJson($url, array_replace($body, ['retail_price_minor' => -1]))
            ->assertJsonValidationErrors('retail_price_minor');
        $this->putJson($url, array_replace($body, ['price_minor' => 12.5]))
            ->assertJsonValidationErrors('price_minor');
        $body['retail_price_minor'] = 21900;
        $this->putJson($url, $body)->assertOk()
            ->assertJsonPath('retail_price_minor', 21900)->assertJsonPath('price_minor', 14995);

        // An old client changing the sale price must not erase the retail price.
        unset($body['retail_price_minor']);
        $body['price_minor'] = 13995;
        $this->putJson($url, $body)->assertOk()
            ->assertJsonPath('retail_price_minor', 21900)->assertJsonPath('price_minor', 13995);

        $catalog = $this->getJson('/api/pos/catalog')->assertOk()->json('products');
        $product = collect($catalog)->firstWhere('sku', $created['sku']);
        $this->assertSame(13995, $product['price_minor']);
    }

    public function test_crud_code_generation_and_conflict_protection(): void
    {
        $this->actingAs($this->manager());
        $this->seed();
        $prefix = '/api/pos/management/';
        $supplier = $this->postJson($prefix.'suppliers', ['name' => 'Fresh Supply', 'email' => 'test@example.test'])->assertCreated()->json('id');
        $this->putJson($prefix.'suppliers/'.$supplier, ['name' => 'Fresh Supply Updated'])->assertOk()->assertJsonPath('name', 'Fresh Supply Updated');
        $category = $this->postJson($prefix.'categories', ['name' => 'Frozen', 'slug' => 'frozen'])->assertCreated()->json('id');
        $this->putJson($prefix.'categories/'.$category, ['name' => 'Frozen Foods'])->assertOk();
        $unit = $this->postJson($prefix.'units', ['name' => 'Tray', 'code' => 'tray', 'quantity_precision' => 0])->assertCreated()->json('id');
        $this->putJson($prefix.'units/'.$unit, ['name' => 'Tray', 'quantity_precision' => 1])->assertOk()->assertJsonPath('quantity_precision', 1);
        $body = ['name' => 'Frozen Peas', 'barcode' => '000123456789', 'category_id' => $category, 'unit_id' => $unit, 'supplier_id' => $supplier, 'price_minor' => 12350];
        $product = $this->postJson($prefix.'products', $body)->assertCreated()->json();
        $this->assertMatchesRegularExpression('/^[0-9]{4}$/D', $product['internal_code']);
        $this->assertSame('000123456789', $product['barcode']);
        $this->postJson($prefix.'products', $body)->assertJsonValidationErrors('barcode');
        $this->postJson($prefix.'products', array_replace($body, ['barcode' => null, 'internal_code' => $product['internal_code']]))
            ->assertJsonValidationErrors('internal_code');
        $this->putJson($prefix.'products/'.$product['id'], array_replace($body, ['internal_code' => '012']))
            ->assertJsonValidationErrors('internal_code');
        $available = collect(range(0, 9999))->map(fn ($n) => sprintf('%04d', $n))->first(
            fn ($candidate) => !PosProduct::where('internal_code', $candidate)->exists()
        );
        $body['internal_code'] = $available;
        $this->putJson($prefix.'products/'.$product['id'], $body)->assertOk()->assertJsonPath('internal_code', $available);
        $this->getJson('/api/pos/catalog')->assertJsonFragment(['internal_code' => $available, 'barcode' => '000123456789']);
        foreach (['categories' => $category, 'units' => $unit, 'suppliers' => $supplier] as $type => $id) {
            $this->deleteJson($prefix.$type.'/'.$id)->assertConflict();
        }
        $this->deleteJson($prefix.'products/'.$product['id'])->assertNoContent();
        $this->getJson('/api/pos/catalog')->assertJsonMissing(['barcode' => '000123456789']);
        $this->putJson($prefix.'products/'.$product['id'], $body + ['is_active' => true, 'supplier_id' => null])->assertOk();
        $this->getJson('/api/pos/catalog')->assertJsonFragment(['barcode' => '000123456789']);
        $this->putJson($prefix.'products/'.$product['id'], array_replace($body, ['supplier_id' => null]))->assertOk();
        $this->deleteJson($prefix.'suppliers/'.$supplier)->assertNoContent();
    }

    public function test_existing_demo_codes_are_unique_and_not_reassigned_when_reseeding(): void
    {
        $this->seed();
        $before = PosProduct::orderBy('id')->pluck('internal_code')->all();
        $this->assertCount(16, array_unique($before));
        foreach ($before as $code) {
            $this->assertMatchesRegularExpression('/^[0-9]{4}$/D', $code);
        }
        $this->seed();
        $this->assertSame($before, PosProduct::orderBy('id')->pluck('internal_code')->all());
    }
}
