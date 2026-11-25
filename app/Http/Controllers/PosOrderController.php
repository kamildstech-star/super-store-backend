<?php

namespace App\Http\Controllers;

use App\Models\PosOrder;
use App\Models\PosProduct;
use App\Services\PosListing;
use App\Services\PosInventory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PosOrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = PosListing::filters($request);
        $term = $filters['q'] ?? '';
        $orders = PosOrder::query()
            ->select(['id', 'created_at', 'cashier', 'customer', 'line_count', 'total_minor', 'finalized_at', 'payment_method'])
            ->orderByDesc('id');
        if ($term !== '') {
            $orders->where(function ($match) use ($term): void {
                PosListing::whereContains($match, ['cashier', 'customer'], $term);
                $number = ltrim($term, '#');
                if (ctype_digit($number) && strlen($number) <= 18) {
                    $match->orWhere('id', (int) $number);
                }
                if (in_array(strtolower($term), ['walk-in', 'walk in'], true)) {
                    $match->orWhereNull('customer');
                }
            });
        }

        return PosListing::response($orders, $filters);
    }

    public function show(PosOrder $order): JsonResponse
    {
        return response()->json($this->detail($order));
    }

    public function store(Request $request): JsonResponse
    {
        $body = $request->validate(['request_id' => ['required', 'uuid']] + $this->orderRules());
        $identity = ['user_id' => $request->user()->id, 'request_id' => $body['request_id']];
        if ($existing = PosOrder::where($identity)->first()) {
            return response()->json($this->detail($existing));
        }

        $order = PosOrder::firstOrCreate($identity, ['cashier' => $request->user()->name] + $this->orderAttributes($body));

        return response()->json($this->detail($order), $order->wasRecentlyCreated ? 201 : 200);
    }

    public function update(Request $request, int $order): JsonResponse
    {
        $body = $request->validate(['revision' => ['required', 'string', 'size:64']] + $this->orderRules());

        return DB::transaction(function () use ($body, $order): JsonResponse {
            $saved = PosOrder::query()->lockForUpdate()->findOrFail($order);
            if ($saved->finalized_at) {
                return response()->json(['message' => 'Finalized sales cannot be edited.'], 409);
            }
            if (! hash_equals($this->revision($saved), $body['revision'])) {
                return response()->json(['message' => 'This order was changed elsewhere. Open it again to edit the latest version.'], 409);
            }

            $saved->update($this->orderAttributes($body, $saved->lines));

            return response()->json($this->detail($saved));
        });
    }

    public function checkout(Request $request, ?int $order = null): JsonResponse
    {
        $body = $request->validate([
            'payment_method' => ['required', 'in:cash,card,bank_transfer'],
        ] + ($order === null ? ['request_id' => ['required', 'uuid']] : ['revision' => ['required', 'string', 'size:64'], 'checkout_id' => ['required', 'uuid']]) + $this->orderRules());

        return DB::transaction(function () use ($request, $order, $body): JsonResponse {
            $user = $request->user();
            $checkoutId = $body['checkout_id'] ?? $body['request_id'];
            if ($order !== null) {
                $saved = PosOrder::query()->lockForUpdate()->findOrFail($order);
            } else {
                $identity = ['user_id' => $user->id, 'request_id' => $body['request_id']];
                $saved = PosOrder::where($identity)->first();
                if (! $saved) {
                    $saved = PosOrder::firstOrCreate($identity, ['cashier' => $user->name] + $this->orderAttributes($body));
                }
                $created = $saved->wasRecentlyCreated;
                $saved = PosOrder::query()->lockForUpdate()->findOrFail($saved->id);
            }

            if ($saved->finalized_at) {
                return $saved->checkout_id === $checkoutId && $saved->payment_method === $body['payment_method']
                    ? response()->json($this->detail($saved))
                    : response()->json(['message' => 'This sale has already been finalized.'], 409);
            }
            if ($order !== null && ! hash_equals($this->revision($saved), $body['revision'])) {
                return response()->json(['message' => 'This order was changed elsewhere. Reopen it before finalizing.'], 409);
            }
            if ($order === null && ! $created) {
                return response()->json(['message' => 'A draft already exists. Open it in Orders to finalize it.'], 409);
            }

            $attributes = $order === null ? ['lines' => $saved->lines] : $this->orderAttributes($body, $saved->lines);
            $products = PosProduct::with('unit')->whereIn('sku', array_column($attributes['lines'], 'sku'))
                ->where('is_active', true)->orderBy('id')->lockForUpdate()->get()->keyBy('sku');
            foreach ($attributes['lines'] as $line) {
                $product = $products->get($line['sku']);
                if (! $product || $product->unit->code !== $line['unit']) {
                    throw ValidationException::withMessages(['lines' => "{$line['name']} is unavailable or its unit changed. Remove it and add the current item before finalizing."]);
                }
                PosInventory::move($product, $user, 'sale', -(int) round($line['qty'] * 1000), 'Finalized sale', ['order_id' => $saved->id]);
            }

            $saved->fill($attributes)->forceFill([
                'finalized_at' => now(), 'checkout_id' => $checkoutId, 'payment_method' => $body['payment_method'], 'finalized_by' => $user->id,
            ])->save();

            return response()->json($this->detail($saved), $order === null ? 201 : 200);
        }, 3);
    }

    private function detail(PosOrder $order): array
    {
        return $order->toArray() + ['revision' => $this->revision($order), 'finalized_at' => null, 'payment_method' => null];
    }

    private function revision(PosOrder $order): string
    {
        return hash('sha256', json_encode([
            $order->customer, $order->note, array_map(function (array $line): array {
                ksort($line);

                return $line;
            }, $order->lines),
        ], JSON_THROW_ON_ERROR));
    }

    private function orderRules(): array
    {
        return [
            'customer' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:160'],
            'lines' => ['required', 'array', 'list', 'min:1', 'max:100'],
            'lines.*' => ['required', 'array:sku,qty,price_minor,discount'],
            'lines.*.sku' => ['required', 'string', 'max:40', 'distinct'],
            'lines.*.qty' => ['required', 'numeric', 'decimal:0,3', 'min:0.001', 'max:9999'],
            'lines.*.price_minor' => ['required', 'integer', 'min:0', 'max:4294967295'],
            'lines.*.discount' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:100'],
        ];
    }

    private function orderAttributes(array $body, array $previous = []): array
    {
        $previousBySku = array_column($previous, null, 'sku');
        $products = PosProduct::query()->with('unit')->where('is_active', true)
            ->whereIn('sku', array_column($body['lines'], 'sku'))->get()->keyBy('sku');
        $lines = [];
        foreach ($body['lines'] as $index => $line) {
            $old = $previousBySku[$line['sku']] ?? null;
            if ($old && (float) $old['qty'] === (float) $line['qty']
                && (int) $old['price_minor'] === (int) $line['price_minor']
                && (float) $old['discount'] === (float) $line['discount']) {
                $lines[] = $old;

                continue;
            }
            $product = $products->get($line['sku']);
            if (! $product) {
                throw ValidationException::withMessages(["lines.$index.sku" => 'A product is no longer available. Remove it and try again.']);
            }
            $qty = (int) round($line['qty'] * 1000);
            if ($qty % (10 ** (3 - $product->unit->quantity_precision)) !== 0) {
                throw ValidationException::withMessages(["lines.$index.qty" => "Check the quantity for {$product->name}."]);
            }
            $discount = (int) round($line['discount'] * 100);
            // Price overrides are allowed in this POS; the server calculates the actual total.
            $scaledPrice = $qty * (int) $line['price_minor'];
            $factor = 10000 - $discount;
            // Split the division so large orders do not overflow PHP integers.
            $amount = intdiv($scaledPrice, 10000000) * $factor
                + intdiv(($scaledPrice % 10000000) * $factor + 5000000, 10000000);
            $lines[] = [
                'sku' => $product->sku,
                'name' => $product->name,
                'unit' => $product->unit->code,
                'qty' => $qty / 1000,
                'price_minor' => (int) $line['price_minor'],
                'discount' => $discount / 100,
                'total_minor' => $amount,
            ];
        }

        return Arr::only($body, ['customer', 'note']) + [
            'line_count' => count($lines),
            'total_minor' => array_sum(array_column($lines, 'total_minor')),
            'lines' => $lines,
        ];
    }
}
