<?php

namespace Tests\Feature;

use App\Models\PosProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PosCatalogTest extends TestCase
{
    use RefreshDatabase {
        refreshDatabase as private runTestMigrations;
    }

    public function refreshDatabase(): void
    {
        $connection = config('database.default');
        $database = $this->app['db']->connection()->getDatabaseName();

        if (
            ! ($connection === 'sqlite' && $database === ':memory:')
            && ! ($connection === 'mysql' && str_ends_with($database, '_test'))
        ) {
            throw new \RuntimeException('Use in-memory SQLite or a dedicated MySQL database ending in _test.');
        }

        $this->runTestMigrations();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::create([
            'name' => 'Manager', 'email' => 'manager@example.test', 'password' => 'test-only-password',
        ]));
    }

    public function test_seeded_rows_are_repeatable_and_exposed_through_the_api(): void
    {
        $this->seed();
        $this->seed();

        $this->assertDatabaseCount('pos_categories', 4);
        $this->assertDatabaseCount('pos_units', 6);
        $this->assertDatabaseCount('pos_products', 16);

        $this->getJson('/api/pos/catalog')->assertOk()
            ->assertJsonPath('currency', 'PKR')
            ->assertJsonCount(4, 'categories')
            ->assertJsonCount(6, 'units')
            ->assertJsonCount(16, 'products')
            ->assertJsonFragment(['code' => 'kg', 'quantity_precision' => 3])
            ->assertJsonPath('products.0.sku', 'MIN-01')
            ->assertJsonPath('products.0.price_minor', 6000);
    }

    public function test_the_catalog_reads_live_database_values_and_hides_inactive_products(): void
    {
        $this->seed();
        PosProduct::where('sku', 'MIN-01')->update(['price_minor' => 7300]);
        PosProduct::where('sku', 'COL-02')->update(['is_active' => false]);

        $this->getJson('/api/pos/catalog')->assertOk()
            ->assertJsonCount(15, 'products')
            ->assertJsonPath('products.0.price_minor', 7300)
            ->assertJsonMissing(['sku' => 'COL-02']);
    }

    public function test_cors_allows_the_dev_frontend_but_not_an_unknown_origin(): void
    {
        $this->seed();
        $this->getJson('/api/pos/catalog', ['Origin' => 'http://127.0.0.1:4173'])
            ->assertOk()->assertHeader('Access-Control-Allow-Origin', 'http://127.0.0.1:4173');
        $this->getJson('/api/pos/catalog', ['Origin' => 'https://other.example'])
            ->assertOk()->assertHeaderMissing('Access-Control-Allow-Origin');
    }
}
