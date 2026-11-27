<?php

namespace App\Http\Controllers;

use App\Models\PosProduct;
use App\Models\PosPurchaseReceipt;
use App\Models\PosSupplier;
use App\Services\PosCashDrawer;
use App\Services\PosInventory;
use App\Services\PosSupplierPayments;
use App\Services\PosListing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PosPurchaseReceiptController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = PosListing::filters($request);
        $supplier = $request->validate(['supplier_id' => ['nullable', 'integer', 'min:1']])['supplier_id'] ?? null;
        $query = PosPurchaseReceipt::query()->orderByDesc('id');
        if ($supplier !== null) {
            $query->where('supplier_id', $supplier);
        }
        if (($term = trim($filters['q'] ?? '')) !== '') {
            $query->where(function ($match) use ($term): void {
                PosListing::whereContains($match, ['supplier_name', 'invoice_reference'], $term);
                if (preg_match('/^(?:PR-)?([0-9]{1,15})$/iD', $term, $reference)) {
                    $match->orWhere('id', (int) $reference[1]);
                }
            });
        }

        return PosListing::response($query, $filters);
    }

    public function products(): JsonResponse
    {
        // Compact options for the existing searchable picker; stock is checked at posting.
        return response()->json(PosProduct::query()->where('is_active', true)
            ->with('unit:id,code,quantity_precision')->orderBy('name')->orderBy('id')
            ->get(['id', 'name', 'internal_code', 'barcode', 'sku', 'unit_id', 'supplier_id']));
    }

    public function show(int $receipt): JsonResponse
    {
        return response()->json(PosPurchaseReceipt::with('lines')->findOrFail($receipt));
    }

    public function store(Request $request): JsonResponse
    {
        $body = $request->validate([
            'request_id' => ['required', 'uuid'],
            'supplier_id' => ['required', 'integer', 'min:1'],
            'invoice_reference' => ['nullable', 'string', 'max:80'],
            'note' => ['nullable', 'string', 'max:240'],
            'payment' => ['nullable', 'array:amount_minor,source,cash_session_id,note', 'required_array_keys:amount_minor,source'],
            'lines' => ['required', 'array', 'list', 'min:1', 'max:50'],
            'lines.*' => ['required', 'array:product_id,unit_id,quantity_milli,unit_cost_minor'],
            'lines.*.product_id' => ['required', 'integer', 'min:1', 'distinct'],
            'lines.*.unit_id' => ['required', 'integer', 'min:1'],
            'lines.*.quantity_milli' => ['required', 'integer', 'between:1,1000000000'],
            'lines.*.unit_cost_minor' => ['required', 'integer', 'between:0,99999999'],
        ] + PosSupplierPayments::rules('payment.'));
        $actor = $request->user();
        $key = strtolower($body['request_id']);
        $supplierId = (int) $body['supplier_id'];
        $invoice = $body['invoice_reference'] ?? null;
        $note = $body['note'] ?? null;
        $lines = array_map(fn ($line) => [
            'product_id' => (int) $line['product_id'],
            'unit_id' => (int) $line['unit_id'],
            'quantity_milli' => (int) $line['quantity_milli'],
            'unit_cost_minor' => (int) $line['unit_cost_minor'],
        ], $body['lines']);
        usort($lines, fn ($a, $b) => $a['product_id'] <=> $b['product_id']);
        $payment = isset($body['payment']) ? PosSupplierPayments::normalize($body['payment']) : null;
        $fingerprint = [$supplierId, $invoice, $note, $lines];
        if ($payment !== null) {
            $fingerprint[] = $payment;
        }
        $hash = hash('sha256', json_encode($fingerprint, JSON_THROW_ON_ERROR));

        // Check completed retries before current supplier or stock validation. Keep this read
        // outside the posting transaction so MySQL does not establish an early read snapshot.
        $existing = PosPurchaseReceipt::where('request_id', $key)->first();
        if ($existing) {
            return $this->replay($existing, $actor->id, $hash);
        }

        return DB::transaction(function () use ($actor, $key, $supplierId, $invoice, $note, $lines, $hash, $payment) {
            $drawer = ($payment['source'] ?? null) === 'drawer' ? PosCashDrawer::lock() : null;
            $supplier = PosSupplier::lockForUpdate()->find($supplierId);
            if (!$supplier) {
                throw ValidationException::withMessages(['supplier_id' => 'Select an existing supplier.']);
            }
            $now = now();
            // The unique request key serializes retries even if their incoming supplier differs.
            DB::table('pos_purchase_receipts')->upsert([[
                'request_id' => $key, 'payload_hash' => $hash, 'supplier_id' => $supplier->id,
                'supplier_name' => $supplier->name, 'user_id' => $actor->id, 'actor' => $actor->name,
                'invoice_reference' => $invoice, 'note' => $note, 'payment_tracking' => true, 'created_at' => $now, 'updated_at' => $now,
            ]], ['request_id'], ['request_id']);
            $receipt = PosPurchaseReceipt::where('request_id', $key)->lockForUpdate()->firstOrFail();
            if ($receipt->line_count > 0) {
                return $this->replay($receipt, $actor->id, $hash);
            }

            $products = PosProduct::with('unit')->whereIn('id', array_column($lines, 'product_id'))
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $total = 0;
            foreach ($lines as $line) {
                $product = $products->get($line['product_id']);
                if (!$product || !$product->is_active) {
                    throw ValidationException::withMessages(['lines' => 'Every line must reference an active product.']);
                }
                if ((int) $product->unit_id !== $line['unit_id']) {
                    throw ValidationException::withMessages(['lines' => "The unit for {$product->name} changed. Refresh and re-enter this line."]);
                }
                unset($line['unit_id']);
                // Round half a paisa up, once per line, using integer arithmetic.
                $lineTotal = intdiv($line['quantity_milli'] * $line['unit_cost_minor'] + 500, 1000);
                $receipt->lines()->create($line + [
                    'name' => $product->name, 'internal_code' => $product->internal_code,
                    'unit' => $product->unit->code, 'total_minor' => $lineTotal,
                ]);
                PosInventory::move($product, $actor, 'receipt', $line['quantity_milli'], 'Purchase receiving', [
                    'supplier_id' => $supplier->id, 'supplier_name' => $supplier->name,
                    'purchase_receipt_id' => $receipt->id,
                ]);
                $total += $lineTotal;
            }
            $receipt->forceFill(['total_minor' => $total, 'line_count' => count($lines)])->save();

            if ($payment !== null) {
                PosSupplierPayments::record($receipt, $actor, $key, $payment, $drawer);
            }

            return response()->json($receipt->load('lines'), 201);
        }, 3);
    }

    private function replay(PosPurchaseReceipt $receipt, int $actor, string $hash): JsonResponse
    {
        abort_unless((int) $receipt->user_id === $actor && hash_equals($receipt->payload_hash, $hash), 409,
            'This receiving request belongs to a different submission.');
        return response()->json($receipt->load('lines'));
    }
}
