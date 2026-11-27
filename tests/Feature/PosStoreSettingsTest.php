<?php

namespace Tests\Feature;

use App\Models\PosOrder;
use App\Models\PosProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PosStoreSettingsTest extends TestCase
{
    use RefreshDatabase {
        refreshDatabase as private runTestMigrations;
    }

    private const PATH = '/api/pos/settings';

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

    private function operator(): User
    {
        return User::create(['name' => 'Test cashier', 'email' => 'store@example.test', 'password' => 'fixture-password']);
    }

    private function settings(int $revision, string $name): array
    {
        return ['revision' => $revision, 'name' => $name, 'address' => "42 Market Road\nLahore",
            'phone' => '+92 300 555 0100', 'receipt_footer' => 'Thank you for shopping with us!'];
    }

    public function test_settings_are_auth_protected_validated_and_reject_stale_overwrites(): void
    {
        $this->getJson(self::PATH)->assertUnauthorized();
        $this->putJson(self::PATH, $this->settings(1, 'Unauthorized store'))->assertUnauthorized();
        $this->actingAs($this->operator())->getJson(self::PATH)->assertOk()
            ->assertJsonPath('name', 'Super Store')->assertJsonPath('revision', 1)->assertJsonPath('address', null)
            ->assertJsonPath('currency', 'PKR')->assertJsonPath('timezone', 'Asia/Karachi');
        $this->putJson(self::PATH, $this->settings(1, ''))->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->putJson(self::PATH, $this->settings(1, "New\nline"))->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->putJson(self::PATH, $this->settings(1, 'My store') + ['currency' => 'USD', 'timezone' => 'UTC'])
            ->assertUnprocessable()->assertJsonValidationErrors(['currency', 'timezone']);

        $body = $this->settings(1, 'My store');
        $saved = $this->putJson(self::PATH, $body)->assertOk()->assertJsonPath('revision', 2)->json();
        $this->putJson(self::PATH, $body)->assertOk()->assertExactJson($saved);
        $this->putJson(self::PATH, $this->settings(1, 'Stale store'))->assertConflict();
        $this->getJson(self::PATH)->assertOk()->assertExactJson($saved);

        $cleared = ['revision' => 2, 'name' => 'My store', 'address' => null, 'phone' => null, 'receipt_footer' => null];
        $this->putJson(self::PATH, $cleared)->assertOk()->assertJsonPath('revision', 3)
            ->assertJsonPath('address', null)->assertJsonPath('phone', null)->assertJsonPath('receipt_footer', null);
    }

    public function test_receipt_branding_is_captured_at_sale_and_refund_posting_and_preserved_on_reprint(): void
    {
        $this->seed();
        $operator = $this->operator();
        $this->actingAs($operator);
        PosProduct::where('sku', 'MIN-01')->firstOrFail()->forceFill(['stock_milli' => 4000])->save();
        $old = PosOrder::create([
            'user_id' => $operator->id, 'request_id' => (string) Str::uuid(), 'cashier' => $operator->name,
            'line_count' => 0, 'total_minor' => 0, 'lines' => [],
        ]);

        $this->putJson(self::PATH, $this->settings(1, 'Market One'))->assertOk();
        $sale = [
            'request_id' => (string) Str::uuid(), 'payment_method' => 'cash', 'cash_received_minor' => 6000,
            'lines' => [['sku' => 'MIN-01', 'qty' => 1, 'price_minor' => 6000, 'discount' => 0]],
        ];
        $posted = $this->postJson('/api/pos/checkout', $sale)->assertCreated()
            ->assertJsonPath('receipt_store.name', 'Market One')->assertJsonPath('receipt_store.phone', '+92 300 555 0100')->json();
        $this->putJson(self::PATH, $this->settings(2, 'Market Two'))->assertOk();
        $this->getJson('/api/pos/orders/'.$old->id)->assertOk()->assertJsonPath('receipt_store', null);
        $order = '/api/pos/orders/'.$posted['id'];
        $this->getJson($order)->assertOk()->assertJsonPath('receipt_store.name', 'Market One');
        $this->postJson('/api/pos/checkout', $sale)->assertOk()->assertJsonPath('receipt_store.name', 'Market One');

        $lines = [['line' => 0, 'qty_milli' => 1000, 'restock' => true]];
        $quote = $this->postJson($order.'/refund-preview', compact('lines'))->assertOk()->json('quote');
        $refundBody = ['lines' => $lines, 'request_id' => (string) Str::uuid(), 'reason' => 'Returned', 'quote' => $quote];
        $refund = $this->postJson($order.'/refunds', $refundBody)->assertCreated()
            ->assertJsonPath('receipt_store.name', 'Market Two')->json();
        $this->putJson(self::PATH, $this->settings(3, 'Market Three'))->assertOk();
        $this->getJson('/api/pos/refunds/'.$refund['id'])->assertOk()->assertJsonPath('receipt_store.name', 'Market Two');
        $this->postJson($order.'/refunds', $refundBody)->assertOk()->assertJsonPath('receipt_store.name', 'Market Two');
    }
}
