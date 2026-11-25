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
        $this->getJson('/api/pos/management')->assertOk();
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
        $this->getJson('/api/pos/orders')->assertExactJson(['orders' => [], 'next_before' => null]);
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
        $this->getJson('/api/pos/orders')->assertOk()->assertJsonPath('orders.0.id', $id)
            ->assertJsonMissingPath('orders.0.lines');
        $this->getJson('/api/pos/orders/'.$id)->assertOk()->assertJsonPath('note', 'Pack separately')
            ->assertJsonPath('lines.0.sku', 'MIN-01')->assertJsonPath('cashier', 'POS Manager');
        $this->getJson('/api/pos/orders?before='.$id)->assertJsonCount(0, 'orders');

        $body['request_id'] = (string) \Illuminate\Support\Str::uuid();
        $body['lines'][0] = ['sku' => 'MIN-01', 'qty' => 9999, 'price_minor' => 4294967295, 'discount' => 0];
        $this->postJson('/api/pos/orders', $body)->assertCreated()->assertJsonPath('total_minor', 42945377982705);
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
