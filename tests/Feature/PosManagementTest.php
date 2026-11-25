<?php

namespace Tests\Feature;

use App\Models\PosProduct;
use App\Models\User;
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
