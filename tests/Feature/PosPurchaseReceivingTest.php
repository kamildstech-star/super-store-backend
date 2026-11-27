<?php

namespace Tests\Feature;

use App\Models\PosProduct;
use App\Models\PosStockMovement;
use App\Models\PosSupplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PosPurchaseReceivingTest extends TestCase
{
    use RefreshDatabase {
        refreshDatabase as private runTestMigrations;
    }

    private const API = '/api/pos/purchases';

    public function refreshDatabase(): void
    {
        $database = $this->app['db']->connection()->getDatabaseName();
        $driver = config('database.default');
        if (! ($driver === 'sqlite' && $database === ':memory:')
            && ! ($driver === 'mysql' && str_ends_with($database, '_test'))) {
            throw new \RuntimeException('Use in-memory SQLite or a dedicated MySQL database ending in _test.');
        }

        $this->runTestMigrations();
    }

    private function line(PosProduct $product, int $quantity, int $cost): array
    {
        return ['product_id' => $product->id, 'unit_id' => $product->unit_id, 'quantity_milli' => $quantity, 'unit_cost_minor' => $cost];
    }

    public function test_posts_stock_cost_and_supplier_history_once_and_preserves_preferred_supplier(): void
    {
        $this->seed();
        $actor = User::create(['name' => 'Receiving clerk', 'email' => 'receiving@example.test', 'password' => 'fixture-password']);
        $other = User::create(['name' => 'Other clerk', 'email' => 'other@example.test', 'password' => 'fixture-password']);
        $water = PosProduct::where('sku', 'MIN-01')->firstOrFail();
        $apple = PosProduct::where('sku', 'APL-13')->firstOrFail();
        $preferred = PosSupplier::create(['name' => 'Preferred supplier']);
        $actual = PosSupplier::create(['name' => 'Actual supplier']);
        $water->update(['supplier_id' => $preferred->id]);
        $waterStock = $water->stock_milli;
        $appleStock = $apple->stock_milli;
        $body = [
            'request_id' => (string) Str::uuid(),
            'supplier_id' => $actual->id, 'invoice_reference' => 'INV-71', 'note' => 'Delivered in the morning',
            'lines' => [$this->line($apple, 1550, 333), $this->line($water, 2000, 1199)],
        ];

        $this->postJson(self::API, $body)->assertUnauthorized();
        $posted = $this->actingAs($actor)->postJson(self::API, $body)
            ->assertCreated()->assertJsonPath('supplier_name', 'Actual supplier')->assertJsonPath('actor', $actor->name)
            ->assertJsonPath('total_minor', 2914)->assertJsonPath('line_count', 2)->assertJsonCount(2, 'lines')
            ->assertJsonMissingPath('request_id')->assertJsonMissingPath('payload_hash')->json();
        $this->assertSame($waterStock + 2000, $water->fresh()->stock_milli);
        $this->assertSame($appleStock + 1550, $apple->fresh()->stock_milli);
        $this->assertSame($preferred->id, $water->fresh()->supplier_id);
        $this->assertNull($apple->fresh()->supplier_id);
        $this->getJson('/api/pos/management/products?supplier_id='.$preferred->id)
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.supplier.name', 'Preferred supplier');
        $this->getJson('/api/pos/management/suppliers?q=Preferred')->assertJsonPath('data.0.products_count', 1);
        $this->getJson(self::API.'/products')->assertOk()->assertJsonFragment(['id' => $water->id, 'supplier_id' => $preferred->id]);

        $water->update(['name' => 'Renamed water', 'is_active' => false]);
        $actual->update(['name' => 'Renamed supplier']);
        $reordered = array_replace($body, ['lines' => array_reverse($body['lines'])]);
        $this->postJson(self::API, $reordered)->assertOk()->assertJsonPath('id', $posted['id'])->assertJsonCount(2, 'lines');
        $this->assertSame($waterStock + 2000, $water->fresh()->stock_milli);
        $this->postJson(self::API, array_replace($body, ['note' => 'Changed request']))->assertConflict();
        $this->actingAs($other)->postJson(self::API, $body)->assertConflict();

        $path = self::API.'/'.$posted['id'];
        $this->getJson($path)->assertOk()->assertJsonPath('supplier_name', 'Actual supplier')->assertJsonPath('invoice_reference', 'INV-71')
            ->assertJsonPath('lines', $posted['lines']);
        $this->getJson(self::API.'?supplier_id='.$actual->id.'&per_page=1&q=INV-71')->assertJsonPath('meta.total', 1);
        $this->getJson(self::API.'?q=PR-'.str_pad($posted['id'], 4, '0', STR_PAD_LEFT))->assertJsonPath('meta.total', 1);
        $this->getJson(self::API.'?supplier_id='.$preferred->id)->assertJsonPath('meta.total', 0);
        $this->getJson('/api/pos/inventory/'.$water->id.'/movements')
            ->assertJsonPath('data.0.purchase_receipt_id', $posted['id'])->assertJsonPath('data.0.type', 'receipt');
        $this->assertSame(2, PosStockMovement::where('purchase_receipt_id', $posted['id'])->count());
        $this->deleteJson('/api/pos/management/suppliers/'.$actual->id)->assertConflict();
    }

    public function test_invalid_line_rolls_back_all_stock_and_receipt_and_allows_corrected_submission(): void
    {
        $this->seed();
        $actor = User::create(['name' => 'Receiving clerk', 'email' => 'receiving@example.test', 'password' => 'fixture-password']);
        $products = PosProduct::with('unit')->get()->filter(fn ($p) => $p->unit->quantity_precision === 0)
            ->sortBy('id')->values();
        [$first, $second] = [$products[0], $products[1]];
        $balance = $first->stock_milli;
        $supplier = PosSupplier::create(['name' => 'Receiving supplier']);
        $body = [
            'request_id' => (string) Str::uuid(), 'supplier_id' => $supplier->id,
            'lines' => [$this->line($first, 1000, 0), $this->line($second, 1500, 12000)],
        ];
        $this->actingAs($actor)->postJson(self::API, $body)->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->assertSame($balance, $first->fresh()->stock_milli);
        $this->assertDatabaseCount('pos_purchase_receipts', 0);
        $this->assertDatabaseCount('pos_purchase_receipt_lines', 0);
        $this->assertSame(0, PosStockMovement::whereNotNull('purchase_receipt_id')->count());

        $body['lines'][1]['quantity_milli'] = 1000;
        $body['lines'][1]['unit_id'] = 9999;
        $this->postJson(self::API, $body)->assertUnprocessable()->assertJsonValidationErrors('lines');
        $body['lines'][1]['unit_id'] = $second->unit_id;
        $second->update(['is_active' => false]);
        $this->postJson(self::API, $body)->assertUnprocessable()->assertJsonValidationErrors('lines');
        $second->update(['is_active' => true]);
        $duplicated = array_replace($body, ['lines' => [$body['lines'][0], $body['lines'][0]]]);
        $this->postJson(self::API, $duplicated)->assertUnprocessable()->assertJsonValidationErrors('lines.0.product_id');
        $this->assertSame($balance, $first->fresh()->stock_milli);
        $this->postJson(self::API, $body)->assertCreated()->assertJsonPath('total_minor', 12000);
        $this->assertSame($balance + 1000, $first->fresh()->stock_milli);

        $this->postJson(self::API, array_replace($body, ['request_id' => (string) Str::uuid(), 'supplier_id' => 9999]))
            ->assertUnprocessable()->assertJsonValidationErrors('supplier_id');
        $this->assertDatabaseCount('pos_purchase_receipts', 1);
    }
}
