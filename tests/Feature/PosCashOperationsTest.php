<?php

namespace Tests\Feature;

use App\Models\PosOrder;
use App\Models\PosProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PosCashOperationsTest extends TestCase
{
    use RefreshDatabase {
        refreshDatabase as private runTestMigrations;
    }

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

    private function cashier(string $email = 'counter@example.test'): User
    {
        return User::create(['name' => ucfirst(strtok($email, '@')), 'email' => $email, 'password' => 'fixture-password']);
    }

    private function cashSale(int $price, int $qty = 1, int $discount = 0, ?int $shift = null): array
    {
        return [
            'request_id' => (string) Str::uuid(), 'payment_method' => 'cash', 'cash_session_id' => $shift,
            'cash_received_minor' => 100000,
            'lines' => [['sku' => 'MIN-01', 'qty' => $qty, 'price_minor' => $price, 'discount' => $discount]],
        ];
    }

    private function draftRefund(int $order, int $milli = 1000, bool $restock = false, ?int $shift = null): array
    {
        $lines = [['line' => 0, 'qty_milli' => $milli, 'restock' => $restock]];
        $quote = $this->postJson("/api/pos/orders/$order/refund-preview", compact('lines'))->assertOk()->json();

        return [[
            'request_id' => (string) Str::uuid(), 'reason' => 'Item returned', 'quote' => $quote['quote'],
            'cash_session_id' => $shift, 'lines' => $lines,
        ], $quote['total_minor']];
    }

    public function test_discounted_refunds_with_cumulative_rounding_and_independent_reporting(): void
    {
        $this->seed();
        $this->getJson('/api/pos/cash-drawer')->assertUnauthorized();
        $this->getJson('/api/pos/refunds/1')->assertUnauthorized();
        $cashier = $this->cashier();
        $second = $this->cashier('other@example.test');
        $product = PosProduct::where('sku', 'MIN-01')->firstOrFail();
        $product->forceFill(['stock_milli' => 9000])->save();

        $this->travelTo(now()->parse('2026-11-27 18:59:00', 'UTC'));
        $sale = $this->actingAs($cashier)->postJson('/api/pos/checkout', $this->cashSale(10001, 3, 50))
            ->assertCreated()->assertJsonPath('total_minor', 15002)->json();
        $id = $sale['id'];
        $path = "/api/pos/orders/$id";
        $original = PosOrder::find($id)->getRawOriginal();
        $this->travelTo(now()->parse('2026-11-27 19:01:00', 'UTC'));
        $this->actingAs($second)->getJson($path.'/return-options')
            ->assertJsonPath('lines.0.remaining_milli', 3000)->assertJsonPath('lines.0.step_milli', 1000)
            ->assertJsonPath('lines.0.can_restock', true);
        $this->postJson($path.'/refund-preview', ['lines' => [['line' => 0, 'qty_milli' => 500, 'restock' => false]]])->assertUnprocessable();
        [$first, $a] = $this->draftRefund($id, 1000, true);
        [$stale] = $this->draftRefund($id);
        $this->assertSame('5001', $a);
        $receipt = $this->postJson($path.'/refunds', $first)->assertCreated()->assertJsonPath('total_minor', '5001')->json();
        $this->postJson($path.'/refunds', $first)->assertOk()->assertExactJson($receipt);
        $this->postJson($path.'/refunds', array_replace($first, ['reason' => 'Different']))->assertConflict();
        $this->postJson($path.'/refunds', $stale)->assertConflict();
        $this->assertDatabaseHas('pos_stock_movements', ['refund_id' => $receipt['id'], 'type' => 'return', 'delta_milli' => 1000]);
        $this->assertSame(7000, $product->refresh()->stock_milli);
        $product->forceFill(['is_active' => false])->save();
        $this->postJson($path.'/refund-preview', ['lines' => [['line' => 0, 'qty_milli' => 1000, 'restock' => true]]])->assertUnprocessable();
        foreach (['5000', '5001'] as $amount) {
            [$body, $quote] = $this->draftRefund($id);
            $this->assertSame($amount, $quote);
            $this->postJson($path.'/refunds', $body)->assertCreated()->assertJsonPath('total_minor', $amount);
        }
        $this->assertSame(7000, $product->refresh()->stock_milli);
        $this->getJson($path.'/return-options')->assertJsonPath('lines.0.remaining_milli', 0);
        $this->postJson($path.'/refund-preview', ['lines' => [['line' => 0, 'qty_milli' => 1000, 'restock' => false]]])->assertUnprocessable();
        $this->getJson('/api/pos/refunds/'.$receipt['id'])->assertOk()->assertExactJson($receipt);
        $this->getJson($path.'/refunds')->assertJsonPath('total', 3);
        $this->assertSame($original, PosOrder::find($id)->getRawOriginal());
        $this->assertDatabaseCount('pos_stock_movements', 2);
        $report = '/api/pos/reports/sales';
        $this->getJson($report.'?period=yesterday')->assertJsonPath('summary.net_sales_minor', '15002');
        $this->getJson($report.'?kind=refunds&per_page=1&page=2&cashier_id='.$second->id)
            ->assertJsonPath('summary.order_count', 0)->assertJsonPath('summary.refunds_minor', '15002')
            ->assertJsonPath('summary.net_sales_minor', '-15002')->assertJsonPath('summary.refund_count', 3)
            ->assertJsonPath('meta.total', 3)->assertJsonPath('data.0.total_minor', '5000');
        $this->getJson($report.'?cashier_id='.$cashier->id)->assertJsonPath('summary.net_sales_minor', '0');
        $this->assertDatabaseCount('pos_refunds', 3);

        $draft = $this->postJson('/api/pos/orders', [
            'request_id' => (string) Str::uuid(), 'lines' => [['sku' => 'APL-13', 'qty' => 1, 'price_minor' => 100, 'discount' => 0]],
        ])->assertCreated()->json();
        $this->getJson('/api/pos/orders/'.$draft['id'].'/return-options')->assertUnprocessable();
        PosOrder::find($id)->forceFill(['payment_method' => 'card'])->save();
        $this->getJson($path.'/return-options')->assertUnprocessable();
    }

    public function test_shift_reconciles_sessions_across_pakistan_midnight_and_safe_retries(): void
    {
        $this->seed();
        $first = $this->cashier();
        $second = $this->cashier('other@example.test');
        PosProduct::where('sku', 'MIN-01')->update(['stock_milli' => 9000]);
        $base = '/api/pos/cash-drawer';
        $this->actingAs($first)->getJson($base)->assertExactJson(['active' => null, 'tracking_enabled' => false]);

        $this->travelTo(now()->parse('2026-11-27 18:59:00', 'UTC'));
        $open = ['request_id' => (string) Str::uuid(), 'opening_minor' => 200000];
        $shift = $this->postJson($base.'/open', $open)->assertCreated()->assertJsonPath('expected_minor', '200000')->json('id');
        $this->postJson($base.'/open', $open)->assertOk()->assertJsonPath('id', $shift);
        $this->postJson($base.'/open', array_replace($open, ['request_id' => (string) Str::uuid()]))->assertConflict();
        $this->postJson('/api/pos/checkout', $this->cashSale(10000))->assertConflict();

        $a = $this->cashSale(30000, shift: $shift);
        $sale = $this->postJson('/api/pos/checkout', $a)->assertCreated()->assertJsonPath('change_minor', 70000)->json('id');
        $b = $this->cashSale(40000, shift: $shift);
        $draft = $this->postJson('/api/pos/orders', ['request_id' => $b['request_id'], 'lines' => $b['lines']])
            ->assertCreated()->assertJsonPath('cash_session_id', null)->json();
        $this->getJson($base)->assertJsonPath('active.sales_minor', '30000');
        $before = $this->getJson($base)->json('active.revision');

        $this->travelTo(now()->parse('2026-11-27 19:01:00', 'UTC'));
        $this->actingAs($second)->postJson('/api/pos/orders/'.$draft['id'].'/checkout', $b + [
            'revision' => $draft['revision'], 'checkout_id' => (string) Str::uuid(),
        ])->assertOk()->assertJsonPath('cash_session_id', $shift)->assertJsonPath('finalized_by', $second->id);
        $this->getJson($base)->assertJsonPath('active.expected_minor', '270000');
        $report = '/api/pos/reports/sales';
        $this->getJson($report.'?period=yesterday')->assertJsonPath('summary.net_sales_minor', '30000');
        $this->getJson($report)->assertJsonPath('summary.net_sales_minor', '40000');

        $moves = $base."/$shift/movements";
        $cashIn = ['request_id' => (string) Str::uuid(), 'kind' => 'in', 'amount_minor' => 50000, 'reason' => 'Added float'];
        $move = $this->postJson($moves, $cashIn)->assertCreated()->json();
        $this->postJson($moves, $cashIn)->assertOk()->assertExactJson($move);
        $this->postJson($moves, array_replace($cashIn, ['request_id' => (string) Str::uuid(), 'kind' => 'out', 'amount_minor' => 10000]))->assertCreated();
        [$refund, $quote] = $this->draftRefund($sale, 1000, false, $shift);
        $this->assertSame('30000', $quote);
        $this->postJson("/api/pos/orders/$sale/refunds", $refund)->assertCreated()->assertJsonPath('cash_session_id', $shift);
        $this->getJson($report)->assertJsonPath('summary.net_sales_minor', '10000');
        $snapshot = $this->getJson($base)->assertJsonPath('active.expected_minor', '280000')
            ->assertJsonPath('active.refunds_minor', '30000')->json('active');
        $close = ['request_id' => (string) Str::uuid(), 'counted_minor' => 278000, 'revision' => $snapshot['revision']];
        $this->postJson($base."/$shift/close", array_replace($close, ['revision' => $before]))->assertConflict();
        $proof = $this->postJson($base."/$shift/close", $close)->assertOk()
            ->assertJsonPath('expected_minor', '280000')->assertJsonPath('variance_minor', '-2000')->assertJsonPath('closed_by', $second->id)->json();
        $this->getJson($base)->assertExactJson(['active' => null, 'tracking_enabled' => true]);
        $this->getJson($base.'/history')->assertJsonPath('data.0.variance_minor', '-2000');
        $this->getJson($moves)->assertJsonPath('total', 2);
        $this->postJson($moves, $cashIn)->assertOk()->assertExactJson($move);
        $this->postJson("/api/pos/orders/$sale/refunds", $refund)->assertOk();
        $this->postJson($moves, array_replace($cashIn, ['request_id' => (string) Str::uuid()]))->assertConflict();

        $newShift = $this->postJson($base.'/open', ['request_id' => (string) Str::uuid(), 'opening_minor' => 0])->assertCreated()->json('id');
        $this->postJson($base."/$shift/close", $close)->assertOk()->assertExactJson($proof);
        $this->postJson('/api/pos/checkout', $this->cashSale(20000, shift: $shift))->assertConflict();
        $this->actingAs($first)->postJson('/api/pos/checkout', $a)->assertOk()->assertJsonPath('id', $sale);
        $this->postJson('/api/pos/checkout', array_replace($a, ['cash_session_id' => $newShift]))->assertConflict();
        $this->postJson('/api/pos/checkout', $this->cashSale(20000, shift: $newShift))->assertCreated();
        $this->getJson($base)->assertJsonPath('active.expected_minor', '20000');
        $this->assertDatabaseCount('pos_stock_movements', 3);
        $this->assertDatabaseCount('pos_refunds', 1);
    }
}
