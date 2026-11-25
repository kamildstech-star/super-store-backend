<?php

namespace App\Http\Controllers;

use App\Models\PosCategory;
use App\Models\PosProduct;
use App\Models\PosUnit;
use Illuminate\Http\JsonResponse;

class PosCatalogController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $categories = PosCategory::query()->orderBy('sort_order')->orderBy('id')
            ->get(['slug', 'name', 'icon', 'color']);

        $units = PosUnit::query()->orderBy('id')->get(['code', 'name', 'quantity_precision']);

        $products = PosProduct::query()->where('is_active', true)
            ->with(['category:id,slug', 'unit:id,code'])
            ->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (PosProduct $product): array => [
                'sku' => $product->sku,
                'internal_code' => $product->internal_code,
                'barcode' => $product->barcode,
                'name' => $product->name,
                'category' => $product->category->slug,
                'unit' => $product->unit->code,
                'price_minor' => $product->price_minor,
                'stock_milli' => $product->stock_milli,
                'art' => [
                    'shape' => $product->art_shape,
                    'ink' => $product->art_ink,
                    'tone' => $product->art_tone,
                    'label' => $product->art_label,
                ],
            ]);

        return response()->json([
            'currency' => 'PKR',
            'categories' => $categories,
            'units' => $units,
            'products' => $products,
        ])->header('Cache-Control', 'no-store');
    }
}
