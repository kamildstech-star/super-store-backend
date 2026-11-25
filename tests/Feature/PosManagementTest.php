<?php

namespace Tests\Feature;

use App\Models\PosProduct;
use App\Models\User;
use Database\Seeders\PosDemoUserSeeder;
use Illuminate\Support\Facades\Hash;
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
        $this->postJson('/api/pos/auth/login', ['email' => 'MANAGER@example.test', 'password' => 'test-only-password'])
            ->assertOk()->assertJsonPath('user.name', 'POS Manager')->assertJsonStructure(['csrf_token']);
        $this->getJson('/api/pos/management/products')->assertOk();
        $this->getJson('/api/pos/auth/session')->assertJsonPath('user.email', 'manager@example.test');
        $this->postJson('/api/pos/auth/logout')->assertOk()->assertJsonPath('user', null);
        $this->getJson('/api/pos/catalog')->assertUnauthorized();
        $this->postJson('/register', [])->assertNotFound();
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
