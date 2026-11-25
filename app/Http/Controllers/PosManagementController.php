<?php

namespace App\Http\Controllers;

use App\Models\PosCategory;
use App\Models\PosProduct;
use App\Models\PosSupplier;
use App\Models\PosStockMovement;
use App\Models\PosUnit;
use App\Services\PosProductCode;
use App\Services\PosListing;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PosManagementController extends Controller
{
    private const TYPES = [
        'products' => PosProduct::class,
        'categories' => PosCategory::class,
        'units' => PosUnit::class,
        'suppliers' => PosSupplier::class,
    ];

    public function index(Request $request, string $resource): JsonResponse
    {
        $filters = PosListing::filters($request);
        $term = $filters['q'] ?? '';
        $query = self::TYPES[$resource]::query()->orderBy('name')->orderBy('id');
        if ($term !== '') {
            $columns = match ($resource) {
                'products' => ['name', 'sku', 'internal_code', 'barcode'],
                'categories' => ['name', 'slug'],
                'units' => ['name', 'code'],
                'suppliers' => ['name', 'email', 'phone'],
            };
            $query->where(function ($match) use ($columns, $term, $resource): void {
                PosListing::whereContains($match, $columns, $term);
                if ($resource === 'products' && in_array(strtolower($term), ['active', 'archived'], true)) {
                    $match->orWhere('is_active', strtolower($term) === 'active');
                }
            });
        }

        return PosListing::response($query, $filters);
    }

    public function options(): JsonResponse
    {
        // Product pickers use lightweight lookup data, independent of any listing page.
        return response()->json([
            'categories' => PosCategory::orderBy('name')->orderBy('id')->get(['id', 'name', 'slug']),
            'units' => PosUnit::orderBy('name')->orderBy('id')->get(['id', 'name', 'code']),
            'suppliers' => PosSupplier::orderBy('name')->orderBy('id')->get(['id', 'name', 'email', 'phone']),
        ]);
    }

    public function store(Request $request, string $resource, PosProductCode $codes): JsonResponse
    {
        $type = self::TYPES[$resource];
        $data = $this->validated($request, $resource);
        $generated = $resource === 'products' && !isset($data['internal_code']);
        if ($resource === 'products') {
            $data += [
                'sku' => 'POS-'.Str::ulid(), 'sort_order' => 0, 'art_shape' => 'box',
                'art_ink' => '#785971', 'art_tone' => '#f0eaf0', 'art_label' => '', 'is_active' => true,
            ];
        } elseif ($resource === 'categories') {
            $data += ['sort_order' => 0, 'icon' => 'grid', 'color' => '#785971'];
        }

        for ($attempt = 0; $attempt < 6; $attempt++) {
            if ($generated) {
                $data['internal_code'] = $codes->next();
            }
            try {
                return response()->json($type::create($data), 201);
            } catch (UniqueConstraintViolationException $error) {
                if (!$generated || PosProduct::where('barcode', $data['barcode'] ?? null)
                    ->whereNotNull('barcode')->exists()) {
                    throw ValidationException::withMessages(['record' => 'That code or barcode is already in use.']);
                }
            }
        }

        throw ValidationException::withMessages(['internal_code' => 'Could not reserve a unique code. Try again.']);
    }

    public function update(Request $request, string $resource, int $id): JsonResponse
    {
        try {
            return DB::transaction(function () use ($request, $resource, $id) {
                $record = self::TYPES[$resource]::query()->lockForUpdate()->findOrFail($id);
                $data = $this->validated($request, $resource, $record);
                if ($resource === 'products' && (int) $data['unit_id'] !== (int) $record->unit_id
                    && PosStockMovement::where('product_id', $record->id)->exists()) {
                    throw ValidationException::withMessages(['unit_id' => 'This product has stock history. Create a new product for a different unit.']);
                }
                if ($resource === 'units' && (int) $data['quantity_precision'] !== $record->quantity_precision) {
                    $ids = PosProduct::where('unit_id', $record->id)->orderBy('id')->lockForUpdate()->pluck('id');
                    if (PosStockMovement::whereIn('product_id', $ids)->exists()) {
                        throw ValidationException::withMessages(['quantity_precision' => 'This unit has stock history. Create a new unit to use a different precision.']);
                    }
                }
                $record->update($data);

                return response()->json($record->refresh());
            }, 3);
        } catch (UniqueConstraintViolationException $error) {
            throw ValidationException::withMessages(['record' => 'That code or barcode is already in use.']);
        }
    }

    public function destroy(string $resource, int $id): JsonResponse
    {
        $record = self::TYPES[$resource]::findOrFail($id);
        if ($resource === 'products') {
            $record->update(['is_active' => false]);
        } else {
            $foreignKey = match ($resource) {
                'categories' => 'category_id', 'units' => 'unit_id', 'suppliers' => 'supplier_id',
            };
            if (PosProduct::where($foreignKey, $id)->exists()
                || ($resource === 'suppliers' && PosStockMovement::where('supplier_id', $id)->exists())) {
                return $this->referenced();
            }

            try {
                $record->delete();
            } catch (QueryException $error) {
                if (in_array((string) $error->getCode(), ['23000', '23503'], true)) {
                    return $this->referenced();
                }
                throw $error;
            }
        }

        return response()->json(null, 204);
    }

    private function referenced(): JsonResponse
    {
        return response()->json(['message' => 'This record is used by a product or inventory history and cannot be deleted while referenced.'], 409);
    }

    private function validated(Request $request, string $resource, ?Model $record = null): array
    {
        $unique = function (string $column) use ($resource, $record) {
            $rule = Rule::unique((new (self::TYPES[$resource]))->getTable(), $column);
            return $record ? $rule->ignore($record->getKey()) : $rule;
        };
        if ($resource === 'products' && is_string($request->input('barcode'))) {
            $request->merge(['barcode' => strtoupper(trim($request->input('barcode')))]);
        }

        $fields = match ($resource) {
            'products' => [
                'internal_code' => [$record ? 'required' : 'nullable', 'string', 'regex:/^[0-9]{4}$/D', $unique('internal_code')],
                'barcode' => ['nullable', 'string', 'regex:/^[A-Z0-9._-]{1,48}$/D', $unique('barcode')],
                'price_minor' => ['required', 'integer', 'between:0,99999999'],
                'category_id' => ['required', 'integer', 'exists:pos_categories,id'],
                'unit_id' => ['required', 'integer', 'exists:pos_units,id'],
                'supplier_id' => ['nullable', 'integer', 'exists:pos_suppliers,id'],
                'is_active' => ['sometimes', 'boolean'],
            ],
            'categories' => $record ? [] : ['slug' => ['required', 'string', 'max:48', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', 'not_in:all', $unique('slug')]],
            'units' => ($record ? [] : ['code' => ['required', 'string', 'max:16', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $unique('code')]])
                + ['quantity_precision' => ['required', 'integer', 'between:0,3']],
            'suppliers' => ['email' => ['nullable', 'email:rfc', 'max:254'], 'phone' => ['nullable', 'string', 'max:40']],
        };

        return $request->validate(['name' => ['required', 'string', 'max:'.match ($resource) {
            'units' => 40, 'categories' => 100, default => 150,
        }]] + $fields);
    }
}
