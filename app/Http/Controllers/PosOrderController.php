<?php

namespace App\Http\Controllers;

use App\Models\PosOrder;
use App\Models\PosProduct;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class PosOrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(['before' => ['sometimes', 'integer', 'min:1']]);
        $orders = PosOrder::query()
            ->select(['id', 'created_at', 'cashier', 'customer', 'line_count', 'total_minor'])
            ->when(isset($filters['before']), fn ($query) => $query->where('id', '<', $filters['before']))
            ->orderByDesc('id')->limit(31)->get();
        $page = $orders->take(30)->values();

        return response()->json(['orders' => $page, 'next_before' => $orders->count() > 30 ? $page->last()->id : null]);
    }

    public function show(PosOrder $order): JsonResponse
    {
        return response()->json($order);
    }

    public function store(Request $request): JsonResponse
    {
        $body = $request->validate([
            'request_id' => ['required', 'uuid'],
            'customer' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:160'],
            'lines' => ['required', 'array', 'list', 'min:1', 'max:100'],
            'lines.*' => ['required', 'array:sku,qty,price_minor,discount'],
            'lines.*.sku' => ['required', 'string', 'max:40', 'distinct'],
            'lines.*.qty' => ['required', 'numeric', 'decimal:0,3', 'min:0.001', 'max:9999'],
            'lines.*.price_minor' => ['required', 'integer', 'min:0', 'max:4294967295'],
            'lines.*.discount' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:100'],
        ]);

        $identity = ['user_id' => $request->user()->id, 'request_id' => $body['request_id']];
        if ($existing = PosOrder::where($identity)->first()) {
            return response()->json($existing);
        }

        $products = PosProduct::query()->with('unit')->where('is_active', true)
            ->whereIn('sku', array_column($body['lines'], 'sku'))->get()->keyBy('sku');
        $lines = [];
        foreach ($body['lines'] as $index => $line) {
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

        $order = PosOrder::firstOrCreate($identity, Arr::only($body, ['customer', 'note']) + [
            'cashier' => $request->user()->name,
            'line_count' => count($lines),
            'total_minor' => array_sum(array_column($lines, 'total_minor')),
            'lines' => $lines,
        ]);

        return response()->json($order, $order->wasRecentlyCreated ? 201 : 200);
    }
}
