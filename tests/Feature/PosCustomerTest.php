<?php

namespace Tests\Feature;

use App\Models\PosProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PosCustomerTest extends TestCase
{
    use RefreshDatabase {
        refreshDatabase as private runTestMigrations;
    }

    private const DIRECTORY = '/api/pos/management/customers';
    private const ORDERS = '/api/pos/orders';

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

    private function cashier(): User
    {
        return User::create(['name' => 'Test Cashier', 'email' => 'cashier@example.test', 'password' => 'fixture-password']);
    }

    private function orderPayload(): array
    {
        return [
            'request_id' => (string) Str::uuid(),
            'lines' => [['sku' => 'MIN-01', 'qty' => 1, 'price_minor' => 6000, 'discount' => 0]],
        ];
    }

    public function test_directory_can_create_edit_search_and_archive_customers_without_rewriting_receipts(): void
    {
        $this->seed();
        $item = PosProduct::where('sku', 'MIN-01')->firstOrFail();
        $item->forceFill(['stock_milli' => 5000])->save();

        $this->getJson(self::DIRECTORY)->assertUnauthorized();
        $this->actingAs($this->cashier())->postJson(self::DIRECTORY, ['email' => 'bad'])->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email']);
        $customer = $this->postJson(self::DIRECTORY, [
            'name' => 'Nadia Khan', 'email' => 'nadia@example.test', 'phone' => '+92 300 555 0100',
        ])->assertCreated()->json();
        $this->postJson(self::DIRECTORY, ['name' => 'Aisha'])->assertCreated();
        $this->getJson(self::DIRECTORY.'?per_page=1&page=2')->assertOk()
            ->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.id', $customer['id']);
        foreach (['Nadia', 'nadia@example.test', '555 0100'] as $q) {
            $this->getJson(self::DIRECTORY.'?q='.urlencode($q))->assertOk()
                ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $customer['id']);
        }

        $body = $this->orderPayload() + ['customer_id' => $customer['id'], 'customer' => 'Client-supplied name',
            'payment_method' => 'cash', 'cash_received_minor' => 6000];
        $sold = $this->postJson('/api/pos/checkout', $body)->assertCreated()
            ->assertJsonPath('customer', 'Nadia Khan')->assertJsonPath('customer_id', $customer['id'])->json();
        $this->putJson(self::DIRECTORY.'/'.$customer['id'], ['name' => 'Nadia Malik'])->assertOk();
        $this->deleteJson(self::DIRECTORY.'/'.$customer['id'])->assertNoContent();
        $this->getJson(self::DIRECTORY.'?q=Nadia')->assertJsonPath('meta.total', 0);
        $this->getJson(self::ORDERS.'/'.$sold['id'])->assertOk()->assertJsonPath('customer', 'Nadia Khan');
        $this->postJson('/api/pos/checkout', $body)->assertOk()->assertJsonPath('id', $sold['id'])
            ->assertJsonPath('customer', 'Nadia Khan');
        $this->assertSame(4000, $item->fresh()->stock_milli);
        $this->postJson(self::ORDERS, $this->orderPayload() + ['customer_id' => $customer['id']])
            ->assertUnprocessable()->assertJsonValidationErrors('customer_id');
    }

    public function test_customer_is_optional_and_a_draft_can_change_or_clear_its_customer(): void
    {
        $this->seed();
        $this->actingAs($this->cashier());
        $first = $this->postJson(self::DIRECTORY, ['name' => 'Same name'])->assertCreated()->json('id');
        $second = $this->postJson(self::DIRECTORY, ['name' => 'Same name'])->assertCreated()->json('id');
        $body = $this->orderPayload();
        $legacy = $this->postJson(self::ORDERS, $body + ['customer' => 'Legacy customer'])
            ->assertCreated()->assertJsonPath('customer', 'Legacy customer')->json();
        $this->postJson(self::ORDERS, $body + ['customer' => 'Legacy customer', 'customer_id' => null])
            ->assertOk()->assertJsonPath('id', $legacy['id']);

        $draft = $this->postJson(self::ORDERS, $this->orderPayload())->assertCreated()
            ->assertJsonPath('customer', null)->assertJsonPath('customer_id', null)->json();
        $path = self::ORDERS.'/'.$draft['id'];
        $chosen = $this->putJson($path, $body + ['revision' => $draft['revision'], 'customer_id' => $first])
            ->assertOk()->assertJsonPath('customer', 'Same name')->assertJsonPath('customer_id', $first)->json();
        $changed = $this->putJson($path, $body + ['revision' => $chosen['revision'], 'customer_id' => $second])
            ->assertOk()->assertJsonPath('customer_id', $second)->json();
        $this->assertNotSame($chosen['revision'], $changed['revision']);
        $this->deleteJson(self::DIRECTORY.'/'.$second)->assertNoContent();
        $this->putJson($path, $body + ['revision' => $changed['revision'], 'customer_id' => $second])
            ->assertOk()->assertJsonPath('customer', 'Same name');
        $this->putJson($path, $body + ['revision' => $changed['revision'], 'customer_id' => null])
            ->assertOk()->assertJsonPath('customer_id', null)->assertJsonPath('customer', null);

        PosProduct::where('sku', 'MIN-01')->firstOrFail()->forceFill(['stock_milli' => 1000])->save();
        $this->postJson('/api/pos/checkout', $this->orderPayload() + [
            'payment_method' => 'cash', 'cash_received_minor' => 6000, 'customer_id' => null,
        ])->assertCreated()->assertJsonPath('customer', null)->assertJsonPath('customer_id', null);
    }
}
