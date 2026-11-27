<?php

namespace Tests\Feature;

use App\Models\PosProduct;
use App\Models\PosPurchaseReceipt;
use App\Models\PosSupplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PosSupplierPaymentTest extends TestCase
{
    use RefreshDatabase {
        refreshDatabase as private runTestMigrations;
    }

    public function refreshDatabase(): void
    {
        $driver = config('database.default');
        $database = $this->app['db']->connection()->getDatabaseName();
        if (! ($driver === 'sqlite' && $database === ':memory:')
            && ! ($driver === 'mysql' && str_ends_with($database, '_test'))) {
            throw new \RuntimeException('Use in-memory SQLite or a dedicated MySQL database ending in _test.');
        }
        $this->runTestMigrations();
    }

    public function test_receiving_and_installments_reconcile_supplier_and_drawer_without_duplicate_entries(): void
    {
        $this->seed();
        $product = PosProduct::where('sku', 'MIN-01')->firstOrFail();
        $supplier = PosSupplier::create(['name' => 'Fixture Supplier']);
        $actor = User::create(['name' => 'Fixture cashier', 'email' => 'cashier@example.test', 'password' => 'fixture-password']);
        $purchases = '/api/pos/purchases';
        $drawer = '/api/pos/cash-drawer';
        $payment = fn (int $amount, string $source, ?int $session = null): array => [
            'amount_minor' => $amount, 'source' => $source, 'cash_session_id' => $session, 'note' => 'Payment reference',
        ];
        $newReceipt = fn (array $extra = []) => [
            'request_id' => (string) Str::uuid(), 'supplier_id' => $supplier->id,
            'lines' => [['product_id' => $product->id, 'unit_id' => $product->unit_id, 'quantity_milli' => 2000, 'unit_cost_minor' => 3000]],
        ] + $extra;
        $this->getJson('/api/pos/suppliers/'.$supplier->id.'/payments')->assertUnauthorized();

        $this->actingAs($actor);
        $this->postJson($purchases, $newReceipt(['payment' => $payment(7000, 'external')]))
            ->assertUnprocessable()->assertJsonValidationErrors('amount_minor');
        $this->postJson($purchases, $newReceipt(['payment' => $payment(1000, 'drawer')]))
            ->assertUnprocessable()->assertJsonValidationErrors('payment.cash_session_id');
        $this->assertSame(0, $product->fresh()->stock_milli);
        $this->assertDatabaseCount('pos_purchase_receipts', 0);

        $shift = $this->postJson($drawer.'/open', ['request_id' => (string) Str::uuid(), 'opening_minor' => 5000])
            ->assertCreated()->json();
        $this->postJson($purchases, $newReceipt(['payment' => $payment(5500, 'drawer', $shift['id'])]))
            ->assertUnprocessable()->assertJsonValidationErrors('amount_minor');
        $this->assertSame(0, $product->fresh()->stock_milli);
        $this->assertDatabaseCount('pos_cash_movements', 0);

        $submission = $newReceipt(['payment' => $payment(2000, 'drawer', $shift['id'])]);
        $paid = $this->postJson($purchases, $submission)->assertCreated()->assertJsonPath('paid_minor', 2000)
            ->assertJsonPath('balance_minor', 4000)->assertJsonPath('payment_status', 'partial')->json();
        $id = $paid['id'];
        $this->postJson($purchases, $submission)->assertOk()->assertJsonPath('id', $id);
        $this->assertSame(2000, $product->fresh()->stock_milli);
        $this->postJson($drawer.'/'.$shift['id'].'/close', [
            'request_id' => (string) Str::uuid(), 'revision' => $shift['revision'], 'counted_minor' => 3000,
        ])->assertConflict();
        $this->getJson($drawer)->assertJsonPath('active.cash_out_minor', '2000')->assertJsonPath('active.expected_minor', '3000');
        $this->getJson($drawer.'/'.$shift['id'].'/movements')->assertJsonPath('data.0.kind', 'out')
            ->assertJsonPath('data.0.reason', 'Supplier payment · PR-'.str_pad((string) $id, 4, '0', STR_PAD_LEFT));

        $path = $purchases.'/'.$id.'/payments';
        $body = ['request_id' => (string) Str::uuid()] + $payment(3000, 'external');
        $installment = $this->postJson($path, $body)->assertCreated()->assertJsonPath('cash_movement_id', null)->json();
        $this->postJson($path, $body)->assertOk()->assertJsonPath('id', $installment['id']);
        $this->postJson($path, array_replace($body, ['amount_minor' => 1]))->assertConflict();
        $this->postJson($path, (['request_id' => (string) Str::uuid()] + $payment(1001, 'external')))
            ->assertUnprocessable()->assertJsonValidationErrors('amount_minor');
        $this->getJson($drawer)->assertJsonPath('active.expected_minor', '3000');
        $last = ['request_id' => (string) Str::uuid()] + $payment(1000, 'drawer', $shift['id']);
        $this->postJson($path, $last)->assertCreated();
        $this->getJson($purchases.'/'.$id)->assertJsonPath('paid_minor', 6000)->assertJsonPath('balance_minor', 0)->assertJsonPath('payment_status', 'paid');
        $finalDrawer = $this->getJson($drawer)->assertJsonPath('active.expected_minor', '2000')->json('active');
        $this->postJson($drawer.'/'.$shift['id'].'/close', [
            'request_id' => (string) Str::uuid(), 'revision' => $finalDrawer['revision'], 'counted_minor' => 2000,
        ])->assertOk()->assertJsonPath('variance_minor', '0');
        $this->postJson($path, $last)->assertOk();
        $this->assertDatabaseCount('pos_cash_movements', 2);
        $this->getJson($path.'?per_page=1&page=2')->assertJsonPath('meta.total', 3)->assertJsonPath('data.0.id', $installment['id']);

        $this->postJson($purchases, $newReceipt())->assertCreated()->assertJsonPath('payment_status', 'unpaid');
        $legacy = PosPurchaseReceipt::where('supplier_id', $supplier->id)->latest('id')->firstOrFail();
        $legacy->forceFill(['payment_tracking' => false])->save();
        $this->getJson($purchases.'/'.$legacy->id)->assertJsonPath('balance_minor', null)->assertJsonPath('payment_status', 'untracked');
        $this->postJson($purchases.'/'.$legacy->id.'/payments', ['request_id' => (string) Str::uuid()] + $payment(1, 'external'))
            ->assertUnprocessable()->assertJsonValidationErrors('amount_minor');
        $this->postJson($purchases, $newReceipt())->assertCreated()->assertJsonPath('balance_minor', 6000);
        $this->getJson('/api/pos/suppliers/'.$supplier->id.'/payments')->assertJsonPath('summary.balance_minor', '6000')
            ->assertJsonPath('summary.untracked_receipts', 1)->assertJsonPath('summary.tracked_receipts', 2)->assertJsonPath('meta.total', 3);
        $this->getJson('/api/pos/management/suppliers?q=Fixture')->assertJsonPath('data.0.supplier_balance_minor', '6000');
    }
}
