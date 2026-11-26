<?php

namespace App\Http\Controllers;

use App\Models\PosProduct;
use App\Models\PosStockMovement;
use App\Models\PosSupplier;
use App\Services\PosInventory;
use App\Services\PosListing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PosInventoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = PosListing::filters($request);
        $request->validate(['low_stock' => ['sometimes', 'boolean'], 'status' => ['sometimes', 'in:active,archived']]);
        $products = PosProduct::with('unit:id,code,quantity_precision')
            ->select(['id', 'sku', 'name', 'internal_code', 'barcode', 'unit_id', 'supplier_id', 'stock_milli', 'low_stock_milli', 'is_active'])
            ->orderBy('name')->orderBy('id');
        if (($term = $filters['q'] ?? '') !== '') {
            $products->where(fn ($query) => PosListing::whereContains($query, ['name', 'sku', 'internal_code', 'barcode'], $term));
        }
        if ($request->filled('status')) {
            $products->where('is_active', $request->input('status') === 'active');
        }
        if ($request->boolean('low_stock')) {
            $products->whereColumn('stock_milli', '<=', 'low_stock_milli');
        }

        return PosListing::response($products, $filters);
    }

    public function movements(Request $request, int $product): JsonResponse
    {
        PosProduct::findOrFail($product);

        return PosListing::response(PosStockMovement::where('product_id', $product)->orderByDesc('id'), PosListing::filters($request));
    }

    public function record(Request $request, int $product): JsonResponse
    {
        $body = $request->validate([
            'request_id' => ['required', 'uuid'],
            'type' => ['required', 'in:opening,receipt,adjustment,damage'],
            'quantity' => ['required', 'numeric', 'decimal:0,3', 'between:-1000000,1000000'],
            'supplier_id' => ['nullable', 'integer', 'required_if:type,receipt', 'prohibited_unless:type,receipt', 'exists:pos_suppliers,id'],
            'reason' => ['required', 'string', 'max:240'],
        ]);
        $delta = (int) round($body['quantity'] * 1000);
        $type = $body['type'];
        if ($delta === 0 || ($type !== 'adjustment' && $delta < 0)) {
            throw ValidationException::withMessages(['quantity' => $type === 'adjustment' ? 'Enter a nonzero adjustment.' : 'Enter a positive quantity.']);
        }

        return DB::transaction(function () use ($request, $product, $body, $type, $delta) {
            $item = PosProduct::lockForUpdate()->findOrFail($product);
            $existing = PosStockMovement::where('product_id', $product)->where('request_id', $body['request_id'])->first();
            if ($existing) {
                abort_unless($existing->user_id === $request->user()->id, 409);

                return response()->json($existing);
            }
            if (!$item->is_active && !($type === 'damage' || ($type === 'adjustment' && $delta < 0))) {
                throw ValidationException::withMessages(['type' => 'Archived products only allow stock reductions. Reactivate the product before adding stock.']);
            }
            if ($type === 'opening' && PosStockMovement::where('product_id', $product)->exists()) {
                throw ValidationException::withMessages(['type' => 'Opening stock is only for the first movement. Use an adjustment instead.']);
            }
            $supplier = isset($body['supplier_id']) ? PosSupplier::findOrFail($body['supplier_id']) : null;
            $movement = PosInventory::move($item, $request->user(), $type, $type === 'damage' ? -$delta : $delta, $body['reason'], [
                'request_id' => $body['request_id'], 'supplier_id' => $supplier?->id, 'supplier_name' => $supplier?->name,
            ]);

            return response()->json($movement, 201);
        }, 3);
    }

    public function threshold(Request $request, int $product): JsonResponse
    {
        $body = $request->validate(['quantity' => ['required', 'numeric', 'decimal:0,3', 'between:0,1000000']]);
        $quantity = (int) round($body['quantity'] * 1000);

        return DB::transaction(function () use ($product, $quantity) {
            $item = PosProduct::where('is_active', true)->lockForUpdate()->findOrFail($product);
            PosInventory::checkPrecision($item, $quantity);
            $item->forceFill(['low_stock_milli' => $quantity])->save();

            return response()->json(['low_stock_milli' => $quantity]);
        }, 3);
    }
}
